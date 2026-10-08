<?php
declare(strict_types=1);

const GALLERY_ALLOWED_PREFIXES = [
    'albums',
];

function gallery_security_headers(string $type = 'default'): void {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');

    if ($type === 'html') {
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'none'; frame-ancestors 'none'");
    }
}

function gallery_public_error(string $message, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    gallery_security_headers();
    echo $message;
    exit;
}

function gallery_require_get(): void {
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') return;

    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    gallery_security_headers();
    echo 'Method not allowed';
    exit;
}

function gallery_log(Throwable|string $error): void {
    $message = $error instanceof Throwable ? $error->getMessage() : $error;
    error_log('[foto.klajunas.lt] ' . $message);
}

function gallery_is_allowed_prefix(string $path): bool {
    $path = trim($path, "/ \t\n\r\0\x0B");
    if ($path === '') return true;

    foreach (GALLERY_ALLOWED_PREFIXES as $prefix) {
        if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
            return true;
        }
    }

    return false;
}

function gallery_assert_allowed_prefix(string $path): void {
    if (!gallery_is_allowed_prefix($path)) {
        gallery_public_error('Not found', 404);
    }
}

/**
 * Miniatiuros adreso versija (&pv=) - b2-gallery.php ir og.php. Ji keiciasi kartu su rodomu failu:
 * synced_at admin'as pakelia, kai perraso JPG kopija ("Sukurti JPG"), pakeicia
 * faila geresne versija (photo_b2_replace), sinchronizuoja ar atstato is B2.
 *  - su JPG kopija (HEIC/video): visada (nuo 2026-10-08);
 *  - paprastoms nuotraukoms: tik jei synced_at >= PREVIEW_VERSION_SINCE. Senesnems
 *    adresas lieka toks pat, kad ~15 tukst. miniatiuru nebutu siunciamos is naujo
 *    vien del sio pakeitimo.
 */
const PREVIEW_VERSION_SINCE = '2026-10-08 00:00:00';
function preview_version(array $row): string {
    $compat = trim((string)($row['compatibility_b2_key'] ?? ''), "/ \t\n\r\0\x0B");
    $synced = trim((string)($row['synced_at'] ?? ''));
    if ($synced === '') return '';
    if ($compat !== '') return substr(md5($compat . '|' . $synced), 0, 8);
    if (strcmp($synced, PREVIEW_VERSION_SINCE) < 0) return '';
    $key = trim((string)($row['b2_key'] ?? ''), "/ \t\n\r\0\x0B");
    return $key === '' ? '' : substr(md5($key . '|' . $synced), 0, 8);
}

// Galerijoje rodomi vaizdo irasai (2026-10-08). Leidziami tik video.php
// (grotuvo srautas) ir download.php (originalas) - img.php ir meta.php ju
// nepriima, nes vaizdo iraso nei sumazinti, nei EXIF perskaityti negali.
const GALLERY_VIDEO_EXTS = ['mp4', 'm4v', 'mov', 'webm'];

function gallery_is_video_file(string $file): bool {
    return in_array(strtolower((string)pathinfo($file, PATHINFO_EXTENSION)), GALLERY_VIDEO_EXTS, true);
}

function gallery_assert_allowed_file(string $file, bool $allowVideo = false): void {
    if (!gallery_is_allowed_prefix($file)) {
        gallery_public_error('Not found', 404);
    }
    if ($allowVideo && gallery_is_video_file($file)) return;

    // GIF leidziamas nuo 2026-10-04: Google Photos animacijos (MOTION.gif)
    // rodomos galerijoje - miniatiura pirmas kadras, perziura originali animacija.
    if (!preg_match('~\.(jpe?g|png|webp|gif|heic|heif)$~i', $file)) {
        gallery_public_error('Unsupported file type', 415);
    }
}

/* ------------------------ Matomumas (visibility) ------------------------
 *
 * Iki 2026-10-03 juodrasciai buvo "paslepti" tik tuo, kad ju nebuvo viesame
 * albumu sarase. B2 aplanko kelias albums/<metai>/<data>__<pavadinimas>
 * atspejamas, o b2-gallery.php ?path=, img.php ?file=, meta.php ir
 * download.php tikrino tik prefiksa "albums/" - tad juodrascio nuotraukas
 * galejo matyti bet kas, zinantis (ar atspejes) kelia.
 *
 * Visos vieso matomumo taisykles - cia, vienoje vietoje. Privaciu albumu darbas
 * (visibility='private' + prisijunges ziurovas) pleciasi butent per
 * gallery_viewer_can_see(): kiti failai klausia tik jos.
 */

/** Ar sis matomumas rodomas visiems, be prisijungimo. */
function gallery_visibility_is_public(string $visibility): bool {
    return $visibility === 'published';
}

/** Ar dabartinis ziurovas gali matyti nuotrauka su tokiu albumo ir nuotraukos matomumu. */
function gallery_viewer_can_see(string $albumVisibility, string $photoVisibility): bool {
    if (gallery_visible_to_everyone($albumVisibility, $photoVisibility)) return true;
    // Privatus albumas: jo paskelbtos nuotraukos - tik leistiems ziurovams.
    return $albumVisibility === 'private'
        && gallery_visibility_is_public($photoVisibility)
        && gallery_viewer_can_see_private();
}

/** Ar nuotrauka rodoma visiems be prisijungimo (tik tada ja galima keseti viesai). */
function gallery_visible_to_everyone(string $albumVisibility, string $photoVisibility): bool {
    return gallery_visibility_is_public($albumVisibility) && gallery_visibility_is_public($photoVisibility);
}

/* ------------------------ Privatus albumas ------------------------
 *
 * visibility='private': albumas matomas sarase su spyna, nuotraukos - tik
 * prisijungusiems. Kas "prisijunges", nusprendzia Settings:
 *   private_album_viewers = 'admins'  - tik adminai (numatyta)
 *                           'members' - adminai + el. pastai is private_viewer_emails
 * Prisijungimas - /login/ (Google). Jis deda $_SESSION['viewer'], kuris NEDUODA
 * jokios admin'o prieigos (admin'as remiasi tik $_SESSION['admin']).
 *
 * Privacios nuotraukos atsakymai VISADA 'private, no-store': Cloudflare raktas yra
 * tik URL, tad viesai iskesuota nario perziura taptu vieša visiems.
 */

/** Sesijos turinys tik skaitymui. Be cookie sesija neatidaroma ir Set-Cookie nesiunciamas. */
function gallery_session(): array {
    static $data = null;
    if ($data !== null) return $data;
    if (session_status() === PHP_SESSION_ACTIVE) return $data = (array)$_SESSION;
    if (session_status() === PHP_SESSION_DISABLED || empty($_COOKIE[session_name()])) return $data = [];
    try {
        @session_start(['read_and_close' => true]);
    } catch (Throwable $e) {
        gallery_log($e);
        return $data = [];
    }
    // Strict mode nezinomam ID sugeneruotu nauja ir ji issiustu - mums jo nereikia.
    if (!headers_sent()) header_remove('Set-Cookie');
    return $data = (array)($_SESSION ?? []);
}

/** settings lentele (reiksme saugoma JSON), kaip admin'o setting(). */
function gallery_setting(string $key, string $default = ''): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $db = gallery_visibility_db();
    if (!$db) return $cache[$key] = $default;
    try {
        $q = $db->prepare('SELECT `value` FROM settings WHERE `key`=? LIMIT 1');
        $q->execute([$key]);
        $v = $q->fetchColumn();
    } catch (Throwable $e) {
        gallery_log($e);
        return $cache[$key] = $default;
    }
    if ($v === false || $v === null) return $cache[$key] = $default;
    $j = json_decode((string)$v, true);
    return $cache[$key] = is_scalar($j) ? (string)$j : $default;
}

/** 'admins' arba 'members' (Settings -> Privatūs albumai). */
function gallery_private_viewers_mode(): string {
    $m = gallery_setting('private_album_viewers', 'admins');
    return in_array($m, ['admins', 'members'], true) ? $m : 'admins';
}

/** Nariu el. pastai privatiems albumams (Settings, po viena eiluteje arba per kableli). */
function gallery_private_viewer_emails(): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtolower(gallery_setting('private_viewer_emails', ''))) ?: [] as $e) {
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) $out[$e] = true;
    }
    return array_keys($out);
}

/** Prisijunges per /login/ ziurovas arba null. */
function gallery_viewer(): ?array {
    $v = gallery_session()['viewer'] ?? null;
    return (is_array($v) && (string)($v['email'] ?? '') !== '') ? $v : null;
}

/**
 * Ar dabartinis ziurovas mato privaciu albumu nuotraukas. Nariu sarasas
 * tikrinamas kiekviena karta - pasalintas is saraso netenka prieigos iskart,
 * nelaukiant sesijos pabaigos.
 */
function gallery_viewer_can_see_private(): bool {
    static $can = null;
    if ($can !== null) return $can;
    if (gallery_viewer_is_admin()) return $can = true;
    $v = gallery_viewer();
    if (!$v) return $can = false;
    if (!empty($v['is_admin'])) return $can = true;
    return $can = gallery_private_viewers_mode() === 'members'
        && in_array(strtolower((string)$v['email']), gallery_private_viewer_emails(), true);
}

/**
 * Ar uzklausa ateina is prisijungusio admin'o (ta pati PHPSESSID sesija, kaip
 * /admin; cookie path=/). Admin'o albumo redagavimo plyteles juodrasciu
 * miniatiuras ima per ta pati /img.php, todel be sito jos luztu.
 *
 * Sesija atidaroma tik skaitymui ir tik tada, kai cookie apskritai yra - viesa
 * uzklausa be cookie sesijos nepaleidzia ir Set-Cookie negauna.
 */
function gallery_viewer_is_admin(): bool {
    return !empty(gallery_session()['admin']);
}

/**
 * DB jungtis matomumo patikrai. Naudoja failo gallery_db(), jei toks yra
 * (b2-gallery.php, meta.php), kitaip jungiasi pati is ~/domains/foto-db-config.php.
 * null - DB nepasiekiama.
 */
function gallery_visibility_db(): ?PDO {
    if (function_exists('gallery_db')) return gallery_db();
    static $pdo = false;
    if ($pdo !== false) return $pdo;
    $cfg = __DIR__ . '/../../foto-db-config.php';
    if (is_file($cfg)) require_once $cfg;
    if (!defined('GALLERY_DB_HOST') || !defined('GALLERY_DB_NAME') || !defined('GALLERY_DB_USER') || !defined('GALLERY_DB_PASS')) {
        return $pdo = null;
    }
    try {
        return $pdo = new PDO(
            'mysql:host=' . GALLERY_DB_HOST . ';dbname=' . GALLERY_DB_NAME . ';charset=utf8mb4',
            GALLERY_DB_USER,
            GALLERY_DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (Throwable $e) {
        gallery_log($e);
        return $pdo = null;
    }
}

/**
 * Rakto stulpeliai, kuriems admin'o ensure_schema() kuria indeksus
 * (photos_<stulpelis>_idx). Keiciant sarasa - keisti ir ten.
 */
const GALLERY_PHOTO_KEY_COLUMNS = ['b2_key', 'compatibility_b2_key', 'original_b2_key', 'thumb_path', 'preview_path', 'web_path'];

/**
 * photos+albums eilutes, kuriu bent vienas is $columns lygus $key.
 *
 * Po viena SELECT kiekvienam stulpeliui, sujungti UNION ALL - ne vienas WHERE su
 * OR: taip kiekviena dalis naudoja savo stulpelio indeksa, nepriklausomai nuo
 * to, ar optimizatorius sugebetu index_merge. Ta pati eilute gali grizti kelis
 * kartus (pvz. thumb_path = compatibility_b2_key) - kvieciantiesiems tai nesvarbu.
 * Isimtis keliauja aukstyn.
 */
function gallery_photo_rows_for_key(PDO $db, string $key, array $columns, string $select): array {
    $parts = [];
    foreach ($columns as $c) {
        if (!in_array($c, GALLERY_PHOTO_KEY_COLUMNS, true)) throw new InvalidArgumentException('Unknown key column ' . $c);
        $parts[] = "SELECT $select FROM photos p JOIN albums a ON a.id = p.album_id WHERE p.`$c` = ?";
    }
    $q = $db->prepare(implode(' UNION ALL ', $parts));
    $q->execute(array_fill(0, count($columns), $key));
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Kam priklauso B2 raktas ir ar jis rodomas siam ziurovui:
 *   'public' - bent viena DB nuotrauka su siuo raktu matoma
 *   'private' - matoma tik siam ziurovui (privatus albumas) - kesuoti viesai NEGALIMA
 *   'hidden' - raktas DB yra, bet nei viena jo nuotrauka nematoma
 *   'absent' - DB tokio rakto nera (senas failas be irasu - elgiamasi kaip anksciau)
 *   'error'  - DB nepasiekiama, atsakymo nezinom
 *
 * "Bent viena" - nes tas pats failas gali tureti kelias eilutes (b2_sync dublikatai,
 * du albumai viename B2 aplanke). Paskelbtos nuotraukos juodrastine kopija kitame
 * albume neturi jos paslepti.
 *
 * Ieskoma visuose stulpeliuose, per kuriuos galerija ir admin'as adresuoja faila:
 * originalas, JPG perziura (HEIC), legacy originalas ir thumb/preview/web keliai.
 */
function gallery_key_access(string $key): string {
    $key = trim($key, "/ \t\n\r\0\x0B");
    if ($key === '') return 'absent';
    $db = gallery_visibility_db();
    if (!$db) return 'error';

    try {
        $rows = gallery_photo_rows_for_key($db, $key, GALLERY_PHOTO_KEY_COLUMNS,
            'a.visibility AS album_visibility, p.visibility AS photo_visibility');
    } catch (Throwable $e) {
        gallery_log($e);
        return 'error';
    }
    if (!$rows) return 'absent';
    $best = 'hidden';
    foreach ($rows as $row) {
        $av = (string)($row['album_visibility'] ?? '');
        $pv = (string)($row['photo_visibility'] ?? '');
        if (gallery_visible_to_everyone($av, $pv)) return 'public';
        // 'private' - matoma TIK siam ziurovui (privatus albumas); kesuoti viesai negalima.
        if (gallery_viewer_can_see($av, $pv)) $best = 'private';
    }
    return $best;
}

/**
 * Ar originala galima atsisiusti per download.php:
 *   true  - bent viena DB nuotrauka su siuo raktu matoma ir leidzia atsisiuntima
 *           (albums.download_enabled=1, photos.is_downloadable=1, is_missing=0)
 *   false - raktas DB yra, bet ne viena jo eilute atsisiuntimo neleidzia
 *   null  - DB tokio rakto nera arba DB nepasiekiama (elgiamasi kaip anksciau)
 *
 * Iki 2026-10-03 sias taisykles tikrino tik b2-gallery.php (slepe downloadUrl),
 * o pats download.php atiduodavo bet kuri originala pagal ?file=. Raktas matomas
 * img.php miniatiuros adrese, tad isjungtas atsisiuntimas buvo tik kosmetinis.
 *
 * Ieskoma tik stulpeliuose, kuriuos galerija deda i downloadUrl: originalas,
 * JPG perziura (HEIC) ir legacy originalas - thumb/preview keliai ne atsisiuntimai.
 */
function gallery_key_download_allowed(string $key): ?bool {
    $key = trim($key, "/ \t\n\r\0\x0B");
    if ($key === '') return null;
    $db = gallery_visibility_db();
    if (!$db) return null;

    try {
        $rows = gallery_photo_rows_for_key($db, $key, ['b2_key', 'compatibility_b2_key', 'original_b2_key'],
            'a.visibility AS album_visibility, p.visibility AS photo_visibility, a.download_enabled, p.is_downloadable, p.is_missing');
    } catch (Throwable $e) {
        gallery_log($e);
        return null;
    }
    if (!$rows) return null;
    foreach ($rows as $row) {
        if ((int)($row['download_enabled'] ?? 0) === 1
            && (int)($row['is_downloadable'] ?? 0) === 1
            && (int)($row['is_missing'] ?? 0) === 0
            && gallery_viewer_can_see((string)($row['album_visibility'] ?? ''), (string)($row['photo_visibility'] ?? ''))) {
            return true;
        }
    }
    return false;
}

/**
 * Bendras sprendimas ?file= tipo endpoint'ams (img/meta/download).
 * Paslepta nuotrauka -> 404 su no-store (Cloudflare jos neiskesuoja), nebent
 * ziuri admin'as.
 *
 * Grazina null, kai atsakyma galima keseti viesai kaip iki siol, arba
 * Cache-Control reiksme, kuria kvieciantysis PRIVALO naudoti: Cloudflare raktas -
 * tik URL, tad viesai iskesuota admin'o perziura taptu vieša visiems.
 */
function gallery_enforce_file_visibility(string $key): ?string {
    $access = gallery_key_access($key);
    if ($access === 'public' || $access === 'absent') return null;
    if ($access === 'private') return 'private, no-store';
    if ($access === 'hidden') {
        if (!gallery_viewer_is_admin()) gallery_public_error('Not found', 404);
        // Tik admin'o narsykleje - kad albumo redagavimas nesiųstu visu plyteliu is naujo.
        return 'private, max-age=3600';
    }
    // DB nepasiekiama: rodom kaip anksciau, bet niekur nekesuojam.
    return 'private, no-store';
}

function gallery_client_ip(): string {
    $cfIp = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? '');
    if ($cfIp !== '' && filter_var($cfIp, FILTER_VALIDATE_IP)) {
        return $cfIp;
    }

    $remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return filter_var($remoteIp, FILTER_VALIDATE_IP) ? $remoteIp : 'unknown';
}

function gallery_rate_limit_dir(): string {
    $dir = gallery_storage_dir('rate_limits');
    return $dir;
}

function gallery_storage_dir(string $name): string {
    $safeName = preg_replace('~[^a-zA-Z0-9_-]~', '', $name);
    $dir = dirname(__DIR__) . '/cache/' . ($safeName ?: 'app');
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function gallery_cache_path(string $bucket, string $key): string {
    return gallery_storage_dir($bucket) . '/' . hash('sha256', $key) . '.cache';
}

function gallery_read_cache(string $bucket, string $key, int $ttlSeconds): ?string {
    $path = gallery_cache_path($bucket, $key);
    if (!is_file($path)) return null;
    $mtime = filemtime($path);
    if ($mtime === false || $mtime + $ttlSeconds < time()) return null;
    $body = file_get_contents($path);
    return is_string($body) && $body !== '' ? $body : null;
}

function gallery_write_cache(string $bucket, string $key, string $body): void {
    $path = gallery_cache_path($bucket, $key);
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $body, LOCK_EX) !== false) {
        @rename($tmp, $path);
    } else {
        @unlink($tmp);
    }
}

function gallery_prune_cache_dir(string $dir, int $maxBytes, int $maxAgeSeconds): void {
    if (!is_dir($dir)) return;

    $files = [];
    $total = 0;
    $now = time();
    foreach (glob(rtrim($dir, '/') . '/*') ?: [] as $path) {
        if (!is_file($path)) continue;
        if (preg_match('~\.(lock|tmp)(\.|$)~', basename($path))) continue;

        $size = filesize($path);
        $mtime = filemtime($path);
        if ($size === false || $mtime === false) continue;

        if ($mtime + $maxAgeSeconds < $now) {
            @unlink($path);
            continue;
        }

        $total += $size;
        $files[] = ['path' => $path, 'size' => $size, 'mtime' => $mtime];
    }

    if ($total <= $maxBytes) return;

    usort($files, fn($a, $b) => $a['mtime'] <=> $b['mtime']);
    foreach ($files as $file) {
        if ($total <= $maxBytes) break;
        if (@unlink($file['path'])) {
            $total -= $file['size'];
        }
    }
}

function gallery_rate_limit(string $bucket, int $maxRequests, int $windowSeconds): void {
    $now = time();
    $ip = gallery_client_ip();
    $key = hash('sha256', $bucket . '|' . $ip);
    $path = gallery_rate_limit_dir() . '/' . $key . '.json';
    $state = ['reset' => $now + $windowSeconds, 'count' => 0];

    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        gallery_log('Rate-limit storage unavailable');
        return;
    }

    try {
        if (!flock($fh, LOCK_EX)) {
            return;
        }

        $raw = stream_get_contents($fh);
        $saved = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($saved) && (int)($saved['reset'] ?? 0) > $now) {
            $state = [
                'reset' => (int)$saved['reset'],
                'count' => (int)($saved['count'] ?? 0),
            ];
        }

        $state['count']++;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state, JSON_UNESCAPED_SLASHES));
        fflush($fh);

        if ($state['count'] > $maxRequests) {
            $retryAfter = max(1, $state['reset'] - $now);
            http_response_code(429);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            header('Retry-After: ' . (string)$retryAfter);
            gallery_security_headers();
            echo 'Too many requests. Try again later.';
            exit;
        }
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function gallery_is_https_request(): bool {
    $https = (string)($_SERVER['HTTPS'] ?? '');
    if ($https !== '' && strtolower($https) !== 'off') return true;

    $proto = (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '');
    return strtolower($proto) === 'https';
}

function gallery_cookie_get_json(string $name): array {
    $raw = (string)($_COOKIE[$name] ?? '');
    if ($raw === '') return [];

    $decoded = json_decode(base64_decode(strtr($raw, '-_', '+/'), true) ?: '', true);
    return is_array($decoded) ? $decoded : [];
}

function gallery_cookie_set_json(string $name, array $value, int $ttlSeconds = 2592000): void {
    $encoded = rtrim(strtr(base64_encode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'), '+/', '-_'), '=');
    setcookie($name, $encoded, [
        'expires' => time() + $ttlSeconds,
        'path' => '/',
        'secure' => gallery_is_https_request(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function gallery_album_views_file(): string {
    return gallery_storage_dir('album_views') . '/albums.json';
}

function gallery_album_views_state(): array {
    $path = gallery_album_views_file();
    if (!is_file($path)) {
        return ['albums' => []];
    }

    $raw = file_get_contents($path);
    $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($decoded)) {
        return ['albums' => []];
    }

    $decoded['albums'] = is_array($decoded['albums'] ?? null) ? $decoded['albums'] : [];
    return $decoded;
}

function gallery_album_views_totals(): array {
    $state = gallery_album_views_state();
    $out = [];

    foreach (($state['albums'] ?? []) as $path => $entry) {
        if (!is_array($entry)) continue;
        $out[(string)$path] = (int)($entry['total'] ?? 0);
    }

    return $out;
}

function gallery_album_views_version(): int {
    $path = gallery_album_views_file();
    $mtime = @filemtime($path);
    return $mtime === false ? 0 : (int)$mtime;
}

function gallery_record_album_view(string $albumPath): bool {
    $albumPath = trim($albumPath, "/ \t\n\r\0\x0B");
    if ($albumPath === '') return false;

    $today = date('Y-m-d');
    $cookieName = 'foto_album_seen';
    $cookie = gallery_cookie_get_json($cookieName);
    if (($cookie['day'] ?? '') !== $today) {
        $cookie = ['day' => $today, 'seen' => []];
    }

    $marker = hash('sha256', $albumPath);
    $seen = is_array($cookie['seen'] ?? null) ? $cookie['seen'] : [];
    if (isset($seen[$marker])) {
        return false;
    }

    $seen[$marker] = 1;
    $cookie['seen'] = $seen;
    gallery_cookie_set_json($cookieName, $cookie);

    $path = gallery_album_views_file();
    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        return true;
    }

    try {
        if (!flock($fh, LOCK_EX)) {
            return true;
        }

        $raw = stream_get_contents($fh);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            $state = ['albums' => []];
        }
        if (!isset($state['albums']) || !is_array($state['albums'])) {
            $state['albums'] = [];
        }

        if (!isset($state['albums'][$albumPath]) || !is_array($state['albums'][$albumPath])) {
            $state['albums'][$albumPath] = ['total' => 0, 'days' => []];
        }

        $state['albums'][$albumPath]['total'] = (int)($state['albums'][$albumPath]['total'] ?? 0) + 1;
        $days = $state['albums'][$albumPath]['days'] ?? [];
        if (!is_array($days)) $days = [];
        $days[$today] = (int)($days[$today] ?? 0) + 1;
        krsort($days);
        $state['albums'][$albumPath]['days'] = array_slice($days, 0, 90, true);

        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        fflush($fh);
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    return true;
}
