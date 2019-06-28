# Exception handling class
Always wanted to convert php errors to exceptions?
Or catch "uncaught exceptions" so you can log them with a logger?

Don't look any further and include this class now! :grin:

#### Example
```php
<?php

// Init ExceptionHandler class:
\Devorto\ExceptionHandler::init();

// Add a logger.
\Devorto\ExceptionHandler::addLogger(new AnyLoggerImplementingLoggerInterface());

// This class removes the need of using `@` before php standard methods because we can now catch and continue with our code but still log that this happened.
try {
	mkdir('/existing-path-which-results-in-a-notice');
} catch (ErrorException $exception) {
	// Log "caught" exception.
	\Devorto\ExceptionHandler::log($exception);
}

/**
 * This will result in a HTTP 500 Error Page.
 * This will however be logged using the exception handler and provided loggers.
 */
throw new Exception('It broke!');
```
