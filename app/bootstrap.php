<?php
/**
 * TELEPAGE — app/bootstrap.php
 *
 * Centralised error and exception handling for all entry points
 * (api/*.php, index.php, admin/*.php).
 *
 * Goals:
 *   1. Never display a full filesystem path, SQL error, stack trace
 *      or internal exception message to the browser. Those leak
 *      information to attackers (the OS username, the project path,
 *      the database schema, the ORM in use, etc.).
 *   2. ALWAYS log the full detail server-side, via Logger, so the
 *      admin retains full debuggability.
 *   3. Return a response shape that matches the entry point: JSON
 *      for API endpoints, short HTML for HTML pages.
 *
 * Behaviour per environment:
 *   - CLI (PHP_SAPI === 'cli')        → display on stderr, full detail
 *   - HTTP + TELEPAGE_DEBUG=1 in env  → short detail in response
 *                                       (file/line, no path), full
 *                                       detail in log
 *   - HTTP default                    → generic message in response,
 *                                       full detail in log
 *
 * Usage:
 *   require_once __DIR__ . '/bootstrap.php';
 *   Bootstrap::init(Bootstrap::MODE_JSON);   // api/*
 *   // or:
 *   Bootstrap::init(Bootstrap::MODE_HTML);   // admin/*, index.php
 */

declare(strict_types=1);

require_once __DIR__ . '/Logger.php';

class Bootstrap
{
    public const MODE_JSON = 'json';
    public const MODE_HTML = 'html';

    private static bool  $initialised = false;
    private static string $mode       = self::MODE_HTML;

    /**
     * Configures error reporting, installs handlers, and locks
     * display_errors to off. Safe to call more than once; subsequent
     * calls only update the mode.
     */
    public static function init(string $mode = self::MODE_HTML): void
    {
        self::$mode = $mode;

        if (self::$initialised) {
            return;
        }
        self::$initialised = true;

        // Report everything to the log, display nothing to the client.
        // CLI gets display_errors=1 so developers see warnings while
        // running scripts manually; HTTP always hides them.
        error_reporting(E_ALL);
        ini_set('display_errors',         PHP_SAPI === 'cli' ? '1' : '0');
        ini_set('display_startup_errors', PHP_SAPI === 'cli' ? '1' : '0');
        ini_set('log_errors', '1');

        set_error_handler([self::class, 'onError']);
        set_exception_handler([self::class, 'onException']);
        register_shutdown_function([self::class, 'onShutdown']);
    }

    /**
     * Promotes non-fatal PHP errors (warnings, notices, deprecations,
     * user errors) into ErrorException so they flow through the
     * exception handler and never leak raw to the browser.
     *
     * Respects error_reporting() (suppressed with @? we skip it).
     */
    public static function onError(int $severity, string $message, string $file, int $line): bool
    {
        if (!(error_reporting() & $severity)) {
            // Error suppressed with @-operator or silenced by config.
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Final catch-all for any Throwable that escapes the script.
     * Logs full detail server-side and emits a sanitised response.
     */
    public static function onException(Throwable $e): void
    {
        // Log full server-side detail — Logger writes to DB + error_log.
        Logger::admin(Logger::ERROR, 'Unhandled exception: ' . get_class($e) . ': ' . $e->getMessage(), [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        if (PHP_SAPI === 'cli') {
            // Developer running a script: show everything on stderr.
            fwrite(STDERR, (string) $e . "\n");
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(500);
        }

        $debug = self::isDebug();
        $body  = self::renderError($e, $debug);
        echo $body;
        exit(1);
    }

    /**
     * Shutdown guard: catches FATAL errors that bypass set_error_handler
     * (E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR). Without this
     * the client would see the default "Fatal error: ... in /full/path".
     */
    public static function onShutdown(): void
    {
        $err = error_get_last();
        if ($err === null) {
            return;
        }
        $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
        if (!($err['type'] & $fatal)) {
            return;
        }

        // Log it.
        Logger::admin(Logger::ERROR, 'Fatal error: ' . $err['message'], [
            'file' => $err['file'],
            'line' => $err['line'],
            'type' => $err['type'],
        ]);

        if (PHP_SAPI === 'cli') {
            // display_errors=1 in CLI already printed it; just exit.
            return;
        }

        // HTTP: emit the sanitised response. headers_sent() is usually
        // true here (fatal after output started), so we can only
        // append our payload.
        if (!headers_sent()) {
            http_response_code(500);
        }
        $debug = self::isDebug();
        // Build a fake Throwable-like payload since we can't rethrow
        // from a fatal.
        echo self::renderErrorFromArray($err, $debug);
    }

    // -------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------

    private static function isDebug(): bool
    {
        return !empty(getenv('TELEPAGE_DEBUG'));
    }

    private static function renderError(Throwable $e, bool $debug): string
    {
        $generic = 'Server error. See admin log for details.';
        $detail  = $debug
            ? get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
            : null;

        if (self::$mode === self::MODE_JSON) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            $payload = ['ok' => false, 'error' => $detail ?? $generic];
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // HTML mode — minimal, no template, no CSS dependency.
        $safe = htmlspecialchars($detail ?? $generic, ENT_QUOTES, 'UTF-8');
        return "<!doctype html><meta charset=\"utf-8\"><title>Error</title>"
             . "<div style=\"font-family:system-ui,sans-serif;max-width:640px;margin:4em auto;padding:2em;\">"
             . "<h1 style=\"font-size:1.2em;\">Server error</h1>"
             . "<p>{$safe}</p>"
             . "</div>";
    }

    private static function renderErrorFromArray(array $err, bool $debug): string
    {
        $generic = 'Server error. See admin log for details.';
        $detail  = $debug
            ? 'Fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']
            : null;

        if (self::$mode === self::MODE_JSON) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            return json_encode(
                ['ok' => false, 'error' => $detail ?? $generic],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        $safe = htmlspecialchars($detail ?? $generic, ENT_QUOTES, 'UTF-8');
        return "<!doctype html><meta charset=\"utf-8\"><title>Error</title>"
             . "<div style=\"font-family:system-ui,sans-serif;max-width:640px;margin:4em auto;padding:2em;\">"
             . "<h1 style=\"font-size:1.2em;\">Server error</h1>"
             . "<p>{$safe}</p>"
             . "</div>";
    }
}
