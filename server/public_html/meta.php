<?php
declare(strict_types=1);

/**
 * meta.php
 * Returns cached basic metadata (EXIF) for a file.
 *
 * IMPORTANT:
 * - Metadata is generated opportunistically the first time img.php is called for the file.
 * - If missing, meta.php will fetch the original once and extract EXIF (on-demand).
 *
 * Response:
 *   { ok:true, file:"...", exif:{...}, image:{width,height,megapixels,bytes} }
 */

ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/gallery-security.php';
gallery_security_headers();
gallery_require_get();
gallery_rate_limit('meta', 120, 60);

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Ne null - nepaskelbtos nuotraukos duomenys admin'ui: viesai keseti negalima.
$metaCacheOverride = null;

function respond_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: ' . ($GLOBALS['metaCacheOverride'] ?? 'public, max-age=86400, stale-while-revalidate=86400'));
    header('Vary: Accept-Encoding');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

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
    if ($file === '' || $traversal) {
        respond_text('Invalid file path', 400);
    }
    gallery_assert_allowed_file($file);
    return $file;
}

function cache_dir(): string {
    $base = dirname(__DIR__) . '/cache';
    $dir = $base . '/b2_img_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function meta_cache_path(string $bucket, string $file): string {
    $key = hash('sha256', $bucket . '|' . $file);
    return cache_dir() . "/{$key}.meta.json";
}

if (!function_exists('gallery_db')) {
    // Credentials live outside the webroot (~/domains/foto-db-config.php), just
    // like b2-config.php — this file holds no secret and is version-controlled.
    $metaDbConfig = __DIR__ . '/../../foto-db-config.php';
    if (is_file($metaDbConfig)) require_once $metaDbConfig;

    if (!defined('GALLERY_DB_HOST')) define('GALLERY_DB_HOST', 'localhost');
    if (!defined('GALLERY_DB_NAME')) define('GALLERY_DB_NAME', 'klajunas_foto');
    if (!defined('GALLERY_DB_USER')) define('GALLERY_DB_USER', 'klajunas_adm');
    if (!defined('GALLERY_DB_PASS')) define('GALLERY_DB_PASS', '');

    function gallery_db(): ?PDO {
        static $pdo = false;
        if ($pdo instanceof PDO) return $pdo;
        if ($pdo === null) return null;
        try {
            $pdo = new PDO(
                'mysql:host=' . GALLERY_DB_HOST . ';dbname=' . GALLERY_DB_NAME . ';charset=utf8mb4',
                GALLERY_DB_USER,
                GALLERY_DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            return $pdo;
        } catch (Throwable $e) {
            gallery_log($e);
            $pdo = null;
            return null;
        }
    }
}

function takeout_json_time($node): ?string {
    if (!is_array($node)) return null;
    $ts = $node['timestamp'] ?? null;
    if ($ts !== null && $ts !== '' && is_numeric($ts)) return gmdate('Y-m-d H:i:s', (int)$ts);
    $formatted = trim((string)($node['formatted'] ?? ''));
    if ($formatted !== '') {
        $time = strtotime($formatted);
        if ($time !== false) return gmdate('Y-m-d H:i:s', $time);
    }
    return null;
}

function takeout_views(?array $meta): ?int {
    if (!$meta) return null;
    $value = $meta['imageViews'] ?? $meta['photoViews'] ?? null;
    if ($value === null || $value === '') return null;
    return is_numeric($value) ? (int)$value : null;
}

function takeout_web_upload_without_camera(?array $meta, array $row = []): bool {
    if (!$meta || empty($meta['googlePhotosOrigin']['webUpload']) || !is_array($meta['googlePhotosOrigin']['webUpload'])) return false;
    foreach (['camera_make','camera_model','lens_model','focal_length','aperture','shutter_speed','iso_value'] as $key) {
        if (($row[$key] ?? null) !== null && (string)$row[$key] !== '') return false;
    }
    return true;
}

function trusted_takeout_photo_taken(?array $meta, array $row = []): ?string {
    if (!$meta || takeout_web_upload_without_camera($meta, $row)) return null;
    return takeout_json_time($meta['photoTakenTime'] ?? null);
}

function exif_has_camera_details(array $exif): bool {
    foreach (['cameraMake','cameraModel','lensModel','fNumber','exposureTime','iso','focalLength'] as $key) {
        if (($exif[$key] ?? null) !== null && (string)$exif[$key] !== '') return true;
    }
    return false;
}

function taken_matches_album_date(string $taken, array $row): bool {
    $start = trim((string)($row['event_date'] ?? ''));
    if ($start === '') return true;
    $end = trim((string)($row['event_date_end'] ?? ''));
    if ($end === '') $end = $start;
    $date = substr($taken, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    return $date >= $start && $date <= $end;
}

function exif_from_photo_row(array $row, ?array $json): array {
    $exif = [];
    if (!empty($row['camera_make'])) $exif['cameraMake'] = (string)$row['camera_make'];
    if (!empty($row['camera_model'])) $exif['cameraModel'] = (string)$row['camera_model'];
    if (!empty($row['lens_model'])) $exif['lensModel'] = (string)$row['lens_model'];
    if (!empty($row['aperture'])) $exif['fNumber'] = (string)$row['aperture'];
    if (!empty($row['shutter_speed'])) $exif['exposureTime'] = (string)$row['shutter_speed'];
    if (!empty($row['iso_value'])) $exif['iso'] = (string)$row['iso_value'];
    if (!empty($row['focal_length'])) $exif['focalLength'] = (string)$row['focal_length'];
    $taken = trusted_takeout_photo_taken($json, $row);
    if (!$taken && !empty($row['taken_at']) && exif_has_camera_details($exif)) $taken = (string)$row['taken_at'];
    if ($taken && !taken_matches_album_date($taken, $row)) $taken = null;
    if ($taken) $exif['dateTaken'] = $taken;
    return $exif;
}

function image_from_photo_row(array $row): array {
    $image = [];
    if (!empty($row['file_size'])) $image['bytes'] = (int)$row['file_size'];
    if (!empty($row['width'])) $image['width'] = (int)$row['width'];
    if (!empty($row['height'])) $image['height'] = (int)$row['height'];
    if (!empty($image['width']) && !empty($image['height'])) $image['megapixels'] = round(($image['width'] * $image['height']) / 1000000, 1);
    return $image;
}

function meta_event_date_label(array $row): string {
    $start = trim((string)($row['event_date'] ?? ''));
    if ($start === '') return '';
    $end = trim((string)($row['event_date_end'] ?? ''));
    if ($end === '' || $end === $start) return $start;
    if (substr($start, 0, 7) === substr($end, 0, 7)) {
        return $start . '/' . substr($end, 8, 2);
    }
    if (substr($start, 0, 4) === substr($end, 0, 4)) {
        return $start . '/' . substr($end, 5);
    }
    return $start . ' - ' . $end;
}

function photo_db_meta(string $file): array {
    $db = gallery_db();
    if (!$db) return [];
    try {
        $q = $db->prepare("SELECT p.*,a.event_date,a.event_date_end,a.location_name FROM photos p LEFT JOIN albums a ON a.id=p.album_id WHERE p.b2_key=? LIMIT 1");
        $q->execute([$file]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row) return [];
        $json = !empty($row['metadata_json']) ? json_decode((string)$row['metadata_json'], true) : null;
        if (!is_array($json)) $json = null;
        $views = $row['photo_views'] ?? takeout_views($json);
        $out = [
            'exif' => exif_from_photo_row($row, $json),
            'image' => image_from_photo_row($row),
            'title' => (string)($row['title'] ?: $row['original_filename'] ?: basename($file)),
            'description' => (string)($row['description'] ?? ($json['description'] ?? '')),
            'imageViews' => ($views !== null && $views !== '') ? (int)$views : null,
            'eventDate' => (string)($row['event_date'] ?? ''),
            'eventDateEnd' => (string)($row['event_date_end'] ?? ''),
            'eventDateLabel' => meta_event_date_label($row),
            'albumEventDate' => (string)($row['event_date'] ?? ''),
            'albumEventDateEnd' => (string)($row['event_date_end'] ?? ''),
            'eventPlace' => (string)($row['location_name'] ?? ''),
        ];
        if ($json) $out['metadata'] = $json;
        return $out;
    } catch (Throwable $e) {
        gallery_log($e);
        return [];
    }
}

function takeout_sidecar_key(string $file): string {
    $file = trim($file, '/');
    $base = basename($file);
    $dir = dirname($file);
    if (str_ends_with($dir, '/originals') || basename($dir) === 'originals') {
        $albumDir = dirname($dir);
        return trim($albumDir, '/').'/metadata/'.$base.'.supplemental-metadata.json';
    }
    return trim($dir, '/').'/metadata/'.$base.'.supplemental-metadata.json';
}

function sidecar_meta(string $file, string $bucketName, string $bucketId, string $keyId, string $appKey, string $authCacheFile): array {
    $sidecar = takeout_sidecar_key($file);
    if ($sidecar === '') return [];
    try {
        [$tmp, $bytes] = download_b2_to_tempfile($sidecar, $bucketName, $bucketId, $keyId, $appKey, $authCacheFile, false);
        if ($tmp === '' || $bytes <= 0) return [];
        if ($bytes <= 0 || $bytes > 1024 * 1024) { @unlink($tmp); return []; }
        $json = json_decode((string)file_get_contents($tmp), true);
        @unlink($tmp);
        if (!is_array($json)) return [];
        $taken = trusted_takeout_photo_taken($json);
        $views = takeout_views($json);
        return [
            'exif' => $taken ? ['dateTaken' => $taken] : [],
            'title' => (string)($json['title'] ?? ''),
            'description' => (string)($json['description'] ?? ''),
            'imageViews' => $views,
            'metadata' => $json,
        ];
    } catch (Throwable $e) {
        gallery_log($e);
        return [];
    }
}

function merge_meta(array ...$parts): array {
    $out = ['exif' => [], 'image' => []];
    foreach ($parts as $part) {
        if (!$part) continue;
        if (!empty($part['exif']) && is_array($part['exif'])) $out['exif'] = array_replace($out['exif'], array_filter($part['exif'], fn($v) => $v !== null && $v !== ''));
        if (!empty($part['image']) && is_array($part['image'])) $out['image'] = array_replace($out['image'], array_filter($part['image'], fn($v) => $v !== null && $v !== ''));
        foreach (['title','description','imageViews','metadata','eventDate','eventDateEnd','eventDateLabel','albumEventDate','albumEventDateEnd','eventPlace'] as $key) {
            if (array_key_exists($key, $part) && $part[$key] !== null && $part[$key] !== '') $out[$key] = $part[$key];
        }
    }
    return $out;
}

function filter_untrusted_taken(array $meta): array {
    if (empty($meta['exif']['dateTaken'])) return $meta;
    $start = trim((string)($meta['albumEventDate'] ?? ''));
    if ($start !== '') {
        $end = trim((string)($meta['albumEventDateEnd'] ?? ''));
        if ($end === '') $end = $start;
        $date = substr((string)$meta['exif']['dateTaken'], 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $start || $date > $end) {
            unset($meta['exif']['dateTaken']);
            return $meta;
        }
    }
    $metadata = is_array($meta['metadata'] ?? null) ? $meta['metadata'] : null;
    $trustedTakeout = trusted_takeout_photo_taken($metadata);
    if ($trustedTakeout) return $meta;
    if (exif_has_camera_details((array)($meta['exif'] ?? []))) return $meta;
    unset($meta['exif']['dateTaken']);
    return $meta;
}

function has_takeout_fields($metadata): bool {
    return is_array($metadata) && (
        !empty($metadata['photoTakenTime'])
        || !empty($metadata['creationTime'])
        || array_key_exists('imageViews', $metadata)
        || array_key_exists('photoViews', $metadata)
    );
}

function curl_request(string $url, array $headers = [], ?array $postJson = null): array {
    if (!function_exists('curl_init')) respond_text('PHP cURL extension is not available', 500);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
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

function curl_download_to_file(string $url, string $dstPath, array $headers = [], int $maxBytes = 0): array {
    if (!function_exists('curl_init')) respond_text('PHP cURL extension is not available', 500);

    $fp = fopen($dstPath, 'wb');
    if ($fp === false) respond_text('Failed to open temp file for metadata', 500);

    $rawHeaders = '';
    $bytes = 0;
    $tooLarge = false;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 120,
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
        if ($tooLarge) respond_text('File too large for metadata extraction', 413);
        gallery_log("B2 metadata download cURL error: {$err}");
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
    string $authCacheFile,
    bool $fatal = true
): array {
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
    if ($downloadUrl === '' || $apiUrl === '' || $authToken === '') {
        if (!$fatal) return ['', 0];
        respond_text('B2 authorize incomplete', 500);
    }

    $srcUrl = b2_file_url($downloadUrl, $bucketName, $file);
    $tmp = tempnam(sys_get_temp_dir(), 'b2meta_');
    if ($tmp === false) respond_text('Failed to create temp file', 500);
    $maxBytes = 80 * 1024 * 1024;

    // public first
    [$http1, $hdr1, $bytes1] = curl_download_to_file($srcUrl, $tmp, ["User-Agent: foto.klajunas.lt meta.php"], $maxBytes);
    if ($http1 === 200 && $bytes1 > 0) return [$tmp, $bytes1];
    if ($http1 !== 401 && $http1 !== 403) {
        @unlink($tmp);
        if (!$fatal) return ['', 0];
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
        if (!$fatal) return ['', 0];
        respond_text('Failed to get download authorization token', 500);
    }

    @unlink($tmp);
    $tmp = tempnam(sys_get_temp_dir(), 'b2meta_');
    if ($tmp === false) respond_text('Failed to create temp file', 500);
    [$http2, $hdr2, $bytes2] = curl_download_to_file($srcUrl, $tmp, ["Authorization: {$downloadAuthToken}", "User-Agent: foto.klajunas.lt meta.php"], $maxBytes);
    if ($http2 === 200 && $bytes2 > 0) return [$tmp, $bytes2];

    @unlink($tmp);
    if (!$fatal) return ['', 0];
    respond_text("Upstream HTTP {$http2}", 403);
}

function extract_exif_basic(string $srcPath): array {
    $out = [];
    if (!function_exists('exif_read_data')) return $out;

    $info = @getimagesize($srcPath);
    $mime = $info['mime'] ?? '';
    if ($mime !== 'image/jpeg') return $out;

    $exif = @exif_read_data($srcPath, 'IFD0,EXIF,GPS', true, false);
    if (!is_array($exif)) return $out;

    $pick = function($sec, $key) use ($exif) { return $exif[$sec][$key] ?? null; };

    $out['dateTaken'] = $pick('EXIF', 'DateTimeOriginal') ?? $pick('IFD0', 'DateTime') ?? null;
    $out['cameraMake'] = $pick('IFD0', 'Make') ?? null;
    $out['cameraModel'] = $pick('IFD0', 'Model') ?? null;
    $out['lensModel'] = $pick('EXIF', 'LensModel') ?? null;
    $out['fNumber'] = $pick('EXIF', 'FNumber') ?? null;
    $out['exposureTime'] = $pick('EXIF', 'ExposureTime') ?? null;
    $out['iso'] = $pick('EXIF', 'ISOSpeedRatings') ?? null;
    $out['focalLength'] = $pick('EXIF', 'FocalLength') ?? null;

    return array_filter($out, fn($v) => $v !== null && $v !== '');
}

function extract_image_details(string $srcPath, int $bytes): array {
    $info = @getimagesize($srcPath);
    if (!is_array($info)) return ['bytes' => $bytes];

    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    $details = ['bytes' => $bytes];

    if ($width > 0 && $height > 0) {
        $details['width'] = $width;
        $details['height'] = $height;
        $details['megapixels'] = round(($width * $height) / 1000000, 1);
    }

    return $details;
}

try {
    $cfgCandidates = [
        __DIR__ . '/../../b2-config.php',
        __DIR__ . '/../b2-config.php',
        __DIR__ . '/b2-config.php',
        dirname(__DIR__) . '/b2-config.php',
    ];
    $cfgLoaded = false;
    foreach ($cfgCandidates as $cfg) {
        if (is_file($cfg)) {
            require_once $cfg;
            $cfgLoaded = true;
            break;
        }
    }
    if (!$cfgLoaded) {
        respond_json(['ok' => false, 'error' => 'b2-config.php not found'], 500);
    }
    if (!defined('B2_KEY_ID') || !defined('B2_APP_KEY') || !defined('B2_BUCKET') || !defined('B2_BUCKET_ID')) {
        respond_json(['ok' => false, 'error' => 'Missing constants in b2-config.php'], 500);
    }
    if (!defined('B2_AUTH_CACHE_FILE')) {
        if (defined('B2_CACHE_FILE')) define('B2_AUTH_CACHE_FILE', (string)B2_CACHE_FILE);
        else define('B2_AUTH_CACHE_FILE', sys_get_temp_dir() . '/b2_auth_cache.json');
    }

    $file = safe_file((string)($_GET['file'] ?? ''));
    if ($file === '') respond_json(['ok' => false, 'error' => 'Missing file'], 400);
    // Pries podeli ir B2: juodrascio EXIF/pavadinimas/aprasymas irgi nevieši.
    $metaCacheOverride = gallery_enforce_file_visibility($file);
    $dbMeta = photo_db_meta($file);

    $metaPath = meta_cache_path((string)B2_BUCKET, $file);
    if (is_file($metaPath)) {
        $meta = json_decode((string)file_get_contents($metaPath), true);
        if (is_array($meta) && isset($meta['image']) && is_array($meta['image'])) {
            $sidecarMeta = has_takeout_fields($dbMeta['metadata'] ?? null) ? [] : sidecar_meta($file, (string)B2_BUCKET, (string)B2_BUCKET_ID, (string)B2_KEY_ID, (string)B2_APP_KEY, (string)B2_AUTH_CACHE_FILE);
            $merged = filter_untrusted_taken(merge_meta($meta, $dbMeta, $sidecarMeta));
            respond_json([
                'ok' => true,
                'file' => $file,
                'exif' => (array)($merged['exif'] ?? []),
                'image' => (array)($merged['image'] ?? []),
                'title' => $merged['title'] ?? null,
                'description' => $merged['description'] ?? null,
                'imageViews' => $merged['imageViews'] ?? null,
                'eventDate' => $merged['eventDate'] ?? null,
                'eventDateEnd' => $merged['eventDateEnd'] ?? null,
                'eventDateLabel' => $merged['eventDateLabel'] ?? null,
                'albumEventDate' => $merged['albumEventDate'] ?? null,
                'albumEventDateEnd' => $merged['albumEventDateEnd'] ?? null,
                'eventPlace' => $merged['eventPlace'] ?? null,
                'metadata' => $merged['metadata'] ?? null,
            ]);
        }
    }

    // on-demand build. Metadata is best-effort: image display must not fail just
    // because EXIF extraction or a Takeout sidecar is unavailable.
    [$tmp, $bytes] = download_b2_to_tempfile($file, (string)B2_BUCKET, (string)B2_BUCKET_ID, (string)B2_KEY_ID, (string)B2_APP_KEY, (string)B2_AUTH_CACHE_FILE, false);
    $image = [];
    $exif = [];
    if ($tmp !== '' && $bytes > 0) {
        $image = extract_image_details($tmp, (int)$bytes);
        $exif = extract_exif_basic($tmp);
        @unlink($tmp);
    }

    $sidecarMeta = has_takeout_fields($dbMeta['metadata'] ?? null) ? [] : sidecar_meta($file, (string)B2_BUCKET, (string)B2_BUCKET_ID, (string)B2_KEY_ID, (string)B2_APP_KEY, (string)B2_AUTH_CACHE_FILE);
    $merged = filter_untrusted_taken(merge_meta(['exif' => $exif, 'image' => $image], $dbMeta, $sidecarMeta, ['title' => basename($file)]));
    if (!empty($merged['exif']) || !empty($merged['image'])) {
        $meta = ['file' => $file, 'exif' => (array)($merged['exif'] ?? []), 'image' => (array)($merged['image'] ?? []), 'generatedAt' => time()];
        @file_put_contents($metaPath, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    respond_json([
        'ok' => true,
        'file' => $file,
        'exif' => (array)($merged['exif'] ?? []),
        'image' => (array)($merged['image'] ?? []),
        'title' => $merged['title'] ?? null,
        'description' => $merged['description'] ?? null,
        'imageViews' => $merged['imageViews'] ?? null,
        'eventDate' => $merged['eventDate'] ?? null,
        'eventDateEnd' => $merged['eventDateEnd'] ?? null,
        'eventDateLabel' => $merged['eventDateLabel'] ?? null,
        'albumEventDate' => $merged['albumEventDate'] ?? null,
        'albumEventDateEnd' => $merged['albumEventDateEnd'] ?? null,
        'eventPlace' => $merged['eventPlace'] ?? null,
        'metadata' => $merged['metadata'] ?? null,
    ]);

} catch (Throwable $e) {
    gallery_log($e);
    $fallbackFile = isset($file) && is_string($file) && $file !== ''
        ? $file
        : rawurldecode(trim((string)($_GET['file'] ?? '')));
    $fallbackFile = str_replace('\\', '/', ltrim(str_replace("\0", '', $fallbackFile), '/'));
    $fallback = isset($dbMeta) && is_array($dbMeta) ? $dbMeta : [];
    $fallback = merge_meta($fallback, ['title' => basename($fallbackFile), 'image' => []]);
    respond_json([
        'ok' => true,
        'file' => $fallbackFile,
        'exif' => (array)($fallback['exif'] ?? []),
        'image' => (array)($fallback['image'] ?? []),
        'title' => $fallback['title'] ?? basename($fallbackFile),
        'description' => $fallback['description'] ?? null,
        'imageViews' => $fallback['imageViews'] ?? null,
        'eventDate' => $fallback['eventDate'] ?? null,
        'eventDateEnd' => $fallback['eventDateEnd'] ?? null,
        'eventDateLabel' => $fallback['eventDateLabel'] ?? null,
        'albumEventDate' => $fallback['albumEventDate'] ?? null,
        'albumEventDateEnd' => $fallback['albumEventDateEnd'] ?? null,
        'eventPlace' => $fallback['eventPlace'] ?? null,
        'metadata' => $fallback['metadata'] ?? null,
        'warning' => 'Metadata extraction unavailable',
    ]);
}
