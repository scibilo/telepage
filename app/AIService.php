<?php

/**
 * TELEPAGE — AIService.php
 * Integration with Google Gemini API (REST).
 *
 * Uses exclusively cURL (vanilla PHP) for maximum portability.
 * Model: gemini-2.5-flash.
 *
 * Features:
 * 1. Semantic summary generation (ai_summary).
 * 2. Intelligent tag generation (ai_tags).
 * 3. ai_processed status update (0: pending, 1: done, 2: error).
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/Logger.php';

class AIService
{
    // Modelli in ordine di preferenza
    private const MODELS = ['gemini-2.5-flash', 'gemini-2.0-flash-001'];
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /**
     * Processes a content item via AI.
     * Generates summary and tags, saving them to the DB.
     *
     * @param int $contentId Content ID in the contents table
     * @return bool true if processing was successful
     */
    public static function processContent(int $contentId): bool
    {
        $config = Config::get();
        $apiKey = $config['gemini_api_key'] ?? '';

        if (empty($apiKey)) {
            Logger::ai(Logger::WARNING, 'API Key Gemini non configurata. Salto elaborazione.', ['id' => $contentId]);
            return false;
        }

        // 1. Fetch content data
        $content = DB::fetchOne(
            'SELECT id, title, description, content_type, url, source_domain FROM contents WHERE id = :id',
            [':id' => $contentId]
        );

        if (!$content) {
            return false;
        }

        $textToAnalyze = ($content['title'] ? $content['title'] . "\n" : "") . ($content['description'] ?? "");
        if (empty(trim($textToAnalyze)) && empty($content['url'])) {
            // If there is no text or URL, nothing to process
            DB::query('UPDATE contents SET ai_processed = 1 WHERE id = :id', [':id' => $contentId]);
            return true;
        }

        // 2. Build the prompt
        $lang = $config['language'] ?? 'it';
        $prompt = self::buildPrompt($textToAnalyze, $content, $lang);

        // 3. Execute the Gemini call
        try {
            $response = self::callGemini($prompt, $apiKey);
            
            if (!$response || !isset($response['candidates'][0]['content']['parts'][0]['text'])) {
                throw new Exception('Risposta Gemini non valida o vuota');
            }

            $rawText = $response['candidates'][0]['content']['parts'][0]['text'];
            
            // 4. Parse JSON from response (AI should return JSON as instructed)
            $aiData = self::parseResponse($rawText);

            if (!$aiData) {
                throw new Exception('Impossibile interpretare JSON generato dall\'AI');
            }

            // 5. Update DB
            self::saveAIData($contentId, $aiData);

            Logger::ai(Logger::INFO, 'Elaborazione AI completata', [
                'id' => $contentId,
                'tags_count' => count($aiData['tags'] ?? [])
            ]);

            return true;

        } catch (Throwable $e) {
            Logger::ai(Logger::ERROR, 'Errore elaborazione AI', [
                'id' => $contentId,
                'error' => $e->getMessage()
            ]);
            
            // Mark as error (ai_processed = 2)
            DB::query('UPDATE contents SET ai_processed = 2 WHERE id = :id', [':id' => $contentId]);
            return false;
        }
    }

    /**
     * Debug test: shows raw Gemini response for a specific content.
     */
    public static function testContent(int $contentId): array
    {
        $config = Config::get();
        $apiKey = $config['gemini_api_key'] ?? '';
        if (empty($apiKey)) return ['error' => 'No API key'];

        $content = DB::fetchOne('SELECT * FROM contents WHERE id = :id', [':id' => $contentId]);
        if (!$content) return ['error' => 'Content not found'];

        $text   = ($content['title'] ?? '') . "\n" . ($content['description'] ?? '');
        $lang   = $config['language'] ?? 'it';
        $prompt = self::buildPrompt($text, $content, $lang);

        $model   = self::MODELS[0];
        $payload = ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 1024]];
        $url     = self::API_BASE . $model . ':generateContent?key=' . $apiKey;
        $ch      = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload), CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_TIMEOUT=>20, CURLOPT_SSL_VERIFYPEER=>true]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $resp   = json_decode($body, true);
        $raw    = $resp['candidates'][0]['content']['parts'][0]['text'] ?? null;
        $parsed = $raw ? self::parseResponse($raw) : null;
        return ['model' => $model, 'http_code' => $code, 'raw' => $raw, 'parsed' => $parsed, 'error' => $resp['error']['message'] ?? null, 'title' => $content['title']];
    }

    /**
     * cURL call to Gemini API.
     */
    private static function callGemini(string $prompt, string $apiKey): ?array
    {
        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature'     => 0.4,
                'maxOutputTokens' => 1024,
            ]
        ];

        $lastError = '';
        foreach (self::MODELS as $model) {
            $url = self::API_BASE . $model . ':generateContent?key=' . $apiKey;
            $ch  = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $resp = json_decode($body, true);
                // Valid response with content
                if (!empty($resp['candidates'][0]['content']['parts'][0]['text'])) {
                    Logger::ai(Logger::INFO, 'Modello usato: ' . $model);
                    return $resp;
                }
                // Response 200 but no candidates — log and try next model
$lastError = $model . ': empty response — ' . substr($body, 0, 200);
            } else {
                $err = json_decode($body ?: '{}', true);
                $lastError = $model . ': HTTP ' . $httpCode . ' — ' . ($err['error']['message'] ?? substr($body, 0, 100));
            }
            usleep(300000); // 300ms tra modelli
        }

        throw new Exception('Tutti i modelli Gemini falliti. Ultimo: ' . $lastError);
    }

    /**
     * Builds the instruction prompt.
     */
    private static function buildPrompt(string $text, array $content, string $lang): string
    {
        $title  = $content['title']        ?? '';
        $domain = $content['source_domain'] ?? '';
        $type   = $content['content_type']  ?? 'link';
        $safeText = mb_strimwidth($text, 0, 800, '...');

        return <<<PROMPT
You are an editorial assistant. Analyze this content and respond ONLY with a JSON object, no other text.

Content:
Title: {$title}
Text: {$safeText}
Source: {$domain}
Type: {$type}

Task:
1. Write a short summary (max 200 chars) in language "{$lang}".
2. List 2-5 relevant tags as lowercase strings without #.

Respond with ONLY this JSON, nothing else before or after:
{"summary":"...","tags":["tag1","tag2"]}
PROMPT;
    }

    /**
     * Extracts JSON data from the AI response.
     */
    private static function parseResponse(string $raw): ?array
    {
        // Attempt 1: direct JSON
        $data = json_decode(trim($raw), true);
        if (is_array($data)) return $data;

        // Attempt 2: remove markdown backticks
        $clean = preg_replace('//', '', $clean);
        $data = json_decode(trim($clean), true);
        if (is_array($data)) return $data;

        // Attempt 3: extract first JSON object with regex
        if (preg_match('/\{[^{}]*summary[^{}]*tags[^{}]*\}/s', $raw, $m) ||
            preg_match('/\{[^{}]*tags[^{}]*summary[^{}]*\}/s', $raw, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) return $data;
        }

        // Attempt 4: extract any JSON object
        if (preg_match('/\{.+\}/s', $raw, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) return $data;
        }

        // Log del raw per debug
        Logger::ai(Logger::WARNING, 'parseResponse fallito', ['raw' => substr($raw, 0, 300)]);
        return null;
    }

    /**
     * Salva i dati AI e collega i tag.
     */
    private static function saveAIData(int $id, array $data): void
    {
        // 1. Update summary
        DB::query(
            'UPDATE contents SET ai_summary = :sum, ai_processed = 1, updated_at = CURRENT_TIMESTAMP WHERE id = :id',
            [':sum' => $data['summary'] ?? null, ':id' => $id]
        );

        // 2. Salva Tag AI
        $tags = $data['tags'] ?? [];
        foreach ($tags as $tagName) {
            $name = strtolower(trim($tagName));
            if (empty($name)) continue;

            $slug = self::slugify($name);

            // Inserisci tag se non esiste, fonte AI
            DB::query(
                'INSERT INTO tags (name, slug, source) VALUES (:name, :slug, "ai")
                 ON CONFLICT(slug) DO UPDATE SET usage_count = usage_count + 1',
                [':name' => $name, ':slug' => $slug]
            );

            $tag = DB::fetchOne('SELECT id FROM tags WHERE slug = :slug', [':slug' => $slug]);
            if ($tag) {
                // Collega tag a contenuto
                DB::query(
                    'INSERT OR IGNORE INTO content_tags (content_id, tag_id) VALUES (:cid, :tid)',
                    [':cid' => $id, ':tid' => $tag['id']]
                );
            }
        }
    }

    /**
     * Utility per slugify.
     */
    private static function slugify(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^a-z0-9\-\_]/', '-', $text) ?? $text;
        $text = preg_replace('/-+/', '-', $text) ?? $text;
        return trim($text, '-');
    }
}
