<?php
namespace axenox\IDE\Common;

use exface\Core\Exceptions\InternalError;
use exface\Core\Interfaces\Log\LoggerInterface;

/**
 * Routes reported AdminNeo PHP errors to the workbench without exposing them in streamed output.
 * 
 * Native fatal errors remain the responsibility of Core's logger shutdown handler. Throwables
 * propagate to the facade or calling action, which logs them and builds the normal error response.
 */
class AdminneoErrorHandler
{
    private LoggerInterface $logger;

    private $previousHandler;

    private int $previousReporting;

    /**
     * Installs the bridge before AdminNeo's bootstrap replaces the PHP error handler.
     *
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->previousReporting = error_reporting();
        $this->previousHandler = set_error_handler([$this, 'handleError']);
    }

    /**
     * Logs nonfatal diagnostics and throws terminating user errors for the facade to handle.
     *
     * @param int $severity
     * @param string $message
     * @param string $file
     * @param int $line
     * @return bool
     */
    public function handleError(int $severity, string $message, string $file, int $line) : bool
    {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }

        $error = new InternalError(
            'AdminNeo: ' . $message,
            null,
            new \ErrorException($message, 0, $severity, $file, $line)
        );
        if (($severity & (E_USER_ERROR | E_RECOVERABLE_ERROR)) !== 0) {
            throw $error;
        }

        $level = LoggerInterface::ERROR;
        if (($severity & (E_NOTICE | E_USER_NOTICE | E_STRICT)) !== 0) {
            $level = LoggerInterface::WARNING;
        } elseif (($severity & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0) {
            $level = LoggerInterface::NOTICE;
        }
        $this->logger->logException($error, $level);
        return true;
    }

    /**
     * Removes AdminNeo's handlers and this bridge, restoring the host's handler and reporting mask.
     *
     * @return void
     */
    public function restore() : void
    {
        $handler = [$this, 'handleError'];
        do {
            // Probe without changing the stack, then unwind handlers installed by AdminNeo.
            $current = set_error_handler($handler);
            restore_error_handler();
            if ($current === $this->previousHandler) {
                break;
            }
            restore_error_handler();
        } while ($current !== $handler && $current !== null);
        error_reporting($this->previousReporting);
    }
}
