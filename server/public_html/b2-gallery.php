<?php
declare(strict_types=1);

/**
 * b2-gallery.php (FIXED + PAGINATED)
 *
 * Lists "albums" (folders) + photos from Backblaze B2 using b2_list_file_names.
 * Since B2 doesn't return "folders", we derive folders from fileName prefixes.
 *
 * Query:
 *   path=<folder>      (no leading/trailing slash; "" for root)
 *   limit=60           (default 60, max 200)
 *   cursor=<fileName>  (B2 startFileName)
 *   withCovers=1       (include coverThumbUrl for folders)
 *
 * Response:
 * {
 *   ok: true,
 *   path: "",
 *   items: [ ... ],
 *   folders: [ ... ],   // compat
 *   photos:  [ ... ],   // compat
 *   nextCursor: "..."   // for infinite scroll
 * }
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=120, s-maxage=600, stale-while-revalidate=86400');
require_once __DIR__ . '/gallery-security.php';
gallery_security_headers('json');
gallery_require_get();

const HEIC_MISSING_DISPLAY_MESSAGE = 'HEIC originalas įkeltas, bet JPG peržiūra nesukurta.';

if (!function_exists('gallery_db')) {
    // Credentials live outside the webroot, exactly like b2-config.php, so this
    // file carries no secret and can be version-controlled.
    $galleryDbConfig = __DIR__ . '/../../foto-db-config.php';
    if (is_file($galleryDbConfig)) require_once $galleryDbConfig;

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
function gallery_db_column_exists(PDO $db, string $table, string $column): bool {
    static $cache = [];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) return false;
    $key = $table.'.'.$column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        $q = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $cache[$key] = (bool)$q->fetch();
    } catch (Throwable $e) {
        gallery_log($e);
        return $cache[$key] = false;
    }
}

/** Coarse device/browser/OS classification from a User-Agent string. */
function gallery_ua_summary(string $ua): array {
    $ua = trim($ua);
    if ($ua === '') return ['device' => 'unknown', 'browser' => '', 'os' => ''];

    if (preg_match('~bot|crawler|spider|slurp|bingpreview|facebookexternalhit|headless|monitor|preview~i', $ua)) {
        return ['device' => 'bot', 'browser' => '', 'os' => ''];
    }

    $isTablet = (bool)preg_match('~iPad|Tablet|PlayBook|Silk~i', $ua)
        || (preg_match('~Android~i', $ua) && !preg_match('~Mobile~i', $ua));
    $isMobile = !$isTablet && (bool)preg_match('~Mobi|iPhone|iPod|Windows Phone~i', $ua);
    $device = $isTablet ? 'tablet' : ($isMobile ? 'mobile' : 'desktop');

    // Order matters: Edge and Opera also advertise "Chrome".
    if (preg_match('~Edg[A-Z]?/~i', $ua))                                 $browser = 'Edge';
    elseif (preg_match('~OPR/|Opera~i', $ua))                             $browser = 'Opera';
    elseif (preg_match('~Firefox/|FxiOS~i', $ua))                         $browser = 'Firefox';
    elseif (preg_match('~Chrome/|CriOS~i', $ua))                          $browser = 'Chrome';
    elseif (preg_match('~Safari/~i', $ua) && preg_match('~Version/~i', $ua)) $browser = 'Safari';
    else                                                                  $browser = 'Kita';

    if (preg_match('~Windows NT~i', $ua))          $os = 'Windows';
    elseif (preg_match('~iPhone|iPad|iPod~i', $ua)) $os = 'iOS';
    elseif (preg_match('~Mac OS X~i', $ua))         $os = 'macOS';
    elseif (preg_match('~Android~i', $ua))          $os = 'Android';
    elseif (preg_match('~Linux~i', $ua))            $os = 'Linux';
    else                                            $os = '';

    return ['device' => $device, 'browser' => $browser, 'os' => $os];
}

/**
 * Records one public album view for the admin Access log.
 * Runs before the origin cache check, and Cloudflare does not cache this API
 * (cf-cache-status: DYNAMIC), so every real album open reaches us. Crawlers are
 * skipped. Never allowed to break the gallery — all errors are swallowed.
 */
function gallery_log_access(string $albumPath): void {
    $albumPath = trim($albumPath, "/ \t\n\r\0\x0B");
    if ($albumPath === '') return;
    $db = function_exists('gallery_db') ? gallery_db() : null;
    if (!$db) return;

    try {
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $sum = gallery_ua_summary($ua);
        if ($sum['device'] === 'bot') return;

        // Retention: IP is personal data, so old rows must not linger even if no
        // admin ever opens the Access page. Purge >180d occasionally (~1%).
        if (random_int(1, 100) === 1) {
            try { $db->exec("DELETE FROM access_logs WHERE occurred_at < DATE_SUB(NOW(), INTERVAL 180 DAY)"); } catch (Throwable $e) {}
        }

        $country = strtoupper(substr((string)($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''), 0, 2));
        if ($country === '' || $country === 'XX' || $country === 'T1') $country = null;

        $ip = function_exists('gallery_client_ip')
            ? gallery_client_ip()
            : (string)($_SERVER['REMOTE_ADDR'] ?? '');

        $albumId = null;
        $q = $db->prepare("SELECT id FROM albums WHERE slug=? OR source_path=? LIMIT 1");
        $q->execute([$albumPath, $albumPath]);
        $found = $q->fetchColumn();
        if ($found !== false && $found !== null) $albumId = (int)$found;

        $referer = substr((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 255);
        if ($referer === '') $referer = null;

        $sql = "INSERT INTO access_logs(occurred_at,album_path,album_id,ip_address,country,device,browser,os,referer,user_agent)
                VALUES(NOW(),?,?,?,?,?,?,?,?,?)";
        $args = [$albumPath, $albumId, $ip, $country, $sum['device'], $sum['browser'], $sum['os'], $referer, $ua];

        try {
            $db->prepare($sql)->execute($args);
        } catch (Throwable $first) {
            // Table may not exist yet (admin creates it on load) — make it here.
            $db->exec("CREATE TABLE IF NOT EXISTS access_logs (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                occurred_at DATETIME NOT NULL,
                album_path VARCHAR(255) NOT NULL,
                album_id BIGINT UNSIGNED NULL,
                ip_address VARCHAR(45) NULL,
                country CHAR(2) NULL,
                device VARCHAR(16) NULL,
                browser VARCHAR(48) NULL,
                os VARCHAR(48) NULL,
                referer VARCHAR(255) NULL,
                user_agent VARCHAR(500) NULL,
                INDEX access_time_idx(occurred_at),
                INDEX access_album_idx(album_id),
                INDEX access_country_idx(country)
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $db->prepare($sql)->execute($args);
        }
    } catch (Throwable $e) {
        gallery_log($e);
    }
}

if (!function_exists('gallery_download_allowed_for_file')) {
    function gallery_download_allowed_for_file(string $file): bool {
        $file = trim(rawurldecode($file), "/ \t\n\r\0\x0B");
        if ($file === '') return false;

        $db = function_exists('gallery_db') ? gallery_db() : null;
        if (!$db) return true;

        try {
            $conditions = ['p.b2_key = ?'];
            $params = [$file];
            if (gallery_db_column_exists($db, 'photos', 'compatibility_b2_key')) {
                $conditions[] = 'p.compatibility_b2_key = ?';
                $params[] = $file;
            }
            if (gallery_db_column_exists($db, 'photos', 'original_b2_key')) {
                $conditions[] = 'p.original_b2_key = ?';
                $params[] = $file;
            }
            $q = $db->prepare(
                "SELECT a.download_enabled, a.visibility AS album_visibility, p.visibility AS photo_visibility, p.is_downloadable, p.is_missing
                 FROM photos p
                 JOIN albums a ON a.id = p.album_id
                 WHERE ".implode(' OR ', $conditions)."
                 LIMIT 1"
            );
            $q->execute($params);
            $row = $q->fetch();
            if (!is_array($row)) return true;

            return (int)($row['download_enabled'] ?? 0) === 1
                && (string)($row['album_visibility'] ?? '') === 'published'
                && (string)($row['photo_visibility'] ?? '') === 'published'
                && (int)($row['is_downloadable'] ?? 0) === 1
                && (int)($row['is_missing'] ?? 0) === 0;
        } catch (Throwable $e) {
            gallery_log($e);
            return true;
        }
    }
}

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

function json_fail(string $msg, int $code = 500, array $extra = []): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_UNESCAPED_SLASHES);
    exit;
}

function manifest_resolve_public_path(string $path): string {
    $path = trim($path, "/ \t\n\r\0\x0B");
    if ($path === '') return '';

    $db = function_exists('gallery_db') ? gallery_db() : null;
    if (!$db) return $path;

    try {
        $q = $db->prepare("SELECT slug,source_path FROM albums WHERE visibility='published' AND (slug=? OR source_path=?) LIMIT 1");
        $q->execute([$path, $path]);
        $row = $q->fetch();
        if (is_array($row) && trim((string)($row['slug'] ?? ''), "/ \t\n\r\0\x0B") !== '') {
            return trim((string)$row['slug'], "/ \t\n\r\0\x0B");
        }

        $q = $db->prepare("SELECT slug,source_path FROM albums WHERE visibility='published' AND slug LIKE ? ORDER BY CHAR_LENGTH(slug) ASC LIMIT 1");
        $q->execute([$path . '-%']);
        $row = $q->fetch();
        if (is_array($row) && trim((string)($row['slug'] ?? ''), "/ \t\n\r\0\x0B") !== '') {
            return trim((string)$row['slug'], "/ \t\n\r\0\x0B");
        }

        $q = $db->prepare("SELECT slug,source_path FROM albums WHERE visibility='published' AND slug LIKE ? ORDER BY CHAR_LENGTH(slug) ASC LIMIT 2");
        $q->execute(['%-' . $path]);
        $rows = $q->fetchAll();
        if (count($rows) === 1 && trim((string)($rows[0]['slug'] ?? ''), "/ \t\n\r\0\x0B") !== '') {
            return trim((string)$rows[0]['slug'], "/ \t\n\r\0\x0B");
        }

        $q = $db->query("SELECT slug,source_path FROM albums WHERE visibility='published'");
        foreach ($q->fetchAll() as $row) {
            $slug = trim((string)($row['slug'] ?? ''), "/ \t\n\r\0\x0B");
            $source = trim((string)($row['source_path'] ?? ''), "/ \t\n\r\0\x0B");
            if ($slug !== '' && basename($source) === $path) return $slug;
        }
    } catch (Throwable $e) {
        gallery_log($e);
    }

    return $path;
}
function manifest_source_path_for_path(string $path): string {
    $path = trim($path, "/ \t\n\r\0\x0B");
    if ($path === '') return '';

    $db = function_exists('gallery_db') ? gallery_db() : null;
    if (!$db) return $path;

    try {
        $q = $db->prepare("SELECT source_path FROM albums WHERE visibility='published' AND (slug=? OR source_path=?) LIMIT 1");
        $q->execute([$path, $path]);
        $source = trim((string)($q->fetchColumn() ?: ''), "/ \t\n\r\0\x0B");
        return $source !== '' ? $source : $path;
    } catch (Throwable $e) {
        gallery_log($e);
        return $path;
    }
}

set_exception_handler(function (Throwable $e) {
    gallery_log($e);
    json_fail('Internal server error', 500);
});

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        gallery_log((string)($e['message'] ?? 'fatal error'));
        echo json_encode(['ok' => false, 'error' => 'Internal server error'], JSON_UNESCAPED_SLASHES);
        exit;
    }
});

function curl_json_request(string $url, array $headers, array $post): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($post, JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => 30,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        gallery_log("B2 list cURL error: {$err}");
        json_fail('Upstream unavailable', 502);
    }
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($body, true);
    if (!is_array($json)) {
        gallery_log("B2 list non-JSON response HTTP {$http}: " . substr((string)$body, 0, 300));
        json_fail('Upstream unavailable', 502);
    }
    if ($http >= 400) {
        gallery_log("B2 list API error HTTP {$http}: " . json_encode($json, JSON_UNESCAPED_SLASHES));
        json_fail('Upstream unavailable', 502);
    }
    return $json;
}

// ✅ Your real config location:
// b2-config.php is in /home/klajunas/domains/b2-config.php
// this file is /home/klajunas.lt/domains/foto.klajunas.lt/public_html/b2-gallery.php
    require_once __DIR__ . '/../../b2-config.php';

// Required constants
foreach (['B2_KEY_ID','B2_APP_KEY','B2_BUCKET','B2_BUCKET_ID'] as $k) {
    if (!defined($k) || (string)constant($k) === '') {
        json_fail("Missing constant {$k} in /home/klajunas.lt/domains/b2-config.php", 500);
    }
}

// ---- Authorize (cached ~23h) ----
$cacheFile = defined('B2_AUTH_CACHE_FILE')
    ? (string)B2_AUTH_CACHE_FILE
    : (sys_get_temp_dir() . '/b2_auth_cache.json');

$auth = null;
if (is_file($cacheFile)) {
    $auth = json_decode((string)file_get_contents($cacheFile), true);
    if (!is_array($auth) || (int)($auth['expires'] ?? 0) < time()) $auth = null;
}

if (!$auth) {
    $basic = base64_encode((string)B2_KEY_ID . ':' . (string)B2_APP_KEY);
    $ch = curl_init('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Basic {$basic}"],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        gallery_log("B2 authorize cURL error: {$err}");
        json_fail('Upstream unavailable', 502);
    }
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $a = json_decode($body, true);
    if (!is_array($a) || $http >= 400) {
        gallery_log("B2 authorize failed HTTP {$http}: " . substr((string)$body, 0, 300));
        json_fail('Upstream unavailable', 502);
    }

    $auth = [
        'apiUrl'      => (string)($a['apiUrl'] ?? ''),
        'downloadUrl' => (string)($a['downloadUrl'] ?? ''),
        'authToken'   => (string)($a['authorizationToken'] ?? ''),
        'expires'     => time() + 23 * 3600,
    ];
    file_put_contents($cacheFile, json_encode($auth, JSON_UNESCAPED_SLASHES));
}

$apiUrl      = (string)($auth['apiUrl'] ?? '');
$downloadUrl = (string)($auth['downloadUrl'] ?? '');
$authToken   = (string)($auth['authToken'] ?? '');
if ($apiUrl === '' || $downloadUrl === '' || $authToken === '') {
    json_fail('B2 authorize returned incomplete data', 500);
}

// ---- Input ----
$requestedPath = trim((string)($_GET['path'] ?? ''), "/ \t\n\r\0\x0B");
$path = manifest_resolve_public_path($requestedPath);
$storagePath = manifest_source_path_for_path($path);
$allowedPath = $storagePath !== '' ? $storagePath : $path;
if (!gallery_is_allowed_prefix($allowedPath)) {
    json_fail('Not found', 404, ['path' => $requestedPath]);
}
$prefix = ($storagePath === '') ? '' : ($storagePath . '/');

$limit = (int)($_GET['limit'] ?? 60);
if ($limit < 10) $limit = 10;
if ($limit > 200) $limit = 200;

$cursor = (string)($_GET['cursor'] ?? '');
$withCovers = (string)($_GET['withCovers'] ?? '0') === '1';

if ($path !== '' && $cursor === '') {
    // Log an access row only on the first view of this album today by this
    // visitor — the same per-day cookie dedup the view counter uses. Otherwise
    // the SPA's re-fetch on browser Back/Forward would inflate the Access log.
    $firstViewToday = gallery_record_album_view($path);
    if ($firstViewToday) gallery_log_access($path);
}

$listCacheKey = json_encode([
    'v' => 14,
    'path' => $path,
    'storagePath' => $storagePath,
    'limit' => $limit,
    'cursor' => $cursor,
    'withCovers' => $withCovers,
    'clientV' => (string)($_GET['v'] ?? ''),
    'views' => gallery_album_views_version(),
    'manifestVersion' => manifest_state_version($path),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (is_string($listCacheKey)) {
    $cachedList = gallery_read_cache('b2_list', $listCacheKey, $path === '' ? 300 : 30);
    if ($cachedList !== null) {
        header('X-Foto-Origin-Cache: HIT');
        echo $cachedList;
        exit;
    }
}

// Helpers
function thumb_url(string $fileName, int $w = 420): string {
    return 'img.php?file=' . rawurlencode($fileName) . '&w=' . $w . '&q=76&fmt=webp&v=7';
}
function view_url(string $fileName): string {
    return 'img.php?file=' . rawurlencode($fileName) . '&w=1400&q=83&fmt=webp&v=7';
}
function photo_download_allowed(string $fileName): bool {
    return function_exists('gallery_download_allowed_for_file') ? gallery_download_allowed_for_file($fileName) : true;
}
function download_url(string $fileName, bool $allowed = true): string {
    if (!$allowed) return '';
    return 'download.php?file=' . rawurlencode($fileName);
}
function meta_url(string $fileName): string {
    return 'meta.php?file=' . rawurlencode($fileName) . '&v=3';
}
function is_image(string $fileName): bool {
    return (bool)preg_match('~\.(jpe?g|png|webp|heic|heif)$~i', $fileName);
}
function is_derived_or_legacy_asset_path(string $fileName): bool {
    return str_contains($fileName, '/jpg-originals/') || str_contains($fileName, '/archive-originals/');
}
function is_displayable_original_path(string $fileName): bool {
    return (bool)preg_match('~\.(jpe?g|png|webp|gif|heic|heif)$~i', $fileName);
}
function manifest_db_original_file_name(array $row): string {
    $source = trim((string)($row['source_path'] ?? $row['sourcePath'] ?? ''), "/ \t\n\r\0\x0B");
    $key = trim((string)($row['b2_key'] ?? $row['fileName'] ?? ''), "/ \t\n\r\0\x0B");
    $stored = trim((string)($row['stored_filename'] ?? $row['storedFilename'] ?? ''), "/ \t\n\r\0\x0B");
    $original = trim((string)($row['original_filename'] ?? $row['originalFilename'] ?? ''), "/ \t\n\r\0\x0B");
    if ($source !== '' && $key !== '' && str_starts_with($key, $source.'/')) return $key;
    if ($source !== '' && $stored !== '') return $source.'/originals/'.$stored;
    if ($source !== '' && $original !== '') return $source.'/originals/'.$original;
    return $key;
}
function manifest_db_display_file_name(array $row): string {
    $compat = trim((string)($row['compatibility_b2_key'] ?? ''), "/ \t\n\r\0\x0B");
    if ($compat !== '') return $compat;
    $original = manifest_db_original_file_name($row);
    if (is_displayable_original_path($original)) return $original;
    $legacyDisplay = trim((string)($row['fileName'] ?? $row['b2_key'] ?? ''), "/ \t\n\r\0\x0B");
    $legacyOriginal = trim((string)($row['original_b2_key'] ?? ''), "/ \t\n\r\0\x0B");
    if ($legacyOriginal !== '' && is_displayable_original_path($legacyDisplay)) return $legacyDisplay;
    return '';
}
function manifest_db_legacy_original_file_name(array $row): string {
    $legacyOriginal = trim((string)($row['original_b2_key'] ?? ''), "/ \t\n\r\0\x0B");
    return $legacyOriginal !== '' ? $legacyOriginal : manifest_db_original_file_name($row);
}
function manifest_album_tags(int $albumId): array {
    $db = function_exists('gallery_db') ? gallery_db() : null;
    if (!$db || $albumId <= 0) return [];
    try {
        $q = $db->prepare("SELECT t.name FROM tags t JOIN album_tags at ON at.tag_id=t.id WHERE at.album_id=? ORDER BY t.name LIMIT 20");
        $q->execute([$albumId]);
        return array_values(array_filter(array_map('strval', $q->fetchAll(PDO::FETCH_COLUMN))));
    } catch (Throwable $e) {
        gallery_log($e);
        return [];
    }
}
function manifest_event_date_label(array $album): string {
    $start = trim((string)($album['event_date'] ?? ''));
    $end = trim((string)($album['event_date_end'] ?? ''));
    if ($start === '') return '';
    return $end !== '' && $end !== $start ? $start.' - '.$end : $start;
}
function manifest_album_rows(): array {
    $db = function_exists('gallery_db') ? gallery_db() : null;
    if (!$db) return [];
    try {
        $urlCols = gallery_db_column_exists($db, 'albums', 'dbsportas_url') ? 'dbsportas_url,klajunas_url,other_url' : "NULL AS dbsportas_url,NULL AS klajunas_url,NULL AS other_url";
        $albums = $db->query("SELECT id,title,subtitle,description,source_path,slug,cover_photo_id,cover_mode,event_date,event_date_end,location_name,author_name,copyright_text,sort_order,$urlCols FROM albums WHERE visibility='published' ORDER BY COALESCE(event_date,'0000-00-00') DESC,sort_order ASC,id DESC")->fetchAll();
    } catch (Throwable $e) {
        try {
            $albums = $db->query("SELECT id,title,source_path,slug,cover_photo_id,'auto' AS cover_mode FROM albums WHERE visibility='published' ORDER BY id DESC")->fetchAll();
        } catch (Throwable $fallback) {
            gallery_log($fallback);
            return [];
        }
    }
    $out = [];
    foreach ($albums as $album) {
        $path = trim((string)($album['slug'] ?: $album['source_path'] ?? ''), "/ \t\n\r\0\x0B");
        if ($path === '') continue;
        $count = 0;
        $cover = '';
        try {
            $q = $db->prepare("SELECT COUNT(*) FROM photos WHERE album_id=? AND visibility='published' AND is_missing=0");
            $q->execute([(int)$album['id']]);
            $count = (int)$q->fetchColumn();
            $coverMode = (string)($album['cover_mode'] ?? 'auto');
            $coverId = (int)($album['cover_photo_id'] ?? 0);
            if ($coverMode === 'none') {
                $cover = '';
            } elseif ($coverMode === 'manual' && $coverId > 0) {
                $compatSelect = gallery_db_column_exists($db, 'photos', 'compatibility_b2_key') ? 'p.compatibility_b2_key' : 'NULL AS compatibility_b2_key';
                $originalB2Select = gallery_db_column_exists($db, 'photos', 'original_b2_key') ? 'p.original_b2_key' : 'NULL AS original_b2_key';
                $q = $db->prepare("SELECT p.b2_key,p.b2_key AS fileName,$compatSelect,$originalB2Select,p.stored_filename,p.original_filename,a.source_path FROM photos p JOIN albums a ON a.id=p.album_id WHERE p.id=? AND p.album_id=? AND p.visibility='published' AND p.is_missing=0 LIMIT 1");
                $q->execute([$coverId, (int)$album['id']]);
                $coverRow = $q->fetch(PDO::FETCH_ASSOC) ?: [];
                $cover = manifest_db_display_file_name($coverRow);
            } else {
                $compatSelect = gallery_db_column_exists($db, 'photos', 'compatibility_b2_key') ? 'p.compatibility_b2_key' : 'NULL AS compatibility_b2_key';
                $originalB2Select = gallery_db_column_exists($db, 'photos', 'original_b2_key') ? 'p.original_b2_key' : 'NULL AS original_b2_key';
                $q = $db->prepare("SELECT p.b2_key,p.b2_key AS fileName,$compatSelect,$originalB2Select,p.stored_filename,p.original_filename,a.source_path FROM photos p JOIN albums a ON a.id=p.album_id WHERE p.album_id=? AND p.visibility='published' AND p.is_missing=0 ORDER BY CASE WHEN p.is_cover_candidate=1 THEN 0 ELSE 1 END ASC, CASE WHEN p.taken_at IS NULL THEN 1 ELSE 0 END ASC, p.taken_at ASC, p.sort_order ASC, p.id ASC LIMIT 1");
                $q->execute([(int)$album['id']]);
                $coverRow = $q->fetch(PDO::FETCH_ASSOC) ?: [];
                $cover = manifest_db_display_file_name($coverRow);
            }
        } catch (Throwable $e) {
            gallery_log($e);
        }
        $out[] = [
            'type' => 'folder',
            'name' => (string)$album['title'],
            'path' => $path,
            'sourcePath' => trim((string)($album['source_path'] ?? ''), "/ \t\n\r\0\x0B"),
            'count' => $count,
            'subtitle' => (string)($album['subtitle'] ?? ''),
            'description' => (string)($album['description'] ?? ''),
            'eventPlace' => (string)($album['location_name'] ?? ''),
            'eventDate' => (string)($album['event_date'] ?? ''),
            'eventDateEnd' => (string)($album['event_date_end'] ?? ''),
            'eventDateLabel' => manifest_event_date_label($album),
            'tags' => manifest_album_tags((int)$album['id']),
            'authorName' => (string)($album['author_name'] ?? ''),
            'copyrightText' => (string)($album['copyright_text'] ?? ''),
            'coverFile' => $cover,
            'sortOrder' => isset($album['sort_order']) ? (int)$album['sort_order'] : null,
            'dbsportasUrl' => trim((string)($album['dbsportas_url'] ?? '')),
            'klajunasUrl' => trim((string)($album['klajunas_url'] ?? '')),
            'otherUrl' => trim((string)($album['other_url'] ?? '')),
        ];
    }
    return $out;
}
function manifest_album_title_for_path(string $path): string {
    $db = function_exists('gallery_db') ? gallery_db() : null;
    if (!$db || $path === '') return '';
    try {
        $q = $db->prepare("SELECT title FROM albums WHERE visibility='published' AND (slug=? OR source_path=?) LIMIT 1");
        $q->execute([$path, $path]);
        return (string)($q->fetchColumn() ?: '');
    } catch (Throwable $e) {
        gallery_log($e);
        return '';
    }
}
function manifest_album_meta_for_path(string $path): ?array {
    if ($path === '') return null;
    $needle = trim($path, "/ \t\n\r\0\x0B");
    foreach (manifest_album_rows() as $album) {
        $albumPath = trim((string)($album['path'] ?? ''), "/ \t\n\r\0\x0B");
        $sourcePath = trim((string)($album['sourcePath'] ?? ''), "/ \t\n\r\0\x0B");
        if ($albumPath === $needle || $sourcePath === $needle) {
            unset($album['coverFile']);
            return $album;
        }
    }
    return null;
}
function manifest_album_photo_view_totals(): array {
    $db = function_exists('gallery_db') ? gallery_db() : null;
    if (!$db) return [];
    try {
        $rows = $db->query(
            "SELECT a.slug,a.source_path,p.photo_views,p.metadata_json
             FROM albums a
             JOIN photos p ON p.album_id=a.id
             WHERE a.visibility='published'
               AND p.visibility='published'
               AND p.is_missing=0"
        )->fetchAll();
    } catch (Throwable $e) {
        gallery_log($e);
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $views = $row['photo_views'] ?? null;
        if (($views === null || $views === '') && !empty($row['metadata_json'])) {
            $metadata = json_decode((string)$row['metadata_json'], true);
            if (is_array($metadata)) $views = $metadata['imageViews'] ?? ($metadata['photoViews'] ?? null);
        }
        if ($views === null || $views === '' || !is_numeric($views)) continue;
        $value = (int)$views;
        foreach (['slug','source_path'] as $key) {
            $path = trim((string)($row[$key] ?? ''), "/ \t\n\r\0\x0B");
            if ($path === '') continue;
            $out[$path] = ($out[$path] ?? 0) + $value;
        }
    }
    return $out;
}
function manifest_state_version(string $path): string {
    $db = function_exists('gallery_db') ? gallery_db() : null;
    if (!$db) return '0';
    try {
        if ($path === '') {
            $q = $db->query(
                "SELECT CONCAT(
                    GREATEST(
                    COALESCE(MAX(UNIX_TIMESTAMP(a.updated_at)), 0),
                    COALESCE(MAX(UNIX_TIMESTAMP(p.updated_at)), 0)
                    ),
                    '-',
                    COALESCE(CRC32(GROUP_CONCAT(CONCAT(p.id, ':', p.sort_order, ':', p.b2_key, ':', COALESCE(p.photo_views, '')) ORDER BY p.album_id ASC, p.sort_order ASC, p.id ASC SEPARATOR '|')), 0),
                    '-',
                    (SELECT COALESCE(CRC32(GROUP_CONCAT(CONCAT_WS(':', a2.id, a2.title, COALESCE(a2.subtitle,''), COALESCE(a2.description,''), COALESCE(a2.event_date,''), COALESCE(a2.event_date_end,''), COALESCE(a2.location_name,''), a2.sort_order, COALESCE(a2.cover_photo_id,0), COALESCE(a2.cover_mode,''), COALESCE(a2.author_name,''), COALESCE(a2.copyright_text,''), COALESCE(a2.dbsportas_url,''), COALESCE(a2.klajunas_url,''), COALESCE(a2.other_url,'')) ORDER BY a2.id SEPARATOR '|')), 0) FROM albums a2 WHERE a2.visibility='published')
                 ) AS v
                 FROM albums a
                 LEFT JOIN photos p ON p.album_id = a.id
                 WHERE a.visibility='published'"
            );
            return (string)($q->fetchColumn() ?: '0');
        }

        $q = $db->prepare(
            "SELECT CONCAT(
                GREATEST(
                COALESCE(MAX(UNIX_TIMESTAMP(a.updated_at)), 0),
                COALESCE(MAX(UNIX_TIMESTAMP(p.updated_at)), 0)
                ),
                '-',
                COALESCE(CRC32(GROUP_CONCAT(CONCAT(p.id, ':', p.sort_order, ':', p.b2_key, ':', COALESCE(p.photo_views, '')) ORDER BY p.sort_order ASC, p.id ASC SEPARATOR '|')), 0),
                '-',
                COALESCE(CRC32(MIN(CONCAT_WS(':', a.id, a.title, COALESCE(a.subtitle,''), COALESCE(a.event_date,''), COALESCE(a.event_date_end,''), COALESCE(a.location_name,''), a.sort_order, COALESCE(a.cover_photo_id,0), COALESCE(a.cover_mode,''), COALESCE(a.author_name,''), COALESCE(a.copyright_text,'')))), 0)
             ) AS v
             FROM albums a
             LEFT JOIN photos p ON p.album_id = a.id
             WHERE a.visibility='published' AND (a.slug=? OR a.source_path=?)"
        );
        $q->execute([$path, $path]);
        return (string)($q->fetchColumn() ?: '0');
    } catch (Throwable $e) {
        gallery_log($e);
        return '0';
    }
}
function manifest_photos_for_path(string $path): array {
    $db = function_exists('gallery_db') ? gallery_db() : null;
    if (!$db || $path === '') return [];
    try {
        $compatSelect = gallery_db_column_exists($db, 'photos', 'compatibility_b2_key') ? 'p.compatibility_b2_key' : 'NULL AS compatibility_b2_key';
        $originalB2Select = gallery_db_column_exists($db, 'photos', 'original_b2_key') ? 'p.original_b2_key' : 'NULL AS original_b2_key';
        $q = $db->prepare("SELECT p.b2_key fileName,p.b2_key,$compatSelect,$originalB2Select,p.stored_filename,p.original_filename,p.file_size contentLength,p.title,p.description,p.photo_views,p.taken_at,p.width,p.height,p.metadata_json,p.camera_make,p.camera_model,p.lens_model,p.focal_length,p.aperture,p.shutter_speed,p.iso_value,a.source_path,a.event_date,a.event_date_end,a.location_name, a.download_enabled, a.visibility AS album_visibility, p.visibility AS photo_visibility, p.is_downloadable, p.is_missing FROM photos p JOIN albums a ON a.id=p.album_id WHERE a.visibility='published' AND p.visibility='published' AND p.is_missing=0 AND (a.slug=? OR a.source_path=?) ORDER BY p.sort_order ASC,p.id ASC");
        $q->execute([$path, $path]);
        $rows = $q->fetchAll();
        foreach ($rows as &$row) {
            $row['originalFileName'] = manifest_db_legacy_original_file_name($row);
            $row['displayFileName'] = manifest_db_display_file_name($row);
            $row['fileName'] = $row['displayFileName'] !== '' ? $row['displayFileName'] : $row['originalFileName'];
        }
        unset($row);
        return $rows;
    } catch (Throwable $e) {
        gallery_log($e);
        return [];
    }
}
function manifest_json_time($node): ?string {
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
function manifest_has_camera_exif(array $photo): bool {
    foreach (['camera_make','camera_model','lens_model','focal_length','aperture','shutter_speed','iso_value'] as $key) {
        if (($photo[$key] ?? null) !== null && (string)$photo[$key] !== '') return true;
    }
    return false;
}
function manifest_is_web_upload_without_camera(array $metadata, array $photo): bool {
    if (manifest_has_camera_exif($photo)) return false;
    return isset($metadata['googlePhotosOrigin']['webUpload']) && is_array($metadata['googlePhotosOrigin']['webUpload']);
}
function manifest_taken_matches_album_date(string $taken, array $photo): bool {
    $start = trim((string)($photo['event_date'] ?? ''));
    if ($start === '') return true;
    $end = trim((string)($photo['event_date_end'] ?? ''));
    if ($end === '') $end = $start;
    $date = substr($taken, 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
    return $date >= $start && $date <= $end;
}
function manifest_photo_meta(array $photo, int $contentLength): array {
    $metadata = [];
    if (!empty($photo['metadata_json'])) {
        $decoded = json_decode((string)$photo['metadata_json'], true);
        if (is_array($decoded)) $metadata = $decoded;
    }
    $views = $photo['photo_views'] ?? ($metadata['imageViews'] ?? ($metadata['photoViews'] ?? null));
    $title = trim((string)($photo['title'] ?? ''));
    if ($title === '') $title = basename((string)($photo['fileName'] ?? $photo['original_filename'] ?? ''));
    $image = ['bytes' => $contentLength];
    if (!empty($photo['width'])) $image['width'] = (int)$photo['width'];
    if (!empty($photo['height'])) $image['height'] = (int)$photo['height'];
    if (!empty($image['width']) && !empty($image['height'])) $image['megapixels'] = round(((int)$image['width'] * (int)$image['height']) / 1000000, 1);
    $exif = [];
    $jsonTaken = manifest_json_time($metadata['photoTakenTime'] ?? null);
    if ($jsonTaken && !manifest_is_web_upload_without_camera($metadata, $photo)) {
        $exif['dateTaken'] = $jsonTaken;
    } elseif (!empty($photo['taken_at']) && manifest_has_camera_exif($photo)) {
        $exif['dateTaken'] = (string)$photo['taken_at'];
    }
    if (!empty($exif['dateTaken']) && !manifest_taken_matches_album_date((string)$exif['dateTaken'], $photo)) {
        unset($exif['dateTaken']);
    }
    if (!empty($photo['camera_make'])) $exif['cameraMake'] = (string)$photo['camera_make'];
    if (!empty($photo['camera_model'])) $exif['cameraModel'] = (string)$photo['camera_model'];
    if (!empty($photo['lens_model'])) $exif['lensModel'] = (string)$photo['lens_model'];
    if (!empty($photo['aperture'])) $exif['fNumber'] = (string)$photo['aperture'];
    if (!empty($photo['shutter_speed'])) $exif['exposureTime'] = (string)$photo['shutter_speed'];
    if (!empty($photo['iso_value'])) $exif['iso'] = (string)$photo['iso_value'];
    if (!empty($photo['focal_length'])) $exif['focalLength'] = (string)$photo['focal_length'];
    $out = [
        'exif' => $exif,
        'image' => $image,
        'title' => $title,
        'description' => (string)($photo['description'] ?? ''),
        'imageViews' => ($views !== null && $views !== '') ? (int)$views : null,
        'eventDate' => (string)($photo['event_date'] ?? ''),
        'eventDateEnd' => (string)($photo['event_date_end'] ?? ''),
        'eventDateLabel' => manifest_event_date_label([
            'event_date' => $photo['event_date'] ?? '',
            'event_date_end' => $photo['event_date_end'] ?? '',
        ]),
        'albumEventDate' => (string)($photo['event_date'] ?? ''),
        'albumEventDateEnd' => (string)($photo['event_date_end'] ?? ''),
        'eventPlace' => (string)($photo['location_name'] ?? ''),
    ];
    if ($metadata) $out['metadata'] = $metadata;
    return $out;
}

$albumViewTotals = gallery_album_views_totals();
$albumPhotoViewTotals = manifest_album_photo_view_totals();

// ---- Fetch page ----
// The root listing counts each album's photos by walking this file list, so an
// album whose files fall past the end of it silently disappears from the public
// gallery. With 1000 per request and a guard of 10 the walk stopped at 10 000
// files — the bucket holds more than twice that, so everything alphabetically
// after roughly 2013 was dropped. B2 allows 10 000 per request, so the whole
// bucket now arrives in fewer requests than before, not more.
$files = [];
$b2Cursor = '';
$guard = 0;
do {
    $post = [
        'bucketId'      => (string)B2_BUCKET_ID,
        'prefix'        => $prefix,
        'maxFileCount'  => 10000,
    ];
    if ($b2Cursor !== '') $post['startFileName'] = $b2Cursor;

    $data = curl_json_request(
        $apiUrl . '/b2api/v2/b2_list_file_names',
        ["Authorization: {$authToken}", "Content-Type: application/json"],
        $post
    );

    $pageFiles = $data['files'] ?? [];
    if (is_array($pageFiles)) $files = array_merge($files, $pageFiles);
    $b2Cursor = (string)($data['nextFileName'] ?? '');
    $guard++;
} while ($b2Cursor !== '' && $guard < 40);

$nextCursor = '';

// ---- Derive immediate children ----
$folders = []; // folderName => ['name','path','count','coverFile']
$photos  = [];

foreach ($files as $f) {
    $fileName = (string)($f['fileName'] ?? '');
    if ($fileName === '') continue;

    if ($prefix !== '' && strncmp($fileName, $prefix, strlen($prefix)) !== 0) continue;

    $rest = ($prefix === '') ? $fileName : substr($fileName, strlen($prefix));
    $slashPos = strpos($rest, '/');

    if ($slashPos !== false) {
        $folderName = substr($rest, 0, $slashPos);
        if ($folderName === '') continue;

        $folderPath = ($path === '') ? $folderName : ($path . '/' . $folderName);
        if (!gallery_is_allowed_prefix($folderPath)) continue;
        if (!isset($folders[$folderName])) {
            $folders[$folderName] = [
                'type' => 'folder',
                'name' => $folderName,
                'path' => $folderPath,
                'count' => 0,
                'coverFile' => '',
            ];
        }

        if (!is_derived_or_legacy_asset_path($fileName) && is_image($fileName)) {
            $folders[$folderName]['count']++;
            if ($withCovers && $folders[$folderName]['coverFile'] === '') {
                $folders[$folderName]['coverFile'] = $fileName;
            }
        }
        continue;
    }

    if (!is_derived_or_legacy_asset_path($fileName) && is_image($fileName) && gallery_is_allowed_prefix($fileName)) {
        $photos[] = [
            'fileName' => $fileName,
            'contentLength' => isset($f['contentLength']) ? (int)$f['contentLength'] : 0,
            'downloadAllowed' => photo_download_allowed($fileName),
        ];
    }
}

uasort($folders, fn($a, $b) => strnatcasecmp((string)$a['name'], (string)$b['name']));
usort($photos, fn($a, $b) => strnatcasecmp(basename((string)$a['fileName']), basename((string)$b['fileName'])));

// Merge DB manifest content into the public listing. B2 remains the file source;
// the DB controls curated visibility, naming, cover choice, and manual order.
if ($path === '') {
    // The public root is the curated DB album list. Do not expose technical B2
    // root prefixes such as "albums/" as standalone public albums.
    $folders = [];
    $photos = [];
    foreach (manifest_album_rows() as $album) {
        $key = (string)$album['path'];
        $sourcePath = trim((string)($album['sourcePath'] ?? ''), "/ \t\n\r\0\x0B");
        $realPhotoCount = 0;
        $realCoverFile = '';
        if ($sourcePath !== '') {
            $sourcePrefix = $sourcePath . '/';
            foreach ($files as $file) {
                $fileName = (string)($file['fileName'] ?? '');
                if ($fileName === '' || is_derived_or_legacy_asset_path($fileName) || !is_image($fileName)) continue;
                if (strncmp($fileName, $sourcePrefix, strlen($sourcePrefix)) !== 0) continue;
                $realPhotoCount++;
                if ($realCoverFile === '') $realCoverFile = $fileName;
            }
        }
        if ($realPhotoCount === 0) continue;
        $album['count'] = $realPhotoCount;
        $folderName = basename($key);
        $sourceFolderName = basename((string)($album['sourcePath'] ?? ''));
        if ($sourceFolderName !== '' && $sourceFolderName !== $folderName && isset($folders[$sourceFolderName])) {
            unset($folders[$sourceFolderName]);
        }
        $coverFile = (string)($album['coverFile'] ?? '');
        if ($coverFile === '' || strncmp($coverFile, $sourcePath . '/', strlen($sourcePath) + 1) !== 0) {
            $coverFile = $realCoverFile;
            $album['coverFile'] = $realCoverFile;
        }
        $coverSlash = strrpos($coverFile, '/');
        $coverFolderName = $coverSlash === false ? '' : substr($coverFile, 0, $coverSlash);
        if ($coverFolderName !== '' && $coverFolderName !== $folderName && isset($folders[$coverFolderName])) {
            unset($folders[$coverFolderName]);
        }
        if (isset($folders[$folderName])) {
            $folders[$folderName]['name'] = $album['name'];
            $folders[$folderName]['path'] = $key;
            $folders[$folderName]['count'] = max((int)$folders[$folderName]['count'], (int)$album['count']);
            foreach (['subtitle','description','eventPlace','eventDate','eventDateEnd','eventDateLabel','tags','authorName','copyrightText','sourcePath','sortOrder','dbsportasUrl','klajunasUrl','otherUrl'] as $albumField) {
                if (array_key_exists($albumField, $album)) $folders[$folderName][$albumField] = $album[$albumField];
            }
            if ($withCovers && (string)$album['coverFile'] !== '') $folders[$folderName]['coverFile'] = (string)$album['coverFile'];
        } else {
            $folders[$folderName] = $album;
        }
    }
    uasort($folders, function($a, $b) {
        $ad = trim((string)($a['eventDate'] ?? ''));
        $bd = trim((string)($b['eventDate'] ?? ''));
        if ($ad !== '' || $bd !== '') {
            $ak = $ad !== '' ? $ad : '0000-00-00';
            $bk = $bd !== '' ? $bd : '0000-00-00';
            $cmp = strcmp($bk, $ak);
            if ($cmp !== 0) return $cmp;
        }
        $ao = $a['sortOrder'] ?? null;
        $bo = $b['sortOrder'] ?? null;
        if ($ao !== null && $bo !== null && (int)$ao !== (int)$bo) return (int)$ao <=> (int)$bo;
        return strnatcasecmp((string)$a['name'], (string)$b['name']);
    });
} else {
    $seenPhotos = [];
    $seenB2Keys = [];
    $seenB2Info = [];
    $b2PhotosByName = [];
    foreach ($files as $file) {
        $fileName = (string)($file['fileName'] ?? '');
        if ($fileName === '') continue;
        $seenB2Keys[$fileName] = true;
        $seenB2Info[$fileName] = $file;
        if (is_derived_or_legacy_asset_path($fileName) || !is_image($fileName)) continue;
        if ($prefix !== '' && strncmp($fileName, $prefix, strlen($prefix)) !== 0) continue;
        $seenPhotos[$fileName] = true;
        $b2PhotosByName[$fileName] = [
            'fileName' => $fileName,
            'contentLength' => isset($file['contentLength']) ? (int)$file['contentLength'] : 0,
        ];
    }
        $manifestPhotos = manifest_photos_for_path($path);
    if ($manifestPhotos) {
        $folders = [];
        $photos = [];
        foreach ($manifestPhotos as $photo) {
            $fileName = (string)($photo['fileName'] ?? '');
            $originalFileName = (string)($photo['originalFileName'] ?? manifest_db_legacy_original_file_name($photo));
            $displayFileName = (string)($photo['displayFileName'] ?? '');
            if ($displayFileName !== '' && !isset($seenB2Keys[$displayFileName])) $displayFileName = '';
            if ($originalFileName === '' || !is_image($originalFileName)) continue;
            if (!isset($seenB2Keys[$originalFileName])) continue;
            $downloadAllowed = true;
            if (array_key_exists('download_enabled', $photo) || array_key_exists('is_downloadable', $photo) || array_key_exists('album_visibility', $photo) || array_key_exists('photo_visibility', $photo) || array_key_exists('is_missing', $photo)) {
                $downloadAllowed = (int)($photo['download_enabled'] ?? 0) === 1
                    && (string)($photo['album_visibility'] ?? '') === 'published'
                    && (string)($photo['photo_visibility'] ?? '') === 'published'
                    && (int)($photo['is_downloadable'] ?? 0) === 1
                    && (int)($photo['is_missing'] ?? 0) === 0;
            }
            $contentLength = isset($seenB2Info[$originalFileName]['contentLength']) ? (int)$seenB2Info[$originalFileName]['contentLength'] : (int)($photo['contentLength'] ?? 0);
            $photos[] = [
                'fileName' => $displayFileName !== '' ? $displayFileName : $originalFileName,
                'originalFileName' => $originalFileName,
                'compatibilityFileName' => trim((string)($photo['compatibility_b2_key'] ?? ''), "/ \t\n\r\0\x0B"),
                'hasDisplayPreview' => $displayFileName !== '',
                'contentLength' => $contentLength,
                'downloadAllowed' => $downloadAllowed,
                'dbMeta' => manifest_photo_meta($photo, $contentLength),
            ];
        }
    } elseif (manifest_album_meta_for_path($path)) {
        $folders = [];
        $photos = [];
    }
}

// ---- Build items ----
$items = [];
$albumMeta = $path === '' ? null : manifest_album_meta_for_path($path);
$albumTitle = is_array($albumMeta) && trim((string)($albumMeta['name'] ?? '')) !== ''
    ? (string)$albumMeta['name']
    : ($path === '' ? '' : manifest_album_title_for_path($path));
if (is_array($albumMeta)) {
    $albumMeta['count'] = count($photos);
    $albumMeta['siteViewCount'] = (int)($albumViewTotals[$path] ?? 0);
    $albumMeta['imageViewCount'] = (int)($albumPhotoViewTotals[$path] ?? 0);
    $albumMeta['viewCount'] = max($albumMeta['siteViewCount'], $albumMeta['imageViewCount']);
}

foreach ($folders as $fo) {
    $it = [
        'type' => 'folder',
        'name' => $fo['name'],
        'path' => $fo['path'],
        'count' => $fo['count'],
        'siteViewCount' => (int)($albumViewTotals[$fo['path']] ?? 0),
        'imageViewCount' => (int)($albumPhotoViewTotals[$fo['path']] ?? 0),
    ];
    $it['viewCount'] = max($it['siteViewCount'], $it['imageViewCount']);
    foreach (['subtitle','description','eventPlace','eventDate','eventDateEnd','eventDateLabel','tags','authorName','copyrightText','sortOrder','dbsportasUrl','klajunasUrl','otherUrl'] as $albumField) {
        if (isset($fo[$albumField]) && $fo[$albumField] !== '' && $fo[$albumField] !== []) $it[$albumField] = $fo[$albumField];
    }
    if ($withCovers && $fo['coverFile'] !== '') {
        $it['coverThumbUrl'] = thumb_url($fo['coverFile'], 420);
        $it['coverViewUrl']  = view_url($fo['coverFile']);
    }
    $items[] = $it;
}

foreach ($photos as $photo) {
    $fileName = (string)$photo['fileName'];
    $originalFileName = trim((string)($photo['originalFileName'] ?? $fileName), "/ \t\n\r\0\x0B");
    $compatibilityFileName = trim((string)($photo['compatibilityFileName'] ?? ''), "/ \t\n\r\0\x0B");
    $hasDisplayPreview = (bool)($photo['hasDisplayPreview'] ?? ($fileName !== '' && is_displayable_original_path($fileName)));
    $downloadAllowed = array_key_exists('downloadAllowed', $photo)
        ? (bool)$photo['downloadAllowed']
        : photo_download_allowed($originalFileName);
    $downloadFile = $originalFileName !== '' ? $originalFileName : $fileName;
    $item = [
        'type' => 'photo',
        'name' => basename($downloadFile),
        'path' => $downloadFile,
        'displayPath' => $hasDisplayPreview ? $fileName : '',
        'compatibilityPath' => $compatibilityFileName,
        'hasCompatibilityPreview' => $hasDisplayPreview,
        'previewMissing' => !$hasDisplayPreview,
        'previewStatus' => $hasDisplayPreview ? '' : HEIC_MISSING_DISPLAY_MESSAGE,
        'originalSize' => (int)($photo['contentLength'] ?? 0),
        'thumbUrl' => $hasDisplayPreview ? thumb_url($fileName, 420) : '',
        'viewUrl' => $hasDisplayPreview ? view_url($fileName) : '',
        'downloadAllowed' => $downloadAllowed,
        'downloadUrl' => download_url($downloadFile, $downloadAllowed),
        'compatibilityDownloadUrl' => $compatibilityFileName !== '' ? download_url($compatibilityFileName, $downloadAllowed) : '',
        'metaUrl' => meta_url($downloadFile),
    ];
    if (!empty($photo['dbMeta']) && is_array($photo['dbMeta'])) $item['meta'] = $photo['dbMeta'];
    $items[] = $item;
}

if ($cursor !== '') {
    $cursorIndex = null;
    foreach ($items as $index => $item) {
        if (($item['path'] ?? '') === $cursor) {
            $cursorIndex = $index;
            break;
        }
    }
    if ($cursorIndex !== null) {
        $items = array_slice($items, $cursorIndex);
    }
}

// Enforce limit (for infinite scroll). B2's nextFileName only exists when the
// upstream page is exhausted, so preserve a cursor for locally-sliced photo
// results too. startFileName is inclusive, so use the first excluded photo.
if (count($items) > $limit) {
    $nextItem = $items[$limit] ?? null;
    if (is_array($nextItem) && isset($nextItem['path'])) {
        $nextCursor = (string)$nextItem['path'];
    }
    $items = array_slice($items, 0, $limit);
}

$response = json_encode([
    'ok' => true,
    'path' => $path,
    'albumTitle' => $albumTitle,
    'albumMeta' => $albumMeta,
    'items' => $items,
    'folders' => array_values(array_map(fn($x) => $x['path'], $folders)), // compat
    'photos'  => array_values(array_map(fn($x) => $x['fileName'], $photos)), // compat
    'totalPhotos' => count($photos),
    'nextCursor' => $nextCursor,
], JSON_UNESCAPED_SLASHES);
if (!is_string($response)) {
    json_fail('Internal server error', 500);
}
if (isset($listCacheKey) && is_string($listCacheKey)) {
    gallery_write_cache('b2_list', $listCacheKey, $response);
}
header('X-Foto-Origin-Cache: MISS');
echo $response;
exit;
