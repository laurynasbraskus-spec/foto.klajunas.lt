<?php
declare(strict_types=1);

/**
 * img.php (THUMBNAIL-ONLY, STREAMING DOWNLOAD, SAFE FOR HUGE FILES)
 *
 * Goals:
 * - NEVER serve originals (always re-encode to JPEG, strip metadata).
 * - Stream download to disk (do NOT hold original in PHP memory).
 * - Avoid GD OOM: estimate required RAM, and if too big -> generate placeholder thumb (cached).
 * - Strong caching for thumbnails (Cloudflare-friendly).
 *
 * Usage:
 *   /img.php?file=PATH/TO/IMAGE.JPG&w=420
 *   /img.php?file=PATH/TO/IMAGE.JPG&w=420&h=420&fit=cover
 *
 * Notes:
 * - JS must use encodeURIComponent(filePath).
 * - Original download link should be separate (NOT here).
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
@set_time_limit(30);
require_once __DIR__ . '/gallery-security.php';
gallery_security_headers();
gallery_require_get();
gallery_rate_limit('img', 300, 60);

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function respond_text(string $msg, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
}

/**
 * Cache-Control is gallery_enforce_file_visibility(): nepaskelbta nuotrauka
 * admin'ui arba nezinomas matomumas (DB nepasiekiama) viesai nekesuojami.
 * null - iprastas viesas metu kesas.
 */
function image_cache_override(?string $set = null): ?string {
    static $override = null;
    if ($set !== null) $override = $set;
    return $override;
}

function send_image_headers(string $mime, int $maxAge = 31536000): void {
    header('Content-Type: ' . $mime);
    $override = image_cache_override();
    header('Cache-Control: ' . ($override ?? 'public, max-age=' . $maxAge . ', immutable'));
    header('Vary: Accept-Encoding');
}

function cache_dir(): string {
    $base = dirname(__DIR__) . '/cache';
    $dir = $base . '/b2_img_cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

/**
 * Atlaisvinam ir PASALINAM uzrakto faila.
 *
 * Anksciau .lock likdavo amzinai - po viena beveik kiekvienai kada nors
 * sugeneruotai miniatiurai. 2026-09-02 ju buvo susikaupe 6497. Trinam dar
 * laikydami uzrakta, kad tuo tarpu niekas nespetu jo atsidaryti is naujo.
 * Blogiausiu atveju dvi lygiagrecios uzklausos sugeneruos ta pacia miniatiura -
 * tai nekenksminga, nes rezultatas idedamas atominiu rename().
 */
function release_thumb_lock($lock, string $lockPath): void {
    @unlink($lockPath);
    @flock($lock, LOCK_UN);
    @fclose($lock);
}

/**
 * Isvalo pamestus .lock failus. Reikalingas ne tik del praeities palikimo:
 * procesui nukritus (timeout, OOM) uzraktas lieka ir be sio valymo.
 */
function prune_stale_locks(string $dir, int $maxAgeSeconds): void {
    if (!is_dir($dir)) return;
    $now = time();
    foreach ((glob($dir . '/*.lock') ?: []) as $f) {
        $m = @filemtime($f);
        if ($m !== false && ($now - $m) > $maxAgeSeconds) @unlink($f);
    }
}

function output_extension(string $fmt): string {
    if ($fmt === 'gif') return 'gif';
    return $fmt === 'webp' ? 'webp' : 'jpg';
}

function output_mime(string $fmt): string {
    if ($fmt === 'gif') return 'image/gif';
    return $fmt === 'webp' ? 'image/webp' : 'image/jpeg';
}

function supports_webp(): bool {
    if (extension_loaded('imagick') && class_exists('Imagick')) {
        try {
            $probe = new Imagick();
            $formats = $probe->queryFormats('WEBP');
            if (is_array($formats) && $formats) {
                return true;
            }
        } catch (Throwable $e) {
        }
    }

    return function_exists('imagewebp');
}

function imagick_supports_format(string $format): bool {
    if (!extension_loaded('imagick') || !class_exists('Imagick')) return false;
    try {
        $probe = new Imagick();
        $formats = $probe->queryFormats(strtoupper($format));
        return is_array($formats) && $formats !== [];
    } catch (Throwable $e) {
        return false;
    }
}

function is_heic_file(string $file): bool {
    return (bool)preg_match('~\.(heic|heif)$~i', $file);
}

function heic_preview_supported(): bool {
    return imagick_supports_format('HEIC') || imagick_supports_format('HEIF');
}
function resolve_output_format(string $requested): string {
    $requested = strtolower(trim($requested));
    if ($requested === 'webp' && supports_webp()) {
        return 'webp';
    }
    return 'jpeg';
}

function thumb_cache_path(string $bucket, string $file, int $w, int $h, string $fit, int $q, string $fmt): string {
    $cacheVersion = is_heic_file($file) ? 'heic-imagick-v1' : 'v1';
    $key = hash('sha256', $cacheVersion . '|' . $bucket . '|' . $file . "|w={$w}|h={$h}|fit={$fit}|q={$q}|fmt={$fmt}");
    return cache_dir() . "/{$key}." . output_extension($fmt);
}

function normalize_size(int $value): int {
    if ($value <= 0) return 0;
    if ($value <= 128) return 128;
    if ($value <= 420) return 420;
    return 1400;
}

function normalize_quality(int $value): int {
    if ($value <= 45) return 45;
    if ($value <= 76) return 76;
    return 83;
}

function output_file(string $path, string $mime): void {
    $size = filesize($path);
    $mtime = filemtime($path) ?: time();
    $etag = '"' . sha1($path . '|' . $size . '|' . $mtime) . '"';

    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }

    send_image_headers($mime, 31536000);
    header('Content-Length: ' . (string)$size);
    readfile($path);
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

/**
 * Nuotraukos B2 raktas pagal jos ID.
 *
 * Kelias saugykloje kinta: albumas pervadinamas arba perkeliamas į kanoninį
 * kelią, ir tada `?file=albums/2013/…` nuoroda miršta. Nuotraukos ID nekinta,
 * todėl straipsniuose ir skelbimuose dedam `?p=<ID>`, o tikrąjį kelią imam čia
 * iš DB — lygiai kaip /a/id<N> pasiima dabartinį albumo slug'ą.
 *
 * Rodom tik tai, ką rodo ir vieša galerija: paskelbtas albumas, paskelbta
 * nuotrauka, failas vietoje. Be šio filtro ID būtų galima persirinkti iš eilės
 * ir taip pasiekti juodraščius. (Kelias irgi atspėjamas — ?file= tikrinamas
 * per gallery_enforce_file_visibility().)
 */
function normalize_rotation(int $rot): int {
    $rot = (($rot % 360) + 360) % 360;
    return in_array($rot, [90, 180, 270], true) ? $rot : 0;
}
/** Pastoviam ?p=ID adresui kampas imamas is DB (tik generuojant miniatiura). */
function photo_rotation_by_id(int $id): int {
    $cfg = __DIR__ . '/../../foto-db-config.php';
    if (!is_file($cfg)) return 0;
    require_once $cfg;
    try {
        $pdo = new PDO('mysql:host=' . GALLERY_DB_HOST . ';dbname=' . GALLERY_DB_NAME . ';charset=utf8mb4', GALLERY_DB_USER, GALLERY_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $q = $pdo->prepare('SELECT rotation FROM photos WHERE id = ? LIMIT 1');
        $q->execute([$id]);
        return normalize_rotation((int)($q->fetchColumn() ?: 0));
    } catch (Throwable $e) {
        return 0; // stulpelio dar nera - rodom kaip anksciau
    }
}
function photo_key_by_id(int $id): string {
    $pdo = gallery_visibility_db();
    if (!$pdo) return '';
    try {
        $q = $pdo->prepare(
            "SELECT COALESCE(NULLIF(p.compatibility_b2_key, ''), p.b2_key) k
               FROM photos p
               JOIN albums a ON a.id = p.album_id
              WHERE p.id = ?
                AND p.visibility = 'published'
                AND p.is_missing = 0
                AND a.visibility = 'published'
              LIMIT 1"
        );
        $q->execute([$id]);
        return trim((string)($q->fetchColumn() ?: ''), "/ \t\n\r\0\x0B");
    } catch (Throwable $e) {
        gallery_log($e);
        return '';
    }
}

function b2_file_url(string $downloadUrl, string $bucketName, string $fileName): string {
    $encoded = str_replace('%2F', '/', rawurlencode($fileName));
    return rtrim($downloadUrl, '/') . '/file/' . rawurlencode($bucketName) . '/' . $encoded;
}

function clamp_int(int $v, int $min, int $max): int {
    return max($min, min($max, $v));
}

/* ------------------------ headers parsing / retry-after ------------------------ */
function parse_headers(string $rawHeaders): array {
    $lines = preg_split("/\r\n|\n|\r/", trim($rawHeaders));
    $out = [];
    foreach ($lines as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) continue;
        $k = strtolower(trim(substr($line, 0, $pos)));
        $v = trim(substr($line, $pos + 1));
        $out[$k] = $v;
    }
    return $out;
}
function retry_after_seconds(array $headers): int {
    if (!isset($headers['retry-after'])) return 0;
    $ra = trim($headers['retry-after']);
    if (ctype_digit($ra)) return (int)$ra;
    $ts = strtotime($ra);
    if ($ts !== false) return max(1, $ts - time());
    return 0;
}

/* ------------------------ cooldown flag (local) ------------------------ */
function blocked_flag_path(): string { return sys_get_temp_dir() . '/b2_blocked_until.txt'; }
function is_blocked(): bool {
    $f = blocked_flag_path();
    if (!is_file($f)) return false;
    $until = (int)@file_get_contents($f);
    return $until > time();
}
function set_blocked_for(int $sec): void { @file_put_contents(blocked_flag_path(), (string)(time() + max(1, $sec))); }
function clear_blocked(): void { $f = blocked_flag_path(); if (is_file($f)) @unlink($f); }

function respond_cooldown(int $sec, int $http, string $phase): void {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=30'); // safe short caching of error
    header('Retry-After: ' . (string)$sec);
    echo "Upstream busy (HTTP {$http}, {$phase}). Try again in {$sec}s.";
    exit;
}

function backoff_seconds(int $attempt): int {
    $base = 10 * (2 ** max(0, $attempt)); // 10s, 20s, 40s...
    $cap = 300; // 5 min cap
    $sec = min($cap, $base);
    $sec += random_int(0, 10);
    return $sec;
}
function should_cooldown(int $http): bool {
    return ($http === 429) || ($http >= 500 && $http <= 599);
}

/* ------------------------ CURL helpers (JSON + STREAM download) ------------------------ */
function curl_json(string $url, array $headers = [], ?array $post = null): array {
    if (!function_exists('curl_init')) respond_text('PHP cURL extension is not available', 500);

    $ch = curl_init($url);
    $h = $headers;

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => $h,
    ];

    if ($post !== null) {
        $h[] = 'Content-Type: application/json';
        $opts[CURLOPT_HTTPHEADER] = $h;
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($post, JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);

    if ($body === false) {
        $err = curl_error($ch);
        curl_close($ch);
        gallery_log("B2 JSON cURL error: {$err}");
        respond_text("Upstream unavailable", 502);
    }
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $json = json_decode($body, true);
    if (!is_array($json)) {
        gallery_log("B2 JSON non-JSON response HTTP {$http}: " . substr((string)$body, 0, 400));
        respond_text("Upstream unavailable", 502);
    }
    if ($http >= 400) {
        gallery_log("B2 JSON API error HTTP {$http}: " . json_encode($json, JSON_UNESCAPED_SLASHES));
        respond_text("Upstream unavailable", 502);
    }
    return $json;
}

/**
 * Streams URL to file. Returns: [http, rawHeaders, bytesWritten]
 */
function curl_download_to_file(string $url, string $dstPath, array $headers = []): array {
    if (!function_exists('curl_init')) respond_text('PHP cURL extension is not available', 500);

    $fp = fopen($dstPath, 'wb');
    if ($fp === false) respond_text('Failed to open temp file for download', 500);

    $rawHeaders = '';
    $bytes = 0;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$rawHeaders) {
            $rawHeaders .= $line;
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION  => function($ch, $data) use ($fp, &$bytes) {
            $len = fwrite($fp, $data);
            if ($len === false) return 0;
            $bytes += $len;
            return $len;
        },
    ]);

    $ok = curl_exec($ch);
    if ($ok === false) {
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        @unlink($dstPath);
        respond_text("cURL download error: {$err}", 502);
    }

    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    return [$http, $rawHeaders, $bytes];
}

/**
 * Verifies a downloaded source file is COMPLETE before it is decoded.
 * A truncated download still parses its header (dimensions are at the start), so
 * the encoder happily produces a scrambled image with a grey tail — and that
 * garbage then gets cached forever. Reject it here instead.
 */
function source_download_is_complete(string $path, array $hdrs): bool {
    $size = @filesize($path);
    if ($size === false || $size < 128) return false;

    $declared = isset($hdrs['content-length']) ? (int)$hdrs['content-length'] : 0;
    if ($declared > 0 && $size !== $declared) return false;
    // Content-Length sutapo iki baito - failas gautas visas. Toliau struktura
    // tikrinti nebereikia ir NEGALIMA: zr. zemiau, kodel EOI paieska klysta.
    if ($declared > 0) return true;

    // Structural check only for formats PHP can actually parse. HEIC/HEIF and
    // video are downloaded through here too (they fall through to a placeholder
    // further down), and getimagesize() cannot read them — PHP has no
    // IMAGETYPE_HEIC. Treating "unparseable" as "corrupt" turned every uncached
    // HEIC into a 502. Content-Length above is the real truncation guard.
    $info = @getimagesize($path);
    if ($info && (int)($info[2] ?? 0) === IMAGETYPE_JPEG) {
        // A truncated JPEG loses its EOI marker (FF D9). But the marker is NOT
        // always the last two bytes: Samsung ("SEFT"), MPF dual-camera, trailing
        // thumbnails and XMP all append data AFTER EOI on perfectly valid files.
        // So search the tail for FF D9 instead of requiring it at the very end —
        // requiring it at the end 502'd every Samsung phone photo. Content-Length
        // above remains the real truncation guard.
        //
        // Kiek to uodegos reikia: Canon MPF failuose po EOI eina VISAS antras
        // paveikslelis. Ukmerges begimo nuotraukoje tai 2,90 MB - su 64 KB langu
        // EOI likdavo nepastebeta ir sveika nuotrauka gaudavo 502 "truncated".
        // Tikrinam iki 8 MB uodegos; Content-Length atvejis grazinamas dar
        // auksciau ir cia nebepatenka.
        $fp = @fopen($path, 'rb');
        if ($fp === false) return false;
        $scan = 8 * 1024 * 1024;
        @fseek($fp, max(0, $size - $scan), SEEK_SET);
        $tail = (string)@fread($fp, $scan);
        @fclose($fp);
        if (strpos($tail, "\xFF\xD9") === false) return false;
    }
    return true;
}

/* ------------------------ Memory safety for GD decode ------------------------ */
function parse_memory_limit_bytes(): int {
    $v = trim((string)ini_get('memory_limit'));
    if ($v === '' || $v === '-1') return PHP_INT_MAX;

    $unit = strtolower(substr($v, -1));
    $num = (int)$v;
    if ($unit === 'g') return $num * 1024 * 1024 * 1024;
    if ($unit === 'm') return $num * 1024 * 1024;
    if ($unit === 'k') return $num * 1024;
    return (int)$v;
}

/**
 * Conservative estimator: decode + resize uses multiple buffers.
 * We assume ~5 bytes/pixel and then add headroom.
 */
function will_gd_oom(int $w, int $h): bool {
    $limit = parse_memory_limit_bytes();
    $used  = memory_get_usage(true);
    $free  = max(0, $limit - $used);

    // 5 bytes/pixel is conservative for GD + overhead.
    $need = (int)($w * $h * 5);

    // plus headroom for resample buffers / PHP overhead
    $need = (int)($need * 1.5);

    return $need > $free;
}

/* ------------------------ Placeholder thumb (cached) ------------------------ */
function make_placeholder_image(string $dstPath, int $w, int $h, string $title, string $subtitle, int $q, string $fmt): void {
    // keep it cheap, never huge
    $w = clamp_int($w > 0 ? $w : 800, 300, 1200);
    $h = clamp_int($h > 0 ? $h : 600, 200, 900);

    $im = imagecreatetruecolor($w, $h);
    if (!$im) respond_text('Failed to create placeholder', 500);

    $bg = imagecolorallocate($im, 20, 22, 25);
    $fg = imagecolorallocate($im, 220, 220, 220);
    $mut = imagecolorallocate($im, 150, 150, 150);

    imagefilledrectangle($im, 0, 0, $w, $h, $bg);

    // simple text using built-in font
    imagestring($im, 5, 16, 16, $title, $fg);
    imagestring($im, 3, 16, 44, $subtitle, $mut);

    if ($fmt === 'webp' && function_exists('imagewebp')) {
        imagepalettetotruecolor($im);
        imagewebp($im, $dstPath, clamp_int($q, 40, 92));
    } else {
        imagejpeg($im, $dstPath, clamp_int($q, 40, 92));
    }
    imagedestroy($im);
}

/* ------------------------ Resize (always to JPEG, strips metadata) ------------------------ */
function imagick_resize_to_image(string $srcPath, string $dstPath, int $w, int $h, string $fit, int $q, string $fmt, int $rot = 0): bool {
    if (!extension_loaded('imagick') || !class_exists('Imagick')) return false;
    if (is_heic_file($srcPath) && !heic_preview_supported()) return false;

    try {
        $img = new Imagick();
        // Shrink-on-load: hint libjpeg to decode at ~target size instead of full
        // resolution. A 12MP phone JPEG (4032×3024) otherwise allocates ~50MB and
        // OOMs cheap shared hosting → PHP fatal → Cloudflare 502. libjpeg picks the
        // smallest 1/1·1/2·1/4·1/8 scale still ≥ the hint, so quality holds.
        // Only affects JPEG decoding; harmless for other formats.
        $hint = max($w, $h) ?: 1600;
        @$img->setOption('jpeg:size', $hint . 'x' . $hint);
        $img->readImage($srcPath . '[0]');
        if (method_exists($img, 'autoOrient')) {
            @$img->autoOrient();
        }
        // Rankinis pasukimas is admin'o - PO EXIF pataisos, nes kai kurie
        // telefonai issaugo jau pasuktus pikselius ir palieka Orientation zyme
        // (Molėtų finalai 2019): tada EXIF pasuka neteisingai, o sis kampas atitaiso.
        if ($rot) {
            $img->rotateImage(new ImagickPixel('none'), $rot);
            $img->setImagePage(0, 0, 0, 0);
        }

        $srcW = $img->getImageWidth();
        $srcH = $img->getImageHeight();
        if ($srcW < 1 || $srcH < 1) return false;

        if ($w <= 0 && $h <= 0) {
            $w = 1600;
            $h = 0;
        }
        if ($w <= 0) $w = (int)round(($h / max(1, $srcH)) * $srcW);
        if ($h <= 0) $h = (int)round(($w / max(1, $srcW)) * $srcH);

        if ($fit === 'cover') {
            $scale = max($w / max(1, $srcW), $h / max(1, $srcH));
            $resizeW = max($w, (int)ceil($srcW * $scale));
            $resizeH = max($h, (int)ceil($srcH * $scale));
            $img->resizeImage($resizeW, $resizeH, Imagick::FILTER_LANCZOS, 1, true);
            $cropX = max(0, (int)floor(($img->getImageWidth() - $w) / 2));
            $cropY = max(0, (int)floor(($img->getImageHeight() - $h) / 2));
            $img->cropImage($w, $h, $cropX, $cropY);
            $img->setImagePage(0, 0, 0, 0);
        } else {
            $img->thumbnailImage($w, $h, true, false);
        }

        $img->stripImage();
        if ($fmt === 'webp') {
            $img->setImageFormat('webp');
            if (defined('Imagick::COMPRESSION_WEBP')) {
                $img->setImageCompression(Imagick::COMPRESSION_WEBP);
            }
            $img->setImageCompressionQuality(clamp_int($q, 40, 92));
        } else {
            $img->setImageFormat('jpeg');
            $img->setImageCompression(Imagick::COMPRESSION_JPEG);
            $img->setImageCompressionQuality(clamp_int($q, 40, 92));
            $img->setInterlaceScheme(Imagick::INTERLACE_PLANE);
        }
        $ok = $img->writeImage($dstPath);
        $img->clear();
        $img->destroy();
        return $ok && is_file($dstPath) && filesize($dstPath) > 0;
    } catch (Throwable $e) {
        return false;
    }
}


function image_resize_to_image(string $srcPath, string $dstPath, int $w, int $h, string $fit, int $q, string $fmt, int $rot = 0): void {
    if (imagick_resize_to_image($srcPath, $dstPath, $w, $h, $fit, $q, $fmt, $rot)) {
        return;
    }

    $info = @getimagesize($srcPath);
    if (!$info) {
        make_placeholder_image(
            $dstPath,
            $w > 0 ? $w : 900,
            $h > 0 ? $h : 600,
            'Preview not generated',
            'Server cannot decode this image format',
            $q,
            $fmt
        );
        return;
    }

    [$srcW, $srcH] = $info;
    $mime = $info['mime'] ?? '';

    if (will_gd_oom((int)$srcW, (int)$srcH)) {
        make_placeholder_image(
            $dstPath,
            $w > 0 ? $w : 900,
            $h > 0 ? $h : 600,
            'Thumbnail not generated',
            'Image is too large for current server memory_limit',
            $q,
            $fmt
        );
        return;
    }

    if ($mime === 'image/jpeg') {
        $src = @imagecreatefromjpeg($srcPath);
    } elseif ($mime === 'image/png') {
        $src = @imagecreatefrompng($srcPath);
    } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
        $src = @imagecreatefromwebp($srcPath);
    } else {
        respond_text('Unsupported mime: ' . $mime, 415);
    }
    if (!$src) respond_text('Failed to decode image', 500);
    if ($rot) {
        // GD imagerotate() suka pries laikrodzio rodykle.
        $rotated = imagerotate($src, 360 - $rot, 0);
        if ($rotated) { imagedestroy($src); $src = $rotated; $srcW = imagesx($src); $srcH = imagesy($src); }
    }

    if ($w <= 0 && $h <= 0) {
        $w = 1600;
        $h = 0;
    }
    if ($w <= 0) $w = (int)round(($h / max(1, $srcH)) * $srcW);
    if ($h <= 0) $h = (int)round(($w / max(1, $srcW)) * $srcH);

    $srcAspect = $srcW / max(1, $srcH);
    $dstAspect = $w / max(1, $h);

    if ($fit === 'cover') {
        if ($srcAspect > $dstAspect) {
            $newH = $srcH;
            $newW = (int)round($srcH * $dstAspect);
            $srcX = (int)round(($srcW - $newW) / 2);
            $srcY = 0;
        } else {
            $newW = $srcW;
            $newH = (int)round($srcW / $dstAspect);
            $srcX = 0;
            $srcY = (int)round(($srcH - $newH) / 2);
        }
        $dst = imagecreatetruecolor($w, $h);
        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $w, $h, $newW, $newH);
    } else {
        $scale = min($w / max(1, $srcW), $h / max(1, $srcH));
        $outW = max(1, (int)floor($srcW * $scale));
        $outH = max(1, (int)floor($srcH * $scale));
        $dst = imagecreatetruecolor($outW, $outH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $outW, $outH, $srcW, $srcH);
    }

    if ($fmt === 'webp' && function_exists('imagewebp')) {
        imagepalettetotruecolor($dst);
        @imagewebp($dst, $dstPath, clamp_int($q, 40, 92));
    } else {
        @imagejpeg($dst, $dstPath, clamp_int($q, 40, 92));
    }
    imagedestroy($src);
    imagedestroy($dst);
}

/* ------------------------ B2 download: public-first, private-fallback (STREAM) ------------------------ */
function download_b2_to_tempfile(
    string $file,
    string $bucketName,
    string $bucketId,
    string $keyId,
    string $appKey,
    string $authCacheFile
): string {
    // authorize (cached)
    $auth = null;
    if (is_file($authCacheFile)) {
        $auth = json_decode((string)file_get_contents($authCacheFile), true);
        if (!is_array($auth) || (int)($auth['expires'] ?? 0) < time()) $auth = null;
    }
    if (!$auth) {
        $basic = base64_encode($keyId . ':' . $appKey);
        $a = curl_json(
            'https://api.backblazeb2.com/b2api/v2/b2_authorize_account',
            ["Authorization: Basic {$basic}"],
            null
        );
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
        respond_text('B2 authorize returned incomplete data', 500);
    }

    $srcUrl = b2_file_url($downloadUrl, $bucketName, $file);

    $tmp = tempnam(sys_get_temp_dir(), 'b2src_');
    if ($tmp === false) respond_text('Failed to create temp file', 500);

    // 1) public
    [$http1, $hdr1, $bytes1] = curl_download_to_file($srcUrl, $tmp, ["User-Agent: foto.klajunas.lt img.php"]);
    $h1 = parse_headers($hdr1);

    if ($http1 === 200 && $bytes1 > 0) {
        if (!source_download_is_complete($tmp, $h1)) {
            @unlink($tmp);
            respond_text('Incomplete source download from B2 (truncated); not caching. Please retry.', 502);
        }
        clear_blocked();
        return $tmp;
    }

    if (should_cooldown($http1)) {
        $ra = retry_after_seconds($h1);
        $sec = $ra > 0 ? $ra : backoff_seconds(0);
        set_blocked_for($sec);
        @unlink($tmp);
        respond_cooldown($sec, $http1, 'public');
    }

    if ($http1 !== 401 && $http1 !== 403) {
        @unlink($tmp);
        respond_text("Upstream HTTP {$http1} (public download failed)", ($http1 === 404 ? 404 : 502));
    }

    // 2) private fallback: get per-prefix download auth
    $prefix = '';
    $slashPos = strrpos($file, '/');
    if ($slashPos !== false) $prefix = substr($file, 0, $slashPos + 1);

    $dlAuth = curl_json(
        $apiUrl . '/b2api/v2/b2_get_download_authorization',
        ["Authorization: {$authToken}"],
        [
            'bucketId' => $bucketId,
            'fileNamePrefix' => $prefix,
            'validDurationInSeconds' => 3600,
        ]
    );

    $downloadAuthToken = (string)($dlAuth['authorizationToken'] ?? '');
    if ($downloadAuthToken === '') {
        @unlink($tmp);
        respond_text("Failed to get download authorization token", 500);
    }

    // download again to same temp file (overwrite)
    @unlink($tmp);
    $tmp = tempnam(sys_get_temp_dir(), 'b2src_');
    if ($tmp === false) respond_text('Failed to create temp file', 500);

    [$http2, $hdr2, $bytes2] = curl_download_to_file(
        $srcUrl,
        $tmp,
        ["Authorization: {$downloadAuthToken}", "User-Agent: foto.klajunas.lt img.php"]
    );
    $h2 = parse_headers($hdr2);

    if ($http2 === 200 && $bytes2 > 0) {
        if (!source_download_is_complete($tmp, $h2)) {
            @unlink($tmp);
            respond_text('Incomplete source download from B2 (truncated); not caching. Please retry.', 502);
        }
        clear_blocked();
        return $tmp;
    }

    if (should_cooldown($http2)) {
        $ra = retry_after_seconds($h2);
        $sec = $ra > 0 ? $ra : backoff_seconds(1);
        set_blocked_for($sec);
        @unlink($tmp);
        respond_cooldown($sec, $http2, 'private');
    }

    @unlink($tmp);
    respond_text("Upstream HTTP {$http2} (private download failed)", 403);
}

/* ------------------------ main ------------------------ */
try {
    // IMPORTANT: you said your real config is /domains/b2-config.php
    // This keeps the same relative path convention you used elsewhere:
    require_once __DIR__ . '/../../b2-config.php';

    if (!defined('B2_KEY_ID') || !defined('B2_APP_KEY') || !defined('B2_BUCKET') || !defined('B2_BUCKET_ID')) {
        respond_text("Missing constants in b2-config.php. Need: B2_KEY_ID, B2_APP_KEY, B2_BUCKET, B2_BUCKET_ID", 500);
    }

    if (!defined('B2_AUTH_CACHE_FILE')) {
        if (defined('B2_CACHE_FILE')) {
            define('B2_AUTH_CACHE_FILE', (string)B2_CACHE_FILE);
        } else {
            define('B2_AUTH_CACHE_FILE', sys_get_temp_dir() . '/b2_auth_cache.json');
        }
    }

    // ?p=<nuotraukos ID> — pastovus adresas, atsparus albumo pervadinimui.
    // ?file=<kelias> lieka veikti kaip veikęs (jį naudoja pati galerija).
    //
    // Podėlio raktas ID atveju yra pats ID, ne kelias: nuotrauka po albumo
    // perkėlimo lieka ta pati, tad ir miniatiūra ta pati. Dėl to podėlio
    // pataikymas apačioje apsieina be DB — o straipsnio puslapyje tokių
    // užklausų būna dešimtys.
    $photoId = (int)($_GET['p'] ?? 0);
    $file = $photoId > 0 ? '' : safe_file((string)($_GET['file'] ?? ''));
    $cacheKey = $photoId > 0 ? 'photo-id/' . $photoId : $file;
    if ($cacheKey === '') {
        respond_text("img.php is OK.\n\nUse:\n  /img.php?file=PATH/TO/IMAGE.JPG&w=420\n  /img.php?file=PATH/TO/IMAGE.JPG&w=420&h=420&fit=cover\n  /img.php?p=12345&w=420            (pastovus adresas pagal nuotraukos ID)\n");
    }

    if (is_blocked()) {
        $until = (int)@file_get_contents(blocked_flag_path());
        $sec = max(5, $until - time());
        respond_cooldown($sec, 429, 'cooldown');
    }

    $w = normalize_size((int)($_GET['w'] ?? 0));
    $h = normalize_size((int)($_GET['h'] ?? 0));
    $fit = (string)($_GET['fit'] ?? 'contain');
    if ($fit !== 'contain' && $fit !== 'cover') $fit = 'contain';

    $q = normalize_quality((int)($_GET['q'] ?? 82));
    $fmt = resolve_output_format((string)($_GET['fmt'] ?? 'jpeg'));

    if ($w <= 0 && $h <= 0) $w = 420; // default for grid thumbs

    // ?rot= - rankinis pasukimas (0/90/180/270). Iena i podelio rakta tik kai ne 0,
    // tad visi esami podelio failai lieka galioti.
    $rot = normalize_rotation((int)($_GET['rot'] ?? 0));

    // ?anim=1 - GIF animacijai (Google Photos MOTION.gif) atiduodamas originalus
    // failas, ne perkoduotas pirmas kadras. Tik ?file= (galerija naudoja ji) ir
    // tik nepasuktam GIF; dydis/kokybe tada nereiksmingi - vienas podelio failas.
    $anim = $photoId <= 0 && (string)($_GET['anim'] ?? '') === '1' && !$rot && preg_match('~\.gif$~i', $file);
    if ($anim) { $w = 0; $h = 0; $fit = 'contain'; $q = 0; $fmt = 'gif'; }

    $thumbPath = thumb_cache_path((string)B2_BUCKET, $cacheKey . ($rot ? '|rot=' . $rot : ''), $w, $h, $fit, $q, $fmt);

    // Matomumas tikrinamas PRIES podeli: miniatiura, kartą sugeneruota kol
    // albumas buvo paskelbtas (ar admin'ui), kitaip liktu viesa amzinai.
    // Kaina - viena DB uzklausa origin'e; Cloudflare kesuotu kopiju ji neliecia.
    $visStart = microtime(true);
    $keyById = '';
    if ($photoId > 0) {
        $keyById = photo_key_by_id($photoId);
        if ($keyById === '') respond_text('Not found', 404);
    } else {
        image_cache_override(gallery_enforce_file_visibility($file));
    }
    header(sprintf('Server-Timing: vis;dur=%.1f', (microtime(true) - $visStart) * 1000));

    if (is_file($thumbPath) && filesize($thumbPath) > 0) {
        output_file($thumbPath, output_mime($fmt));
    }

    // Podėlyje nėra — tik dabar prireikia tikrojo B2 kelio.
    if ($photoId > 0) {
        $file = safe_file($keyById);
        if (!$rot) $rot = photo_rotation_by_id($photoId);
    }

    $lockPath = $thumbPath . '.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false) {
        respond_text("Thumbnail generator busy", 503);
    }
    if (!flock($lock, LOCK_EX | LOCK_NB)) {
        // Ta pacia miniatiura siuo metu generuoja kita uzklausa. Iki siol cia
        // buvo 503, ir narsykleje tai reiske negrizdama zala: <img> onerror
        // plytele pakeicia uzrasu "Nepavyko uzkrauti perziuros" ir daugiau
        // nebebando iki puslapio perkrovimo. Butent taip albume atsirasdavo
        // pavienes tuscios plyteles, nors failas B2 buvo tvarkingas.
        //
        // Generavimas trunka apie sekunde, todel tiesiog palaukiam rezultato.
        // Failas idedamas atominiu rename(), tad pamatytas jis jau pilnas.
        for ($i = 0; $i < 60; $i++) {
            usleep(100000);                      // 0,1 s, is viso iki 6 s
            clearstatcache(true, $thumbPath);
            if (is_file($thumbPath) && filesize($thumbPath) > 0) {
                fclose($lock);
                output_file($thumbPath, output_mime($fmt));
            }
        }
        fclose($lock);
        respond_text("Thumbnail generator busy", 503);
    }

    if (is_file($thumbPath) && filesize($thumbPath) > 0) {
        release_thumb_lock($lock, $lockPath);
        output_file($thumbPath, output_mime($fmt));
    }

    // STREAM download original to temp file (no huge PHP string)
    $tmpSrc = download_b2_to_tempfile(
        $file,
        (string)B2_BUCKET,
        (string)B2_BUCKET_ID,
        (string)B2_KEY_ID,
        (string)B2_APP_KEY,
        (string)B2_AUTH_CACHE_FILE
    );

    // Resize/re-encode to cached derivative
    $tmpThumb = $thumbPath . '.tmp.' . bin2hex(random_bytes(4));
    if ($anim) {
        // Originalus GIF be perkodavimo: tik patikrinam, kad tai tikrai GIF.
        // set_error_handler cia kiekviena perspejima pavercia isimtimi (net su @),
        // todel unlink tik jei failas dar liko - po rename() jo nebera.
        $sig = (string)file_get_contents($tmpSrc, false, null, 0, 6);
        if ($sig === 'GIF87a' || $sig === 'GIF89a') rename($tmpSrc, $tmpThumb);
        if (is_file($tmpSrc)) unlink($tmpSrc);
    } else {
        image_resize_to_image($tmpSrc, $tmpThumb, $w, $h, $fit, $q, $fmt, $rot);
        @unlink($tmpSrc);
    }

    if (!is_file($tmpThumb) || filesize($tmpThumb) <= 0) {
        @unlink($tmpThumb);
        release_thumb_lock($lock, $lockPath);
        respond_text("Failed to create thumbnail", 500);
    }

    @rename($tmpThumb, $thumbPath);
    if (!is_file($thumbPath) || filesize($thumbPath) <= 0) {
        @unlink($tmpThumb);
        release_thumb_lock($lock, $lockPath);
        respond_text("Failed to save thumbnail", 500);
    }
    release_thumb_lock($lock, $lockPath);

    if (random_int(1, 100) === 1) {
        gallery_prune_cache_dir(cache_dir(), 300 * 1024 * 1024, 90 * 86400);
        prune_stale_locks(cache_dir(), 3600);
    }

    output_file($thumbPath, output_mime($fmt));

} catch (Throwable $e) {
    gallery_log($e);
    respond_text("Internal server error", 500);
}
