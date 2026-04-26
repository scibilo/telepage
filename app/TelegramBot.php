<?php

/**
 * TELEPAGE — TelegramBot.php
 * Real-time Telegram webhook handler.
 *
 * Architectural rules:
 *  - Responds to Telegram within 5 seconds
 *  - Validates X-Telegram-Bot-Api-Secret-Token (done in api/webhook.php)
 *  - Media saved with hash-based filenames
 *  - AI never blocks the webhook
 *  - Soft delete (is_deleted=1)
 *  - Upsert on telegram_message_id
 *
 * Flow:
 *  1. Receive JSON update → already validated by api/webhook.php
 *  2. Extract channel_post or edited_channel_post
 *  3. Verify chat_id
 *  4. Detect content type
 *  5. Extract hashtags → manual tags
 *  6. Scraper::fetch() if external URL
 *  7. Save record in DB (upsert on telegram_message_id)
 *  8. Save manual tags
 *  9. If ai_auto_tag → queue for AI (ai_processed=0)
 * 10. Respond 200 OK (done by api/webhook.php)
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Scraper.php';
require_once __DIR__ . '/Str.php';

class TelegramBot
{
    // -----------------------------------------------------------------------
    // Entry point
    // -----------------------------------------------------------------------

    /**
     * Processes a Telegram update already decoded from JSON.
     *
     * @param array $update Array PHP decodificato dall'update Telegram
     * @return int|null ID del contenuto elaborato o null
     */
    public static function handleUpdate(array $update): ?int
    {
        // Extract the channel post (new or edited)
        $post = $update['channel_post']
             ?? $update['edited_channel_post']
             ?? null;

        if ($post === null) {
            // Update does not concern a channel post — ignore silently
            return null;
        }

        // Verify the channel matches the configured one
        $config    = Config::get();
        $chatId    = (string) ($post['chat']['id'] ?? '');
        $configuredChannel = (string) ($config['telegram_channel_id'] ?? '');

        if (!empty($configuredChannel) && $chatId !== $configuredChannel) {
            Logger::webhook(Logger::WARNING, 'Update da canale non autorizzato', [
                'received_chat_id' => $chatId,
                'expected'         => $configuredChannel,
            ]);
            return null;
        }

        try {
            return self::processPost($post, $config);
        } catch (Throwable $e) {
            Logger::webhook(Logger::ERROR, 'Errore elaborazione update', [
                'error'    => $e->getMessage(),
                'trace'    => substr($e->getTraceAsString(), 0, 500),
                'update_id'=> $update['update_id'] ?? null,
            ]);
            return null;
        }
    }

    // -----------------------------------------------------------------------
    // Elaborazione post
    // -----------------------------------------------------------------------

    private static function processPost(array $post, array $config): int
    {
        $messageId = (int) ($post['message_id'] ?? 0);
        $chatId    = (string) ($post['chat']['id'] ?? '');
        $date      = (int) ($post['date'] ?? time());

        // Message text (may have caption for media)
        $rawText = $post['text'] ?? $post['caption'] ?? '';

        // --- Detect content type and extract URL ---
        [$contentType, $url, $mediaSaved] = self::detectAndSaveMedia($post, $config);

        // If there is no URL and it is not a media, extract the URL from the text
        if (empty($url)) {
            $url = self::extractUrl($rawText, $post['entities'] ?? $post['caption_entities'] ?? []);
        }

        // --- Extract hashtags → manual tags ---
        $manualTags = self::extractHashtags($rawText);

        // --- Pulisci testo ---
        $cleanText = self::cleanText($rawText);

        // --- Scraping metadati URL ---
        $meta = [
            'url'          => $url,
            'title'        => '',
            'description'  => $cleanText,
            'image'        => $mediaSaved ?: '',
            'image_source' => $mediaSaved ? 'telegram' : 'placeholder',
            'favicon'      => '',
            'content_type' => $contentType,
            'source_domain'=> $url ? Scraper::fetch('')['source_domain'] ?? '' : '',
        ];

        if (!empty($url)) {
            // Update content_type only if still generic
            $scraped = Scraper::fetch($url);
            $meta = array_merge($meta, [
                'url'          => $url,
                'title'        => $scraped['title'] ?? '',
                'description'  => $scraped['description'] ?: $cleanText,
                'favicon'      => $scraped['favicon'] ?? '',
                'content_type' => $scraped['content_type'] ?? $contentType,
                'source_domain'=> $scraped['source_domain'] ?? '',
            ]);
            // Immagine: preferisci media Telegram, poi scraped
            if (empty($meta['image'])) {
                $meta['image']        = $scraped['image'] ?? '';
                $meta['image_source'] = $scraped['image_source'] ?? 'placeholder';
            }
        }

        // --- Save to DB (upsert on telegram_message_id) ---
        // upsertContent always returns the inserted/updated id; failure
        // surfaces as a DB exception which propagates to handleUpdate's
        // try/catch. The previous `if ($contentId === null)` branch was
        // dead code (return type is int, not ?int) and is removed.
        $contentId = self::upsertContent(
            $meta,
            $messageId,
            $chatId,
            $date,
            $config
        );

        // --- Save manual tags ---
        if (!empty($manualTags)) {
            self::saveManualTags($contentId, $manualTags);
        }

        // --- Accoda per AI processing ---
        // (already set with ai_processed=0 in the upsert)

        Logger::webhook(Logger::INFO, 'Post elaborato', [
            'content_id' => $contentId,
            'type'       => $meta['content_type'],
            'url'        => $url ?: '(no url)',
            'tags'       => count($manualTags),
        ]);

        return $contentId;
    }

    // -----------------------------------------------------------------------
    // Upsert contenuto
    // -----------------------------------------------------------------------

    /**
     * INSERT OR REPLACE su telegram_message_id + telegram_chat_id.
     * Restituisce l'id del record nel DB.
     */
    private static function upsertContent(
        array  $meta,
        int    $messageId,
        string $chatId,
        int    $date,
        array  $config
    ): int {
        $aiProcessed = ($config['ai_auto_tag'] || $config['ai_auto_summary']) ? 0 : 1;

        // Check if already exists
        $existing = DB::fetchOne(
            'SELECT id FROM contents WHERE telegram_message_id = :mid AND telegram_chat_id = :cid',
            [':mid' => $messageId, ':cid' => $chatId]
        );

        $createdAt = date('Y-m-d H:i:s', $date);
        $now       = date('Y-m-d H:i:s');

        if ($existing) {
            // UPDATE — cancella i tag esistenti e li riscrive (FIX #3: edit Telegram)
            DB::query('DELETE FROM content_tags WHERE content_id = :id', [':id' => $existing['id']]);
            DB::query(
                'UPDATE contents SET
                    url = :url, title = :title, description = :description,
                    image = :image, image_source = :image_source,
                    favicon = :favicon, content_type = :content_type,
                    source_domain = :source_domain, updated_at = :now
                 WHERE id = :id',
                [
                    ':url'          => $meta['url'] ?? null,
                    ':title'        => $meta['title'] ?? null,
                    ':description'  => $meta['description'] ?? null,
                    ':image'        => $meta['image'] ?? null,
                    ':image_source' => $meta['image_source'] ?? 'placeholder',
                    ':favicon'      => $meta['favicon'] ?? null,
                    ':content_type' => $meta['content_type'] ?? 'link',
                    ':source_domain'=> $meta['source_domain'] ?? null,
                    ':now'          => $now,
                    ':id'           => $existing['id'],
                ]
            );
            return (int) $existing['id'];
        }

        // INSERT
        DB::query(
            'INSERT INTO contents
                (url, title, description, image, image_source, favicon,
                 content_type, source_domain,
                 telegram_message_id, telegram_chat_id,
                 ai_processed, is_deleted, created_at, updated_at)
             VALUES
                (:url, :title, :description, :image, :image_source, :favicon,
                 :content_type, :source_domain,
                 :mid, :cid,
                 :ai_processed, 0, :created_at, :now)',
            [
                ':url'          => $meta['url'] ?? null,
                ':title'        => $meta['title'] ?? null,
                ':description'  => $meta['description'] ?? null,
                ':image'        => $meta['image'] ?? null,
                ':image_source' => $meta['image_source'] ?? 'placeholder',
                ':favicon'      => $meta['favicon'] ?? null,
                ':content_type' => $meta['content_type'] ?? 'link',
                ':source_domain'=> $meta['source_domain'] ?? null,
                ':mid'          => $messageId,
                ':cid'          => $chatId,
                ':ai_processed' => $aiProcessed,
                ':created_at'   => $createdAt,
                ':now'          => $now,
            ]
        );

        return (int) DB::lastInsertId();
    }

    // -----------------------------------------------------------------------
    // Media detection + download
    // -----------------------------------------------------------------------

    /**
     * Rileva il tipo di contenuto e scarica/salva eventuali media.
     *
     * @return array{0: string, 1: string, 2: string}
     *         [content_type, url_esterno, path_media_locale]
     */
    private static function detectAndSaveMedia(array $post, array $config): array
    {
        $botToken  = $config['telegram_bot_token'] ?? '';
        $mediaDir  = dirname(__DIR__) . '/assets/media';
        $mediaBase = 'assets/media';

        // Lite Mode: Skip downloads if disabled
        $canDownload = $config['download_media'] ?? true;

        // Foto
        if (!empty($post['photo'])) {
            if (!$canDownload) return ['photo', '', ''];
            $photos = $post['photo'];
            usort($photos, fn($a, $b) => ($b['file_size'] ?? 0) - ($a['file_size'] ?? 0));
            $largest = $photos[0];
            $path = self::downloadFile($largest['file_id'], 'jpg', $botToken, $mediaDir, $mediaBase);
            return ['photo', '', $path];
        }

        // Video
        if (!empty($post['video'])) {
            if (!$canDownload) return ['video', '', ''];
            $video = $post['video'];
            // Scarica solo thumbnail se disponibile (risparmia banda)
            if (!empty($video['thumb'])) {
                $path = self::downloadFile($video['thumb']['file_id'], 'jpg', $botToken, $mediaDir, $mediaBase);
                return ['video', '', $path];
            }
            return ['video', '', ''];
        }

        // Document con MIME immagine → scarica
        if (!empty($post['document'])) {
            if (!$canDownload) return ['document', '', ''];
            $doc  = $post['document'];
            $mime = $doc['mime_type'] ?? '';
            if (str_starts_with($mime, 'image/')) {
                $ext  = self::mimeToExt($mime);
                $path = self::downloadFile($doc['file_id'], $ext, $botToken, $mediaDir, $mediaBase);
                return ['photo', '', $path];
            }
            return ['document', '', ''];
        }

        // Animation (GIF/mp4)
        if (!empty($post['animation'])) {
            if (!$canDownload) return ['video', '', ''];
            $anim = $post['animation'];
            if (!empty($anim['thumb'])) {
                $path = self::downloadFile($anim['thumb']['file_id'], 'jpg', $botToken, $mediaDir, $mediaBase);
                return ['video', '', $path];
            }
            return ['video', '', ''];
        }

        // Voice / Audio
        if (!empty($post['voice']) || !empty($post['audio'])) {
            return ['note', '', ''];
        }

        // Poll
        if (!empty($post['poll'])) {
            return ['note', '', ''];
        }

        // Solo testo — content_type determinato dal URL (se presente)
        // viene risolto dopo
        return ['note', '', ''];
    }

    // -----------------------------------------------------------------------
    // Download file da Telegram
    // -----------------------------------------------------------------------

    /**
     * Scarica un file da Telegram usando file_id.
     * Salva con nome hash (RB-06).
     *
     * @return string Path relativo al file salvato, es. "assets/media/abc123_1700000000.jpg"
     *                Stringa vuota in caso di errore.
     */
    private static function downloadFile(
        string $fileId,
        string $ext,
        string $botToken,
        string $mediaDir,
        string $mediaBase
    ): string {
        if (empty($botToken) || empty($fileId)) {
            return '';
        }

        // 1. getFile → ottieni file_path
        $resp = self::apiRequest($botToken, 'getFile', ['file_id' => $fileId]);
        if (!($resp['ok'] ?? false)) {
            Logger::webhook(Logger::WARNING, 'getFile fallito', ['file_id' => $fileId]);
            return '';
        }

        $filePath = $resp['result']['file_path'] ?? '';
        if (empty($filePath)) {
            return '';
        }

        // 2. Costruisci URL download (RB-06: nome hash)
        $downloadUrl = "https://api.telegram.org/file/bot{$botToken}/{$filePath}";
        $hash        = md5($fileId . time());
        $timestamp   = time();
        $filename    = "{$hash}_{$timestamp}.{$ext}";
        $savePath    = "{$mediaDir}/{$filename}";

        // 3. Verify directory
        if (!is_dir($mediaDir)) {
            @mkdir($mediaDir, 0755, true);
        }

        // 4. Download con cURL
        $ch = curl_init($downloadUrl);
        $fp = fopen($savePath, 'wb');
        if ($fp === false) {
            Logger::webhook(Logger::ERROR, 'Impossibile aprire file per scrittura', ['path' => $savePath]);
            return '';
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        curl_exec($ch);
        $errno    = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($errno !== 0 || $httpCode !== 200) {
            Logger::webhook(Logger::ERROR, 'Download media fallito', [
                'errno'    => $errno,
                'http'     => $httpCode,
            ]);
            @unlink($savePath);
            return '';
        }

        Logger::webhook(Logger::INFO, 'Media scaricato', ['file' => $filename]);
        return "{$mediaBase}/{$filename}";
    }

    // -----------------------------------------------------------------------
    // Tag management
    // -----------------------------------------------------------------------

    /**
     * Salva i tag manuali estratti dagli hashtag del messaggio.
     * Inserisce il tag in `tags` se non esiste (upsert), poi collega a `content_tags`.
     */
    private static function saveManualTags(int $contentId, array $tagNames): void
    {
        foreach ($tagNames as $name) {
            $name = strtolower(trim($name));
            if (empty($name)) {
                continue;
            }

            $slug = Str::slugify($name);

            // Upsert tag
            DB::query(
                'INSERT INTO tags (name, slug, source)
                 VALUES (:name, :slug, "manual")
                 ON CONFLICT(slug) DO UPDATE SET usage_count = usage_count + 1',
                [':name' => $name, ':slug' => $slug]
            );

            $tag = DB::fetchOne('SELECT id FROM tags WHERE slug = :slug', [':slug' => $slug]);
            if (!$tag) {
                continue;
            }

            // Collega content ↔ tag (ignora duplicati)
            DB::query(
                'INSERT OR IGNORE INTO content_tags (content_id, tag_id)
                 VALUES (:cid, :tid)',
                [':cid' => $contentId, ':tid' => $tag['id']]
            );
        }
    }

    // -----------------------------------------------------------------------
    // Text / hashtag utilities
    // -----------------------------------------------------------------------

    /** Extracts all hashtags from text and Telegram entities. */
    private static function extractHashtags(string $text): array
    {
        $tags = [];
        if (preg_match_all('/#([a-zA-Z0-9_À-ÿ]+)/', $text, $m)) {
            $tags = $m[1];
        }
        return array_unique(array_filter($tags));
    }

    /** Extracts the first URL from Telegram entities or raw text. */
    private static function extractUrl(string $text, array $entities): string
    {
        // First: explicit entities (type=url)
        foreach ($entities as $entity) {
            if (($entity['type'] ?? '') === 'url') {
                $offset = $entity['offset'] ?? 0;
                $length = $entity['length'] ?? 0;
                return mb_substr($text, $offset, $length);
            }
            if (($entity['type'] ?? '') === 'text_link') {
                return $entity['url'] ?? '';
            }
        }

        // Fallback: regex sull'URL nel testo
        if (preg_match('/(https?:\/\/[^\s\]]+)/', $text, $m)) {
            return $m[1];
        }

        return '';
    }

    /** Rimuove URL e hashtag dal testo per salvare solo la parte leggibile. */
    private static function cleanText(string $text): string
    {
        // Rimuovi URL
        $clean = preg_replace('/(https?:\/\/[^\s]+)/', '', $text) ?? $text;
        // Rimuovi hashtag
        $clean = preg_replace('/#[a-zA-Z0-9_À-ÿ]+/', '', $clean) ?? $clean;
        // Normalizza spazi
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        return trim($clean);
    }

    /** Genera slug per un nome tag. */
    // Removed: slugify() logic moved to Str::slugify(). See app/Str.php.

    // -----------------------------------------------------------------------
    // Telegram API helper
    // -----------------------------------------------------------------------

    private static function apiRequest(string $token, string $method, array $params = []): array
    {
        $url = "https://api.telegram.org/bot{$token}/{$method}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($params),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body = curl_exec($ch);
        curl_close($ch);

        $parsed = json_decode($body ?: '{}', true);
        return is_array($parsed) ? $parsed : ['ok' => false];
    }

    // -----------------------------------------------------------------------
    // Utility
    // -----------------------------------------------------------------------

    private static function mimeToExt(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'jpg',
        };
    }

    // -----------------------------------------------------------------------
    // Webhook setup
    // -----------------------------------------------------------------------

    /**
     * Registra o aggiorna il webhook Telegram tramite setWebhook.
     *
     * @param string $webhookUrl URL HTTPS del file api/webhook.php
     * @param string $secret     Webhook secret token
     * @return array Risposta Telegram
     */
    public static function setWebhook(string $webhookUrl, string $secret): array
    {
        $config   = Config::get();
        $token    = $config['telegram_bot_token'] ?? '';

        if (empty($token)) {
            return ['ok' => false, 'description' => 'Token bot non configurato'];
        }

        return self::apiRequest($token, 'setWebhook', [
            'url'             => $webhookUrl,
            'secret_token'    => $secret,
            'allowed_updates' => ['channel_post', 'edited_channel_post'],
            'max_connections' => 100,
        ]);
    }

    /**
     * Recupera le info sul webhook corrente.
     */
    public static function getWebhookInfo(): array
    {
        $config = Config::get();
        $token  = $config['telegram_bot_token'] ?? '';

        if (empty($token)) {
            return ['ok' => false, 'description' => 'Token bot non configurato'];
        }

        return self::apiRequest($token, 'getWebhookInfo');
    }

    /**
     * Rimuove il webhook (necessario per usare getUpdates).
     */
    public static function deleteWebhook(): array
    {
        $config = Config::get();
        $token  = $config['telegram_bot_token'] ?? '';
        return self::apiRequest($token, 'deleteWebhook', ['drop_pending_updates' => false]);
    }

    /**
     * Recupera gli ultimi update via getUpdates (limitato, solo recenti).
     * Usato per sync rapida da admin.
     *
     * @return array Array di update
     */
    public static function getUpdates(int $offset = 0, int $limit = 100): array
    {
        $config = Config::get();
        $token  = $config['telegram_bot_token'] ?? '';

        $resp = self::apiRequest($token, 'getUpdates', [
            'offset'          => $offset,
            'limit'           => $limit,
            'allowed_updates' => ['channel_post', 'edited_channel_post'],
        ]);

        return $resp['result'] ?? [];
    }
}
