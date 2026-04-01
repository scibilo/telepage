<?php

/**
 * TELEPAGE — Config.php
 * Lettura e scrittura sicura di config.json.
 *
 * REGOLE CRITICHE (RB-03):
 *  - Non scrivere mai PHP eseguibile (no var_export, no eval)
 *  - Usa solo json_encode() / json_decode()
 *  - Il file config.json è protetto da .htaccess: Deny from all
 */

class Config
{
    /** Path assoluto a config.json */
    private static string $configPath = '';

    /** Cache in-memory per la durata della request */
    private static ?array $cache = null;

    /**
     * Valori di default per tutte le chiavi supportate.
     * Usati come fallback quando config.json non esiste ancora
     * (es. durante la prima esecuzione del wizard).
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
     * Restituisce il path assoluto alla root del progetto.
     * (due livelli sopra a app/Config.php)
     */
    public static function rootPath(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Restituisce il path assoluto a config.json.
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
     * Legge config.json e restituisce l'array di configurazione.
     * Fonde i default con i valori salvati (il file vince sui default).
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

        // db_path default: path assoluto a data/app.sqlite
        if (empty($config['db_path'])) {
            $config['db_path'] = self::rootPath() . '/data/app.sqlite';
        }

        if (file_exists($path)) {
            $raw = file_get_contents($path);

            if ($raw === false) {
                // Non riesce a leggere il file — usa i default
                self::$cache = $config;
                return self::$cache;
            }

            $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            if (is_array($parsed)) {
                // Merge: il file sovrascrive i default
                $config = array_merge($config, $parsed);
            }
        }

        self::$cache = $config;
        return self::$cache;
    }

    /**
     * Restituisce il valore di una singola chiave.
     *
     * @param string $key
     * @param mixed  $default Valore di ritorno se la chiave non esiste
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
            throw new RuntimeException('Config::save() — json_encode fallito: ' . json_last_error_msg());
        }

        // Scrittura atomica: scrivi su file temporaneo, poi rinomina
        $tmpPath = $path . '.tmp.' . getmypid();

        if (file_put_contents($tmpPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Config::save() — impossibile scrivere ' . $tmpPath);
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            throw new RuntimeException('Config::save() — impossibile rinominare in ' . $path);
        }

        // Invalida la cache in-memory
        self::$cache = null;
    }

    /**
     * Aggiorna solo le chiavi specificate, mantenendo le altre invariate.
     *
     * @param array<string, mixed> $updates Coppie chiave→valore da aggiornare
     * @throws RuntimeException
     */
    public static function update(array $updates): void
    {
        $current = self::get();
        $merged  = array_merge($current, $updates);
        self::save($merged);
    }

    /**
     * Imposta una singola chiave e salva.
     *
     * @throws RuntimeException
     */
    public static function set(string $key, mixed $value): void
    {
        self::update([$key => $value]);
    }

    // -----------------------------------------------------------------------
    // Utilità
    // -----------------------------------------------------------------------

    /**
     * Verifica se l'installazione è stata completata.
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
