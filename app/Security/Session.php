<?php

/**
 * TELEPAGE — app/Security/Session.php
 *
 * Single entry point for configuring and starting a PHP session. All
 * callers that need $_SESSION should go through Session::start() instead
 * of calling session_name()/session_start() directly. The helper:
 *
 *   - Derives a per-installation cookie name (tp_<hash>) so multiple
 *     Telepage installs on the same domain can't share sessions.
 *   - Sets the Secure flag based on the current request's scheme — if
 *     the request arrived over HTTPS, the browser will never send this
 *     cookie back over plain HTTP (defeats cookie theft via mixed
 *     content or an active MitM that downgrades the first request).
 *   - Sets HttpOnly so document.cookie from JS can't read the session
 *     ID (defence in depth against stored XSS).
 *   - Sets SameSite=Lax so the cookie is not sent on most cross-site
 *     sub-requests, blocking a large class of CSRF before CsrfGuard
 *     even runs.
 *
 * Must be called before any output is sent (session_start triggers
 * Set-Cookie headers).
 */

declare(strict_types=1);

class Session
{
    /**
     * Configure cookie params and open the session. Idempotent: calling
     * it twice is safe (PHP ignores session_start if already active).
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Cookie name derived from install path — two Telepage installs
        // under the same domain (e.g. /app1 and /app2) get distinct
        // session cookies and never overwrite each other.
        session_name('tp_' . substr(hash('sha256', TELEPAGE_ROOT), 0, 16));

        session_set_cookie_params([
            'lifetime' => 0,                   // session cookie, no persistence
            'path'     => '/',
            'domain'   => '',                  // host-only, no subdomains
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }

    /**
     * Detects HTTPS from either direct TLS or a trusted proxy forwarding
     * (X-Forwarded-Proto: https). The forwarded-proto check mirrors the
     * logic already in detectBaseUrl() / Bootstrap, so behaviour is
     * consistent across the codebase.
     */
    private static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') {
            return true;
        }
        if (($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '') === '443') {
            return true;
        }
        return false;
    }
}
