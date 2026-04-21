<?php

/**
 * TELEPAGE — Config.php
 * Safe read and write of config.json.
 *
 * CRITICAL RULES:
 *  - Never write executable PHP (no var_export, no eval)
 *  - Use only json_encode() / json_decode()
 *  - config.json is protected by .htaccess: Deny from all
 */

class Config
{
    /** Path assoluto a config.json */
    private static string $configPath = '';

    /** In-memory cache for the duration of the request */
    private static ?array $cache = null;

    /**
     * Default values for all supported keys.
     * Used as fallback when config.json does not yet exist
     * (e.g. during the first run of the wizard).
     */
    private static array $defaults = [
        'app_name'          => 'Telepage',
        'theme_color'       => '#0d6efd',
        'logo_path'         => 'assets/img/logo.png',
        'telegram_bot_token'   => '',
        'telegram_channel_id'  => '',
        'db_path'           => '', // Viene risolto in get() tramite rootPath()
        'gemini_api_key'    => '',
        'ai_enabled'        => false,
        'ai_auto_tag'       => false,
        'ai_auto_summary'   => false,
        'items_per_page'    => 12,
        'pagination_type'   => 'classic',   // classic|enhanced|loadmore|infinite
        'webhook_secret'    => '',
        'language'          => 'en',
        'installed'         => false,
    ];

    // -----------------------------------------------------------------------
    // Path helpers
    // -----------------------------------------------------------------------

    /**
     * Returns the absolute path to the project root.
     * (two levels above app/Config.php)
     */
    public static function rootPath(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Returns the absolute path to config.json.
     */
    private static function getConfigPath(): string
    {
        if (self::$configPath === '') {
            self::$configPath = self::rootPath() . '/config.json';
        }
        return self::$configPath;
    }

    // -----------------------------------------------------------------------
    // Lettura
    // -----------------------------------------------------------------------

    /**
     * Reads config.json and returns the configuration array.
     * Merges defaults with saved values (file wins over defaults).
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path   = self::getConfigPath();
        $config = self::$defaults;

        // db_path default: absolute path to data/app.sqlite
        if (empty($config['db_path'])) {
            $config['db_path'] = self::rootPath() . '/data/app.sqlite';
        }

        if (file_exists($path)) {
            $raw = file_get_contents($path);

            if ($raw === false) {
                // Cannot read file — use defaults
                self::$cache = $config;
                return self::$cache;
            }

            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($parsed)) {
                // Merge: file overrides defaults
                $config = array_merge($config, $parsed);
            }
        }

        self::$cache = $config;
        return self::$cache;
    }

    /**
     * Returns the value of a single key.
     *
     * @param string $key
     * @param mixed  $default Return value if the key does not exist
     * @return mixed
     */
    public static function getKey(string $key, mixed $default = null): mixed
    {
        $config = self::get();
        return $config[$key] ?? $default;
    }

    // -----------------------------------------------------------------------
    // Scrittura
    // -----------------------------------------------------------------------

    /**
     * Salva l'array di configurazione su config.json.
     * NON usa mai var_export o serialize: solo json_encode.
     *
     * Not concurrency-safe by itself — concurrent callers can still
     * lose updates relative to each other (see update() for the
     * locked read-modify-write wrapper that concurrent writers
     * should use). This method is exposed for first-time writes
     * (install wizard, migration scripts) where a single writer
     * is the only process touching the file.
     *
     * @param array<string, mixed> $data Array completo da salvare
     * @throws RuntimeException se la scrittura fallisce
     */
    public static function save(array $data): void
    {
        // Sanity check: non permettere di salvare php_executable
        // (chiave esplicita come salvaguardia aggiuntiva)
        unset($data['__php__'], $data['eval'], $data['exec']);

        $path = self::getConfigPath();

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            throw new RuntimeException('Config::save() — json_encode failed: ' . json_last_error_msg());
        }

        // Atomic write: write to tmp file then rename. rename(2) is
        // atomic on POSIX for files on the same filesystem, so readers
        // never see a partial file. A random suffix (not just PID)
        // keeps two concurrent saves with the same PID on a restart
        // from colliding on the tmp path.
        $tmpPath = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmpPath, $json) === false) {
            throw new RuntimeException('Config::save() — cannot write ' . $tmpPath);
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new RuntimeException('Config::save() — cannot rename to ' . $path);
        }

        // Invalida la cache in-memory
        self::$cache = null;
    }

    /**
     * Updates only the specified keys, leaving the others unchanged.
     *
     * Race-safe against concurrent updates: the read-modify-write
     * cycle runs under an exclusive flock() on a dedicated lock file.
     * Two concurrent calls to update() with different keys will
     * therefore always both land in the final config — the old code
     * had a lost-update window where the later writer's get() would
     * read the state BEFORE the earlier writer's save(), then
     * overwrite the earlier writer's changes on merge.
     *
     * @param array<string, mixed> $updates Key→value pairs to update
     * @throws RuntimeException
     */
    public static function update(array $updates): void
    {
        $lockPath = self::getConfigPath() . '.lock';

        // 'c' creates the file if missing without truncating. We need
        // the handle alive for the entire critical section; fclose
        // releases the lock implicitly on PHP ≤7; explicit LOCK_UN +
        // fclose is safer across versions.
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Config::update() — cannot open lock file ' . $lockPath);
        }

        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new RuntimeException('Config::update() — cannot acquire exclusive lock');
        }

        try {
            // CRITICAL SECTION — no other updater can run here.
            // Invalidate the in-memory cache first so get() re-reads
            // the on-disk file: a previous updater in another process
            // may have written between our last get() and now.
            self::$cache = null;
            $current = self::get();
            $merged  = array_merge($current, $updates);
            self::save($merged);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Sets a single key and saves.
     *
     * @throws RuntimeException
     */
    public static function set(string $key, mixed $value): void
    {
        self::update([$key => $value]);
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    /**
     * Checks if the installation has been completed.
     */
    public static function isInstalled(): bool
    {
        return (bool) self::getKey('installed', false);
    }

    /**
     * Invalida la cache in-memory (utile nei test).
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
