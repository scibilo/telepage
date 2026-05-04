<?php

/**
 * TELEPAGE — api/admin/helpers.php
 *
 * Shared helpers for admin API modules. Included by api/admin.php
 * (the dispatcher) before requiring any module file, so every action
 * function has access to jsonOk, jsonError, requirePost, etc.
 *
 * clientIp() is intentionally NOT redefined here — it lives in
 * app/Http.php and is loaded by Composer's 'files' autoload on every
 * request. The version in the old monolith was identical; keeping one
 * copy prevents divergence.
 */

declare(strict_types=1);

// -----------------------------------------------------------------------
// Rate limiting (admin-specific — wider window + configurable maxHits)
// -----------------------------------------------------------------------

function checkRateLimit(string $ip, string $endpoint, int $maxHits, int $windowSeconds): bool
{
    try {
        $rec = DB::fetchOne(
            'SELECT hit_count, window_start FROM rate_limits WHERE ip=:ip AND endpoint=:ep',
            [':ip' => $ip, ':ep' => $endpoint]
        );

        $now = time();

        if ($rec) {
            $age = $now - (int) $rec['window_start'];
            if ($age > $windowSeconds) {
                DB::query(
                    'UPDATE rate_limits SET hit_count=1, window_start=:now WHERE ip=:ip AND endpoint=:ep',
                    [':now' => $now, ':ip' => $ip, ':ep' => $endpoint]
                );
                return true;
            }
            if ((int) $rec['hit_count'] >= $maxHits) {
                return false;
            }
            DB::query(
                'UPDATE rate_limits SET hit_count=hit_count+1 WHERE ip=:ip AND endpoint=:ep',
                [':ip' => $ip, ':ep' => $endpoint]
            );
        } else {
            DB::query(
                'INSERT INTO rate_limits (ip, endpoint, hit_count, window_start) VALUES (:ip,:ep,1,:now)',
                [':ip' => $ip, ':ep' => $endpoint, ':now' => $now]
            );
        }

        return true;
    } catch (Throwable) {
        return true; // On DB error, do not block
    }
}

// -----------------------------------------------------------------------
// Input / Output helpers
// -----------------------------------------------------------------------

function requirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonError(405, 'Method Not Allowed');
    }
}

/** Reads the JSON or form-encoded body. */
function getJsonBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        return json_decode($raw ?: '{}', true) ?? [];
    }
    return $_POST;
}

/** Reads a parameter from JSON body or POST. */
function bodyParam(string $key): mixed
{
    static $body = null;
    if ($body === null) {
        $body = getJsonBody();
    }
    return $body[$key] ?? $_POST[$key] ?? null;
}

function jsonOk(array $data): never
{
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(int $code, string $message): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function detectBaseUrl(): string
{
    $is_https = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                 ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ||
                 ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on' ||
                 ($_SERVER['HTTP_X_FORWARDED_PORT'] ?? '') === '443');

    $scheme = $is_https ? 'https' : 'http';

    $serverName = $_SERVER['SERVER_NAME'] ?? '';
    $httpHost   = $_SERVER['HTTP_HOST']   ?? '';
    if ($serverName !== '' && $serverName !== '_' && $serverName !== 'default') {
        $host = Str::safeHost($serverName, 'localhost');
    } else {
        $host = Str::safeHost($httpHost, 'localhost');
    }

    $docRoot  = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    $fileDir  = rtrim(dirname(__FILE__), '/');
    $appRoot  = rtrim(dirname(dirname($fileDir)), '/'); // api/admin/ → root

    if (!empty($docRoot) && str_starts_with($appRoot, $docRoot)) {
        $webPath = substr($appRoot, strlen($docRoot));
    } else {
        $script  = $_SERVER['SCRIPT_NAME'] ?? '';
        $webPath = rtrim(dirname(dirname($script)), '/');
    }

    $base = $scheme . '://' . $host . $webPath;
    return str_replace('http://', 'https://', $base);
}
