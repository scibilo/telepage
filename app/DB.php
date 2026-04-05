<?php

/**
 * TELEPAGE — DB.php
 * PDO Singleton for SQLite 3 with WAL mode.
 *
 * Specifications:
 *  - Single connection per request
 *  - PRAGMA journal_mode=WAL    → concurrent reads
 *  - PRAGMA foreign_keys=ON     → referential integrity
 *  - PRAGMA cache_size=-64000   → ~64 MB RAM cache
 *  - ATTR_ERRMODE → EXCEPTION   → errors as exceptions
 *  - ATTR_DEFAULT_FETCH_MODE → ASSOC
 *
 * Dependencies: Config.php (must be included first)
 */

// Assicura che Config sia disponibile
require_once __DIR__ . '/Config.php';

class DB
{
    /** Istanza singleton PDO */
    private static ?PDO $pdo = null;

    /**
     * Returns the PDO connection, creating it if necessary.
     *
     * @return PDO
     * @throws RuntimeException if the DB path is not configured or not writable
     */
    public static function get(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
        }

        return self::$pdo;
    }

    /**
     * Creates the PDO connection and configures SQLite.
     *
     * @return PDO
     * @throws RuntimeException
     */
    private static function connect(): PDO
    {
        $config  = Config::get();
        $dbPath  = $config['db_path'] ?? '';

        if (empty($dbPath)) {
            throw new RuntimeException('DB::connect() — db_path not configured in config.json');
        }

        // Verify the directory exists and is writable
        $dbDir = dirname($dbPath);

        if (!is_dir($dbDir)) {
            if (!mkdir($dbDir, 0750, true)) {
                throw new RuntimeException("DB::connect() — cannot create directory: {$dbDir}");
            }
            // Proteggi la directory con .htaccess se appena creata
            self::writeDataHtaccess($dbDir);
        }

        if (!is_writable($dbDir)) {
            throw new RuntimeException("DB::connect() — directory not writable: {$dbDir}");
        }

        try {
            $pdo = new PDO('sqlite:' . $dbPath);
        } catch (PDOException $e) {
            throw new RuntimeException('DB::connect() — PDO failed: ' . $e->getMessage());
        }

        // Error mode: exceptions
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Fetch mode default: array associativo
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Emulate prepares: false for SQLite (uses real prepared statements)
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        // Performance and integrity PRAGMAs
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA cache_size=-64000');

        // Lock timeout: 5 seconds before raising SQLITE_BUSY
        $pdo->exec('PRAGMA busy_timeout=5000');

        return $pdo;
    }

    // -----------------------------------------------------------------------
    // Schema utility methods
    // -----------------------------------------------------------------------

    /**
     * Creates all tables if they do not yet exist.
     * Called by the installation wizard (Step 2).
     *
     * @throws PDOException
     */
    public static function initSchema(): void
    {
        $pdo = self::get();

        $pdo->exec("
            -- -------------------------------------------------------
            -- Tabella principale contenuti
            -- -------------------------------------------------------
            CREATE TABLE IF NOT EXISTS contents (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                url                 TEXT,
                title               TEXT,
                description         TEXT,
                ai_summary          TEXT,
                image               TEXT,
                image_source        TEXT DEFAULT 'scraped',
                favicon             TEXT,
                content_type        TEXT DEFAULT 'link',
                source_domain       TEXT,
                telegram_message_id INTEGER,
                telegram_chat_id    TEXT,
                is_deleted          INTEGER DEFAULT 0,
                ai_processed        INTEGER DEFAULT 0,
                created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_contents_created ON contents(created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_contents_type    ON contents(content_type);
            CREATE INDEX IF NOT EXISTS idx_contents_deleted ON contents(is_deleted);
            CREATE INDEX IF NOT EXISTS idx_contents_ai      ON contents(ai_processed);

            -- -------------------------------------------------------
            -- Tabella tag
            -- -------------------------------------------------------
            CREATE TABLE IF NOT EXISTS tags (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT UNIQUE NOT NULL,
                slug        TEXT UNIQUE NOT NULL,
                color       TEXT DEFAULT '#6c757d',
                source      TEXT DEFAULT 'manual',
                usage_count INTEGER DEFAULT 0
            );

            -- -------------------------------------------------------
            -- Tabella di relazione contenuti↔tag
            -- -------------------------------------------------------
            CREATE TABLE IF NOT EXISTS content_tags (
                content_id INTEGER NOT NULL,
                tag_id     INTEGER NOT NULL,
                PRIMARY KEY (content_id, tag_id),
                FOREIGN KEY(content_id) REFERENCES contents(id) ON DELETE CASCADE,
                FOREIGN KEY(tag_id)     REFERENCES tags(id)     ON DELETE CASCADE
            );

            -- -------------------------------------------------------
            -- Tabella admin
            -- -------------------------------------------------------
            CREATE TABLE IF NOT EXISTS admins (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            -- -------------------------------------------------------
            -- Tabella cursori di import
            -- -------------------------------------------------------
            CREATE TABLE IF NOT EXISTS import_cursors (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                chat_id         TEXT NOT NULL,
                last_msg_id     INTEGER DEFAULT 0,
                date_from       INTEGER,
                status          TEXT DEFAULT 'idle',
                total_found     INTEGER DEFAULT 0,
                total_imported  INTEGER DEFAULT 0,
                updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            -- -------------------------------------------------------
            -- Tabella log strutturati
            -- -------------------------------------------------------
            CREATE TABLE IF NOT EXISTS logs (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                level      TEXT,
                category   TEXT,
                message    TEXT,
                context    TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );

            CREATE INDEX IF NOT EXISTS idx_logs_created ON logs(created_at DESC);
            CREATE INDEX IF NOT EXISTS idx_logs_level   ON logs(level, category);

            -- -------------------------------------------------------
            -- Tabella rate limiting (API pubblica + admin + login)
            -- -------------------------------------------------------
            CREATE TABLE IF NOT EXISTS rate_limits (
                ip         TEXT NOT NULL,
                endpoint   TEXT NOT NULL,
                hit_count  INTEGER DEFAULT 1,
                window_start INTEGER NOT NULL,
                PRIMARY KEY (ip, endpoint)
            );
        ");
    }

    // -----------------------------------------------------------------------
    // Query helpers
    // -----------------------------------------------------------------------

    /**
     * Esegue una query preparata e restituisce lo statement.
     *
     * @param string  $sql
     * @param array   $params
     * @return PDOStatement
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Restituisce tutte le righe di una query.
     *
     * @param string $sql
     * @param array  $params
     * @return array<int, array<string, mixed>>
     */
    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * Restituisce la prima riga di una query, o null se vuota.
     *
     * @param string $sql
     * @param array  $params
     * @return array<string, mixed>|null
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Restituisce il valore della prima colonna della prima riga.
     * Utile per COUNT, MAX, ecc.
     *
     * @param string $sql
     * @param array  $params
     * @return mixed|null
     */
    public static function fetchScalar(string $sql, array $params = []): mixed
    {
        $row = self::query($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row !== false ? $row[0] : null;
    }

    /**
     * Restituisce l'ultimo id inserito.
     */
    public static function lastInsertId(): string
    {
        return self::get()->lastInsertId();
    }

    /**
     * Inicia una transazione.
     */
    public static function beginTransaction(): bool
    {
        return self::get()->beginTransaction();
    }

    /**
     * Commit della transazione corrente.
     */
    public static function commit(): bool
    {
        return self::get()->commit();
    }

    /**
     * Rollback della transazione corrente.
     */
    public static function rollBack(): bool
    {
        return self::get()->rollBack();
    }

    // -----------------------------------------------------------------------
    // Utilità interne
    // -----------------------------------------------------------------------

    /**
     * Crea un file .htaccess protettivo nella directory data/.
     * Chiamato automaticamente se la directory viene creata dal codice.
     */
    private static function writeDataHtaccess(string $dir): void
    {
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all\n");
        }
    }

    /**
     * Resetta la connessione singleton (usato nei test).
     * NON chiamare in produzione.
     */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
