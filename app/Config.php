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

        // Scrittura atomica: scrivi su file temporaneo, poi rinomina
        $tmpPath = $path . '.tmp.' . getmypid();

        if (file_put_contents($tmpPath, $json, LOCK_EX) === false) {
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
     * @param array<string, mixed> $updates Key→value pairs to update
     * @throws RuntimeException
     */
    public static function update(array $updates): void
    {
        $current = self::get();
        $merged  = array_merge($current, $updates);
        self::save($merged);
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
