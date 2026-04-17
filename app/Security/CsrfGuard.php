<?php

/**
 * TELEPAGE — CsrfGuard.php
 * Cross-Site Request Forgery protection.
 *
 * Design:
 *  - One token per session, rotated on login (session_regenerate_id).
 *  - Expected location: $_SESSION['csrf_token'] (already populated by
 *    admin/_auth.php and admin/login.php).
 *  - Clients send the token via the HTTP header `X-CSRF-Token`.
 *  - Comparison uses hash_equals to avoid timing attacks.
 *
 * Usage (server):
 *    require_once TELEPAGE_ROOT . '/app/Security/CsrfGuard.php';
 *    CsrfGuard::verifyForWrite();   // dies with 403 on mismatch
 *
 * Usage (client):
 *    Read <meta name="csrf" content="..."> and send as X-CSRF-Token header
 *    on every non-GET request.
 *
 * This file is deliberately infrastructural only: including it does NOT
 * enforce anything. Enforcement happens at the call sites.
 */

declare(strict_types=1);

class CsrfGuard
{
    /**
     * HTTP methods that mutate state and therefore require CSRF verification.
     * GET and HEAD are considered safe by HTTP spec and are not checked.
     */
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Name of the HTTP header the client is expected to send.
     * Kept as a constant so tests and client code agree on it.
     */
    public const HEADER_NAME = 'X-CSRF-Token';

    /** Corresponding $_SERVER key for the header above. */
    private const SERVER_KEY = 'HTTP_X_CSRF_TOKEN';

    /**
     * Returns the session token, or an empty string if not set.
     * Does NOT generate a new token — generation happens at login time in
     * admin/_auth.php and admin/login.php. This keeps token lifecycle
     * centralised and avoids accidental rotations mid-request.
     */
    public static function token(): string
    {
        return (string) ($_SESSION['csrf_token'] ?? '');
    }

    /**
     * Verifies the CSRF token for the current request.
     *
     * - If the HTTP method is safe (GET/HEAD), returns true without checking.
     * - Otherwise compares the request header against the session token.
     *
     * Intentionally does NOT call session_start(): the caller is responsible
     * for starting the session before invoking this method (matches how
     * api/admin.php currently does it).
     *
     * @return bool true if the request is allowed to proceed
     */
    public static function isValid(): bool
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, self::WRITE_METHODS, true)) {
            return true;
        }

        $sessionToken = self::token();
        if ($sessionToken === '') {
            // No token in session means either no active login or a fresh
            // session before the token was minted — treat as invalid.
            return false;
        }

        $headerToken = (string) ($_SERVER[self::SERVER_KEY] ?? '');
        if ($headerToken === '') {
            return false;
        }

        return hash_equals($sessionToken, $headerToken);
    }

    /**
     * Strict version of isValid(): if the token is missing or wrong,
     * emits a 403 JSON response and terminates the request.
     *
     * Intended for use at the top of api/admin.php-style dispatchers,
     * right after the authentication check.
     *
     * Response format intentionally matches jsonError() in api/admin.php
     * so existing client code doesn't need special-casing.
     */
    public static function verifyForWrite(): void
    {
        if (self::isValid()) {
            return;
        }

        // Fresh response headers — the caller may or may not have set
        // Content-Type yet.
        if (!headers_sent()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
        }

        echo json_encode([
            'ok'    => false,
            'error' => 'CSRF token missing or invalid. Reload the admin page and retry.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
