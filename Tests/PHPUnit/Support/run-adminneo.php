<?php
/**
 * Runs the real AdminNeo bootstrap with a test-only driver and a recording workbench logger.
 */
require dirname(__DIR__) . '/bootstrap.php';

use axenox\IDE\Common\AdminneoErrorHandler;
use exface\Core\CommonLogic\Log\Logger;
use exface\Core\Exceptions\InternalError;
use exface\Core\Interfaces\iCanGenerateDebugWidgets;
use exface\Core\Interfaces\Log\LogHandlerInterface;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
ini_set('session.use_cookies', '0');
session_cache_limiter('');
error_reporting(E_ALL);
$_SERVER['ADMINNEO_TEST_FAILURE'] = $argv[1];
$_SERVER['REQUEST_URI'] = '/adminneo?ide_test=localhost&username=test&settings=';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_GET = ['ide_test' => 'localhost', 'username' => 'test', 'settings' => '', 'lang' => 'en'];
$_POST = $_COOKIE = [];
session_id('ide-test-' . bin2hex(random_bytes(8)));
session_start();
$_SESSION['pwds']['ide_test']['localhost']['test'] = 'test-fixture';
register_shutdown_function(function () : void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    session_destroy();
});

/**
 * Supplies the test-only driver after AdminNeo has loaded its abstract driver classes.
 *
 * @return \AdminNeo\Admin
 */
function adminneo_instance()
{
    require __DIR__ . '/FakeAdminneoConnection.php';
    require __DIR__ . '/FakeAdminneoDriver.php';
    return \AdminNeo\Admin::create(['embeddedMode' => true, 'versionVerification' => false]);
}

$logger = new Logger();
$logger->appendHandler(new class implements LogHandlerInterface {
    /**
     * Writes outside HTML buffering, so a fatal cannot discard the recorded evidence.
     *
     * {@inheritDoc}
     * @see LogHandlerInterface::handle()
     */
    public function handle($level, $message, array $context = [], iCanGenerateDebugWidgets $sender = null) : void
    {
        $error = $context['exception'] ?? null;
        $original = $error instanceof InternalError ? $error->getPrevious() : $error;
        fwrite(STDOUT, 'LOG:' . json_encode([
            'level' => $level,
            'message' => $message,
            'id' => $context['id'] ?? null,
            'severity' => $original instanceof \ErrorException ? $original->getSeverity()
                : ($original instanceof \Symfony\Component\ErrorHandler\Error\FatalError ? $original->getError()['type'] : null),
            'file' => $original ? $original->getFile() : null,
            'line' => $original ? $original->getLine() : null
        ], JSON_THROW_ON_ERROR) . PHP_EOL);
    }
});
$bridge = new AdminneoErrorHandler($logger);
$appRoot = dirname(__DIR__, 3);
$cwd = getcwd();
ob_start();
try {
    chdir(dirname($appRoot) . '/adminneo/admin');
    require $appRoot . '/Adminneo/adminneo.php';
} catch (\Throwable $error) {
    $logger->logException($error instanceof InternalError ? $error : new InternalError($error->getMessage(), null, $error));
} finally {
    $bridge->restore();
    chdir($cwd);
}
$page = ob_get_clean();
echo 'PAGE:' . (strpos($page, "<form id='settings'") !== false ? 'settings' : 'absent') . PHP_EOL;
echo 'DIAGNOSTICS:' . (preg_match('/Warning:|Fatal error:|Notice:/', $page) ? 'leaked' : 'clean') . PHP_EOL;
