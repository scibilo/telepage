<?php

/**
 * TELEPAGE — TelegramImporter.php
 * Import storico da JSON export di Telegram Desktop.
 *
 * (Sezione 5.2 architettura)
 * Metodo principale: import da file result.json esportato da Telegram Desktop.
 *
 * RB-11: verificare duplicati su telegram_message_id prima di ogni INSERT.
 *
 * Flusso:
 *  1. Upload JSON → parse → anteprima
 *  2. Selector date_from / date_to
 *  3. Per ogni messaggio con URL o media:
 *     a. Estrai url, text, date, id
 *     b. Verifica duplicati
 *     c. Scraper::fetch()
 *     d. Salva in DB
 *     e. Aggiorna import_cursors
 *  4. Processare in batch da 20
 *  5. Progress via polling AJAX
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Scraper.php';

class TelegramImporter
{
    private const BATCH_SIZE = 20;

    // -----------------------------------------------------------------------
    // Import avvio (chiamato da api/admin.php)
    // -----------------------------------------------------------------------

    /**
     * Analizza i messaggi e salva il cursore per l'import progressivo.
     * Esegue il primo batch subito.
     *
     * @param array $messages   Array messaggi da export Telegram Desktop
     * @param int   $dateFrom   Timestamp Unix inizio (0 = tutti)
     * @param int   $dateTo     Timestamp Unix fine (PHP_INT_MAX = tutti)
     * @return array Info sul cursore creato
     */
    public static function startImport(array $messages, int $dateFrom = 0, int $dateTo = PHP_INT_MAX): array
    {
        $config = Config::get();
        $chatId = $config['telegram_channel_id'] ?? 'import';

        // Filtra per data e tipo (solo messaggi con URL o media)
        $filtered = self::filterMessages($messages, $dateFrom, $dateTo);
        $total    = count($filtered);

        if ($total === 0) {
            return [
                'cursor_id'     => 0,
                'total_found'   => 0,
                'total_imported'=> 0,
                'status'        => 'done',
                'message'       => 'Nessun messaggio nel periodo selezionato',
            ];
        }

        // Range date effettivo
        $dates = array_column($filtered, 'date');
        sort($dates);
        $minDate = $dates[0]          ?? 0;
        $maxDate = end($dates)        ?: 0;

        // Salva la coda di import in una tabella temporanea (uniamo nel cursor status)
        // Usiamo import_cursors per tracciare lo stato
        $existing = DB::fetchOne('SELECT id FROM import_cursors WHERE chat_id=:cid', [':cid' => $chatId]);

        if ($existing) {
            DB::query(
                'UPDATE import_cursors SET
                    status=:st, total_found=:tf, total_imported=0, date_from=:df, updated_at=CURRENT_TIMESTAMP
                 WHERE id=:id',
                [':st' => 'running', ':tf' => $total, ':df' => $dateFrom, ':id' => $existing['id']]
            );
            $cursorId = (int) $existing['id'];
        } else {
            DB::query(
                'INSERT INTO import_cursors (chat_id, date_from, status, total_found, total_imported)
                 VALUES (:cid, :df, :st, :tf, 0)',
                [':cid' => $chatId, ':df' => $dateFrom, ':st' => 'running', ':tf' => $total]
            );
            $cursorId = (int) DB::lastInsertId();
        }

        // Salva i messaggi filtrati su file temporaneo (evita il limite di dimensione della sessione PHP)
        // La sessione può gestire max 2-4MB su shared hosting — un export grande la farebbe crashare
        $queueFile = self::queueFilePath($cursorId);
        file_put_contents($queueFile, json_encode([
            'messages' => $filtered,
            'batch'    => 0,
        ], JSON_UNESCAPED_UNICODE));

        // Esegui subito il primo batch
        $imported = self::processBatch($cursorId);

        return [
            'cursor_id'      => $cursorId,
            'total_found'    => $total,
            'total_imported' => $imported,
            'date_min'       => date('Y-m-d', $minDate),
            'date_max'       => date('Y-m-d', $maxDate),
            'status'         => $imported >= $total ? 'done' : 'running',
        ];
    }

    // -----------------------------------------------------------------------
    // Batch processing (chiamato via polling)
    // -----------------------------------------------------------------------

    /**
     * Processa il prossimo batch (BATCH_SIZE messaggi) dalla sessione.
     *
     * @return int Numero di messaggi importati in questo batch
     */
    public static function processBatch(int $cursorId): int
    {
        $queueFile = self::queueFilePath($cursorId);
        if (!file_exists($queueFile)) {
            self::markDone($cursorId);
            return 0;
        }

        $queue    = json_decode(file_get_contents($queueFile), true) ?? [];
        $messages = $queue['messages'] ?? [];
        $batchNum = (int) ($queue['batch'] ?? 0);
        $offset   = $batchNum * self::BATCH_SIZE;
        $batch    = array_slice($messages, $offset, self::BATCH_SIZE);

        if (empty($batch)) {
            self::markDone($cursorId);
            return 0;
        }

        $imported = 0;

        foreach ($batch as $msg) {
            if (self::importMessage($msg)) {
                $imported++;
            }
        }

        // Aggiorna batch counter nel file
        $queue['batch'] = $batchNum + 1;
        file_put_contents($queueFile, json_encode($queue, JSON_UNESCAPED_UNICODE));

        $totalImported = (int) DB::fetchScalar(
            'SELECT total_imported FROM import_cursors WHERE id=:id',
            [':id' => $cursorId]
        );

        $newTotal = $totalImported + $imported;
        $total    = count($messages);
        $done     = $newTotal >= $total;

        DB::query(
            'UPDATE import_cursors SET total_imported=:ti, status=:st, updated_at=CURRENT_TIMESTAMP WHERE id=:id',
            [':ti' => $newTotal, ':st' => $done ? 'done' : 'running', ':id' => $cursorId]
        );

        if ($done) {
            self::clearSession();
        }

        return $imported;
    }

    /**
     * Restituisce lo stato corrente dell'import per polling AJAX.
     */
    public static function getStatus(): array
    {
        $cursor = DB::fetchOne(
            'SELECT * FROM import_cursors ORDER BY updated_at DESC LIMIT 1'
        );

        if (!$cursor) {
            return ['status' => 'idle', 'progress' => 0, 'total' => 0, 'imported' => 0];
        }

        $total    = (int) $cursor['total_found'];
        $imported = (int) $cursor['total_imported'];
        $progress = $total > 0 ? round($imported / $total * 100) : 0;

        $queueFile  = self::queueFilePath((int) $cursor['id']);
        return [
            'status'         => $cursor['status'],
            'progress'       => $progress,
            'total'          => $total,
            'imported'       => $imported,
            'cursor_id'      => $cursor['id'],
            'needs_batch'    => ($cursor['status'] === 'running' && file_exists($queueFile)),
        ];
    }

    // -----------------------------------------------------------------------
    // Import singolo messaggio
    // -----------------------------------------------------------------------

    /**
     * Importa un singolo messaggio dall'export JSON.
     * Verifica duplicati su telegram_message_id (RB-11).
     *
     * @return bool true se il messaggio è stato importato
     */
    private static function importMessage(array $msg): bool
    {
        $messageId = (int) ($msg['id'] ?? 0);
        $chatId    = (string) ($msg['chat_id'] ?? Config::getKey('telegram_channel_id', 'import'));
        $date      = is_int($msg['date']) ? $msg['date'] : strtotime($msg['date'] ?? '');

        // RB-11: controlla duplicati
        $existing = DB::fetchOne(
            'SELECT id FROM contents WHERE telegram_message_id=:mid',
            [':mid' => $messageId]
        );
        if ($existing) {
            return false;
        }

        // Estrai testo (formato export Telegram Desktop)
        $rawText = self::extractText($msg);
        $url     = self::extractUrlFromExport($msg);

        // Tipo contenuto e media locale
        [$contentType, $localMedia] = self::resolveMediaType($msg);

        // Se l'URL è presente, scrapa i metadati
        $meta = [
            'url'          => $url,
            'title'        => '',
            'description'  => preg_replace('/#[a-zA-Z0-9_]+/', '', $rawText) ?? '',
            'image'        => $localMedia,
            'image_source' => $localMedia ? 'telegram' : 'placeholder',
            'favicon'      => '',
            'content_type' => $contentType,
            'source_domain'=> '',
        ];

        if (!empty($url)) {
            try {
                $scraped = Scraper::fetch($url);
                $meta['title']        = $scraped['title']        ?? '';
                $meta['description']  = $scraped['description']  ?: $meta['description'];
                $meta['favicon']      = $scraped['favicon']      ?? '';
                $meta['content_type'] = $scraped['content_type'] ?? $contentType;
                $meta['source_domain']= $scraped['source_domain']?? '';
                if (empty($meta['image'])) {
                    $meta['image']        = $scraped['image']        ?? '';
                    $meta['image_source'] = $scraped['image_source'] ?? 'placeholder';
                }
            } catch (Throwable $e) {
                Logger::import(Logger::WARNING, 'Scraper error durante import', [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Hashtag → tag manuali
        $hashTags = [];
        if (preg_match_all('/#([a-zA-Z0-9_À-ÿ]+)/', $rawText, $m)) {
            $hashTags = $m[1];
        }

        // Config per AI flag
        $config      = Config::get();
        $aiProcessed = ($config['ai_auto_tag'] || $config['ai_auto_summary']) ? 0 : 1;

        $createdAt = $date ? date('Y-m-d H:i:s', $date) : date('Y-m-d H:i:s');

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
                ':url'    => $meta['url']           ?: null,
                ':title'  => $meta['title']         ?: null,
                ':desc'   => $meta['description']   ?: null,
                ':img'    => $meta['image']         ?: null,
                ':img_src'=> $meta['image_source'],
                ':fav'    => $meta['favicon']       ?: null,
                ':ct'     => $meta['content_type'],
                ':sd'     => $meta['source_domain'] ?: null,
                ':mid'    => $messageId,
                ':cid'    => $chatId,
                ':ai'     => $aiProcessed,
                ':ca'     => $createdAt,
            ]
        );

        $contentId = (int) DB::lastInsertId();

        // --- AI Processing immediato (se abilitato) ---
        if ($config['ai_enabled'] && $config['ai_auto_tag']) {
            require_once __DIR__ . '/AIService.php';
            AIService::processContent($contentId);
        }

        // Salva tag
        foreach (array_unique(array_filter($hashTags)) as $tag) {
            $name = strtolower(trim($tag));
            $slug = preg_replace('/[^a-z0-9\-]/', '-', $name) ?? $name;
            $slug = trim($slug, '-');

            DB::query(
                'INSERT INTO tags (name, slug, source) VALUES (:n, :s, "manual")
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

        return true;
    }

    // -----------------------------------------------------------------------
    // Helper: estrazione dati dall'export JSON Telegram Desktop
    // -----------------------------------------------------------------------

    /**
     * Estrae il testo da un messaggio dell'export.
     * Il formato Telegram Desktop può avere 'text' come stringa o array.
     */
    private static function extractText(array $msg): string
    {
        $text = $msg['text'] ?? '';

        if (is_array($text)) {
            // Il testo è un array di segmenti: [{type, text}, ...]
            $plain = '';
            foreach ($text as $segment) {
                if (is_string($segment)) {
                    $plain .= $segment;
                } elseif (is_array($segment)) {
                    $plain .= $segment['text'] ?? '';
                }
            }
            return $plain;
        }

        return (string) $text;
    }

    /**
     * Estrae URL da un messaggio export.
     */
    private static function extractUrlFromExport(array $msg): string
    {
        // 1. Cerca nelle entità (tipo 'link' o 'text_link')
        $textEntities = $msg['text_entities'] ?? [];
        foreach ($textEntities as $entity) {
            $type = $entity['type'] ?? '';
            if ($type === 'link') {
                return $entity['text'] ?? '';
            }
            if ($type === 'text_link') {
                return $entity['href'] ?? '';
            }
        }

        // 2. Regex nel testo grezzo
        $rawText = self::extractText($msg);
        if (preg_match('/(https?:\/\/[^\s]+)/', $rawText, $m)) {
            return rtrim($m[1], '.,;');
        }

        return '';
    }

    /**
     * Rileva il tipo di contenuto e il path locale (se media).
     * @return array{0: string, 1: string} [content_type, local_path]
     */
    private static function resolveMediaType(array $msg): array
    {
        $mediaType = $msg['media_type'] ?? '';
        $file      = $msg['file'] ?? '';
        $photo     = $msg['photo'] ?? '';
        $thumb     = $msg['thumbnail'] ?? '';

        if (!empty($photo)) {
            return ['photo', ''];  // Path non importato nei backup storici
        }
        if ($mediaType === 'video_file' || $mediaType === 'video_message') {
            return ['video', ''];
        }
        if ($mediaType === 'sticker') {
            return ['note', ''];
        }
        if (!empty($file)) {
            $mime = mime_content_type($file) ?: '';
            if (str_starts_with($mime, 'image/')) {
                return ['photo', ''];
            }
            return ['document', ''];
        }

        return ['link', ''];
    }

    // -----------------------------------------------------------------------
    // Filtro messaggi
    // -----------------------------------------------------------------------

    private static function filterMessages(array $messages, int $dateFrom, int $dateTo): array
    {
        return array_values(array_filter($messages, function ($msg) use ($dateFrom, $dateTo) {
            // Considera solo messaggi normali (no servizi)
            if (($msg['type'] ?? '') !== 'message') {
                return false;
            }

            // Filtra per data
            $date = is_int($msg['date']) ? $msg['date'] : strtotime($msg['date'] ?? '');
            if ($date < $dateFrom || $date > $dateTo) {
                return false;
            }

            // Considera solo messaggi con URL, media o testo non vuoto
            $text = self::extractText($msg);
            $url  = self::extractUrlFromExport($msg);
            $hasMedia = !empty($msg['photo']) || !empty($msg['file']) || !empty($msg['media_type']);

            return !empty($url) || $hasMedia || !empty(trim($text));
        }));
    }

    // -----------------------------------------------------------------------
    // Utilities
    // -----------------------------------------------------------------------

    private static function markDone(int $cursorId): void
    {
        DB::query(
            'UPDATE import_cursors SET status="done", updated_at=CURRENT_TIMESTAMP WHERE id=:id',
            [':id' => $cursorId]
        );
        // Elimina il file queue temporaneo
        $queueFile = self::queueFilePath($cursorId);
        if (file_exists($queueFile)) {
            @unlink($queueFile);
        }
        self::clearSession();
    }

    private static function clearSession(): void
    {
        // Legacy: per compatibilità con vecchie sessioni
        unset($_SESSION['import_messages'], $_SESSION['import_batch'], $_SESSION['import_cursor']);
    }

    /** Path del file queue temporaneo per questo cursor. */
    private static function queueFilePath(int $cursorId): string
    {
        $dir = dirname(__DIR__) . '/data';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir . '/import_queue_' . $cursorId . '.json';
    }
}
