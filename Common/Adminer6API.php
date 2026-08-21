<?php
namespace axenox\IDE\Common;

use exface\Core\CommonLogic\Selectors\DataConnectionSelector;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\JsonDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\UnexpectedValueException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Integration for the axenox/adminer 6.x fork.
 *
 * Serves the fork's static files, boots it with the ExFace plugin stack (see
 * `Adminer6/adminer.php`) and exposes helpers like {@see self::exportDDL()} and
 * {@see self::runSql()} for programmatic access.
 *
 * To fall back to the bundled Adminer 4.8.2 sources, instantiate {@see Adminer4API} instead
 * of this class in {@see \axenox\IDE\Facades\IDEFacade}.
 *
 * @author andrej.kabachnik
 */
class Adminer6API extends InclusionAPI implements SqlAdminApiInterface
{
    const NO_PASSWROD = '12345678';

    /**
     *
     * {@inheritDoc}
     * @see \Psr\Http\Server\RequestHandlerInterface::handle()
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $innerPath = StringDataType::substringAfter($path, $this->getBaseUrlPath(), '');
        return $this->runAdminer($innerPath);
    }



    /**
     *
     * @param array $connectionConfig
     * @param string $connectorClass
     * @return array|NULL
     */
    protected function getAdminerAuth(array $connectionConfig, string $connectorClass) : ?array
    {
        $auth = null;
        
        // Check for placeholders and replace them if needed
        $connectionJson = JsonDataType::encodeJson($connectionConfig);
        if (mb_stripos($connectionJson, '[#') !== false) {
            $phRenderer = $this->getTemplateRenderer();
            $connectionJson = $phRenderer->render($connectionJson);
            $connectionConfig = JsonDataType::decodeJson($connectionJson);
        }
        
        switch (true) {
            // MySQL, MariaDB
            case stripos($connectorClass, 'mariadb') !== false:
            case stripos($connectorClass, 'mysql') !== false:
            case stripos($connectorClass, 'postgresql') !== false:
                $password = $connectionConfig['password'];
                if ($password === '' || $password === null) {
                    $password = Adminer6API::NO_PASSWROD;
                }
                $auth = [
                    'server' => $connectionConfig['host'] . ($connectionConfig['port'] ? ':' . $connectionConfig['port'] : ''),
                    'username' => $connectionConfig['user'],
                    'password' => $password,
                    'driver' => $this->getAdminerDriver($connectorClass),
                    'db'    => $connectionConfig['dbase']
                ];
                // SSL config
                if (null !== $sslVal = $connectionConfig['ssl_key'] ?? null) {
                    $auth['ssl']['key'] = $sslVal;
                }
                if (null !== $sslVal = $connectionConfig['ssl_certificate_path'] ?? null) {
                    $auth['ssl']['cert'] = $this->getPathInWorkbench($sslVal);
                }
                if (null !== $sslVal = $connectionConfig['ssl_ca_certificate_path'] ?? null) {
                    $auth['ssl']['ca'] = $this->getPathInWorkbench($sslVal);
                }
                break;
            // Microsoft SQL Server
            case stripos($connectorClass, 'mssql') !== false:
                $password = $connectionConfig['PWD'] ?? $connectionConfig['password'];
                if ($password === '' || $password === null) {
                    $password = Adminer6API::NO_PASSWROD;
                }
                $auth = [
                    'server' => ($connectionConfig['serverName'] ?? $connectionConfig['host']) . ($connectionConfig['port'] ? ':' . $connectionConfig['port'] : ''),
                    'username' => $connectionConfig['UID'] ?? $connectionConfig['user'],
                    'password' => $password,
                    'driver' => $this->getAdminerDriver($connectorClass),
                    'db'    => $connectionConfig['database'] ?? $connectionConfig['dbase']
                ];
                // Advanced connection options (e.g. TrustServerCertificate, Encrypt) are passed
                // to the MS SQL driver via connectSsl() - the AdminerLoginSsl plugin exposes the
                // `ssl` array there. The old integration appended ;Options={...} to the server
                // name, which the 6.x driver would treat as part of the host.
                if (null !== $options = $connectionConfig['connection_options'] ?? null) {
                    if (is_string($options)) {
                        $options = json_decode($options, true) ?: [];
                    }
                    foreach ((array) $options as $optKey => $optVal) {
                        $auth['ssl'][$optKey] = $optVal;
                    }
                }
        }
        return $auth;
    }

    protected function getPathInWorkbench(string $path) : string
    {
        if (FilePathDataType::isAbsolute($path)) {
            return $path;
        }
        return $this->getWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR . $path;
    }

    /**
     *
     * @param string $connector
     * @return string|NULL
     */
    protected function getAdminerDriver(string $connector) : ?string
    {
        $adminerDrivers = [
            'mysql' => 'server',
            'sqlite' => 'sqlite', // what is the difference to sqlite2???
            'postgresql' => 'pgsql',
            'oraclesql' => 'oracle',
            'mssql' => 'mssql',
            'mongodb' => 'mongo',
            'elastic' => 'elastic'
        ];

        foreach ($adminerDrivers as $key => $driver) {
            if (stripos($connector, $key) !== false) {
                return $driver;
            }
        }

        return null;
    }

    /**
     *
     * @return string|NULL
     */
    protected function getAdminerDbInSession() : ?string
    {
        $pwds = $_SESSION['pwds'];
        if ($pwds === null) {
            return null;
        }
        $servers = $pwds['server'];
        $serverName = null;
        foreach ($servers as $serverName => $serverData) {
            foreach ($serverData as $userName => $userData) {
                if (! empty($userData)) {
                    return $serverName;
                }
            }
        }
        return null;
    }

    /**
     *
     * @param string $pathInFacade
     * @throws UnexpectedValueException
     * @return ResponseInterface
     */
    protected function runAdminer(string $pathInFacade) : ResponseInterface
    {
        $target = StringDataType::substringAfter($pathInFacade, 'adminer/');
        $selector = rtrim($target, '/');

        $forkDir = $this->getForkAdminerPath();
        $customDir = $this->getCustomAssetsPath();

        switch (true) {
            // Custom ExFace assets (theme CSS, vendored jush editor, ...) are served under the
            // "exface/" prefix. The jush editor lives in Adminer6/assets/jush/ because Adminer
            // keeps it as a git submodule that Composer does not populate; AdminerExfaceDesign
            // loads it from here via head()/syntaxHighlighting() overrides.
            case StringDataType::startsWith($selector, 'exface/'):
                $file = $customDir . StringDataType::substringAfter($selector, 'exface/');
                if (file_exists($file) && ! is_dir($file)) {
                    return $this->serveFile($file);
                }
                return new Response(404, $this->getHeadersCommon(), 'Not found: ' . $selector);

            // Static files shipped with the Adminer fork (static/..., etc.)
            case $selector !== '' && ! is_dir($forkDir . $selector) && file_exists($forkDir . $selector):
                return $this->serveFile($forkDir . $selector);

            // Otherwise render an Adminer page for the given connection
            default:
                switch (true) {
                    case isset($_POST['logout']):
                        $_GET = [];
                        $_POST = [];
                        break;
                    case !count($_GET):
                        // adminer/localhost/ -> localhost
                        $dataSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.CONNECTION');
                        $dataSheet->getFilters()->addConditionFromString('ALIAS_WITH_NS', $selector, ComparatorDataType::EQUALS);
                        $dataSheet->getColumns()->addMultiple([
                            'CONFIG',
                            'CONNECTOR',
                            'UID',
                        ]);

                        $dataSheet->dataRead();

                        if (strcasecmp($selector, DataConnectionSelector::METAMODEL_CONNECTION_ALIAS) === 0 || strcasecmp($selector, DataConnectionSelector::METAMODEL_CONNECTION_UID) === 0) {
                            $config = $this->getWorkbench()->getCoreApp()->getConfig()->getOption('METAMODEL.CONNECTOR_CONFIG')->toArray();
                            $connector = $this->getWorkbench()->getCoreApp()->getConfig()->getOption('METAMODEL.CONNECTOR');
                        } else {
                            $row = $dataSheet->getRowsDecrypted()[0] ?? null;
                            if ($row === null) {
                                throw new UnexpectedValueException('Data connection "' . $selector . '" not found!');
                            }

                            if ($row['CONFIG'] ?? null) {
                                $config = JsonDataType::decodeJson($row['CONFIG']);
                            } else {
                                $config = [];
                            }
                            $connector = $row['CONNECTOR'];
                        }

                        $_POST['auth'] = $this->getAdminerAuth($config, $connector);
                        break;
                }

                // open Adminer
                $html = $this->launchAdminer();
                // IDEA replacing X-Frme-Options did not work well. Sometimes the headers are sent earlier.
                // How to prevent sending headers??? Override header() function somehow?
                // remove Adminer denial of integration into iBrowser
                // header_remove('X-Frame-Options');
                $headers = headers_list();
                $headers = array_merge($headers, $this->getHeadersCommon());
                return new Response(200, $headers, $html);
        }
    }

    /**
     * Streams a file from disk with a suitable Content-Type header.
     *
     * @param string $absPath
     * @return ResponseInterface
     */
    protected function serveFile(string $absPath) : ResponseInterface
    {
        $stream = fopen($absPath, 'r');
        switch (FilePathDataType::findExtension($absPath)) {
            case 'css':
                $mimeType = 'text/css';
                break;
            case 'js':
                $mimeType = 'text/javascript';
                break;
            default:
                $mimeType = mime_content_type($absPath);
                break;
        }
        $headers = $this->getHeadersCommon();
        $headers['Content-Type'] = $mimeType;
        return new Response(200, $headers, $stream);
    }

    /**
     * Absolute path to the `adminer/` folder of the axenox/adminer 6.x fork.
     *
     * @return string
     */
    protected function getForkAdminerPath() : string
    {
        return $this->getWorkbench()->filemanager()->getPathToVendorFolder()
            . DIRECTORY_SEPARATOR . 'axenox'
            . DIRECTORY_SEPARATOR . 'adminer'
            . DIRECTORY_SEPARATOR . 'adminer'
            . DIRECTORY_SEPARATOR;
    }

    /**
     * Absolute path to the folder with ExFace-specific Adminer assets (theme CSS, JS).
     *
     * @return string
     */
    protected function getCustomAssetsPath() : string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Adminer6' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR;
    }

    /**
     * Absolute path to the wrapper that boots the fork with the ExFace plugin stack.
     *
     * @return string
     */
    protected function getWrapperPath() : string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Adminer6' . DIRECTORY_SEPARATOR . 'adminer.php';
    }

    /**
     *
     * @return string|NULL
     */
    protected function launchAdminer() : ?string
    {
        ob_start();
        session_start();
        $this->injectLoginCsrfToken();
        $cwd = getcwd();
        chdir($this->getForkAdminerPath());
        require $this->getWrapperPath();
        $output = ob_get_contents();
        ob_end_clean();
        chdir($cwd);
        return $output;
    }

    /**
     * Provides a valid CSRF token for the login that the API injects server-side.
     *
     * Adminer 6.x gates the login (`$_POST['auth']`) with `verify_token()`, which compares a
     * token from the request body (`$_POST['token']`) against `$_SESSION['token']` and requires
     * a same-origin request. Since Adminer shares the session with the workbench, we can set
     * both values ourselves and thus keep Adminer's CSRF protection intact instead of disabling
     * it in the core.
     *
     * @return void
     */
    protected function injectLoginCsrfToken() : void
    {
        // Only relevant when we perform a server-side auto-login
        if (! isset($_POST['auth'])) {
            return;
        }
        if (empty($_SESSION['token'])) {
            $_SESSION['token'] = rand(1, 1e6);
        }
        // Mirror Adminer\get_token(): token = (rand XOR session token) . ":" . rand
        $rand = rand(1, 1e6);
        $_POST['token'] = (($rand ^ $_SESSION['token']) . ':' . $rand);
        // verify_token() also requires a same-origin request. The login is issued by the
        // workbench itself, so present it as same-origin if the header would fail the check.
        $secFetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
        if (! in_array($secFetchSite, ['', 'same-origin'], true)) {
            $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
        }
    }

    /**
     * Returns the DDL (CREATE script) for a given table or view
     *
     * Returns a comment with an error if the table or view was not found
     *
     * @param SqlDataConnectorInterface $connection
     * @param string $tableOrViewName
     * @param string|null $schema
     * @param string|null $style - e.g. `DROP+CREATE` or `CREATE`
     * @return string
     */
    public function exportDDL(SqlDataConnectorInterface $connection, string $tableOrViewName, ?string $schema = null, ?string $style = 'CREATE') : string
    {
        $facadePath = $this->getApiUrlPath($connection, null, $schema);
        if (\Adminer\connection() === null) {
            $this->runAdminer($facadePath);
        }

        $driver = \Adminer\driver();
        $tableStatus = $driver->table_status($tableOrViewName);
        if ($driver->is_view($tableStatus)) {
            $viewStatus = $driver->view($tableOrViewName);
            $dump = "CREATE VIEW $tableOrViewName AS \n" . $viewStatus["select"];
        } else {
            $dump = $driver->create_sql($tableOrViewName, false, $style);
            if (empty($dump)) {
                $dump = '-- ERROR: table "' . $tableOrViewName . '" not found in schema/tablespace "' . $schema . '"';
            }
        }
        return $dump;
    }

    public function runSql(SqlDataConnectorInterface $connector, string $sql) : array
    {
        $facadePath = $this->getApiUrlPath($connector);
        if (\Adminer\connection() === null) {
            // TODO only run adminer if it was not run yet during the current HTTP request.
            $this->runAdminer($facadePath);
        }

        return \Adminer\get_rows($sql);
    }

    protected function getApiUrlPath(SqlDataConnectorInterface $connection, ?string $function = null, ?string $schema = null) : string
    {
        // adminer/suedlink_tpcde_db_azure_dev?mssql=kmtssqlsrvdev.database.windows.net&username=kmtsadmin&db=SuedLinkKmtsDev&ns=dbo&dump=
        $adminerAuth = $this->getAdminerAuth($connection->exportUxonObject()->toArray(), get_class($connection));
        if (! $this->isAuthenticated($adminerAuth)) {
            // $this->authenticate($adminerAuth);
        }
        $url = '/adminer/' . $connection->getAliasWithNamespace();
        $_GET[$adminerAuth['driver']] = $adminerAuth['server'];
        $_GET['username'] = $adminerAuth['username'];
        $_GET['db'] = $adminerAuth['db'];
        $_GET['ns'] = $schema ?? '';
        if ($function !== null) {
            $_GET[$function] = '';
        }
        return $url;
    }

    protected function isAuthenticated(array $adminerAuth) : bool
    {
        $server = ($_SESSION['db'] ?? [])['server'];
        if ($server === null) {
            return false;
        }
        $users = $server[$adminerAuth['server']];
        if (! is_array($users)) {
            return false;
        }
        if (! isset($users[$adminerAuth['username']])) {
            return false;
        }
        return true;
    }

    protected function authenticate(array $adminerAuth)
    {
        $getVars = $_GET;
        $postVars = $_POST;
        $_GET = [];
        $_POST['auth'] = $adminerAuth;

        $this->launchAdminer();

        $_GET = $getVars;
        $_POST = $postVars;

    }

    /**
     * Returns the Adminer CSRF token stored in the session
     *
     * Adminer uses CSRF tokens for every request. It seems, though, they are not required to call adminer functions
     * without processing an entire request. Should the CSRF token be required in future, you can find its logic in
     * `functions.inc.php` in `get_token()` and `verify_token()`.
     *
     * @return string
     */
    private function getApiToken() : string
    {
        return $_SESSION['token'];
    }
}