<?php
declare(strict_types=1);

/**
 * video.php
 * Vaizdo iraso srautas galerijos grotuvui (2026-10-08).
 *
 * download.php visa faila pirma parsisiuncia i laikina faila - grotuvui tai
 * netinka: pradeti groti reiketu laukti viso failo, o prasukimui narsykle siuncia
 * Range uzklausas. Cia Range perduodamas i B2 ir atsakymas (200/206) srautu
 * leidziamas naudotojui be laikino failo.
 *
 * - Tik DB zinomi raktai (photos.*_b2_key): senas B2 failas be iraso - 404.
 * - Matomumas - tas pats gallery_enforce_file_visibility(), kaip img/download.
 * - Cache-Control visada "private": Cloudflare video nekesuoja, kesuoja tik
 *   naudotojo narsykle (privataus albumo - no-store).
 * - Content-Length tikslus (is B2), todel .htaccess jam ijungia
 *   ap_trust_cgilike_cl - kitaip hosto Apache ji nuima ir prasukimas neveikia.
 */

ini_set('display_errors', '0');
ini_set('zlib.output_compression', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/gallery-security.php';
gallery_security_headers();
gallery_require_get();
// Kiekvienas prasukimas - nauja Range uzklausa, tad riba didesne nei download.php.
gallery_rate_limit('video', 600, 600);

const VIDEO_MIME = [
    'mp4'  => 'video/mp4',
    'm4v'  => 'video/mp4',
    'mov'  => 'video/quicktime',
    'webm' => 'video/webm',
];

function video_fail(string $msg, int $code): void {
    gallery_public_error($msg, $code);
}

function video_safe_file(string $file): string {
    $file = rawurldecode(trim($file));
    $file = str_replace('\\', '/', $file);
    $file = ltrim($file, '/');
    $file = str_replace("\0", '', $file);
    // Kaip download.php: kelio atgal ieskom segmentais, ne "..".
    foreach (explode('/', $file) as $seg) {
        if ($seg === '.' || $seg === '..') video_fail('Invalid file path', 400);
    }
    if ($file === '') video_fail('Missing file', 400);
    gallery_assert_allowed_file($file, true);
    if (!gallery_is_video_file($file)) video_fail('Unsupported file type', 415);
    return $file;
}

/** B2 autorizacija is to paties kesavimo failo kaip download.php. */
function video_b2_auth(bool $refresh): array {
    $cacheFile = (string)B2_AUTH_CACHE_FILE;
    if (!$refresh && is_file($cacheFile)) {
        $auth = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($auth) && (int)($auth['expires'] ?? 0) > time() && ($auth['downloadUrl'] ?? '') !== '' && ($auth['authToken'] ?? '') !== '') return $auth;
    }
    $ch = curl_init('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . base64_encode(B2_KEY_ID . ':' . B2_APP_KEY)],
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $a = is_string($body) ? json_decode($body, true) : null;
    if ($http !== 200 || !is_array($a)) {
        gallery_log("video.php B2 authorize HTTP {$http}");
        video_fail('Upstream unavailable', 502);
    }
    $auth = [
        'apiUrl' => (string)($a['apiUrl'] ?? ''),
        'downloadUrl' => (string)($a['downloadUrl'] ?? ''),
        'authToken' => (string)($a['authorizationToken'] ?? ''),
        'expires' => time() + 23 * 3600,
    ];
    if ($auth['downloadUrl'] === '' || $auth['authToken'] === '') video_fail('Upstream unavailable', 502);
    @file_put_contents($cacheFile, json_encode($auth, JSON_UNESCAPED_SLASHES));
    return $auth;
}

/**
 * Leidzia B2 atsakyma naudotojui srautu. Grazina 0, kai atsakymas jau issiustas,
 * arba B2 HTTP koda, kai nieko neissiusta (pvz. 401 - pasibaiges raktas).
 */
function video_stream(string $url, string $token, string $range, string $mime, string $cacheControl, string $name): int {
    $status = 0;
    $hdrs = [];
    $streaming = false;
    $reqHeaders = ['Authorization: ' . $token, 'User-Agent: foto.klajunas.lt video.php'];
    if ($range !== '') $reqHeaders[] = 'Range: ' . $range;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION   => false,
        CURLOPT_CONNECTTIMEOUT   => 15,
        CURLOPT_LOW_SPEED_LIMIT  => 1,
        CURLOPT_LOW_SPEED_TIME   => 60,
        CURLOPT_HTTPHEADER       => $reqHeaders,
        CURLOPT_HEADERFUNCTION   => function ($ch, string $line) use (&$status, &$hdrs, &$streaming, $mime, $cacheControl, $name) {
            $len = strlen($line);
            $t = trim($line);
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $t, $m)) { $status = (int)$m[1]; $hdrs = []; return $len; }
            if ($t !== '') {
                $p = strpos($t, ':');
                if ($p !== false) $hdrs[strtolower(trim(substr($t, 0, $p)))] = trim(substr($t, $p + 1));
                return $len;
            }
            // Antrasciu pabaiga: naudotojui siunciam tik sekmingus atsakymus.
            if (!in_array($status, [200, 206, 416], true)) return $len;
            http_response_code($status);
            header('Content-Type: ' . $mime);
            header('Accept-Ranges: bytes');
            header('Cache-Control: ' . $cacheControl);
            header('Content-Disposition: inline; filename="' . str_replace(['"', "\r", "\n"], '', $name) . '"');
            if (isset($hdrs['content-range'])) header('Content-Range: ' . $hdrs['content-range']);
            if (isset($hdrs['content-length'])) header('Content-Length: ' . $hdrs['content-length']);
            if (isset($hdrs['x-bz-content-sha1']) && preg_match('~^[0-9a-f]{40}$~', $hdrs['x-bz-content-sha1'])) {
                header('ETag: "' . $hdrs['x-bz-content-sha1'] . '"');
            }
            $streaming = true;
            return $len;
        },
        CURLOPT_WRITEFUNCTION    => function ($ch, string $data) use (&$streaming) {
            if (!$streaming) return strlen($data); // klaidos kuna praleidziam
            echo $data;
            flush();
            // Naudotojas prasuko ar uzdare - B2 siuntima nutraukiam.
            return connection_aborted() ? 0 : strlen($data);
        },
    ]);
    curl_exec($ch);
    $err = curl_errno($ch) ? curl_error($ch) : '';
    curl_close($ch);
    if ($streaming) return 0;
    if ($err !== '') gallery_log("video.php B2 cURL: {$err}");
    return $status;
}

try {
    require_once __DIR__ . '/../../b2-config.php';
    if (!defined('B2_KEY_ID') || !defined('B2_APP_KEY') || !defined('B2_BUCKET')) video_fail('Server misconfigured', 500);
    if (!defined('B2_AUTH_CACHE_FILE')) {
        if (defined('B2_CACHE_FILE')) define('B2_AUTH_CACHE_FILE', (string)B2_CACHE_FILE);
        else define('B2_AUTH_CACHE_FILE', sys_get_temp_dir() . '/b2_auth_cache.json');
    }
    if (!function_exists('curl_init')) video_fail('Server misconfigured', 500);

    $file = video_safe_file((string)($_GET['file'] ?? ''));
    $access = gallery_key_access($file);
    if ($access === 'absent') video_fail('Not found', 404);
    if ($access === 'error') video_fail('Temporarily unavailable', 503);
    // Paslepta -> 404 (admin'ui leidziama), privatus -> no-store.
    $cacheOverride = gallery_enforce_file_visibility($file);
    $cacheControl = $cacheOverride ?? 'private, max-age=86400';

    // Viena baitu sritis "bytes=a-b" / "a-" / "-n". Kitokia (kelios sritys) -
    // ignoruojam ir atiduodam visa faila (RFC 9110 tai leidzia).
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    if ($range !== '' && (!preg_match('~^bytes=(\d*)-(\d*)$~', $range, $m) || ($m[1] === '' && $m[2] === ''))) $range = '';

    $mime = VIDEO_MIME[strtolower((string)pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    @set_time_limit(0);
    while (ob_get_level() > 0) @ob_end_clean();

    $refresh = false;
    for ($try = 0; $try < 2; $try++) {
        $auth = video_b2_auth($refresh);
        $url = rtrim((string)$auth['downloadUrl'], '/') . '/file/' . rawurlencode((string)B2_BUCKET) . '/' . str_replace('%2F', '/', rawurlencode($file));
        $http = video_stream($url, (string)$auth['authToken'], $range, $mime, $cacheControl, basename($file));
        if ($http === 0) exit;
        if ($http !== 401) break;
        $refresh = true; // pasibaiges B2 raktas - viena karta is naujo
    }
    if ($http === 404) video_fail('Not found', 404);
    gallery_log("video.php B2 HTTP {$http} for {$file}");
    video_fail('Upstream unavailable', 502);
} catch (Throwable $e) {
    gallery_log($e);
    if (!headers_sent()) video_fail('Internal server error', 500);
    exit;
}
