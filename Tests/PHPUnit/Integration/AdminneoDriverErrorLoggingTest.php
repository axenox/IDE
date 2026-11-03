<?php
namespace axenox\IDE\Tests\PHPUnit\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Verifies real AdminNeo bootstrap, authentication and crash logging without a database server.
 */
class AdminneoDriverErrorLoggingTest extends TestCase
{
    /**
     * Native warnings, not just trigger_error diagnostics, must pass through the fork's filter.
     *
     * @return void
     */
    public function testNativeDriverWarningIsLogged() : void
    {
        $process = $this->runAdminneo('native-warning');
        $records = $this->getRecords($process);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]['level']);
        $this->assertSame(E_WARNING, $records[0]['severity']);
        $this->assertStringContainsString('fopen(', $records[0]['message']);
        $this->assertStringContainsString('PAGE:settings', $process->getOutput());
        $this->assertStringContainsString('DIAGNOSTICS:clean', $process->getOutput());
    }

    /**
     * A nonfatal driver warning is logged with debug details while the settings page still renders.
     *
     * @return void
     */
    public function testUnexpectedDriverWarningIsLogged() : void
    {
        $process = $this->runAdminneo('warning');
        $records = $this->getRecords($process);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]['level']);
        $this->assertSame('AdminNeo: Unexpected test driver warning', $records[0]['message']);
        $this->assertSame(E_USER_WARNING, $records[0]['severity']);
        $this->assertNotEmpty($records[0]['id']);
        $this->assertStringContainsString('FakeAdminneoConnection.php', $records[0]['file']);
        $this->assertGreaterThan(0, $records[0]['line']);
        $this->assertStringContainsString('PAGE:settings', $process->getOutput());
        $this->assertStringContainsString('DIAGNOSTICS:clean', $process->getOutput());
    }

    /**
     * Normal authentication and page rendering must not generate error records.
     *
     * @return void
     */
    public function testSuccessfulPageDoesNotLogErrors() : void
    {
        $process = $this->runAdminneo('none');
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame([], $this->getRecords($process));
        $this->assertStringContainsString('PAGE:settings', $process->getOutput());
    }

    /**
     * AdminNeo's intentional missing-key suppression must survive the host bridge.
     *
     * @return void
     */
    public function testMissingArrayKeysRemainSilent() : void
    {
        $process = $this->runAdminneo('missing-key');
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame([], $this->getRecords($process));
        $this->assertStringContainsString('PAGE:settings', $process->getOutput());
    }

    /**
     * A driver's explicitly suppressed diagnostics must not leak into logs or HTML.
     *
     * @return void
     */
    public function testSuppressedDriverWarningsRemainSilent() : void
    {
        $process = $this->runAdminneo('suppressed');
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame([], $this->getRecords($process));
        $this->assertStringContainsString('PAGE:settings', $process->getOutput());
    }

    /**
     * Terminating user errors must propagate once rather than continue rendering.
     *
     * @return void
     */
    public function testUserFatalReachesCallerOnce() : void
    {
        $process = $this->runAdminneo('user-fatal');
        $records = $this->getRecords($process);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertCount(1, $records);
        $this->assertSame('critical', $records[0]['level']);
        $this->assertSame(E_USER_ERROR, $records[0]['severity']);
        $this->assertStringContainsString('PAGE:absent', $process->getOutput());
    }

    /**
     * PHP TypeError must reach the caller's workbench logger without duplicate shutdown records.
     *
     * @return void
     */
    public function testTypeErrorReachesCallerOnce() : void
    {
        $process = $this->runAdminneo('type-error');
        $records = $this->getRecords($process);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertCount(1, $records);
        $this->assertSame('critical', $records[0]['level']);
        $this->assertStringContainsString('strlen()', $records[0]['message']);
        $this->assertStringContainsString('PAGE:absent', $process->getOutput());
    }

    /**
     * Core's shutdown logger must catch native compile fatals with display_errors disabled.
     *
     * @return void
     */
    public function testNativeFatalIsLoggedOnce() : void
    {
        $process = $this->runAdminneo('native-fatal');
        $records = $this->getRecords($process);
        $this->assertFalse($process->isSuccessful());
        $this->assertCount(1, $records, $process->getErrorOutput());
        $this->assertSame('critical', $records[0]['level']);
        $this->assertSame(E_COMPILE_ERROR, $records[0]['severity']);
        $this->assertStringContainsString('must be compatible', $records[0]['message']);
        $this->assertStringContainsString('FatalAdminneoConnection.php', $records[0]['file']);
        $this->assertGreaterThan(0, $records[0]['line']);
    }

    /**
     * Logging must persist even when a streaming path exits without unwinding the launcher.
     *
     * @return void
     */
    public function testWarningIsLoggedBeforeEarlyExit() : void
    {
        $process = $this->runAdminneo('stream-warning');
        $records = $this->getRecords($process);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertCount(1, $records);
        $this->assertSame('error', $records[0]['level']);
        $this->assertSame(E_USER_WARNING, $records[0]['severity']);
        $this->assertStringNotContainsString('PAGE:settings', $process->getOutput());
    }

    /**
     * Runs the isolated fixture using the real fork and the current PHP executable.
     *
     * @param string $mode
     * @return Process
     */
    private function runAdminneo(string $mode) : Process
    {
        $process = new Process([PHP_BINARY, dirname(__DIR__) . '/Support/run-adminneo.php', $mode]);
        $process->run();
        return $process;
    }

    /**
     * Extracts structured workbench log records without treating captured HTML as evidence.
     *
     * @param Process $process
     * @return array[]
     */
    private function getRecords(Process $process) : array
    {
        preg_match_all('/^LOG:(.+)$/m', $process->getOutput(), $matches);
        return array_map(function (string $record) : array {
            return json_decode($record, true, 512, JSON_THROW_ON_ERROR);
        }, $matches[1]);
    }
}
