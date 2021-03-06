<?php

namespace Devorto;

use ErrorException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Class ExceptionHandler
 *
 * @package Devorto
 */
class ExceptionHandler
{
    /**
     * @var LoggerInterface[]
     */
    protected static $loggers = [];

    /**
     * @var bool
     */
    protected static $displayErrors;

    /**
     * @var bool
     */
    protected static $notifyCustomLoggers;

    /**
     * @var bool
     */
    protected static $notifyErrorLog;

    /**
     * Initializes the ExceptionHandler and sets default values for error handling.
     *
     * @param bool $displayErrors When set to false, errors won't be written to screen.
     * @param bool $notifyCustomLoggers When set to false, custom loggers will not be notified.
     * @param bool $notifyErrorLog When set to false, php's error_log() will not be notified.
     */
    public static function init(
        bool $displayErrors = false,
        bool $notifyCustomLoggers = false,
        bool $notifyErrorLog = true
    ): void {
        static::$displayErrors = $displayErrors;
        static::$notifyCustomLoggers = $notifyCustomLoggers;
        static::$notifyErrorLog = $notifyErrorLog;

        // Force error reporting to always be on. But hide it for the user.
        error_reporting(E_ALL);
        ini_set('display_errors', 0);
        ini_set('display_startup_errors', 0);

        // Register exception handler with PHP.
        set_error_handler([static::class, 'phpErrorToExceptionHandler'], E_ALL);
        set_exception_handler([static::class, 'phpExceptionHandler']);
        register_shutdown_function([static::class, 'phpShutdownHandler']);
    }

    /**
     * Adds a logger to the ExceptionHandler to which a notification will be send when something goes wrong.
     *
     * @param LoggerInterface $logger
     */
    public static function addLogger(LoggerInterface $logger): void
    {
        static::$loggers[] = $logger;
    }

    /**
     * Logs an error to php error log and all other given loggers.
     * Should be used for caught exceptions to still inform us about them.
     * Note: also used by phpExceptionHandler to prevent duplicate code, see $isEmergency parameter.
     *
     * @param Throwable $throwable
     * @param bool $isEmergency Used only for uncaught exceptions.
     */
    public static function log(Throwable $throwable, bool $isEmergency = false): void
    {
        if (static::$notifyErrorLog) {
            // Write error to standard php log, this works because of the magic __toString() function.
            error_log($throwable);
        }

        // Trigger custom loggers? For example when in test environment we don't need to spam (production) logs.
        if (!static::$notifyCustomLoggers) {
            return;
        }

        // Add some extra info if it was a web request.
        $message = '';
        if (PHP_SAPI !== 'cli') {
            $message .= sprintf(
                'Method: %s, %s%sURL: %s://%s%s%sUser-Agent: %s%s',
                filter_input(INPUT_SERVER, 'SERVER_PROTOCOL', FILTER_DEFAULT),
                filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_DEFAULT),
                PHP_EOL,
                filter_input(INPUT_SERVER, 'REQUEST_SCHEME', FILTER_DEFAULT),
                filter_input(INPUT_SERVER, 'HTTP_HOST', FILTER_DEFAULT),
                filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_DEFAULT),
                PHP_EOL,
                filter_input(INPUT_SERVER, 'HTTP_USER_AGENT', FILTER_DEFAULT),
                PHP_EOL . PHP_EOL
            );
        }
        $message .= $throwable;

        foreach (static::$loggers as $logger) {
            // To prevent infinite loops, when for some reason an Exception is being throw in the logger.
            try {
                if ($isEmergency) {
                    $logger->emergency($message);
                } else {
                    $logger->error($message);
                }
            } catch (Throwable $throwable) {
                // This is the only place we can notify our system that a logger died.
                error_log($throwable);
            }
        }
    }

    /**
     * Converts php notices/warnings/errors to ErrorException and throws this.
     *
     * @param int $severity
     * @param string $message
     * @param string $file
     * @param int $line
     *
     * @throws ErrorException
     */
    public static function phpErrorToExceptionHandler(int $severity, string $message, string $file, int $line): void
    {
        // This error code is not included in error_reporting
        if (!(error_reporting() & $severity)) {
            return;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Handle uncaught exception logic here.
     *
     * @param Throwable $throwable
     *
     * @throws Throwable
     */
    public static function phpExceptionHandler(Throwable $throwable): void
    {
        // Send $throwable to logs.
        static::log($throwable, true);

        // Dump on screen when running in a test environment.
        if (static::$displayErrors) {
            if (PHP_SAPI === 'cli') {
                echo $throwable . PHP_EOL;
            } else {
                http_response_code(500);
                echo '<pre>' . htmlspecialchars($throwable) . '</pre>';
            }

            return;
        }

        // Triggers web server default 500 error page.
        throw $throwable;
    }

    /**
     * When php shuts down, check if this is caused by a(n) (fatal) error.
     * If so convert and catch it to have it processed like the rest of the exceptions.
     *
     * @throws Throwable
     */
    public static function phpShutdownHandler(): void
    {
        $error = error_get_last();
        if (!empty($error) && is_array($error)) {
            try {
                self::phpErrorToExceptionHandler($error['type'], $error['message'], $error['file'], $error['line']);
            } catch (Throwable $throwable) {
                self::phpExceptionHandler($throwable);
            }
        }
    }
}
