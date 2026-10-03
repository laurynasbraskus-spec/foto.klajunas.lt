<?php
declare(strict_types=1);

/**
 * download.php
 * Download ORIGINAL file from B2 as an attachment.
 *
 * - Not used for browsing.
 * - Optional CDN caching: allow Cloudflare to cache downloads for 1 day.
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/gallery-security.php';
gallery_security_headers();
gallery_require_get();
gallery_rate_limit('download', 30, 600);

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function respond_text(string $msg, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
}

function safe_file(string $file): string {
    $file = rawurldecode(trim($file));
    $file = str_replace('\\', '/', $file);
    $file = ltrim($file, '/');
    $file = str_replace("\0", '', $file);
    // Kelio atgal ieskom SEGMENTAIS, ne kaip eilutes gabalu: du taskai pacio failo
    // varde yra visiskai teiseti. Del str_contains($file, '..') dvi archyvo
    // nuotraukos, kuriu vardai baigiasi daugtaskiu ("201 Kupriniu miestelis....jpg"),
    // galerijoje atiduodavo 400 - tai isaiskejo silodant miniatiuras is anksto.
    // Segmentas, lygus "." arba "..", ir toliau atmetamas.
    $traversal = false;
    foreach (explode('/', $file) as $seg) {
        if ($seg === '.' || $seg === '..') { $traversal = true; break; }
    }
    if ($file === '' || $traversal) respond_text('Invalid file path', 400);
    gallery_assert_allowed_file($file);
    return $file;
}

function curl_request(string $url, array $headers = [], ?array $postJson = null): array {
    if (!function_exists('curl_init')) respond_text('PHP cURL extension is not available', 500);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HTTPHEADER     => $headers,
    ];

    if ($postJson !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $headers;
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($postJson, JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        gallery_log("B2 request cURL error: {$err}");
        respond_text("Upstream unavailable", 502);
    }

    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($resp, 0, $headerSize);
    $body = substr($resp, $headerSize);

    return [$http, $rawHeaders, $body];
}

function curl_json(string $url, array $headers = [], ?array $post = null): array {
    [$http, $rawHeaders, $body] = curl_request($url, $headers, $post);
    $json = json_decode($body, true);
    if (!is_array($json)) {
        gallery_log("B2 JSON non-JSON response HTTP {$http}");
        respond_text("Upstream unavailable", 502);
    }
    if ($http >= 400) {
        gallery_log("B2 JSON API error HTTP {$http}: " . json_encode($json, JSON_UNESCAPED_SLASHES));
        respond_text("Upstream unavailable", 502);
    }
    return $json;
}

function b2_file_url(string $downloadUrl, string $bucketName, string $fileName): string {
    $encoded = str_replace('%2F', '/', rawurlencode($fileName));
    return rtrim($downloadUrl, '/') . '/file/' . rawurlencode($bucketName) . '/' . $encoded;
}

function parse_content_type(string $rawHeaders): string {
    if (preg_match('/\r\ncontent-type:\s*([^\r\n]+)/i', $rawHeaders, $m)) {
        return trim($m[1]);
    }
    return 'application/octet-stream';
}

function curl_download_to_file(string $url, string $dstPath, array $headers = [], int $maxBytes = 0): array {
    if (!function_exists('curl_init')) respond_text('PHP cURL extension is not available', 500);

    $fp = fopen($dstPath, 'wb');
    if ($fp === false) respond_text('Failed to open temp file for download', 500);

    $rawHeaders = '';
    $bytes = 0;
    $tooLarge = false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$rawHeaders) {
            $rawHeaders .= $line;
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function($ch, $data) use ($fp, &$bytes, &$tooLarge, $maxBytes) {
            $len = strlen($data);
            if ($maxBytes > 0 && $bytes + $len > $maxBytes) {
                $tooLarge = true;
                return 0;
            }
            $written = fwrite($fp, $data);
            if ($written === false) return 0;
            $bytes += $written;
            return $written;
        },
    ]);

    $ok = curl_exec($ch);
    if ($ok === false) {
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        @unlink($dstPath);
        if ($tooLarge) respond_text('File too large', 413);
        gallery_log("B2 download cURL error: {$err}");
        respond_text("Upstream unavailable", 502);
    }

    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    return [$http, $rawHeaders, $bytes];
}

function download_b2_to_tempfile(
    string $file,
    string $bucketName,
    string $bucketId,
    string $keyId,
    string $appKey,
    string $authCacheFile
): array {
    // returns [tempPath, contentType, bytes]
    $auth = null;
    if (is_file($authCacheFile)) {
        $auth = json_decode((string)file_get_contents($authCacheFile), true);
        if (!is_array($auth) || (int)($auth['expires'] ?? 0) < time()) $auth = null;
    }
    if (!$auth) {
        $basic = base64_encode($keyId . ':' . $appKey);
        $a = curl_json('https://api.backblazeb2.com/b2api/v2/b2_authorize_account', ["Authorization: Basic {$basic}"], null);
        $auth = [
            'apiUrl' => (string)($a['apiUrl'] ?? ''),
            'downloadUrl' => (string)($a['downloadUrl'] ?? ''),
            'authToken' => (string)($a['authorizationToken'] ?? ''),
            'expires' => time() + 23 * 3600,
        ];
        file_put_contents($authCacheFile, json_encode($auth, JSON_UNESCAPED_SLASHES));
    }

    $downloadUrl = (string)($auth['downloadUrl'] ?? '');
    $apiUrl      = (string)($auth['apiUrl'] ?? '');
    $authToken   = (string)($auth['authToken'] ?? '');
    if ($downloadUrl === '' || $apiUrl === '' || $authToken === '') respond_text('B2 authorize incomplete', 500);

    $srcUrl = b2_file_url($downloadUrl, $bucketName, $file);
    $tmp = tempnam(sys_get_temp_dir(), 'b2dl_');
    if ($tmp === false) respond_text('Failed to create temp file', 500);
    $maxBytes = 1024 * 1024 * 1024; // 1 GB safety guard for original downloads.

    // public first
    [$http1, $hdr1, $bytes1] = curl_download_to_file($srcUrl, $tmp, ["User-Agent: foto.klajunas.lt download.php"], $maxBytes);
    if ($http1 === 200 && $bytes1 > 0) {
        return [$tmp, parse_content_type($hdr1), $bytes1];
    }
    if ($http1 !== 401 && $http1 !== 403) {
        @unlink($tmp);
        respond_text("Upstream HTTP {$http1}", ($http1 === 404 ? 404 : 502));
    }

    // private fallback
    $prefix = '';
    $slashPos = strrpos($file, '/');
    if ($slashPos !== false) $prefix = substr($file, 0, $slashPos + 1);

    $dlAuth = curl_json(
        $apiUrl . '/b2api/v2/b2_get_download_authorization',
        ["Authorization: {$authToken}"],
        ['bucketId' => $bucketId, 'fileNamePrefix' => $prefix, 'validDurationInSeconds' => 3600]
    );
    $downloadAuthToken = (string)($dlAuth['authorizationToken'] ?? '');
    if ($downloadAuthToken === '') {
        @unlink($tmp);
        respond_text('Failed to get download authorization token', 500);
    }

    @unlink($tmp);
    $tmp = tempnam(sys_get_temp_dir(), 'b2dl_');
    if ($tmp === false) respond_text('Failed to create temp file', 500);
    [$http2, $hdr2, $bytes2] = curl_download_to_file($srcUrl, $tmp, ["Authorization: {$downloadAuthToken}", "User-Agent: foto.klajunas.lt download.php"], $maxBytes);
    if ($http2 === 200 && $bytes2 > 0) {
        return [$tmp, parse_content_type($hdr2), $bytes2];
    }

    @unlink($tmp);
    respond_text("Upstream HTTP {$http2}", 403);
}

try {
    require_once __DIR__ . '/../../b2-config.php';
    if (!defined('B2_KEY_ID') || !defined('B2_APP_KEY') || !defined('B2_BUCKET') || !defined('B2_BUCKET_ID')) {
        respond_text("Missing constants in b2-config.php. Need: B2_KEY_ID, B2_APP_KEY, B2_BUCKET, B2_BUCKET_ID", 500);
    }
    if (!defined('B2_AUTH_CACHE_FILE')) {
        if (defined('B2_CACHE_FILE')) define('B2_AUTH_CACHE_FILE', (string)B2_CACHE_FILE);
        else define('B2_AUTH_CACHE_FILE', sys_get_temp_dir() . '/b2_auth_cache.json');
    }

    $file = safe_file((string)($_GET['file'] ?? ''));
    if ($file === '') respond_text('Missing file', 400);
    // Juodrascio originalas nesiunciamas (404 dar pries B2 kreipini).
    $downloadCacheOverride = gallery_enforce_file_visibility($file);

    [$tmpPath, $contentType, $bytes] = download_b2_to_tempfile(
        $file,
        (string)B2_BUCKET,
        (string)B2_BUCKET_ID,
        (string)B2_KEY_ID,
        (string)B2_APP_KEY,
        (string)B2_AUTH_CACHE_FILE
    );

    $baseName = basename($file);
    $etag = '"' . sha1((string)B2_BUCKET . '|' . $file . '|' . $bytes) . '"';

    header('ETag: ' . $etag);
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . str_replace('"','', $baseName) . '"');
    // Allow CDN/browser reuse of public gallery downloads for 1 day.
    header('Cache-Control: ' . ($downloadCacheOverride ?? 'public, max-age=86400, s-maxage=86400'));
    header('Content-Length: ' . (string)$bytes);
    readfile($tmpPath);
    @unlink($tmpPath);
    exit;

} catch (Throwable $e) {
    gallery_log($e);
    respond_text("Internal server error", 500);
}
