<?php

/**
 * TELEPAGE — HistoryScanner.php
 * Recupero messaggi storici dal canale Telegram.
 *
 * Strategia: forwardMessage → leggi contenuto → deleteMessage
 * Il bot deve essere admin del canale (requisito standard per avere il token).
 *
 * Flusso per ogni message_id:
 *  1. forwardMessage(from_chat_id=canale, chat_id=canale, message_id=X)
 *  2. Leggi testo/entità dal messaggio forwardato
 *  3. deleteMessage(message_id=forwardato)
 *  4. Processa il contenuto (scraping URL, tag, ecc.)
 *  5. Salva nel DB (skip se già esiste)
 *
 * Scansiona a ritroso: dal message_id più recente verso i più vecchi.
 * Ogni batch copre BATCH_SIZE messaggi importati con successo.
 * I buchi (messaggi cancellati o non pertinenti) vengono saltati
 * con un limite di MAX_ATTEMPTS per batch.
 */

declare(strict_types=1);

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Scraper.php';

class HistoryScanner
{
    /** Messaggi da importare per batch */
    private const BATCH_SIZE = 50;

    /** Tentativi max per batch (evita loop infiniti su canali sparsi) */
    private const MAX_ATTEMPTS = 300;

    /** Pausa ogni N tentativi (evita rate limiting Telegram) */
    private const SLEEP_EVERY = 10;
    private const SLEEP_MS    = 300_000; // 300ms

    // -----------------------------------------------------------------------
    // Punto di partenza
    // -----------------------------------------------------------------------

    /**
     * Restituisce l'ID da cui iniziare la scansione.
     * Usa il messaggio più recente noto a Telegram (getUpdates offset=-1)
     * oppure il MAX noto nel DB + un margine.
     *
     * @return array{suggested_start: int, db_max_id: int, gap: int}
     */
    public static function getStartId(): array
    {
        $config = Config::get();
        $token  = $config['telegram_bot_token'] ?? '';

        // ID più alto nel DB
        $dbMaxId = (int) DB::fetchScalar(
            'SELECT MAX(telegram_message_id) FROM contents WHERE telegram_message_id IS NOT NULL'
        ) ?: 0;

        // Determina punto di partenza per la scansione
        $currentId = max(1000, $dbMaxId + 500); // fallback

        if (!empty($token)) {
            // Prima prova: getWebhookInfo per vedere l'ultimo update ricevuto
            $whInfo = self::apiRequest($token, 'getWebhookInfo', []);
            $lastErrMsg = $whInfo['result']['last_error_message'] ?? '';
            // Non ci dà l'ID direttamente, ma proviamo getUpdates con offset alto
            
            // Seconda prova: getUpdates con offset=-1
            $resp = self::apiRequest($token, 'getUpdates', [
                'limit'           => 1,
                'offset'          => -1,
                'allowed_updates' => ['channel_post', 'edited_channel_post'],
            ]);
            $msgId = $resp['result'][0]['channel_post']['message_id']
                  ?? $resp['result'][0]['edited_channel_post']['message_id']
                  ?? 0;
            if ($msgId > $dbMaxId) {
                $currentId = $msgId + 10;
            }

            // Terza prova: se getUpdates non aiuta, usa margine largo
            if ($currentId <= $dbMaxId + 10 && $dbMaxId > 0) {
                $currentId = $dbMaxId + 2000;
            }
        }

        return [
            'suggested_start' => $currentId,
            'db_max_id'       => $dbMaxId,
            'gap'             => max(0, $currentId - $dbMaxId),
        ];
    }

    // -----------------------------------------------------------------------
    // Scan batch
    // -----------------------------------------------------------------------

    /**
     * Scansiona un batch di messaggi a partire da $startId (decrescente).
     *
     * @param int $startId   message_id da cui partire (incluso)
     * @param int $batchSize Numero di messaggi da importare (default BATCH_SIZE)
     * @return array Risultato del batch
     */
    public static function scanBatch(int $startId, int $batchSize = self::BATCH_SIZE): array
    {
        $config   = Config::get();
        $token    = $config['telegram_bot_token'] ?? '';
        $chatId   = $config['telegram_channel_id'] ?? '';

        if (empty($token) || empty($chatId)) {
            return [
                'ok'            => false,
                'error'         => 'Token o Channel ID non configurati',
                'imported'      => 0,
                'skipped'       => 0,
                'errors'        => 0,
                'attempts'      => 0,
                'next_start_id' => $startId,
                'has_more'      => false,
                'messages'      => [],
            ];
        }

        $imported  = 0;
        $skipped   = 0;
        $errors    = 0;
        $attempts  = 0;
        $currentId = $startId;
        $messages  = [];

        $maxAttempts = min(self::MAX_ATTEMPTS, $batchSize * 6);

        while ($imported < $batchSize && $attempts < $maxAttempts && $currentId > 0) {
            $attempts++;

            // Pausa anti-rate-limit
            if ($attempts > 1 && $attempts % self::SLEEP_EVERY === 0) {
                usleep(self::SLEEP_MS);
            }

            // Salta se già nel DB
            if (self::existsInDb((int) $currentId)) {
                $skipped++;
                $currentId--;
                continue;
            }

            // Recupera il messaggio via forwardMessage
            $post = self::fetchMessageViaForward($token, $chatId, (int) $currentId);

            if ($post === null) {
                // Messaggio non trovato (cancellato o ID inesistente) — salta
                $currentId--;
                continue;
            }

            // Verifica che valga la pena importare
            if (!self::isImportable($post)) {
                $skipped++;
                $currentId--;
                continue;
            }

            // Importa
            $result = self::importPost($post, (int) $currentId, $chatId, $config);

            if ($result['ok']) {
                $imported++;
                $messages[] = [
                    'id'    => $currentId,
                    'title' => $result['title'],
                    'url'   => $result['url'],
                ];
            } else {
                $errors++;
                Logger::scanner(Logger::WARNING, 'Import fallito', [
                    'message_id' => $currentId,
                    'error'      => $result['error'] ?? 'unknown',
                ]);
            }

            $currentId--;
        }

        $hasMore = $currentId > 0 && $imported >= $batchSize;

        Logger::scanner(Logger::INFO, 'Batch completato', [
            'start'     => $startId,
            'end'       => $currentId,
            'imported'  => $imported,
            'skipped'   => $skipped,
            'errors'    => $errors,
            'attempts'  => $attempts,
        ]);

        return [
            'ok'            => true,
            'imported'      => $imported,
            'skipped'       => $skipped,
            'errors'        => $errors,
            'attempts'      => $attempts,
            'next_start_id' => $currentId,
            'has_more'      => $hasMore,
            'messages'      => $messages,
        ];
    }

    // -----------------------------------------------------------------------
    // Recupero messaggio via forwardMessage
    // -----------------------------------------------------------------------

    /**
     * Forwarda un messaggio dal canale a se stesso, legge il contenuto,
     * poi elimina immediatamente il messaggio forwardato.
     *
     * @return array|null Array con i campi del post, o null se non disponibile
     */
    private static function fetchMessageViaForward(string $token, string $chatId, int $messageId): ?array
    {
        // 1. Forward
        $resp = self::apiRequest($token, 'forwardMessage', [
            'chat_id'              => $chatId,
            'from_chat_id'         => $chatId,
            'message_id'           => $messageId,
            'disable_notification' => true,
        ]);

        if (!($resp['ok'] ?? false)) {
            // Messaggio non trovato / cancellato — normale per canali sparsi
            return null;
        }

        $forwarded   = $resp['result'];
        $forwardedId = (int) ($forwarded['message_id'] ?? 0);

        // 2. Elimina subito il messaggio forwardato (mantieni il canale pulito)
        if ($forwardedId > 0) {
            self::apiRequest($token, 'deleteMessage', [
                'chat_id'    => $chatId,
                'message_id' => $forwardedId,
            ]);
        }

        // 3. Ricostruisci il post originale con i dati recuperati
        // Il messaggio forwardato ha lo stesso contenuto dell'originale
        return [
            'message_id'       => $messageId,
            'chat'             => ['id' => $chatId],
            'date'             => $forwarded['forward_origin']['date']
                               ?? $forwarded['forward_date']
                               ?? $forwarded['date']
                               ?? time(),
            'text'             => $forwarded['text']    ?? '',
            'caption'          => $forwarded['caption'] ?? '',
            'entities'         => $forwarded['entities']         ?? [],
            'caption_entities' => $forwarded['caption_entities'] ?? [],
            'photo'            => $forwarded['photo']     ?? null,
            'video'            => $forwarded['video']     ?? null,
            'document'         => $forwarded['document'] ?? null,
            'animation'        => $forwarded['animation'] ?? null,
        ];
    }

    // -----------------------------------------------------------------------
    // Valutazione importabilità
    // -----------------------------------------------------------------------

    /**
     * Decide se un post merita di essere importato.
     * Criteri: ha un URL esterno, oppure ha media, oppure ha testo con hashtag.
     */
    private static function isImportable(array $post): bool
    {
        $text = $post['text'] . ' ' . $post['caption'];

        // Ha un URL esterno (non t.me)
        if (preg_match('~https?://(?!t\.me)[^\s]+~i', $text)) {
            return true;
        }

        // Ha media
        if (!empty($post['photo']) || !empty($post['video']) || !empty($post['animation'])) {
            return true;
        }

        // Ha testo con almeno un hashtag
        if (preg_match('/#\w+/', $text) && strlen(trim($text)) > 10) {
            return true;
        }

        // Ha testo lungo (nota)
        $cleanText = preg_replace('/#\w+/', '', $text);
        if (strlen(trim($cleanText)) > 80) {
            return true;
        }

        return false;
    }

    // -----------------------------------------------------------------------
    // Import singolo post
    // -----------------------------------------------------------------------

    private static function importPost(array $post, int $messageId, string $chatId, array $config): array
    {
        try {
            $rawText = trim(($post['text'] ?? '') . ' ' . ($post['caption'] ?? ''));
            $entities = array_merge($post['entities'] ?? [], $post['caption_entities'] ?? []);

            // Estrai URL
            $url = self::extractUrl($rawText, $entities);

            // Estrai hashtag
            $hashtags = self::extractHashtags($rawText);

            // Testo pulito
            $cleanText = trim(preg_replace(['~https?://\S+~', '/#\w+/'], '', $rawText));

            // Tipo contenuto
            $contentType = self::detectType($url, $post);

            // Metadati
            $meta = [
                'url'          => $url,
                'title'        => '',
                'description'  => $cleanText,
                'image'        => '',
                'image_source' => 'placeholder',
                'favicon'      => '',
                'content_type' => $contentType,
                'source_domain'=> '',
            ];

            // Scraping se c'è un URL
            if (!empty($url)) {
                try {
                    $scraped = Scraper::fetch($url);
                    $meta = array_merge($meta, [
                        'title'        => $scraped['title']        ?? '',
                        'description'  => $scraped['description']  ?: $cleanText,
                        'image'        => $scraped['image']        ?? '',
                        'image_source' => $scraped['image_source'] ?? 'scraped',
                        'favicon'      => $scraped['favicon']      ?? '',
                        'content_type' => $scraped['content_type'] ?? $contentType,
                        'source_domain'=> $scraped['source_domain'] ?? '',
                    ]);
                } catch (Throwable) {
                    // Scraping fallito — usa fallback
                }
            }

            // Titolo fallback
            if (empty($meta['title'])) {
                if (!empty($cleanText)) {
                    $meta['title'] = mb_strimwidth($cleanText, 0, 80, '…');
                } elseif (!empty($url)) {
                    $meta['title'] = 'Link da ' . (parse_url($url, PHP_URL_HOST) ?: $url);
                } else {
                    $meta['title'] = 'Nota #' . $messageId;
                }
            }

            // URL fallback per post senza link
            if (empty($meta['url'])) {
                $cleanChatId = ltrim($chatId, '-100');
                $meta['url'] = "https://t.me/c/{$cleanChatId}/{$messageId}";
                $meta['source_domain'] = 'Canale Telegram';
            }

            // AI processing flag
            $aiProcessed = ($config['ai_auto_tag'] || $config['ai_auto_summary']) ? 0 : 1;

            $createdAt = date('Y-m-d H:i:s', $post['date'] ?? time());

            DB::query(
                'INSERT INTO contents
                    (url, title, description, image, image_source, favicon,
                     content_type, source_domain,
                     telegram_message_id, telegram_chat_id,
                     ai_processed, is_deleted, created_at, updated_at)
                 VALUES
                    (:url, :title, :desc, :img, :img_src, :fav,
                     :ct, :sd,
                     :mid, :cid,
                     :ai, 0, :ca, CURRENT_TIMESTAMP)',
                [
                    ':url'     => $meta['url'],
                    ':title'   => $meta['title'],
                    ':desc'    => $meta['description'] ?: null,
                    ':img'     => $meta['image']        ?: null,
                    ':img_src' => $meta['image_source'],
                    ':fav'     => $meta['favicon']      ?: null,
                    ':ct'      => $meta['content_type'],
                    ':sd'      => $meta['source_domain'] ?: null,
                    ':mid'     => $messageId,
                    ':cid'     => $chatId,
                    ':ai'      => $aiProcessed,
                    ':ca'      => $createdAt,
                ]
            );

            $contentId = (int) DB::lastInsertId();

            // Salva tag
            foreach (array_unique(array_filter($hashtags)) as $tag) {
                $name = strtolower(trim($tag));
                $slug = trim(preg_replace('/[^a-z0-9\-]/', '-', $name), '-');
                if (empty($slug)) continue;

                DB::query(
                    'INSERT INTO tags (name, slug, source)
                     VALUES (:n, :s, "manual")
                     ON CONFLICT(slug) DO UPDATE SET usage_count = usage_count + 1',
                    [':n' => $name, ':s' => $slug]
                );

                $tagRow = DB::fetchOne('SELECT id FROM tags WHERE slug=:s', [':s' => $slug]);
                if ($tagRow) {
                    DB::query(
                        'INSERT OR IGNORE INTO content_tags (content_id, tag_id) VALUES (:cid, :tid)',
                        [':cid' => $contentId, ':tid' => $tagRow['id']]
                    );
                }
            }

            return ['ok' => true, 'title' => $meta['title'], 'url' => $meta['url']];

        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    // -----------------------------------------------------------------------
    // Statistiche
    // -----------------------------------------------------------------------

    public static function getStats(): array
    {
        return [
            'total_contents' => (int) DB::fetchScalar('SELECT COUNT(*) FROM contents WHERE is_deleted=0'),
            'with_telegram_id' => (int) DB::fetchScalar('SELECT COUNT(*) FROM contents WHERE telegram_message_id IS NOT NULL'),
            'min_message_id' => DB::fetchScalar('SELECT MIN(telegram_message_id) FROM contents WHERE telegram_message_id IS NOT NULL') ?? 0,
            'max_message_id' => DB::fetchScalar('SELECT MAX(telegram_message_id) FROM contents WHERE telegram_message_id IS NOT NULL') ?? 0,
        ];
    }

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    private static function existsInDb(int $messageId): bool
    {
        // FIX #5: considera "esistente" solo i contenuti attivi (is_deleted=0)
        // I contenuti soft-deleted vengono reimportati
        $row = DB::fetchOne(
            'SELECT id, is_deleted FROM contents WHERE telegram_message_id = :id',
            [':id' => $messageId]
        );
        if (!$row) return false;
        // Se è nel cestino, lo ripristina invece di reimportarlo da zero
        if ((int)$row['is_deleted'] === 1) {
            DB::query(
                'UPDATE contents SET is_deleted=0, updated_at=CURRENT_TIMESTAMP WHERE id=:id',
                [':id' => $row['id']]
            );
            Logger::scanner(Logger::INFO, 'Contenuto ripristinato dal cestino', ['id' => $row['id'], 'msg_id' => $messageId]);
        }
        return true;
    }

    private static function extractUrl(string $text, array $entities): string
    {
        foreach ($entities as $e) {
            if ($e['type'] === 'url') {
                return mb_substr($text, $e['offset'], $e['length']);
            }
            if ($e['type'] === 'text_link') {
                return $e['url'] ?? '';
            }
        }
        if (preg_match('~(https?://(?!t\.me)\S+)~i', $text, $m)) {
            return rtrim($m[1], '.,;)');
        }
        return '';
    }

    private static function extractHashtags(string $text): array
    {
        preg_match_all('/#([a-zA-Z0-9_À-ÿ]+)/', $text, $m);
        return $m[1] ?? [];
    }

    private static function detectType(string $url, array $post): string
    {
        if (!empty($post['photo']))     return 'photo';
        if (!empty($post['video']))     return 'video';
        if (!empty($post['animation'])) return 'video';
        if (empty($url))                return 'note';

        if (preg_match('~youtube\.com|youtu\.be~i', $url))  return 'youtube';
        if (preg_match('~tiktok\.com~i', $url))              return 'tiktok';
        if (preg_match('~instagram\.com~i', $url))           return 'instagram';

        return 'link';
    }

    private static function apiRequest(string $token, string $method, array $params = []): array
    {
        $url = "https://api.telegram.org/bot{$token}/{$method}";
        $ch  = curl_init($url);
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
}
