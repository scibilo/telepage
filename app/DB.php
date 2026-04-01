<?php

/**
 * TELEPAGE — DB.php
 * Singleton PDO per SQLite 3 con WAL mode.
 *
 * Specifiche (sezione 4.1 architettura):
 *  - Una sola connessione per request
 *  - PRAGMA journal_mode=WAL    → letture concorrenti
 *  - PRAGMA foreign_keys=ON     → integrità referenziale
 *  - PRAGMA cache_size=-64000   → ~64 MB cache in RAM
 *  - ATTR_ERRMODE → EXCEPTION   → errori come eccezioni
 *  - ATTR_DEFAULT_FETCH_MODE → ASSOC
 *
 * Dipendenze: Config.php (deve essere incluso prima)
 */

// Assicura che Config sia disponibile
require_once __DIR__ . '/Config.php';

class DB
{
    /** Istanza singleton PDO */
    private static ?PDO $pdo = null;

    /**
     * Restituisce la connessione PDO, creandola se necessario.
     *
     * @return PDO
     * @throws RuntimeException se il percorso DB non è configurato o non scrivibile
     */
    public static function get(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = self::connect();
        }

        return self::$pdo;
    }

    /**
     * Crea la connessione PDO e configura SQLite.
     *
     * @return PDO
     * @throws RuntimeException
     */
    private static function connect(): PDO
    {
        $config  = Config::get();
        $dbPath  = $config['db_path'] ?? '';

        if (empty($dbPath)) {
            throw new RuntimeException('DB::connect() — db_path non configurato in config.json');
        }

        // Verifica che la directory esista ed è scrivibile
        $dbDir = dirname($dbPath);

        if (!is_dir($dbDir)) {
            if (!mkdir($dbDir, 0750, true)) {
                throw new RuntimeException("DB::connect() — impossibile creare directory: {$dbDir}");
            }
            // Proteggi la directory con .htaccess se appena creata
            self::writeDataHtaccess($dbDir);
        }

        if (!is_writable($dbDir)) {
            throw new RuntimeException("DB::connect() — directory non scrivibile: {$dbDir}");
        }

        try {
            $pdo = new PDO('sqlite:' . $dbPath);
        } catch (PDOException $e) {
            throw new RuntimeException('DB::connect() — PDO fallito: ' . $e->getMessage());
        }

        // Modalità errore: eccezioni
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Fetch mode default: array associativo
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Emulate prepares: false per SQLite (usa prepared reali)
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        // PRAGMA di performance e integrità (sezione 3 architettura)
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA foreign_keys=ON');
        $pdo->exec('PRAGMA cache_size=-64000');

        // Timeout per lock: 5 secondi prima di dare SQLITE_BUSY
        $pdo->exec('PRAGMA busy_timeout=5000');

        return $pdo;
    }

    // -----------------------------------------------------------------------
    // Metodi di utilità per lo schema
    // -----------------------------------------------------------------------

    /**
     * Crea tutte le tabelle se non esistono ancora.
     * Chiamato dal wizard di installazione (Step 2).
     *
     * Schema completo secondo sezione 3 dell'architettura.
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
