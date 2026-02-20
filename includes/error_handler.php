<?php
/**
 * SafeSpace Global Error Handler
 * Include once at the top of any page.
 * - Logs all errors to /logs/error.log
 * - Never exposes stack traces to the browser
 * - Returns clean JSON for API endpoints
 */

// Create logs directory if it doesn't exist
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0750, true);
}

// Configure PHP error reporting
ini_set('display_errors', '0');          // Never show raw errors in browser
ini_set('log_errors', '1');
ini_set('error_log', $logDir . '/error.log');
error_reporting(E_ALL);

// ─── Exception Handler ────────────────────────────────────────────────────────
set_exception_handler(function (Throwable $e) {
    $message = sprintf(
        "[%s] EXCEPTION %s: %s in %s:%d\nStack:\n%s\n",
        date('Y-m-d H:i:s'),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $e->getTraceAsString()
    );
    error_log($message);

    $isApi = isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json');

    if ($isApi) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => false,
            'message' => 'A server error occurred. Please try again later.'
        ]);
    } else {
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo '<!DOCTYPE html><html><head><title>Error — SafeSpace</title>
        <style>
            body{font-family:Inter,sans-serif;background:#0f172a;color:#e2e8f0;
                 display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
            .box{background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);
                 border-radius:16px;padding:2.5rem 3rem;max-width:480px;text-align:center}
            h1{color:#f87171;margin-bottom:.5rem}
            a{color:#818cf8;text-decoration:underline}
        </style></head>
        <body><div class="box">
            <h1>⚠️ Oops!</h1>
            <p>Something went wrong on our end. Our team has been notified.</p>
            <p><a href="dashboard.php">← Return to Dashboard</a></p>
        </div></body></html>';
    }
    exit;
});

// ─── Error Handler ─────────────────────────────────────────────────────────────
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    // Don't handle suppressed errors (@)
    if (!(error_reporting() & $errno)) {
        return false;
    }
    $levels = [
        E_ERROR   => 'ERROR', E_WARNING => 'WARNING', E_NOTICE => 'NOTICE',
        E_STRICT  => 'STRICT', E_DEPRECATED => 'DEPRECATED',
        E_USER_ERROR => 'USER_ERROR', E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE'
    ];
    $level = $levels[$errno] ?? 'UNKNOWN';
    error_log(sprintf(
        "[%s] PHP %s: %s in %s:%d\n",
        date('Y-m-d H:i:s'), $level, $errstr, $errfile, $errline
    ));

    // Fatal-level errors throw exceptions so the exception handler catches them
    if (in_array($errno, [E_ERROR, E_USER_ERROR])) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    return true; // Don't execute PHP's internal error handler
});

// ─── Shutdown Handler (catches fatal errors) ──────────────────────────────────
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log(sprintf(
            "[%s] FATAL %s: %s in %s:%d\n",
            date('Y-m-d H:i:s'), $error['type'], $error['message'], $error['file'], $error['line']
        ));
    }
});
