<?php
namespace axenox\IDE\Tests\PHPUnit\Unit\Common;

use axenox\IDE\Common\AdminneoErrorHandler;
use exface\Core\CommonLogic\Log\Logger;
use exface\Core\Exceptions\InternalError;
use exface\Core\Interfaces\Log\LogHandlerInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the IDE error bridge without starting Workbench or accessing the metamodel.
 */
class AdminneoErrorHandlerTest extends TestCase
{
    /**
     * Unexpected warnings retain their location, stack and workbench debug-widget sender.
     *
     * @return void
     */
    public function testWarningIsLoggedWithDebugDetails() : void
    {
        $logger = new Logger();
        $sink = $this->createMock(LogHandlerInterface::class);
        $sink->expects($this->once())->method('handle')->with(
            LoggerInterface::ERROR,
            'AdminNeo: Unexpected driver warning',
            $this->callback(function (array $context) : bool {
                return ! empty($context['id']) && $context['exception'] instanceof InternalError;
            }),
            $this->callback(function (InternalError $error) : bool {
                $previous = $error->getPrevious();
                return $previous instanceof \ErrorException
                    && $previous->getSeverity() === E_WARNING
                    && $previous->getFile() === __FILE__
                    && $previous->getLine() === 42
                    && $previous->getTrace() !== [];
            })
        );
        $logger->appendHandler($sink);
        $reporting = error_reporting(E_ALL);
        $bridge = new AdminneoErrorHandler($logger);
        try {
            $this->assertTrue($bridge->handleError(E_WARNING, 'Unexpected driver warning', __FILE__, 42));
        } finally {
            $bridge->restore();
            error_reporting($reporting);
        }
    }

    /**
     * PHP's @ operator must continue to suppress deliberately ignored driver diagnostics.
     *
     * @return void
     */
    public function testSuppressedWarningIsNotLogged() : void
    {
        $logger = new Logger();
        $sink = $this->createMock(LogHandlerInterface::class);
        $sink->expects($this->never())->method('handle');
        $logger->appendHandler($sink);
        $bridge = new AdminneoErrorHandler($logger);
        try {
            @trigger_error('Ignored warning', E_USER_WARNING);
            $this->addToAssertionCount(1);
        } finally {
            $bridge->restore();
        }
    }

    /**
     * Errors excluded from error_reporting must not be sent to the logger.
     *
     * @return void
     */
    public function testReportingMaskIsRespected() : void
    {
        $logger = new Logger();
        $sink = $this->createMock(LogHandlerInterface::class);
        $sink->expects($this->never())->method('handle');
        $logger->appendHandler($sink);
        $bridge = new AdminneoErrorHandler($logger);
        try {
            error_reporting(E_ERROR);
            $this->assertFalse($bridge->handleError(E_WARNING, 'Ignored warning', __FILE__, __LINE__));
        } finally {
            $bridge->restore();
        }
    }

    /**
     * Terminating user errors propagate once instead of continuing execution or logging twice.
     *
     * @return void
     */
    public function testUserErrorPropagatesToTheFacade() : void
    {
        $logger = new Logger();
        $sink = $this->createMock(LogHandlerInterface::class);
        $sink->expects($this->never())->method('handle');
        $logger->appendHandler($sink);
        $reporting = error_reporting(E_ALL);
        $bridge = new AdminneoErrorHandler($logger);
        try {
            $this->expectException(InternalError::class);
            $this->expectExceptionMessage('AdminNeo: Broken plugin');
            trigger_error('Broken plugin', E_USER_ERROR);
        } finally {
            $bridge->restore();
            error_reporting($reporting);
        }
    }

    /**
     * Restoring must unwind AdminNeo's handler stack without losing the host handler's mask.
     *
     * @return void
     */
    public function testRestorePreservesHostHandlerAndReportingMask() : void
    {
        $hostCalls = [];
        $host = function (int $severity) use (&$hostCalls) : bool {
            $hostCalls[] = $severity;
            return true;
        };
        $reporting = error_reporting(E_ALL & ~E_DEPRECATED);
        set_error_handler($host, E_USER_NOTICE);
        try {
            $bridge = new AdminneoErrorHandler(new Logger());
            set_error_handler(function () : bool {
                return true;
            });
            error_reporting(E_ALL);
            $bridge->restore();
            $this->assertSame(E_ALL & ~E_DEPRECATED, error_reporting());
            trigger_error('Host notice', E_USER_NOTICE);
            $this->assertSame([E_USER_NOTICE], $hostCalls);
            $probe = set_error_handler($host);
            restore_error_handler();
            $this->assertSame($host, $probe);
        } finally {
            restore_error_handler();
            error_reporting($reporting);
        }
    }
}
