<?php

/**
 * TELEPAGE — Scraper.php
 * Metadata extraction from any URL.
 *
 * Fallback chain:
 *  1. oEmbed (YouTube, TikTok, Instagram, Twitter/X, Vimeo)
 *  2. Open Graph tags (og:title, og:description, og:image)
 *  3. Standard meta (title, meta description)
 *  4. Fallback: title from domain
 *
 * Timeout: connect=5s, total=15s
 * SSL_VERIFYPEER=true in production with CA bundle
 */

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/Security/UrlValidator.php';

class Scraper
{
    // -----------------------------------------------------------------------
    // Config cURL
    // -----------------------------------------------------------------------

    private const TIMEOUT_CONNECT = 5;
    private const TIMEOUT_TOTAL   = 15;

    /** User-Agent desktop */
    private const UA_DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /** User-Agent mobile (per siti che servono OG solo a mobile) */
    private const UA_MOBILE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    /** Domain → oEmbed endpoint map */
    private const OEMBED_PROVIDERS = [
        'youtube.com'  => 'https://www.youtube.com/oembed?url={url}&format=json',
        'youtu.be'     => 'https://www.youtube.com/oembed?url={url}&format=json',
        'vimeo.com'    => 'https://vimeo.com/api/oembed.json?url={url}',
        'tiktok.com'   => 'https://www.tiktok.com/oembed?url={url}',
        'instagram.com'=> 'https://graph.facebook.com/v18.0/instagram_oembed?url={url}',
        'twitter.com'  => 'https://publish.twitter.com/oembed?url={url}',
        'x.com'        => 'https://publish.twitter.com/oembed?url={url}',
    ];

    // -----------------------------------------------------------------------
    // Entry point principale
    // -----------------------------------------------------------------------

    /**
     * Retrieves metadata from a URL.
     * Returns a normalised array with: title, description, image, favicon,
     * content_type, source_domain.
     *
     * @param string $url URL da analizzare
     * @return array<string, mixed>
     */
    public static function fetch(string $url): array
    {
        // Normalizza URL
        $url = self::normalizeUrl($url);

        if (empty($url)) {
            return self::emptyMeta($url);
        }

        $domain = self::extractDomain($url);

        // Rilevamento tipo contenuto
        $contentType = self::detectContentType($url, $domain);

        // Link Telegram: non scrapare, usa media da TelegramBot
        if ($domain === 't.me' || $domain === 'telegram.me') {
            return self::telegramMeta($url, $domain);
        }

        // URL diretti a immagini
        if (self::isDirectImageUrl($url)) {
            return [
                'url'          => $url,
                'title'        => basename(parse_url($url, PHP_URL_PATH) ?? ''),
                'description'  => '',
                'image'        => $url,
                'image_source' => 'scraped',
                'favicon'      => self::faviconUrl($domain),
                'content_type' => 'photo',
                'source_domain'=> $domain,
            ];
        }

        // Try the fallback chain
        $meta = null;

        // 1. oEmbed (per piattaforme note)
        $oembedEndpoint = self::getOembedEndpoint($domain, $url);
        if ($oembedEndpoint) {
            $meta = self::fetchOembed($oembedEndpoint, $url, $domain, $contentType);
        }

        // 2. OpenGraph + meta standard via cURL
        if ($meta === null || empty($meta['title'])) {
            $meta = self::fetchHttp($url, $domain, $contentType);
        }

        // 3. Fallback minimo
        if ($meta === null || empty($meta['title'])) {
            $meta = self::fallbackMeta($url, $domain, $contentType);
        }

        return $meta;
    }

    // -----------------------------------------------------------------------
    // 1. oEmbed
    // -----------------------------------------------------------------------

    private static function getOembedEndpoint(string $domain, string $url): ?string
    {
        foreach (self::OEMBED_PROVIDERS as $host => $template) {
            if (str_contains($domain, $host)) {
                return str_replace('{url}', urlencode($url), $template);
            }
        }
        return null;
    }

    private static function fetchOembed(
        string $endpoint,
        string $originalUrl,
        string $domain,
        string $contentType
    ): ?array {
        $body = self::httpGet($endpoint, self::UA_DESKTOP);
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        $title       = trim($data['title'] ?? '');
        $description = trim($data['author_name'] ?? '');
        $image       = '';

        // YouTube: thumbnail HD
        if (str_contains($domain, 'youtube') || str_contains($domain, 'youtu.be')) {
            $videoId = self::extractYoutubeId($originalUrl);
            if ($videoId) {
                // Prova maxresdefault, fallback su hqdefault
                $maxres = "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg";
                $hq     = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
                $image  = self::imageExists($maxres) ? $maxres : $hq;
            }
        }

        // TikTok / Instagram: thumbnail_url dall'oEmbed
        if (empty($image) && !empty($data['thumbnail_url'])) {
            $image = $data['thumbnail_url'];
        }

        return [
            'url'          => $originalUrl,
            'title'        => $title ?: self::titleFromDomain($domain),
            'description'  => $description,
            'image'        => $image,
            'image_source' => $image ? 'scraped' : 'placeholder',
            'favicon'      => self::faviconUrl($domain),
            'content_type' => $contentType,
            'source_domain'=> $domain,
        ];
    }

    // -----------------------------------------------------------------------
    // 2. HTTP fetch → OpenGraph + standard meta
    // -----------------------------------------------------------------------

    private static function fetchHttp(
        string $url,
        string $domain,
        string $contentType
    ): ?array {
        // Prima prova con UA desktop
        $body = self::httpGet($url, self::UA_DESKTOP);

        // Some sites serve OG only on mobile — retry with mobile UA
        if ($body !== null && !self::hasOgTags($body)) {
            $mobileBody = self::httpGet($url, self::UA_MOBILE);
            if ($mobileBody !== null && self::hasOgTags($mobileBody)) {
                $body = $mobileBody;
            }
        }

        if ($body === null) {
            return null;
        }

        // Parse HTML con DOMDocument (error soppresso per HTML malformato)
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();
        @$doc->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);

        // Open Graph
        $ogTitle       = self::xpathMeta($xpath, 'og:title');
        $ogDescription = self::xpathMeta($xpath, 'og:description');
        $ogImage       = self::xpathMeta($xpath, 'og:image');

        // Standard meta
        $metaTitle       = self::xpathTitle($xpath);
        $metaDescription = self::xpathMeta($xpath, 'description', 'name');

        $title       = $ogTitle       ?: $metaTitle       ?: self::titleFromDomain($domain);
        $description = $ogDescription ?: $metaDescription ?: '';
        $image       = $ogImage ?: self::findFirstImage($xpath, $url);

        // Favicon: prova link element, fallback Google favicon service
        $favicon = self::extractFavicon($xpath, $url) ?: self::faviconUrl($domain);

        return [
            'url'          => $url,
            'title'        => self::cleanText($title),
            'description'  => self::cleanText($description),
            'image'        => $image,
            'image_source' => $image ? 'scraped' : 'placeholder',
            'favicon'      => $favicon,
            'content_type' => $contentType,
            'source_domain'=> $domain,
        ];
    }

    // -----------------------------------------------------------------------
    // 3. Fallback minimo
    // -----------------------------------------------------------------------

    private static function fallbackMeta(string $url, string $domain, string $contentType): array
    {
        return [
            'url'          => $url,
            'title'        => self::titleFromDomain($domain),
            'description'  => '',
            'image'        => '',
            'image_source' => 'placeholder',
            'favicon'      => self::faviconUrl($domain),
            'content_type' => $contentType,
            'source_domain'=> $domain,
        ];
    }

    // -----------------------------------------------------------------------
    // HTTP helper
    // -----------------------------------------------------------------------

    /**
     * Executes a cURL GET and returns the body.
     * Returns null on error or if SSRF validation fails.
     *
     * SSRF protection:
     *  - UrlValidator::isSafeToFetch() called on the initial URL.
     *  - CURLOPT_PROTOCOLS + CURLOPT_REDIR_PROTOCOLS restrict cURL to
     *    http/https, so a malicious Location: file:/// is ignored.
     *  - CURLOPT_FOLLOWLOCATION is DISABLED; we follow redirects
     *    manually (max 5 hops) and re-validate each Location target
     *    with UrlValidator. This blocks DNS-rebind and open-redirect
     *    chains that would have tricked libcurl-native redirect.
     */
    private static function httpGet(string $url, string $userAgent): ?string
    {
        $maxHops = 5;

        for ($hop = 0; $hop <= $maxHops; $hop++) {
            $validation = UrlValidator::validate($url);
            if (!$validation['ok']) {
                Logger::scraper(Logger::WARNING, 'SSRF blocked: ' . $validation['reason'], [
                    'url' => $url,
                    'hop' => $hop,
                ]);
                return null;
            }

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL             => $url,
                CURLOPT_RETURNTRANSFER  => true,
                CURLOPT_FOLLOWLOCATION  => false,
                CURLOPT_CONNECTTIMEOUT  => self::TIMEOUT_CONNECT,
                CURLOPT_TIMEOUT         => self::TIMEOUT_TOTAL,
                CURLOPT_SSL_VERIFYPEER  => true,
                CURLOPT_SSL_VERIFYHOST  => 2,
                CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_USERAGENT       => $userAgent,
                CURLOPT_HTTPHEADER      => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: it-IT,it;q=0.9,en;q=0.8',
                    'Accept-Encoding: gzip, deflate',
                ],
                CURLOPT_ENCODING        => '', // abilita decompressione automatica
            ]);

            $body     = curl_exec($ch);
            $errno    = curl_errno($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $location = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            curl_close($ch);

            if ($errno !== 0 || $body === false) {
                Logger::scraper(Logger::WARNING, "cURL error fetching {$url}", ['errno' => $errno]);
                return null;
            }

            // Follow redirects manually — re-validate each target.
            if ($httpCode >= 300 && $httpCode < 400 && !empty($location)) {
                // Resolve relative Location against the current URL.
                $url = self::absoluteUrl($location, $url);
                continue;
            }

            if ($httpCode >= 400) {
                Logger::scraper(Logger::WARNING, "HTTP {$httpCode} for {$url}");
                return null;
            }

            return $body;
        }

        Logger::scraper(Logger::WARNING, 'Too many redirects', ['final_url' => $url]);
        return null;
    }

    // -----------------------------------------------------------------------
    // DOMXPath helpers
    // -----------------------------------------------------------------------

    /** Reads a meta property or name. */
    private static function xpathMeta(DOMXPath $xpath, string $name, string $attr = 'property'): string
    {
        $nodes = $xpath->query(
            "//meta[@{$attr}='{$name}']/@content |
             //meta[@{$attr}='".strtoupper($name)."']/@content"
        );
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        return trim($nodes->item(0)->nodeValue ?? '');
    }

    /** Reads the content of the <title> tag. */
    private static function xpathTitle(DOMXPath $xpath): string
    {
        $nodes = $xpath->query('//title');
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        return trim($nodes->item(0)->textContent ?? '');
    }

    /** Trova il favicon dal tag <link rel="...icon...">. */
    private static function extractFavicon(DOMXPath $xpath, string $baseUrl): string
    {
        $nodes = $xpath->query(
            "//link[contains(@rel,'icon')]/@href"
        );
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $href = trim($nodes->item(0)->nodeValue ?? '');
        if (empty($href)) {
            return '';
        }
        return self::absoluteUrl($href, $baseUrl);
    }

    /** Trova la prima immagine significativa nella pagina (fallback). */
    private static function findFirstImage(DOMXPath $xpath, string $baseUrl): string
    {
        $nodes = $xpath->query('//img[@src][string-length(@src)>10]/@src');
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        // Prende la prima immagine che non sia un tracker/pixel da 1x1
        for ($i = 0; $i < $nodes->length; $i++) {
            $src = trim($nodes->item($i)->nodeValue ?? '');
            if (
                !empty($src) &&
                !str_contains($src, 'pixel') &&
                !str_contains($src, 'tracker') &&
                !str_contains($src, '1x1')
            ) {
                return self::absoluteUrl($src, $baseUrl);
            }
        }
        return '';
    }

    /** Controlla se l'HTML contiene tag OG. */
    private static function hasOgTags(string $html): bool
    {
        return str_contains($html, 'og:title') || str_contains($html, 'og:description');
    }

    // -----------------------------------------------------------------------
    // URL / domain utilities
    // -----------------------------------------------------------------------

    private static function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // If the URL already declares a scheme, keep it. Invalid schemes
        // (file://, gopher://, javascript:, ...) will be rejected later
        // by UrlValidator — we must NOT silently rewrite them to https://
        // because doing so would turn 'file:///etc/passwd' into
        // 'https://file:///etc/passwd' and obscure the real scheme.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
        }

        // No scheme at all — assume https://
        $url = 'https://' . $url;
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    private static function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? '';
        return strtolower(preg_replace('/^www\./', '', $host));
    }

    private static function titleFromDomain(string $domain): string
    {
        // Remove TLD: "youtube.com" → "YouTube" (capitalised)
        $name = preg_replace('/\.[^.]+$/', '', $domain) ?? $domain;
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }

    private static function faviconUrl(string $domain): string
    {
        return "https://www.google.com/s2/favicons?domain={$domain}&sz=64";
    }

    private static function absoluteUrl(string $href, string $base): string
    {
        if (str_starts_with($href, 'http')) {
            return $href;
        }
        if (str_starts_with($href, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?? 'https';
            return $scheme . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            $parsed = parse_url($base);
            return ($parsed['scheme'] ?? 'https') . '://' . ($parsed['host'] ?? '') . $href;
        }
        return rtrim($base, '/') . '/' . $href;
    }

    /** Rileva content_type dall'URL/dominio. */
    private static function detectContentType(string $url, string $domain): string
    {
        if (str_contains($domain, 'youtube') || str_contains($domain, 'youtu.be')) {
            return 'youtube';
        }
        if (str_contains($domain, 'tiktok.com')) {
            return 'tiktok';
        }
        if (str_contains($domain, 'instagram.com')) {
            return 'instagram';
        }
        if (str_contains($domain, 't.me') || str_contains($domain, 'telegram.me')) {
            return 'telegram_post';
        }
        if (self::isDirectImageUrl($url)) {
            return 'photo';
        }
        // Estensioni video
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        if (preg_match('/\.(mp4|mov|avi|webm|mkv)$/', $path)) {
            return 'video';
        }
        return 'link';
    }

    private static function isDirectImageUrl(string $url): bool
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        return (bool) preg_match('/\.(jpg|jpeg|png|gif|webp|svg|avif)(\?|$)/', $path);
    }

    /** Extracts YouTube video ID from URL. */
    private static function extractYoutubeId(string $url): string
    {
        if (preg_match('/(?:v=|\/embed\/|\.be\/|\/v\/|\/shorts\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
            return $m[1];
        }
        return '';
    }

    /** Checks whether an image URL responds with 200. */
    private static function imageExists(string $url): bool
    {
        if (!UrlValidator::isSafeToFetch($url)) {
            Logger::scraper(Logger::WARNING, 'SSRF blocked in imageExists', ['url' => $url]);
            return false;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY          => true,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_TIMEOUT         => 5,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

    private static function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }

    // -----------------------------------------------------------------------
    // Meta speciali
    // -----------------------------------------------------------------------

    private static function emptyMeta(string $url): array
    {
        return [
            'url'          => $url,
            'title'        => '',
            'description'  => '',
            'image'        => '',
            'image_source' => 'placeholder',
            'favicon'      => '',
            'content_type' => 'link',
            'source_domain'=> '',
        ];
    }

    private static function telegramMeta(string $url, string $domain): array
    {
        return [
            'url'          => $url,
            'title'        => 'Post Telegram',
            'description'  => '',
            'image'        => '',
            'image_source' => 'telegram',
            'favicon'      => self::faviconUrl('telegram.org'),
            'content_type' => 'telegram_post',
            'source_domain'=> $domain,
        ];
    }
}
