<?php

/**
 * TELEPAGE — Logger.php
 * Structured logs on SQLite `logs` table.
 *
 * Categories: webhook|import|ai|scraper|admin
 * Levels: info|warning|error
 *
 * Every admin operation is logged with IP and timestamp.
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/DB.php';

class Logger
{
    // Log levels
    const INFO    = 'info';
    const WARNING = 'warning';
    const ERROR   = 'error';

    // Categories
    const WEBHOOK = 'webhook';
    const IMPORT  = 'import';
    const AI      = 'ai';
    const SCRAPER = 'scraper';
    const ADMIN   = 'admin';
    const SYSTEM  = 'system';

    /**
     * Writes a log record to the `logs` table.
     * If the DB write fails, falls back to PHP error_log (does not block the flow).
     *
     * @param string $level    info|warning|error
     * @param string $category webhook|import|ai|scraper|admin|system
     * @param string $message  Messaggio leggibile
     * @param array  $context  Dati aggiuntivi (serializzati come JSON)
     */
    public static function log(
        string $level,
        string $category,
        string $message,
        array  $context = []
    ): void {
        // Always add IP and timestamp to context
        $context['_ip']  = self::clientIp();
        $context['_ts']  = date('Y-m-d H:i:s');

        $contextJson = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            DB::query(
                'INSERT INTO logs (level, category, message, context, created_at)
                 VALUES (:level, :category, :message, :context, CURRENT_TIMESTAMP)',
                [
                    ':level'    => $level,
                    ':category' => $category,
                    ':message'  => $message,
                    ':context'  => $contextJson,
                ]
            );
        } catch (Throwable $e) {
            // Fallback: PHP error_log — never blocks the main flow
            error_log(sprintf(
                '[TELEPAGE][%s][%s] %s | context=%s | db_error=%s',
                strtoupper($level),
                $category,
                $message,
                $contextJson,
                $e->getMessage()
            ));
        }
    }

    // -----------------------------------------------------------------------
    // Level shorthands
    // -----------------------------------------------------------------------

    public static function info(string $category, string $message, array $context = []): void
    {
        self::log(self::INFO, $category, $message, $context);
    }

    public static function warning(string $category, string $message, array $context = []): void
    {
        self::log(self::WARNING, $category, $message, $context);
    }

    public static function error(string $category, string $message, array $context = []): void
    {
        self::log(self::ERROR, $category, $message, $context);
    }

    // -----------------------------------------------------------------------
    // Category shorthands (used by the respective modules)
    // -----------------------------------------------------------------------

    public static function webhook(string $level, string $message, array $context = []): void
    {
        self::log($level, self::WEBHOOK, $message, $context);
    }

    public static function import(string $level, string $message, array $context = []): void
    {
        self::log($level, self::IMPORT, $message, $context);
    }

    public static function ai(string $level, string $message, array $context = []): void
    {
        self::log($level, self::AI, $message, $context);
    }

    public static function scraper(string $level, string $message, array $context = []): void
    {
        self::log($level, self::SCRAPER, $message, $context);
    }

    public static function scanner(string $level, string $message, array $context = []): void
    {
        self::log($level, "scanner", $message, $context);
    }

    public static function admin(string $level, string $message, array $context = []): void
    {
        self::log($level, self::ADMIN, $message, $context);
    }

    public static function system(string $level, string $message, array $context = []): void
    {
        self::log($level, self::SYSTEM, $message, $context);
    }

    // -----------------------------------------------------------------------
    // Log reading (used by api/admin.php?action=logs)
    // -----------------------------------------------------------------------

    /**
     * Retrieves filtered logs with pagination.
     *
     * @param string|null $level     Filtro livello (null = tutti)
     * @param string|null $category  Filtro categoria (null = tutte)
     * @param int         $page      Pagina (1-based)
     * @param int         $perPage   Record per pagina
     * @return array{data: array, total: int, pages: int}
     */
    public static function fetch(
        ?string $level    = null,
        ?string $category = null,
        int     $page     = 1,
        int     $perPage  = 50
    ): array {
        $where  = [];
        $params = [];

        if ($level !== null) {
            $where[]          = 'level = :level';
            $params[':level'] = $level;
        }

        if ($category !== null) {
            $where[]             = 'category = :category';
            $params[':category'] = $category;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $offset   = ($page - 1) * $perPage;

        $total = (int) DB::fetchScalar(
            "SELECT COUNT(*) FROM logs {$whereSql}",
            $params
        );

        $data = DB::fetchAll(
            "SELECT id, level, category, message, context, created_at
               FROM logs
             {$whereSql}
             ORDER BY created_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($params, [':limit' => $perPage, ':offset' => $offset])
        );

        // Parse context JSON for each row
        foreach ($data as &$row) {
            $row['context'] = json_decode($row['context'] ?? '{}', true) ?? [];
        }
        unset($row);

        return [
            'data'  => $data,
            'total' => $total,
            'pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Deletes logs older than N days (periodic cleanup).
     *
     * @param int $days
     * @return int Rows deleted
     */
    public static function cleanup(int $days = 30): int
    {
        DB::query(
            "DELETE FROM logs WHERE created_at < datetime('now', '-{$days} days')"
        );
        return (int) DB::fetchScalar('SELECT changes()');
    }

    // -----------------------------------------------------------------------
    // Internal utilities
    // -----------------------------------------------------------------------

    /**
     * Rileva IP del client, gestendo proxy con X-Forwarded-For.
     */
    private static function clientIp(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',    // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ($headers as $header) {
            $val = $_SERVER[$header] ?? '';
            if ($val !== '') {
                // X-Forwarded-For may contain a list of IPs: take the first
                $ip = trim(explode(',', $val)[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return 'unknown';
    }
}
