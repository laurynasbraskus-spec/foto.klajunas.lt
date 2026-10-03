<?php
declare(strict_types=1);

// Session hardening MUST happen before session_start(), otherwise the cookie
// goes out with the host's defaults — verified live: PHPSESSID had no HttpOnly,
// Secure or SameSite at all. ini_set is used as well because some shared hosts
// ignore parts of session_set_cookie_params.
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// DB credentials live outside the webroot (~/domains/foto-db-config.php), just
// like b2-config.php. This file therefore holds no secret and can go into git.
// If the config is missing, DB_PASS stays empty and the connection fails loudly
// rather than silently falling back to a password baked into the source.
$adminDbConfig = __DIR__ . '/../../../foto-db-config.php';
if (is_file($adminDbConfig)) require_once $adminDbConfig;

if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'klajunas_foto');
if (!defined('DB_USER')) define('DB_USER', 'klajunas_adm');
if (!defined('DB_PASS')) define('DB_PASS', '');
const GOOGLE_CLIENT_ID = '107457534251-rqhhm5vm2ok3anb25uf5l4qcudl475ou.apps.googleusercontent.com';
const ALLOWED_EMAILS = ['info@klajunas.lt', 'okklajunas@gmail.com'];
// 'ready' panaikintas 2026-10-03 (neturejo prasmes; DB eiluciu su juo nebuvo).
const STATUSES = ['draft', 'published', 'private', 'hidden'];

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    return $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function go(string $url): never { header('Location: '.$url); exit; }
function token(): string { return $_SESSION['_token'] ??= bin2hex(random_bytes(32)); }
function csrf(): void {
    if (!hash_equals((string)($_SESSION['_token'] ?? ''), (string)($_POST['_token'] ?? ''))) {
        if (wants_json_response()) json_exit(['ok' => false, 'error' => 'Session expired.'], 401);
        http_response_code(419);
        exit('Session expired.');
    }
}
function flash(string $m, string $t='ok', string $link='', string $linkLabel=''): void { $_SESSION['flash'][] = [$m, $t, $link, $linkLabel]; }
function is_xhr(): bool { return (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'; }
function accepts_json(): bool { return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json'); }
function wants_json_response(): bool { return is_xhr() || accepts_json(); }
function json_exit(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function json_error(string $message, int $status = 400): never { json_exit(['ok' => false, 'error' => $message], $status); }
function remember_return_path(string $path): string {
    $key = bin2hex(random_bytes(8));
    $_SESSION['return_paths'][$key] = $path;
    return $key;
}
function consume_return_path(string $fallback): string {
    $key = (string)($_POST['return_key'] ?? '');
    $paths = $_SESSION['return_paths'] ?? [];
    $back = is_array($paths) && isset($paths[$key]) ? (string)$paths[$key] : '';
    if ($key !== '' && is_array($_SESSION['return_paths'] ?? null)) unset($_SESSION['return_paths'][$key]);
    if ($back === '') $back = (string)($_POST['back'] ?? $fallback);
    if (preg_match('~^https?://~i', $back)) { $parts = parse_url($back); $back = !empty($parts['query']) ? '?'.$parts['query'] : $fallback; }
    elseif (str_starts_with($back, '/')) { $parts = parse_url($back); $back = !empty($parts['query']) ? '?'.$parts['query'] : $fallback; }
    return $back;
}
function uid(): string {
    $d = random_bytes(16); $d[6] = chr((ord($d[6]) & 15) | 64); $d[8] = chr((ord($d[8]) & 63) | 128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}
function slug(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $s) ?: ''), '-');
    return $s ?: 'item-'.date('Ymd-His');
}
function b2_folder_slug(string $s): string {
    // Kaip slug(), bet su dviem skirtumais:
    //  1) '/' pavadinime virsta '_' (pvz., "(2009-05-15/17)" -> "2009-05-15_17");
    //  2) DIDZIOSIOS RAIDES ISLAIKOMOS. Vietovardziai ir pavadinimai B2 lieka
    //     skaitomi ("Daugiadienes-Vilnius", ne "daugiadienes-vilnius"), o
    //     kanoninis vardas sutampa su jau esanciais aplankais - todel B2 Sync
    //     nebeskelbia "differs from canonical" vien del raidziu dydzio.
    //     Viesa nuoroda (slug()) lieka mazosiomis, kaip ir turi buti URL'e.
    $s = str_replace('/', '_', $s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = trim(preg_replace('/[^a-zA-Z0-9_]+/', '-', $s) ?: '', '-_');
    return $s ?: 'item-'.date('Ymd-His');
}
/**
 * Ilga busenos frazė -> [css klase, trumpas zenkliuko tekstas].
 * Lentelei reikia dviejų dalykų: spalvos, kuria matai per visa ekrana, ir
 * dviejų žodžių, kurie telpa i viena eilute. Pilnas tekstas lieka title=.
 */
function b2_status_severity(string $status): array {
    $map = [
        'DB and B2 match canonical'                 => ['ok',   'sutampa'],
        'DB linked to real B2 folder'               => ['ok',   'susietas'],
        'real B2 folder differs from canonical'     => ['warn', 'ne kanoninis'],
        // Dublikatu cia realiai nera: tie patys failai guli dviejuose keliuose -
        // kanoniniame ir sename. Todel ir rasom, kuris kelias yra kuris.
        'DB linked, duplicate B2 folders exist'     => ['warn', 'du keliai'],
        'not linked in DB'                          => ['warn', 'nesusietas'],
        'not linked, likely duplicate of DB album'  => ['warn', 'antras kelias'],
        'DB linked, no B2 images'                   => ['err',  'nėra nuotraukų'],
        'DB/B2 mismatch'                            => ['err',  'neatitikimas'],
    ];
    return $map[$status] ?? ['warn', $status];
}

/**
 * Ka su tuo daryti - viena eilute. Naudojama meniu mygtuko uzrasui, kad
 * matytum reikalinga veiksma neatidares meniu.
 */
function b2_primary_hint(string $status): string {
    $hints = [
        'DB and B2 match canonical'                 => 'Veiksmai',
        'DB linked to real B2 folder'               => 'Veiksmai',
        'real B2 folder differs from canonical'     => 'Pervadinti į kanoninį',
        'DB linked, duplicate B2 folders exist'     => 'Perkelti į kanoninį',
        'not linked in DB'                          => 'Susieti su albumu',
        'not linked, likely duplicate of DB album'  => 'Perkelti į kanoninį',
        'DB linked, no B2 images'                   => 'Įkelti nuotraukas',
        'DB/B2 mismatch'                            => 'Sutvarkyti DB įrašus',
    ];
    return $hints[$status] ?? 'Veiksmai';
}

/**
 * Kur grizti po veiksmo albumu sarase.
 *
 * Anksciau visi veiksmai darydavo go(consume_return_path('?page=albums')) ir numesdavo filtrus:
 * istrynus viena juodrasti sarasas vel rodydavo visus albumus, ir filtra
 * tekdavo uzsidėti is naujo. Grazinam ta pacia uzklausa, is kurios veiksmas
 * buvo iskviestas.
 */
function source_path_from_title(string $s): string {
    $s = trim((string)(preg_replace('/\s+/u', ' ', $s) ?: $s));
    $s = (string)(preg_replace('~[\\\\/:*?"<>|]+~u', '-', $s) ?: $s);
    $s = (string)(preg_replace('~-+~u', '-', $s) ?: $s);
    $s = trim($s, " \t\n\r\0\x0B.-");
    if ($s === '') $s = 'Album '.date('Y-m-d');
    return function_exists('mb_substr') ? mb_substr($s, 0, 140, 'UTF-8') : substr($s, 0, 140);
}
function normalize_visibility(?string $value, string $fallback='draft'): string {
    $v = strtolower(trim((string)$value));
    if ($v === 'archived') $v = 'hidden';
    if ($v === 'ready') $v = 'draft';
    if ($v === '') $v = strtolower(trim($fallback));
    if ($v === 'archived') $v = 'hidden';
    return in_array($v, STATUSES, true) ? $v : 'draft';
}
// Kliento (formos/JSON) matomumas. normalize_visibility() nezinoma reiksme
// tyliai paverstu draft (2026-10-03: 85 nuotr. su 'public' tapo nematomos),
// todel cia: '' -> $default, alias'ai -> kanonine, nezinoma -> null (atmesti).
const VISIBILITY_ALIASES = ['public' => 'published', 'archived' => 'hidden', 'ready' => 'draft'];
function visibility_from_input(mixed $value, string $default='draft'): ?string {
    if (!is_scalar($value) && $value !== null) return null;
    $v = strtolower(trim((string)$value));
    if ($v === '') return normalize_visibility($default);
    $v = VISIBILITY_ALIASES[$v] ?? $v;
    return in_array($v, STATUSES, true) ? $v : null;
}
// "No preview" visur reiskia ta pati: perziuros generavimas nepavyko ARBA HEIC/HEIF
// originalas be JPG kopijos (serveris HEIC dekoduoti negali, tad galerijoje tuscia).
const NO_PREVIEW_SQL = "(p.preview_status='failed' OR ((LOWER(p.original_filename) LIKE '%.heic' OR LOWER(p.original_filename) LIKE '%.heif') AND COALESCE(p.compatibility_b2_key,'')=''))";
function photo_has_no_preview(array $p): bool {
    if ((string)($p['preview_status'] ?? '') === 'failed') return true;
    return is_heic_name((string)($p['original_filename'] ?? '')) && trim((string)($p['compatibility_b2_key'] ?? ''), '/') === '';
}
function invalid_visibility_message(mixed $value): string {
    $shown = is_scalar($value) ? trim((string)$value) : gettype($value);
    return 'Neleistinas matomumas „'.$shown.'". Leidžiamos reikšmės: '.implode(', ', STATUSES).' (public = published). Niekas neįrašyta.';
}
function ensure_schema(): void {
    $sql = [
        "CREATE TABLE IF NOT EXISTS admins (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, google_sub VARCHAR(191) NULL UNIQUE, email VARCHAR(191) NOT NULL UNIQUE, name VARCHAR(191) NULL, avatar_url VARCHAR(500) NULL, role VARCHAR(32) NOT NULL DEFAULT 'editor', is_active TINYINT(1) NOT NULL DEFAULT 1, last_login_at TIMESTAMP NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS albums (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, uuid CHAR(36) NOT NULL UNIQUE, parent_id BIGINT UNSIGNED NULL, source_type VARCHAR(32) NOT NULL DEFAULT 'manual', source_path VARCHAR(500) NULL, slug VARCHAR(191) NOT NULL UNIQUE, title VARCHAR(191) NOT NULL, subtitle VARCHAR(191) NULL, description TEXT NULL, event_date DATE NULL, event_date_end DATE NULL, location_name VARCHAR(191) NULL, country_code CHAR(2) NULL, author_name VARCHAR(191) NULL, copyright_text VARCHAR(191) NULL, cover_photo_id BIGINT UNSIGNED NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0, visibility VARCHAR(32) NOT NULL DEFAULT 'draft', is_featured TINYINT(1) NOT NULL DEFAULT 0, password_hash VARCHAR(255) NULL, download_enabled TINYINT(1) NOT NULL DEFAULT 1, seo_title VARCHAR(191) NULL, seo_description TEXT NULL, notes_internal LONGTEXT NULL, synced_at TIMESTAMP NULL, created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX albums_visibility_sort_idx(visibility,sort_order), INDEX albums_event_idx(event_date), INDEX albums_source_idx(source_type)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS photos (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, uuid CHAR(36) NOT NULL UNIQUE, album_id BIGINT UNSIGNED NOT NULL, source_type VARCHAR(32) NOT NULL DEFAULT 'b2_sync', b2_bucket VARCHAR(191) NULL, b2_key VARCHAR(500) NOT NULL, compatibility_b2_key VARCHAR(500) NULL, original_b2_key VARCHAR(500) NULL, original_filename VARCHAR(255) NOT NULL, stored_filename VARCHAR(255) NULL, file_ext VARCHAR(16) NULL, original_format VARCHAR(16) NULL, converted_from_heic TINYINT(1) NOT NULL DEFAULT 0, preview_status VARCHAR(20) NOT NULL DEFAULT 'ready', preview_error TEXT NULL, mime_type VARCHAR(191) NULL, file_size BIGINT UNSIGNED NULL, photo_views INT UNSIGNED NULL, checksum_sha1 CHAR(40) NULL, title VARCHAR(191) NULL, caption VARCHAR(500) NULL, alt_text VARCHAR(255) NULL, description TEXT NULL, author_name VARCHAR(191) NULL, copyright_text VARCHAR(191) NULL, credit_line VARCHAR(191) NULL, taken_at TIMESTAMP NULL, timezone VARCHAR(64) NULL, city VARCHAR(191) NULL, region VARCHAR(191) NULL, country VARCHAR(191) NULL, country_code CHAR(2) NULL, latitude DECIMAL(10,7) NULL, longitude DECIMAL(10,7) NULL, camera_make VARCHAR(191) NULL, camera_model VARCHAR(191) NULL, lens_model VARCHAR(191) NULL, focal_length VARCHAR(64) NULL, aperture VARCHAR(64) NULL, shutter_speed VARCHAR(64) NULL, iso_value INT UNSIGNED NULL, width INT UNSIGNED NULL, height INT UNSIGNED NULL, orientation VARCHAR(32) NULL, thumb_path VARCHAR(500) NULL, preview_path VARCHAR(500) NULL, web_path VARCHAR(500) NULL, sort_order INT UNSIGNED NOT NULL DEFAULT 0, visibility VARCHAR(32) NOT NULL DEFAULT 'draft', is_cover_candidate TINYINT(1) NOT NULL DEFAULT 0, is_downloadable TINYINT(1) NOT NULL DEFAULT 1, is_missing TINYINT(1) NOT NULL DEFAULT 0, metadata_json JSON NULL, notes_internal LONGTEXT NULL, synced_at TIMESTAMP NULL, created_by BIGINT UNSIGNED NULL, updated_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY photos_album_b2_unique(album_id,b2_key), INDEX photos_album_sort_idx(album_id,sort_order,taken_at,id), INDEX photos_visibility_idx(visibility), INDEX photos_missing_idx(is_missing), INDEX photos_filename_idx(original_filename)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS tags (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(191) NOT NULL UNIQUE, slug VARCHAR(191) NOT NULL UNIQUE, type VARCHAR(32) NOT NULL DEFAULT 'keyword', created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS photo_tags (photo_id BIGINT UNSIGNED NOT NULL, tag_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(photo_id,tag_id)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS album_tags (album_id BIGINT UNSIGNED NOT NULL, tag_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(album_id,tag_id)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS audit_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_id BIGINT UNSIGNED NULL, entity_type VARCHAR(64) NOT NULL, entity_id BIGINT UNSIGNED NULL, action VARCHAR(128) NOT NULL, summary VARCHAR(500) NULL, new_values JSON NULL, ip_address VARCHAR(45) NULL, user_agent TEXT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX audit_entity_idx(entity_type,entity_id), INDEX audit_action_idx(action)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS settings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `group` VARCHAR(64) NOT NULL, `key` VARCHAR(191) NOT NULL UNIQUE, `value` JSON NULL, type VARCHAR(32) NOT NULL DEFAULT 'string', created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS b2_sync_runs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, bucket VARCHAR(191) NOT NULL, prefix VARCHAR(500) NULL, mode VARCHAR(64) NOT NULL, options JSON NULL, status VARCHAR(32) NOT NULL DEFAULT 'draft', result JSON NULL, started_at TIMESTAMP NULL, finished_at TIMESTAMP NULL, triggered_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS import_runs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, type VARCHAR(64) NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'draft', filename VARCHAR(255) NULL, options JSON NULL, summary JSON NULL, preview_rows JSON NULL, errors JSON NULL, triggered_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS zip_downloads (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, type VARCHAR(32) NOT NULL, album_id BIGINT UNSIGNED NULL, selection JSON NULL, variant VARCHAR(32) NOT NULL DEFAULT 'originals', only_published TINYINT(1) NOT NULL DEFAULT 1, output_filename VARCHAR(255) NOT NULL DEFAULT 'photos.zip', files_count INT UNSIGNED NOT NULL DEFAULT 0, archive_size BIGINT UNSIGNED NULL, status VARCHAR(32) NOT NULL DEFAULT 'draft', created_by BIGINT UNSIGNED NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];
    foreach ($sql as $s) db()->exec($s);
    if (!db()->query("SHOW COLUMNS FROM photos LIKE 'photo_views'")->fetch()) {
        db()->exec("ALTER TABLE photos ADD photo_views INT UNSIGNED NULL AFTER file_size");
    }
    if (!db()->query("SHOW COLUMNS FROM photos LIKE 'original_b2_key'")->fetch()) {
        db()->exec("ALTER TABLE photos ADD original_b2_key VARCHAR(500) NULL AFTER b2_key");
    }
    if (!db()->query("SHOW COLUMNS FROM photos LIKE 'compatibility_b2_key'")->fetch()) {
        db()->exec("ALTER TABLE photos ADD compatibility_b2_key VARCHAR(500) NULL AFTER b2_key");
    }
    ensure_photo_preview_schema();
    if (!db()->query("SHOW COLUMNS FROM albums LIKE 'cover_mode'")->fetch()) {
        db()->exec("ALTER TABLE albums ADD cover_mode VARCHAR(16) NOT NULL DEFAULT 'auto' AFTER cover_photo_id");
    }
    if (!db()->query("SHOW COLUMNS FROM albums LIKE 'sport_type'")->fetch()) {
        db()->exec("ALTER TABLE albums ADD sport_type VARCHAR(191) NULL AFTER location_name");
    }
    if (!db()->query("SHOW COLUMNS FROM albums LIKE 'dbsportas_url'")->fetch()) {
        db()->exec("ALTER TABLE albums ADD dbsportas_url VARCHAR(500) NULL AFTER seo_description");
    }
    if (!db()->query("SHOW COLUMNS FROM albums LIKE 'klajunas_url'")->fetch()) {
        db()->exec("ALTER TABLE albums ADD klajunas_url VARCHAR(500) NULL AFTER dbsportas_url");
    }
    if (!db()->query("SHOW COLUMNS FROM albums LIKE 'other_url'")->fetch()) {
        db()->exec("ALTER TABLE albums ADD other_url VARCHAR(500) NULL AFTER klajunas_url");
    }
    // Several live tables were created without a created_at default, so every row
    // landed with created_at = NULL. Give them one so future rows get real
    // timestamps (audit() also writes NOW() explicitly). Existing NULLs stay NULL.
    foreach (['audit_logs', 'photos', 'albums'] as $tbl) {
        try {
            $col = db()->query("SHOW COLUMNS FROM `$tbl` LIKE 'created_at'")->fetch();
            if ($col && ($col['Default'] ?? null) === null) {
                db()->exec("ALTER TABLE `$tbl` MODIFY created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP");
            }
        } catch (Throwable $e) { /* non-fatal */ }
    }
    // Cached "oldest content upload in B2" for the delete-lock badge, so the album
    // list can show 🔒 without a B2 API call per row. Immutable once known (oldest
    // upload never gets older); enforcement in delete_album stays live/authoritative.
    try {
        if (!db()->query("SHOW COLUMNS FROM albums LIKE 'b2_oldest_upload_at'")->fetch()) {
            db()->exec("ALTER TABLE albums ADD b2_oldest_upload_at DATETIME NULL AFTER synced_at");
        }
    } catch (Throwable $e) { /* non-fatal */ }
    // Seni albumo slug'ai: pervadinus albuma dalintos /a/<senas-slug> nuorodos
    // turi veikti toliau — og.php pagal cia rastą album_id nukreipia i dabartini.
    db()->exec("CREATE TABLE IF NOT EXISTS album_slug_aliases (slug VARCHAR(191) NOT NULL PRIMARY KEY, album_id BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, INDEX album_slug_aliases_album_idx(album_id)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    db()->exec("CREATE TABLE IF NOT EXISTS access_logs (
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

    db()->exec("UPDATE albums SET cover_mode='manual' WHERE cover_photo_id IS NOT NULL AND cover_photo_id > 0 AND cover_mode='auto'");
    db()->exec("UPDATE albums SET visibility='hidden' WHERE visibility='archived'");
    db()->exec("UPDATE photos SET visibility='hidden' WHERE visibility='archived'");
    try {
        db()->exec("UPDATE photos SET photo_views = CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.imageViews')), '') AS UNSIGNED) WHERE photo_views IS NULL AND metadata_json IS NOT NULL AND JSON_VALID(metadata_json) AND JSON_EXTRACT(metadata_json, '$.imageViews') IS NOT NULL");
    } catch (Throwable) {
        // Older MySQL variants may not support JSON functions identically; leave existing data untouched.
    }
    ensure_member_schema();
    $st = db()->prepare("INSERT INTO admins(email,name,role,is_active) VALUES(?,?,'superadmin',1) ON DUPLICATE KEY UPDATE role='superadmin', is_active=1");
    $st->execute(['info@klajunas.lt','OK Klajunas']);
    $st->execute(['okklajunas@gmail.com','OK Klajunas Gmail']);
}
function audit(string $type, ?int $id, string $action, string $summary, ?array $values=null): void {
    db()->prepare("INSERT INTO audit_logs(admin_id,entity_type,entity_id,action,summary,new_values,ip_address,user_agent,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())")
        ->execute([$_SESSION['admin']['id'] ?? null, $type, $id, $action, $summary, $values ? json_encode($values, JSON_UNESCAPED_UNICODE) : null, $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '',0,900)]);
}
function ensure_overlay_schema(): void {
    $q = db()->query("SHOW COLUMNS FROM albums LIKE 'metadata_json'");
    if (!$q->fetch()) db()->exec("ALTER TABLE albums ADD metadata_json JSON NULL AFTER notes_internal");
    ensure_photo_preview_schema();
}
/* Rankinis pasukimas: kampas pagal laikrodzio rodykle (0/90/180/270), taikomas
 * TIK rodant (img.php ?rot=). Originalai B2 nekeiciami - ir HEIC, ir JPG. */
function photo_rotation_column(): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        if (!db()->query("SHOW COLUMNS FROM photos LIKE 'rotation'")->fetch()) {
            db()->exec("ALTER TABLE photos ADD rotation SMALLINT NOT NULL DEFAULT 0 AFTER orientation");
        }
        $has = true;
    } catch (Throwable $e) {
        $has = false;
    }
    return $has;
}
function photo_rot_query(array $p): array {
    $rot = ((((int)($p['rotation'] ?? 0)) % 360) + 360) % 360;
    return in_array($rot, [90, 180, 270], true) ? ['rot' => $rot] : [];
}
/* Viesas img.php / meta.php / download.php kiekvienam origin kreipiniui ieško
 * rakto sešiuose photos stulpeliuose (gallery-security.php,
 * GALLERY_PHOTO_KEY_COLUMNS). Be indeksu tai pilnas lenteles skenas, ~20 ms
 * (matuota 2026-10-03). Priesdelis 191 simbolis: utf8mb4 tilpsta ir COMPACT
 * eiluciu formato 767 baitu ribose, o lygybes paieskai priesdelio pakanka.
 * Visi trukstami - vienu ALTER (viena perstatymo eiga). Nepavykus admin'as
 * veikia toliau: indeksai tik greitis, ne teisingumas. */
function ensure_photo_key_indexes(): void {
    try {
        $have = [];
        foreach (db()->query("SHOW INDEX FROM photos")->fetchAll() as $ix) $have[(string)$ix['Key_name']] = true;
        $add = [];
        foreach (['b2_key', 'compatibility_b2_key', 'original_b2_key', 'thumb_path', 'preview_path', 'web_path'] as $col) {
            $name = 'photos_' . $col . '_idx';
            if (!isset($have[$name])) $add[] = "ADD INDEX `$name` (`$col`(191))";
        }
        if ($add) db()->exec('ALTER TABLE photos ' . implode(', ', $add));
    } catch (Throwable $e) {
        error_log('[foto.klajunas.lt] ensure_photo_key_indexes: ' . $e->getMessage());
    }
}
function ensure_photo_preview_schema(): void {
    if (!db()->query("SHOW COLUMNS FROM photos LIKE 'original_format'")->fetch()) {
        db()->exec("ALTER TABLE photos ADD original_format VARCHAR(16) NULL AFTER file_ext");
    }
    if (!db()->query("SHOW COLUMNS FROM photos LIKE 'converted_from_heic'")->fetch()) {
        db()->exec("ALTER TABLE photos ADD converted_from_heic TINYINT(1) NOT NULL DEFAULT 0 AFTER original_format");
    }
    if (!db()->query("SHOW COLUMNS FROM photos LIKE 'preview_status'")->fetch()) {
        db()->exec("ALTER TABLE photos ADD preview_status VARCHAR(20) NOT NULL DEFAULT 'ready' AFTER converted_from_heic");
    }
    if (!db()->query("SHOW COLUMNS FROM photos LIKE 'preview_error'")->fetch()) {
        db()->exec("ALTER TABLE photos ADD preview_error TEXT NULL AFTER preview_status");
    }
    if (!db()->query("SHOW COLUMNS FROM photos LIKE 'thumb_path'")->fetch()) {
        db()->exec("ALTER TABLE photos ADD thumb_path VARCHAR(500) NULL AFTER orientation");
    }
    if (!db()->query("SHOW COLUMNS FROM photos LIKE 'preview_path'")->fetch()) {
        db()->exec("ALTER TABLE photos ADD preview_path VARCHAR(500) NULL AFTER thumb_path");
    }
    if (!db()->query("SHOW COLUMNS FROM photos LIKE 'web_path'")->fetch()) {
        db()->exec("ALTER TABLE photos ADD web_path VARCHAR(500) NULL AFTER preview_path");
    }
    db()->exec("UPDATE photos SET original_format = LOWER(file_ext) WHERE (original_format IS NULL OR original_format='') AND file_ext IS NOT NULL AND file_ext<>''");
    db()->exec("UPDATE photos SET converted_from_heic = 1 WHERE converted_from_heic=0 AND LOWER(COALESCE(original_format,file_ext,'')) IN ('heic','heif') AND COALESCE(compatibility_b2_key,'')<>''");
    db()->exec("UPDATE photos SET preview_status = CASE WHEN LOWER(COALESCE(original_format,file_ext,'')) IN ('heic','heif') AND COALESCE(compatibility_b2_key,'')='' THEN 'failed' ELSE 'ready' END WHERE preview_status IS NULL OR preview_status=''");
    ensure_photo_key_indexes();
    db()->exec("UPDATE photos SET preview_path = compatibility_b2_key, web_path = compatibility_b2_key, thumb_path = compatibility_b2_key WHERE COALESCE(compatibility_b2_key,'')<>'' AND (preview_path IS NULL OR preview_path='' OR web_path IS NULL OR web_path='' OR thumb_path IS NULL OR thumb_path='')");
}
/* ---------------------------------------------------------------------------
 * Nariu ikelimo irankis (/upload)
 *
 * Nariai kelia originalus tiesiai i pasirinkto albumo kanonini B2 aplanka
 * ({source_path}/originals/), todel B2 archyvas lieka sutvarkytas pats ir
 * niekas niekada neperkeliama ir netrinama - invariantas nepazeidziamas.
 * "Priskyrimas albumui" yra ne baitu judinimas, o DB busena: nario ikeltos
 * nuotraukos visada gimsta draft, o tu jas perziuri ir paskelbi.
 *
 * Sios funkcijos gyvena admin faile todel, kad /upload/index.php ji ikrauna
 * biblioteka (KLAJUNAS_ADMIN_LIB) - taip B2, EXIF ir DB logika yra VIENA, o ne
 * dvi kopijos, kurios laikui begant issiskirtu (ta klaida jau kartą padaryta su
 * simple-foto-admin/index.php).
 * ------------------------------------------------------------------------ */
function ensure_member_schema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS member_invites (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL UNIQUE,
        token_hint VARCHAR(16) NOT NULL DEFAULT '',
        label VARCHAR(191) NOT NULL DEFAULT '',
        album_id BIGINT UNSIGNED NULL,
        max_photos INT UNSIGNED NOT NULL DEFAULT 0,
        uploads_done INT UNSIGNED NOT NULL DEFAULT 0,
        photos_done INT UNSIGNED NOT NULL DEFAULT 0,
        expires_at DATETIME NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by BIGINT UNSIGNED NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        last_used_at DATETIME NULL,
        INDEX member_invites_album_idx(album_id),
        INDEX member_invites_active_idx(is_active)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS member_uploads (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        album_id BIGINT UNSIGNED NOT NULL,
        admin_id BIGINT UNSIGNED NULL,
        invite_id BIGINT UNSIGNED NULL,
        contributor_name VARCHAR(191) NOT NULL DEFAULT '',
        contributor_email VARCHAR(191) NULL,
        session_key CHAR(32) NULL,
        files_received INT UNSIGNED NOT NULL DEFAULT 0,
        files_stored INT UNSIGNED NOT NULL DEFAULT 0,
        files_skipped INT UNSIGNED NOT NULL DEFAULT 0,
        bytes_stored BIGINT UNSIGNED NOT NULL DEFAULT 0,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(500) NULL,
        created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY member_uploads_session_uk(session_key),
        INDEX member_uploads_album_idx(album_id),
        INDEX member_uploads_time_idx(created_at)
    ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    // SHOW COLUMNS cia rasomas su iterptu literalu, ne prepared parametru:
    // MariaDB nepalaiko "SHOW COLUMNS ... LIKE ?" paruostose uzklausose.
    if (!db()->query("SHOW COLUMNS FROM photos LIKE 'contributor_name'")->fetch()) {
        db()->exec("ALTER TABLE photos ADD contributor_name VARCHAR(191) NULL AFTER credit_line");
    }
    if (!db()->query("SHOW COLUMNS FROM photos LIKE 'contributor_email'")->fetch()) {
        db()->exec("ALTER TABLE photos ADD contributor_email VARCHAR(191) NULL AFTER contributor_name");
    }
    if (!db()->query("SHOW COLUMNS FROM photos LIKE 'member_invite_id'")->fetch()) {
        db()->exec("ALTER TABLE photos ADD member_invite_id BIGINT UNSIGNED NULL AFTER contributor_email");
    }
    if (!db()->query("SHOW COLUMNS FROM albums LIKE 'accepts_member_uploads'")->fetch()) {
        db()->exec("ALTER TABLE albums ADD accepts_member_uploads TINYINT(1) NOT NULL DEFAULT 0 AFTER download_enabled");
    }
    // Kada apie si ikelima issiustas laiskas info@ - kad pakartotinis
    // member_upload_done nesiustu antro laisko.
    if (!db()->query("SHOW COLUMNS FROM member_uploads LIKE 'notified_at'")->fetch()) {
        db()->exec("ALTER TABLE member_uploads ADD notified_at DATETIME NULL AFTER bytes_stored");
    }
}

/**
 * Laiskas info@klajunas.lt apie nario ikelima. Siunciama per PHP mail() is
 * hostingo serverio 188.245.41.88, kuris irasytas klajunas.lt SPF. Nesekmė
 * tik zurnale - ikelimo ji nestabdo (failai jau B2 ir DB).
 */
const UPLOAD_NOTIFY_TO = 'info@klajunas.lt';
const UPLOAD_NOTIFY_FROM = 'noreply@klajunas.lt';
function upload_notify_mail(string $subject, string $body, ?string $replyTo = null): bool {
    $headers = ['From: foto.klajunas.lt <'.UPLOAD_NOTIFY_FROM.'>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit'];
    if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: '.$replyTo;
    try {
        $ok = mail(UPLOAD_NOTIFY_TO, '=?UTF-8?B?'.base64_encode($subject).'?=', $body, implode("\r\n", $headers), '-f'.UPLOAD_NOTIFY_FROM);
    } catch (Throwable $e) {
        $ok = false;
    }
    if (!$ok) error_log('[upload_notify] mail() nepavyko: '.$subject);
    return $ok;
}

/** Rolės, kurioms leidžiama kelti per /upload. Superadmin irgi - kad galėtum pats išbandyti. */
function member_upload_roles(): array { return ['member', 'editor', 'superadmin']; }

/** Nario ikelimu tikslinis prefiksas: tas pats kanoninis albumo aplankas kaip admin'e. */
function member_album_prefix(array $album): string {
    return album_storage_base_prefix((string)($album['source_path'] ?? ''));
}

/**
 * Albumai, i kuriuos siuo metu gali kelti nariai.
 *
 * Salygos: ijungtas `accepts_member_uploads` IR yra B2 kelias. Be kelio failas
 * neturetu kur atsidurti, o kelio spejimas cia butu tylus archyvo darkymas -
 * geriau albumo nerodyti ir admin'e parodyti ispejima.
 */
function member_open_albums(): array {
    return db()->query("SELECT id,title,subtitle,event_date,source_path,visibility FROM albums WHERE accepts_member_uploads=1 AND COALESCE(source_path,'')<>'' ORDER BY COALESCE(event_date,'0000-00-00') DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
}

function member_album_by_id(int $albumId): ?array {
    $st = db()->prepare("SELECT * FROM albums WHERE id=? LIMIT 1");
    $st->execute([$albumId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function member_invite_token_hash(string $token): string { return hash('sha256', $token); }

/**
 * Randa GALIOJANTI kvietima pagal atviro teksto tokena.
 *
 * DB laikomas tik SHA-256 - nutekejes DB dump'as neduoda veikianciu nuorodu.
 * Grazina null ir tada, kai kvietimas rastas, bet nebegalioja: skambintojui
 * nesvarbu kuris is triju stabdziu suveike, o tyliai skirtingi pranesimai
 * leistu spelioti, ar tokenas apskritai egzistuoja.
 */
function member_invite_by_token(string $token): ?array {
    $token = trim($token);
    if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{16,96}$/', $token)) return null;
    $st = db()->prepare("SELECT * FROM member_invites WHERE token_hash=? LIMIT 1");
    $st->execute([member_invite_token_hash($token)]);
    $invite = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$invite) return null;
    if ((int)$invite['is_active'] !== 1) return null;
    if (!empty($invite['expires_at']) && strtotime((string)$invite['expires_at']) < time()) return null;
    // Limitas skaiciuoja NUOTRAUKAS, ne uzklausas: vienas 60 nuotrauku ikelimas
    // issiskaido i ~10 POST partiju, tad skaiciuojant uzklausas "limitas 20"
    // reisktu visai ne 20 nuotrauku.
    if ((int)$invite['max_photos'] > 0 && (int)$invite['photos_done'] >= (int)$invite['max_photos']) return null;
    return $invite;
}

/** Narys mato tik savo ikelimus; admin'as - visus. */
function member_recent_uploads(int $limit = 50, ?int $albumId = null): array {
    $limit = max(1, min(500, $limit));
    $sql = "SELECT u.*, a.title album_title FROM member_uploads u LEFT JOIN albums a ON a.id=u.album_id";
    $params = [];
    if ($albumId !== null && $albumId > 0) { $sql .= " WHERE u.album_id=?"; $params[] = $albumId; }
    $sql .= " ORDER BY u.id DESC LIMIT ".$limit;
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Kiek albume guli nario atsiustu, dar nepaskelbtu nuotrauku - "laukia perziuros". */
function member_pending_counts(): array {
    $rows = db()->query("SELECT album_id, COUNT(*) c FROM photos WHERE source_type='member_upload' AND visibility='draft' GROUP BY album_id")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) $out[(int)$r['album_id']] = (int)$r['c'];
    return $out;
}
function takeout_image_views(?array $node): ?int {
    $value = $node['imageViews'] ?? $node['photoViews'] ?? null;
    if ($value === null || $value === '') return null;
    if (is_numeric($value)) return (int)$value;
    return null;
}
function takeout_origin_paths(array $value, string $prefix=''): array {
    $out = [];
    foreach ($value as $key => $child) {
        $path = $prefix === '' ? (string)$key : $prefix.'.'.(string)$key;
        if (is_array($child) && $child) {
            $nested = takeout_origin_paths($child, $path);
            if ($nested) $out = array_merge($out, $nested);
            else $out[] = $path;
        } else {
            $out[] = $path;
        }
    }
    return array_values(array_unique($out));
}
function append_album_takeout_internal_notes(int $albumId, ?array $albumJson, array $jsonFiles): void {
    if ($albumId <= 0) return;
    $origins = [];
    $urls = [];
    $collect = function(string $label, array $j) use (&$origins, &$urls): void {
        $url = trim((string)($j['url'] ?? ''));
        if ($url !== '') $urls[$label] = $url;
        $origin = $j['googlePhotosOrigin'] ?? null;
        if (is_array($origin)) {
            foreach (takeout_origin_paths($origin) as $path) {
                $origins[$path] = ($origins[$path] ?? 0) + 1;
            }
        }
    };
    if (is_array($albumJson)) $collect('metadata.json', $albumJson);
    foreach ($jsonFiles as $name => $entry) {
        $j = $entry['json'] ?? null;
        if (!is_array($j)) continue;
        $collect((string)$name, $j);
    }
    if (!$origins && !$urls) return;
    ksort($origins);
    ksort($urls);
    $lines = ['Google Photos / Takeout metadata'];
    if ($origins) {
        $lines[] = 'Origins:';
        foreach ($origins as $origin => $count) $lines[] = '- '.$origin.': '.$count;
    }
    if ($urls) {
        $lines[] = 'URLs:';
        $i = 0;
        foreach ($urls as $name => $url) {
            $lines[] = '- '.$name.': '.$url;
            $i++;
            if ($i >= 20 && count($urls) > 20) {
                $lines[] = '- ... '.(count($urls) - $i).' more URLs';
                break;
            }
        }
    }
    $body = implode("\n", $lines);
    $hash = substr(sha1($body), 0, 12);
    $marker = '[google-takeout-metadata '.$hash.']';
    $st = db()->prepare("SELECT notes_internal FROM albums WHERE id=? LIMIT 1");
    $st->execute([$albumId]);
    $existing = (string)($st->fetchColumn() ?: '');
    if (str_contains($existing, $marker)) return;
    $block = $marker."\n".$body."\n[/google-takeout-metadata]";
    $notes = trim($existing);
    $notes = $notes === '' ? $block : $notes."\n\n".$block;
    db()->prepare("UPDATE albums SET notes_internal=?, updated_by=? WHERE id=?")->execute([$notes, $_SESSION['admin']['id'] ?? null, $albumId]);
}
function album_metadata_payload(array $album, ?array $existing = null): array {
    $meta = is_array($existing) ? $existing : [];
    $title = trim((string)($album['title'] ?? ($meta['title'] ?? '')));
    if ($title !== '') $meta['title'] = $title;
    $description = (string)($album['description'] ?? ($meta['description'] ?? ''));
    if ($description !== '') $meta['description'] = $description;
    elseif (!array_key_exists('description', $meta)) $meta['description'] = '';
    $visibility = (string)($album['visibility'] ?? ($meta['access'] ?? 'draft'));
    $meta['access'] = $visibility === 'published' ? 'public' : $visibility;
    if (!empty($album['event_date'])) {
        $meta['date'] = [
            'timestamp' => (string)strtotime((string)$album['event_date'] . ' 00:00:00 UTC'),
            'formatted' => (string)$album['event_date'],
        ];
    } elseif (!array_key_exists('date', $meta) && !empty($meta['date'])) {
        $meta['date'] = $meta['date'];
    }
    if (!empty($album['location_name'])) $meta['locationName'] = (string)$album['location_name'];
    if (!empty($album['sport_type'])) $meta['sportType'] = (string)$album['sport_type'];
    if (!empty($album['author_name'])) $meta['authorName'] = (string)$album['author_name'];
    if (!empty($album['copyright_text'])) $meta['copyrightText'] = (string)$album['copyright_text'];
    if (!empty($album['seo_title'])) $meta['seoTitle'] = (string)$album['seo_title'];
    if (!empty($album['seo_description'])) $meta['seoDescription'] = (string)$album['seo_description'];
    if (!empty($album['dbsportas_url'])) $meta['dbsportasUrl'] = (string)$album['dbsportas_url'];
    if (!empty($album['klajunas_url'])) $meta['klajunasUrl'] = (string)$album['klajunas_url'];
    if (!empty($album['other_url'])) $meta['otherUrl'] = (string)$album['other_url'];
    if (!empty($album['source_path'])) $meta['sourcePath'] = (string)$album['source_path'];
    if (!empty($album['slug'])) $meta['slug'] = (string)$album['slug'];
    return $meta;
}
function photo_metadata_payload(array $photo, ?array $existing = null): array {
    $meta = is_array($existing) ? $existing : [];
    $title = trim((string)($photo['title'] ?? ''));
    if ($title !== '') $meta['title'] = $title;
    elseif (!array_key_exists('title', $meta) && !empty($photo['original_filename'])) $meta['title'] = (string)$photo['original_filename'];

    $description = (string)($photo['description'] ?? '');
    if ($description !== '') $meta['description'] = $description;
    elseif (!array_key_exists('description', $meta)) $meta['description'] = '';

    if (!empty($photo['taken_at'])) {
        $ts = strtotime((string)$photo['taken_at'] . ' UTC');
        $meta['photoTakenTime'] = [
            'timestamp' => (string)$ts,
            'formatted' => gmdate('M j, Y, g:i:s A T', $ts),
        ];
    }
    if (isset($photo['latitude'], $photo['longitude']) && $photo['latitude'] !== '' && $photo['longitude'] !== '') {
        $meta['geoData'] = [
            'latitude' => (float)$photo['latitude'],
            'longitude' => (float)$photo['longitude'],
            'altitude' => 0,
            'latitudeSpan' => 0,
            'longitudeSpan' => 0,
        ];
    }
    $views = $photo['photo_views'] ?? ($meta['imageViews'] ?? null);
    if ($views !== null && $views !== '') {
        $meta['imageViews'] = (string)$views;
    }
    if (!empty($photo['author_name'])) $meta['authorName'] = (string)$photo['author_name'];
    if (!empty($photo['copyright_text'])) $meta['copyrightText'] = (string)$photo['copyright_text'];
    if (!empty($photo['credit_line'])) $meta['creditLine'] = (string)$photo['credit_line'];
    if (!empty($photo['city'])) $meta['city'] = (string)$photo['city'];
    if (!empty($photo['region'])) $meta['region'] = (string)$photo['region'];
    if (!empty($photo['country'])) $meta['country'] = (string)$photo['country'];
    if (!empty($photo['country_code'])) $meta['countryCode'] = (string)$photo['country_code'];
    if (!empty($photo['camera_make'])) $meta['cameraMake'] = (string)$photo['camera_make'];
    if (!empty($photo['camera_model'])) $meta['cameraModel'] = (string)$photo['camera_model'];
    if (!empty($photo['lens_model'])) $meta['lensModel'] = (string)$photo['lens_model'];
    if (!empty($photo['focal_length'])) $meta['focalLength'] = (string)$photo['focal_length'];
    if (!empty($photo['aperture'])) $meta['aperture'] = (string)$photo['aperture'];
    if (!empty($photo['shutter_speed'])) $meta['shutterSpeed'] = (string)$photo['shutter_speed'];
    if (!empty($photo['iso_value'])) $meta['isoValue'] = (int)$photo['iso_value'];
    if (!empty($photo['album_id'])) $meta['albumId'] = (int)$photo['album_id'];
    if (!empty($photo['visibility'])) $meta['visibility'] = (string)$photo['visibility'];
    if (array_key_exists('is_downloadable', $photo)) $meta['downloadable'] = !empty($photo['is_downloadable']);
    return $meta;
}
function persist_album_metadata_json(int $albumId): void {
    if ($albumId <= 0) return;
    $st = db()->prepare("SELECT * FROM albums WHERE id=? LIMIT 1");
    $st->execute([$albumId]);
    $album = $st->fetch(PDO::FETCH_ASSOC);
    if (!$album) return;
    $meta = album_metadata_payload($album, !empty($album['metadata_json']) ? json_decode((string)$album['metadata_json'], true) : null);
    db()->prepare("UPDATE albums SET metadata_json=?, updated_by=? WHERE id=?")->execute([json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $_SESSION['admin']['id'] ?? null, $albumId]);
}
function persist_photo_metadata_json(int $photoId): void {
    if ($photoId <= 0) return;
    $st = db()->prepare("SELECT * FROM photos WHERE id=? LIMIT 1");
    $st->execute([$photoId]);
    $photo = $st->fetch(PDO::FETCH_ASSOC);
    if (!$photo) return;
    $meta = photo_metadata_payload($photo, !empty($photo['metadata_json']) ? json_decode((string)$photo['metadata_json'], true) : null);
    db()->prepare("UPDATE photos SET metadata_json=?, updated_by=? WHERE id=?")->execute([json_encode($meta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $_SESSION['admin']['id'] ?? null, $photoId]);
}
function album_marker_payload(array $album): string {
    return json_encode([
        'type' => 'klajunas-foto-album-marker',
        'do_not_delete' => true,
        'note' => 'Album marker for B2 virtual folder. Do not delete in admin/B2.',
        'album_id' => (int)($album['id'] ?? 0),
        'title' => (string)($album['title'] ?? ''),
        'source_path' => trim((string)($album['source_path'] ?? ''), '/'),
        'updated_at' => date('c'),
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
}
function ensure_album_marker_file(array $album, ?array &$upload = null): void {
    $prefix = trim((string)($album['source_path'] ?? ''), '/');
    if ($prefix === '') return;
    if ($upload === null) $upload = b2_upload_url();
    b2_upload_data(album_marker_payload($album), $prefix.'/.album-netrinti.json', 'application/json', $upload);
}
function set_setting(string $key, string $value, string $group='general', string $type='string'): void {
    db()->prepare("INSERT INTO settings(`group`,`key`,`value`,type) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE `group`=VALUES(`group`), value=VALUES(value), type=VALUES(type)")
        ->execute([$group, $key, json_encode($value, JSON_UNESCAPED_UNICODE), $type]);
}
function metadata_sidecar_backfill_needed(): bool {
    return setting('metadata_sidecars_backfilled', '') !== '1';
}
function backfill_metadata_sidecars(): array {
    $albums = 0; $photos = 0;
    $albumIds = db()->query("SELECT id FROM albums WHERE metadata_json IS NULL OR metadata_json='' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($albumIds as $albumId) {
        persist_album_metadata_json((int)$albumId);
        $albums++;
    }
    $photoIds = db()->query("SELECT id FROM photos WHERE metadata_json IS NULL OR metadata_json='' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($photoIds as $photoId) {
        persist_photo_metadata_json((int)$photoId);
        $photos++;
    }
    set_setting('metadata_sidecars_backfilled', '1');
    set_setting('metadata_sidecars_backfilled_at', date('c'));
    return ['albums' => $albums, 'photos' => $photos];
}
function need_login(): void {
    if (empty($_SESSION['admin'])) {
        if (wants_json_response()) json_exit(['ok' => false, 'error' => 'Login required.'], 401);
        // Po prisijungimo grizti i prasyta puslapi (pvz. /?a=<slug>/edit nuoroda).
        // Saugomi tik GET page/id/slug - jokiu action'u ir jokiu isoriniu adresu.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && empty($_GET['action']) && isset($_GET['page']) && $_GET['page'] !== 'login') {
            $_SESSION['after_login'] = '?'.http_build_query(array_intersect_key($_GET, ['page' => 1, 'id' => 1, 'slug' => 1]));
        }
        go('?page=login');
    }
}
function current_admin(): array { return $_SESSION['admin'] ?? []; }
function current_admin_id(): int { return (int)(current_admin()['id'] ?? 0); }
function current_admin_role(): string { return strtolower((string)(current_admin()['role'] ?? '')); }
function is_superadmin(): bool { return current_admin_role() === 'superadmin'; }
function require_superadmin(): void {
    need_login();
    if (!is_superadmin()) {
        if (wants_json_response()) json_exit(['ok' => false, 'error' => 'Forbidden'], 403);
        http_response_code(403);
        exit('Forbidden');
    }
}
function album_editable_by_current_user(array $album): bool { return is_superadmin() || (current_admin_id() > 0 && (int)($album['created_by'] ?? 0) === current_admin_id()); }
function require_album_editable_by_id(int $albumId): array {
    $st = db()->prepare("SELECT * FROM albums WHERE id=? LIMIT 1");
    $st->execute([$albumId]);
    $album = $st->fetch(PDO::FETCH_ASSOC);
    if (!$album) {
        if (wants_json_response()) json_exit(['ok' => false, 'error' => 'Album not found'], 404);
        http_response_code(404);
        exit('Album not found');
    }
    if (!album_editable_by_current_user($album)) {
        if (wants_json_response()) json_exit(['ok' => false, 'error' => 'Forbidden'], 403);
        http_response_code(403);
        exit('Forbidden');
    }
    return $album;
}
function require_photo_editable_by_id(int $photoId): array {
    $st = db()->prepare("SELECT p.*, a.created_by album_created_by, a.title album_title FROM photos p JOIN albums a ON a.id=p.album_id WHERE p.id=? LIMIT 1");
    $st->execute([$photoId]);
    $photo = $st->fetch(PDO::FETCH_ASSOC);
    if (!$photo) {
        if (wants_json_response()) json_exit(['ok' => false, 'error' => 'Photo not found'], 404);
        http_response_code(404);
        exit('Photo not found');
    }
    if (!is_superadmin() && (current_admin_id() <= 0 || (int)($photo['album_created_by'] ?? 0) !== current_admin_id())) {
        if (wants_json_response()) json_exit(['ok' => false, 'error' => 'Forbidden'], 403);
        http_response_code(403);
        exit('Forbidden');
    }
    return $photo;
}
function google_payload(string $jwt): array {
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token='.rawurlencode($jwt));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>12, CURLOPT_HTTPHEADER=>['Accept: application/json']]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $p = json_decode((string)$body, true);
    if ($code !== 200 || !is_array($p)) throw new RuntimeException('Google token verification failed.');
    if (($p['aud'] ?? '') !== GOOGLE_CLIENT_ID) throw new RuntimeException('Google client mismatch.');
    if (($p['email_verified'] ?? '') !== 'true') throw new RuntimeException('Google email is not verified.');
    return $p;
}
function do_login(): void {
    csrf();
    try {
        $p = google_payload(trim((string)($_POST['credential'] ?? '')));
        $email = strtolower((string)($p['email'] ?? ''));
        $role = '';
        $q = db()->prepare("SELECT * FROM admins WHERE email=? AND is_active=1 LIMIT 1");
        $q->execute([$email]);
        $admin = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($admin) {
            db()->prepare("UPDATE admins SET google_sub=COALESCE(NULLIF(?,''),google_sub), name=COALESCE(NULLIF(?,''),name), avatar_url=COALESCE(NULLIF(?,''),avatar_url), last_login_at=NOW() WHERE id=?")
                ->execute([$p['sub'] ?? null, $p['name'] ?? $email, $p['picture'] ?? null, (int)$admin['id']]);
            $q->execute([$email]);
            $admin = $q->fetch(PDO::FETCH_ASSOC) ?: $admin;
        } elseif (in_array($email, ALLOWED_EMAILS, true)) {
            db()->prepare("INSERT INTO admins(google_sub,email,name,avatar_url,role,is_active,last_login_at) VALUES(?,?,?,?, 'superadmin',1,NOW()) ON DUPLICATE KEY UPDATE google_sub=VALUES(google_sub), name=VALUES(name), avatar_url=VALUES(avatar_url), role='superadmin', is_active=1, last_login_at=NOW()")
                ->execute([$p['sub'] ?? null, $email, $p['name'] ?? $email, $p['picture'] ?? null]);
            $q->execute([$email]);
            $admin = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$admin) throw new RuntimeException('Unauthorized Google account: '.$email);
        $_SESSION['admin'] = $admin;
        audit('admin', (int)$_SESSION['admin']['id'], 'login', 'Admin logged in');
        $next = (string)($_SESSION['after_login'] ?? '');
        unset($_SESSION['after_login']);
        go(preg_match('~^\?page=[a-z_]+(&[A-Za-z0-9_=%.\-]*)?$~', $next) ? $next : '?page=dashboard');
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); go('?page=login'); }
}
function head(string $title): void {
    echo '<!doctype html><html lang="lt"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title><style>
    :root{color-scheme:dark;--bg:#0b0e12;--panel:#151a20;--panel2:#10151b;--input:#0d1116;--line:rgba(255,255,255,.09);--line-strong:rgba(255,255,255,.22);--text:#e8eef6;--muted:#a7b3c2;--accent:#ffb74a;--accent-soft:rgba(255,183,74,.14);--accent-line:rgba(255,183,74,.4);--accent-ink:#ffc46b;--btn:#1c222b;--th:#11161c;--td-line:#252d36;--topbar:rgba(11,14,18,.88);--ok-bg:#132018;--err-bg:#251114;--err-line:#5c2229;--radius:14px}body.light{color-scheme:light;--bg:#fdfdfc;--panel:#ffffff;--panel2:#f7f7f4;--input:#ffffff;--line:#e8e8e2;--line-strong:#cfcfc8;--text:#1a1a1a;--muted:#6b6b6b;--accent:#ffb74a;--accent-soft:rgba(255,183,74,.16);--accent-line:rgba(214,143,32,.45);--accent-ink:#8a5a10;--btn:#ffffff;--th:#f1f1ec;--td-line:#eeeee8;--topbar:rgba(253,253,252,.9);--ok-bg:#eaf6ee;--err-bg:#fdeaea;--err-line:#e3b3b3}*{box-sizing:border-box}body{margin:0;background:radial-gradient(1200px 800px at 50% -200px,var(--accent-soft),transparent 60%),var(--bg);font-family:system-ui,-apple-system,Segoe UI,sans-serif;color:var(--text);font-size:15px}body.preview-open{overflow:hidden}a{color:inherit;text-decoration:none}.top{min-height:76px;border-bottom:1px solid var(--line);position:sticky;top:0;background:var(--topbar);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);z-index:2}.top-inner{max-width:1200px;margin:0 auto;padding:14px 16px;display:flex;align-items:center;gap:18px}.brand{font-size:24px;font-weight:850}.nav{display:flex;gap:6px;flex-wrap:nowrap;overflow-x:auto;min-width:0;flex:0 1 auto;scrollbar-width:none}.nav::-webkit-scrollbar{display:none}.nav a,.btn,button{border:1px solid var(--line);background:var(--btn);color:var(--text);border-radius:99px;padding:9px 15px;font-weight:650;cursor:pointer;white-space:nowrap;transition:border-color .15s,background .15s}.nav a:hover,.btn:hover,button:hover{border-color:var(--line-strong)}.nav a{padding:7px 12px;font-size:13.5px;white-space:nowrap}.nav a.active,.primary{border-color:var(--accent-line)!important;background:var(--accent-soft)!important;color:var(--accent-ink)!important}.wrap{max-width:1200px;margin:auto;padding:24px 16px 44px}.spacer{flex:1}.muted{color:var(--muted)}.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:18px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}.gateway-card{display:flex;flex-direction:column;transition:border-color .15s ease,transform .15s ease,background .15s ease}.gateway-hint{margin-top:6px;font-size:12px;color:var(--muted);line-height:1.35;min-height:2.7em;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2;overflow:hidden}.dash-panel{display:flex;flex-direction:column;gap:8px;padding:14px 16px}.dash-panel h2{margin:0 0 2px;font-size:15px}.dash-row{display:flex;align-items:center;gap:8px;font-size:13.5px}.dash-row .badge{flex-shrink:0;min-width:52px;text-align:center}.dash-note{font-size:12px;color:var(--muted);line-height:1.4;margin:0}.gateway-card:hover{border-color:var(--accent-line);background:var(--panel2);transform:translateY(-1px)}.metric{font-size:30px;font-weight:850;line-height:1.1}table{width:100%;border-collapse:collapse;background:var(--panel);border:1px solid var(--line)}th,td{border-bottom:1px solid var(--td-line);padding:10px;text-align:left;vertical-align:top}th{font-size:12px;color:var(--muted);text-transform:uppercase;background:var(--th)}input,select,textarea{width:100%;background:var(--input);border:1px solid var(--line);color:var(--text);border-radius:10px;padding:10px;font:inherit}input[type=checkbox]{width:18px;height:18px;min-width:18px;padding:0;margin:0;accent-color:var(--accent);cursor:pointer;vertical-align:middle}label{display:block;font-size:12px;color:var(--muted);margin:0 0 6px}.formgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:14px 0}.album-fields-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-top:10px}.album-field-panel{border:2px solid var(--accent-line);border-radius:10px;padding:14px;background:var(--panel2);min-width:0}.album-field-panel h3{margin:0 0 12px}.album-field-panel .formgrid{grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin:0 0 14px}.album-field-panel textarea{min-height:112px}.album-field-panel .full{grid-column:1/-1}.album-field-panel .field-full{grid-column:1/-1}.actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:14px 0}.badge{display:inline-block;border:1px solid var(--line);border-radius:999px;padding:3px 8px;font-size:12px;color:var(--muted)}.flash{padding:12px 14px;border-radius:8px;margin:0 0 12px;border:1px solid var(--line);background:var(--ok-bg)}.err{background:var(--err-bg);border-color:var(--err-line)}.login{min-height:100vh;display:grid;place-items:center;padding:30px}.login .card{max-width:560px}.logo{width:132px;height:132px;object-fit:cover;margin-bottom:14px}.small{font-size:12px}.photo-board{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:12px}.photo-tile{background:var(--panel2);border:1px solid var(--line);border-radius:8px;overflow:hidden;cursor:grab;position:relative}.photo-tile.dragging{opacity:.45;outline:2px solid var(--accent)}.photo-tile:focus{outline:2px solid var(--accent);outline-offset:2px}.photo-tile img,.photo-tile .no-thumb{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;background:var(--bg)}.photo-tile .no-thumb{display:grid;place-items:center;color:var(--muted)}.photo-tile-body{padding:9px}.photo-title{font-weight:800;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.photo-tools{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px}.mini{padding:5px 10px;font-size:12px;border-radius:99px}.pill-on{border-color:var(--accent-line)!important;background:var(--accent-soft)!important;color:var(--accent-ink)!important}.order-save{position:sticky;bottom:12px;z-index:3}.cover-flag{position:absolute;top:8px;left:8px;background:var(--accent-soft);border:1px solid var(--accent-line);color:var(--accent-ink);border-radius:999px;padding:3px 7px;font-size:12px}.photo-preview-overlay{position:fixed;inset:0;z-index:60;display:none;background:rgba(5,8,10,.96)}.photo-preview-overlay.open{display:block}.photo-preview-shell{width:100%;height:100%;display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,370px);background:#070b0e;outline:0}.photo-preview-media{display:grid;grid-template-rows:minmax(0,1fr);place-items:center;padding:22px;min-width:0;min-height:0;overflow:hidden}.photo-preview-media img{max-width:100%;max-height:100%;object-fit:contain;background:#05080b;border-radius:8px;box-shadow:0 14px 55px rgba(0,0,0,.45)}.photo-preview-meta{border-left:1px solid var(--line);background:var(--panel);padding:22px 18px;overflow:auto}.preview-topline{font-size:12px;color:var(--muted);line-height:1.4}.photo-preview-title{margin:6px 0 8px;font-size:18px;line-height:1.15;font-weight:850;word-break:break-word}.preview-file{font-size:13px;font-weight:750;margin-bottom:14px;word-break:break-word}.preview-dl{display:grid;grid-template-columns:max-content 1fr;gap:8px 14px;margin:0;font-size:14px}.preview-dl dt{color:var(--muted)}.preview-dl dd{margin:0;word-break:break-word}.preview-admin{margin-top:16px;padding-top:14px;border-top:1px solid var(--line)}.preview-section-title{margin:0 0 12px;font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}.preview-dl-admin dd{white-space:pre-wrap}.photo-preview-close,.photo-preview-nav{position:absolute;width:44px;height:44px;border-radius:99px;border:1px solid var(--line);background:var(--btn);color:var(--text);display:grid;place-items:center;font-size:28px;font-weight:700;cursor:pointer;z-index:2}.photo-preview-close{top:16px;right:16px}.photo-preview-nav.prev{left:16px;top:50%;transform:translateY(-50%)}.photo-preview-nav.next{right:calc(370px + 16px);top:50%;transform:translateY(-50%)}#photoListPreviewOverlay .photo-preview-nav.next{right:calc(320px + 16px)}.photo-preview-nav:disabled{opacity:.45;cursor:not-allowed}.photo-preview-close svg,.photo-preview-nav svg{width:22px;height:22px;stroke:currentColor;fill:none;stroke-width:2.4;stroke-linecap:round;stroke-linejoin:round;display:block}.photo-preview-close:hover,.photo-preview-nav:hover:not(:disabled){border-color:var(--accent-line);background:var(--accent-soft);color:var(--accent-ink)}@media(min-width:1440px){.top-inner,.wrap{max-width:1400px}}@media(max-width:1280px){.top-inner>span.muted{display:none}}@media(max-width:900px){.photo-preview-shell{grid-template-columns:1fr;grid-template-rows:minmax(0,1fr) minmax(250px,38vh)}.photo-preview-meta{border-left:0;border-top:1px solid var(--line)}.photo-preview-close{top:10px;right:10px}.photo-preview-nav.prev{left:10px}.photo-preview-nav.next{right:10px}}@media(max-width:1100px){.album-fields-grid{grid-template-columns:1fr}}@media(max-width:760px){.top-inner{align-items:flex-start;flex-direction:column}.wrap{padding:16px}table{font-size:13px}.photo-board{grid-template-columns:repeat(auto-fill,minmax(130px,1fr))}}
/* --- Vertikalus tankis ---
   Antrastes neturejo savo taisykliu ir naudojo narsykles numatytasias
   (h1 ~21px is virsaus IR apacios). Prie kelių kortelių viename puslapyje tai
   susideda i tuscia trecdali ekrano. */
.wrap>h1{font-size:24px;line-height:1.2;margin:0 0 14px;letter-spacing:-.02em}
.wrap>h2{font-size:18px;margin:22px 0 10px}
.card>h2:first-child,.card>h3:first-child{margin-top:0}
.card h2{font-size:17px;margin:0 0 10px}
.card h3{font-size:14px;margin:18px 0 8px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)}
.card>p{margin:0 0 12px}
.card>p:last-child{margin-bottom:0}
.card+.card{margin-top:14px}
.card table{margin-top:2px}
.actions{margin:10px 0}
.actions:first-child{margin-top:0}
/* --- B2 Sync lentele: viena eilute vienam aplankui --- */
/* Plati lentele slenka savo konteineryje, o ne stumia puslapio. Be sito
   naršykle spaudzia stulpelius tol, kol kelias laužosi po viena simbolį. */
.tablewrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.tablewrap>table{min-width:760px;width:auto}
.b2-row>td{padding-top:7px;padding-bottom:7px;vertical-align:middle}
.b2-row .b2-prefix{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px;margin-top:3px;overflow-wrap:anywhere;line-height:1.4}
.b2-row .b2-prefix .dim{color:var(--muted);opacity:.7}
.b2-row .b2-album{min-width:280px;max-width:460px}
.b2-row .b2-album>a{white-space:normal;display:inline-block;max-width:100%;text-align:left;line-height:1.35}
.b2-row .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.b2-row .b2-quick{white-space:nowrap}
.spill{white-space:nowrap}
.infobtn{border:1px solid var(--line);background:var(--btn);color:var(--muted);width:22px;height:22px;line-height:1;border-radius:50%;padding:0;font-size:12px;font-weight:800;font-style:italic;cursor:pointer;vertical-align:middle}
.infobtn:hover{border-color:var(--line-strong);color:var(--text)}
.infobtn.on{border-color:var(--accent-line);background:var(--accent-soft);color:var(--accent-ink)}
.b2-detail>td{background:var(--panel2);border-top:0}
/* Veiksmu meniu: <details> kaip dropdown. Iskleistas turinys pozicionuojamas
   absoliuciai, kad neisstumtu lenteles eilutes. */
.rowmenu{position:relative;display:inline-block}
.rowmenu>summary{list-style:none;cursor:pointer;border:1px solid var(--line);background:var(--btn);color:var(--text);border-radius:99px;padding:6px 12px;font-size:12.5px;font-weight:650;white-space:nowrap;user-select:none}
.rowmenu>summary::-webkit-details-marker{display:none}
.rowmenu>summary::after{content:" ▾";color:var(--muted)}
.rowmenu>summary:hover{border-color:var(--line-strong)}
.rowmenu[open]>summary{border-color:var(--accent-line);background:var(--accent-soft);color:var(--accent-ink)}
/* Plotis fiksuotas, o ne tik max-width: kelio eilutes yra vienas ilgas zodis be
   tarpu, todel be laužymo jos isspausdavo turini uz popup ribu. */
.rowmenu-pop{position:absolute;right:0;top:calc(100% + 6px);z-index:40;
  width:min(430px,86vw);max-width:min(430px,86vw);
  background:var(--panel);border:1px solid var(--line-strong);border-radius:12px;padding:10px;
  box-shadow:0 14px 40px rgba(0,0,0,.45);display:flex;flex-direction:column;gap:8px;text-align:left;
  overflow-wrap:anywhere;word-break:break-word}
.rowmenu-pop *{min-width:0;max-width:100%}
.rowmenu-pop span{display:block;overflow-wrap:anywhere}
/* Lentele slenka horizontaliai, todel atidarytas meniu buvo nukerpamas.
   Atidarius ji apkarpyma laikinai isjungiam. */
.tablewrap:has(details.rowmenu[open]){overflow:visible}
.rowmenu-pop .actions{margin:0;flex-direction:column;align-items:stretch;gap:5px}
.rowmenu-pop form{border-bottom:1px solid var(--td-line);padding-bottom:8px}
.rowmenu-pop form:last-of-type{border-bottom:0;padding-bottom:0}
.rowmenu-pop input[type=text],.rowmenu-pop input:not([type]){width:100%;min-width:0}
.rowmenu-pop button{width:100%}
.b2-detail-in{display:flex;gap:20px;flex-wrap:wrap;padding:4px 2px 8px}
.b2-detail-status{flex:1 1 260px;min-width:0}
.b2-detail-actions{flex:2 1 420px;min-width:0}
.b2-detail-actions .actions{margin:0 0 6px}
/* --- Albumu tinklelis: narsymo rodinys salia lenteles --- */
.albumgrid{display:none;grid-template-columns:repeat(auto-fill,minmax(232px,1fr));gap:18px;margin-top:12px}
body.view-grid .albumgrid{display:grid}
body.view-grid table.albums-table{display:none}
body.view-grid .bulkbar{display:none}
.acard{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column;transition:border-color .15s,transform .15s}
.acard:hover{border-color:var(--line-strong);transform:translateY(-2px)}
.acard-cover{position:relative;aspect-ratio:4/3;display:block;overflow:hidden;background:var(--panel2)}
.acard-cover img{width:100%;height:100%;object-fit:cover;display:block}
.acard-ph{position:absolute;inset:0;display:grid;place-items:center;font-size:34px;font-weight:850;letter-spacing:-.03em;color:var(--line-strong);font-variant-numeric:tabular-nums}
.acard-count{position:absolute;right:9px;top:9px;font-size:12px;font-weight:750;color:#fff;background:rgba(0,0,0,.5);padding:3px 9px;border-radius:99px;font-variant-numeric:tabular-nums}
.acard-flag{position:absolute;left:9px;top:9px;font-size:10.5px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#fff;background:rgba(0,0,0,.5);padding:3px 8px;border-radius:6px}
.acard-body{padding:11px 12px 13px;display:flex;flex-direction:column;gap:7px;flex:1}
.acard-title{font-size:14px;font-weight:700;line-height:1.35;margin:0;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.acard-title a{color:var(--text);text-decoration:none}
.acard-title a:hover{color:var(--accent-ink)}
.acard-meta{display:flex;flex-wrap:wrap;gap:4px 12px;color:var(--muted);font-size:12.5px;font-variant-numeric:tabular-nums;margin-top:auto}
.acard-meta .warn{color:var(--accent-ink);opacity:.9}
.spill{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:750;letter-spacing:.03em;text-transform:uppercase;padding:3px 9px;border-radius:99px;border:1px solid}
.spill .d{width:6px;height:6px;border-radius:50%;background:currentColor}
.spill.s-published{color:#5fd08a;background:rgba(95,208,138,.13);border-color:rgba(95,208,138,.34)}
body.light .spill.s-published{color:#1f7a45;background:rgba(31,122,69,.1);border-color:rgba(31,122,69,.28)}
.spill.s-draft,.spill.s-private,.spill.s-hidden{color:var(--muted);background:var(--th);border-color:var(--line)}
/* --- Sonine navigacija: meniu is virsutines juostos perkeltas i kairę --- */
.shell{display:flex;align-items:flex-start;max-width:1400px;margin:0 auto}
.side{flex:0 0 208px;position:sticky;top:76px;align-self:flex-start;padding:18px 10px 40px;border-right:1px solid var(--line)}
.side .nav{display:flex;flex-direction:column;gap:3px;overflow:visible;flex-wrap:nowrap}
.side .nav a{border:0;background:transparent;border-radius:9px;padding:9px 12px;font-size:14px;font-weight:600;text-align:left;width:100%;white-space:nowrap}
.side .nav a:hover{background:var(--btn)}
.side .nav a.active{border:0!important;background:var(--accent-soft)!important;color:var(--accent-ink)!important}
.side .nav-group{margin:14px 0 4px;padding:0 12px;font-size:11px;font-weight:750;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);opacity:.75}
.shell>main.wrap{flex:1 1 auto;min-width:0;max-width:none;margin:0}
@media(max-width:900px){
.shell{flex-direction:column}
.side{position:static;flex:0 0 auto;width:100%;padding:10px;border-right:0;border-bottom:1px solid var(--line)}
.side .nav{flex-direction:row;overflow-x:auto;gap:6px}
.side .nav a{width:auto;border-radius:99px}
.side .nav-group{display:none}
}
/* Puslapio pavadinimas persikele i virsutine juosta salia logotipo - ten buvo
   tuscia vieta, o turinys del atskiros antrastes eilutes prasidedavo per zemai.
   Pati h1 lieka viena: puslapiuose ji tik paslepiama, nes rasoma cia. */
/* Dvi to paties albumo eilutes skiriasi tik keliu, todel role rasoma zodziais:
   vien is spalvos ar zenkliuko nesuprasi, kuris kelias yra tikslas. */
/* Filtrai uzimdavo keturias eilutes pries lentele: paieska su fiksuotu 520 px
   plociu isstumdavo likusius laukus i kita eilute, o albumu ir metu filtrai
   gulejo dar zemiau. Dabar visi filtrai teka viena juosta ir lauzosi tik tada,
   kai tikrai netelpa; paieska pleciasi ir traukiasi pagal likusia vieta. */
.albumfilter{gap:8px;margin:10px 0 0;align-items:center}
.albumfilter .filter-q{flex:1 1 240px;min-width:180px;max-width:520px}
.albumfilter select{flex:0 1 auto;max-width:26ch}
.bulkbar{margin-top:8px!important}

/* Albumu sarasas talpino maziau irasu nei tinklelis, nors lentele tam ir skirta.
   Priezastis - ne teksto dydis, o lauzymas: Edit/Delete, tvarkos rodykles ir
   zenkleliai kiekviename langelyje krisdavo i atskiras eilutes, ir viena eilute
   isaugdavo iki keliu simtu pikseliu. Neleidziam lauzyti ir suspaudziam valdiklius. */
.albums-table td,.albums-table th{padding:6px 8px;vertical-align:middle}
.albums-table td .actions{margin:0;gap:4px;flex-wrap:nowrap;align-items:center;justify-content:flex-start}
.albums-table td .btn,.albums-table td button{padding:4px 9px;font-size:12px;line-height:1.2}
.albums-table td .mini{padding:3px 8px;font-size:11.5px}
.albums-table td .badge{padding:2px 7px;font-size:11px}
.albums-table td:nth-child(2),.albums-table td:nth-child(3){white-space:nowrap}
.albums-table th:nth-child(2),.albums-table td:nth-child(2){width:1%;padding-left:4px;padding-right:4px}
.albums-table th:nth-child(3),.albums-table td:nth-child(3){text-align:center}
.albums-table td:nth-child(3) .actions{justify-content:center}
.albums-table td:nth-child(2) .actions{flex-direction:column;flex-wrap:nowrap;align-items:center}
/* "Needs fixing" ir "Storage" gali tureti kelis zenklelius - leidziam lauzyti,
   bet be tarpu tarp eiluciu, kad nekiltu aukstis. */
.albums-table td:nth-child(9),.albums-table td:nth-child(10){white-space:normal;line-height:1.35}
.albums-table td:nth-child(9) .badge,.albums-table td:nth-child(10) .badge{margin:1px 2px 1px 0}
.albums-table td:nth-child(4){min-width:170px}

/* Dvi CSV puslapio kortelės. Tinklelis jas ir taip ištempia iki vienodo aukščio,
   todėl užtenka nustatyti vienodą stulpelio plotį. Veiksmų į apačią NEstumiam:
   bandžius taip, Export kortelėje atsivėrė tuščia duobė per vidurį. Tuštuma
   trumpesnėje kortelėje turi likti apačioje - tai atrodo natūraliai. */
.csvgrid{grid-template-columns:repeat(auto-fit,minmax(320px,1fr));align-items:stretch}
.csvgrid>.card>h2{margin-top:0}
.csvgrid>.card p:last-child{margin-bottom:0}
.role{display:inline-block;margin-left:8px;padding:1px 7px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.02em;vertical-align:1px;white-space:nowrap}
.role-canon{background:var(--accent-soft);border:1px solid var(--accent-line);color:var(--accent-ink)}
.role-old{background:transparent;border:1px solid var(--line-strong);color:var(--muted)}
.pagetitle{margin:0;font-size:22px;font-weight:850;line-height:1.1;letter-spacing:-.01em;padding-left:18px;border-left:1px solid var(--line);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0}
.wrap h1:not(.keep){display:none}
/* Kai antraste buvo eiluteje kartu su mygtukais (Albums, Access, Audit),
   ja paslepus space-between nuspaustu mygtukus i kaire - grazinam desinen. */
.wrap .actions:has(>h1:not(.keep)){justify-content:flex-end!important}

/* Meniui persikelus i sonine juosta, virsutinei juostai nebeliko ko deti i
   atskiras eilutes. Sena taisykle ja vertė stulpeliu ir siaurame lange
   logotipas su dviem mygtukais suvalgydavo puse ekrano. */
@media(max-width:760px){
.pagetitle{font-size:17px;padding-left:10px}
.top{min-height:0}
.top-inner{flex-direction:row;align-items:center;flex-wrap:wrap;gap:8px;padding:10px 12px}
.top-inner .brand img{height:34px}
.top-inner .btn{padding:7px 12px;font-size:13px}
.side{padding:8px 10px}
.wrap{padding:14px 12px 40px}
.wrap h1{font-size:22px}
}
</style><script src="/assets/vendor/heic2any/heic2any.min.js"></script><script src="/assets/vendor/heic-to/heic-to.js"></script></head><body><script>try{if(localStorage.getItem("kadmin_theme")==="light")document.body.classList.add("light");if((localStorage.getItem("kadmin_albumview")||"grid")==="grid")document.body.classList.add("view-grid");}catch(e){document.body.classList.add("view-grid");}</script>';
    foreach ($_SESSION['flash'] ?? [] as $f) echo '<div class="wrap" style="padding-bottom:0"><div class="flash '.e($f[1]).'">'.e($f[0]).(!empty($f[2]) ? ' <a href="'.e($f[2]).'" style="font-weight:750;text-decoration:underline;text-underline-offset:3px">'.e(($f[3] ?? '') !== '' ? $f[3] : 'Atidaryti').' →</a>' : '').'</div></div>';
    unset($_SESSION['flash']);
    if ($title !== 'Login') {
        $items = is_superadmin()
            ? ['dashboard'=>'Dashboard','albums'=>'Albums','photos'=>'Photos','inbox'=>'Uploads','tags'=>'Tags','b2'=>'B2 Sync','takeout'=>'Takeout','import'=>'CSV','zip'=>'ZIP','settings'=>'Settings','access'=>'Access','audit'=>'Audit','admins'=>'Admins']
            : ['dashboard'=>'Dashboard','albums'=>'Albums','photos'=>'Photos'];
        $currentPage = (string)($_GET['page'] ?? 'dashboard');
        $currentAction = (string)($_GET['action'] ?? '');
        if ($currentPage === 'takeout_preview_pending' || strpos($currentAction, 'takeout_') === 0) $currentPage = 'takeout';
        // Virsutineje juostoje lieka tik prekes zenklas ir paskyros valdikliai;
        // pati navigacija persikele i sonine juosta .side.
        echo '<div class="top"><div class="top-inner"><a class="brand" href="/" title="Atidaryti foto.klajunas.lt viešą galeriją"><img src="/foto-klajunas-logo.png" alt="foto.klajunas.lt" style="display:block;height:50px;width:auto"></a><h1 class="pagetitle">'.e($title).'</h1><div class="spacer"></div><button type="button" class="btn" id="themeToggle">◑ Light</button><span class="muted small">'.e($_SESSION['admin']['email'] ?? '').'</span><a class="btn" href="?action=logout">Logout</a></div></div>';
        echo '<div class="shell"><aside class="side"><nav class="nav">';
        $groups = ['dashboard'=>'', 'albums'=>'Turinys', 'members'=>'Įkėlimas', 'import'=>'', 'settings'=>'Sistema'];
        foreach ($items as $k=>$v) {
            if (isset($groups[$k]) && $groups[$k] !== '') echo '<div class="nav-group">'.e($groups[$k]).'</div>';
            echo '<a class="'.($currentPage===$k?'active':'').'" href="?page='.e($k).'">'.e($v).'</a>';
        }
        echo '</nav></aside><script>(function(){var b=document.getElementById("themeToggle");if(!b)return;function L(on){document.body.classList.toggle("light",on);b.textContent=on?"◐ Dark":"◑ Light";}L(document.body.classList.contains("light"));b.addEventListener("click",function(){var on=!document.body.classList.contains("light");try{localStorage.setItem("kadmin_theme",on?"light":"dark");}catch(e){}L(on);});})();</script><main class="wrap">';
    }
}
function foot(string $title): void { if ($title !== 'Login') echo '</main></div>'; if ($title !== 'Login') echo '<script>(function(){function bindChecks(root){root.querySelectorAll("input[data-master-check]").forEach(function(master){var form=master.closest("form")||document;var boxes=Array.from(form.querySelectorAll("input[type=checkbox][name=\"ids[]\"]"));function sync(){master.checked=boxes.length>0&&boxes.every(function(b){return b.checked;});master.indeterminate=boxes.some(function(b){return b.checked;})&&!master.checked;}master.addEventListener("change",function(){boxes.forEach(function(b){b.checked=master.checked;});sync();});boxes.forEach(function(b){b.addEventListener("change",sync);});sync();});}bindChecks(document);'
        // Gyva paieska: anksciau po 600 ms pauzes buvo form.submit() - visas
        // puslapis persikraudavo ir per ta laika vedami simboliai dingdavo.
        // Dabar puslapis parsiunciamas fone ir pakeiciamas tik <main> turinys,
        // o pati filtru forma (su lauku, kuriame rasoma) lieka ta pati.
        // Pasenusius atsakymus (seq) ismetam; nepavykus - senas budas.
        .'var seq=0;function liveSearch(inp){var form=inp.form;if(!form)return;var my=++seq;var u=new URL(form.getAttribute("action")||location.href,location.href);u.search=new URLSearchParams(new FormData(form)).toString();u.hash="";function fallback(){try{sessionStorage.setItem("kadmin_qfocus","1");}catch(e){}form.submit();}fetch(u.toString(),{credentials:"same-origin"}).then(function(r){if(!r.ok)throw new Error(r.status);return r.text();}).then(function(html){if(my!==seq)return;var doc=new DOMParser().parseFromString(html,"text/html");var nm=doc.querySelector("main.wrap"),om=document.querySelector("main.wrap");var nq=nm&&nm.querySelector("form input[name=q]");if(!om||!nq||!nq.form||!om.contains(form)){fallback();return;}var had=document.activeElement===inp,s=inp.selectionStart,e=inp.selectionEnd;nq.form.replaceWith(form);om.replaceWith(nm);if(had){inp.focus();try{inp.setSelectionRange(s,e);}catch(x){}}nm.querySelectorAll("script").forEach(function(o){if(form.contains(o))return;var n=document.createElement("script");n.textContent=o.textContent;o.replaceWith(n);});bindChecks(nm);history.replaceState(null,"",u.toString());}).catch(function(){if(my===seq)fallback();});}'
        .'document.querySelectorAll("form input[name=q]").forEach(function(inp){var t;inp.addEventListener("input",function(){clearTimeout(t);t=setTimeout(function(){liveSearch(inp);},400);});});try{if(sessionStorage.getItem("kadmin_qfocus")==="1"){sessionStorage.removeItem("kadmin_qfocus");var qi=document.querySelector("form input[name=q]");if(qi){qi.focus();var L=qi.value.length;qi.setSelectionRange(L,L);}}}catch(e){}})();</script>'; echo '</body></html>'; }
function status_select(string $name, string $value=''): string {
    $value = trim((string)$value);
    $value = $value !== '' ? normalize_visibility($value) : '';
    $h='<select name="'.e($name).'"><option value="">Any status</option>';
    foreach (STATUSES as $s) $h.='<option value="'.e($s).'"'.($value===$s?' selected':'').'>'.e($s).'</option>';
    return $h.'</select>';
}
function search_sql(array $cols, string $q, array &$params): string {
    if ($q==='') return '';
    foreach ($cols as $c) { $parts[]="$c LIKE ?"; $params[]='%'.$q.'%'; }
    return ' AND ('.implode(' OR ', $parts).')';
}
function photo_is_previewable(string $name): bool {
    return (bool)preg_match('~\\.(jpe?g|png|webp|heic|heif|gif)$~i', $name);
}
function photo_kind_label(string $name): string {
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['mp4','mov','m4v','avi','webm'], true)) return 'video';
    if ($ext === 'pdf') return 'PDF';
    if (in_array($ext, ['doc','docx'], true)) return 'doc';
    if (in_array($ext, ['xls','xlsx','csv'], true)) return 'sheet';
    return 'file';
}
function thumb_url(array $p, int $w=420): string {
    if (!preg_match('~\.(jpe?g|png|webp|heic|heif|gif)$~i', (string)$p['original_filename'])) return '';
    $displayKey = trim((string)($p['thumb_path'] ?? ''), '/');
    if ($displayKey === '') $displayKey = trim((string)($p['web_path'] ?? ''), '/');
    if ($displayKey === '') $displayKey = trim((string)($p['preview_path'] ?? ''), '/');
    if ($displayKey === '') $displayKey = trim((string)($p['compatibility_b2_key'] ?? ''), '/');
    if ($displayKey === '' && preg_match('~\.(jpe?g|png|webp|gif|heic|heif)$~i', (string)($p['b2_key'] ?? ''))) $displayKey = trim((string)$p['b2_key'], '/');
    if ($displayKey === '') return '';
    return '/img.php?'.http_build_query(['file'=>$displayKey,'w'=>$w,'h'=>280,'fit'=>'cover','q'=>76,'fmt'=>'webp','v'=>7] + photo_rot_query($p));
}
function preview_url(array $p, int $w=1600, int $h=1200): string {
    if (!preg_match('~\.(jpe?g|png|webp|heic|heif|gif)$~i', (string)$p['original_filename'])) return '';
    $displayKey = trim((string)($p['web_path'] ?? ''), '/');
    if ($displayKey === '') $displayKey = trim((string)($p['preview_path'] ?? ''), '/');
    if ($displayKey === '') $displayKey = trim((string)($p['compatibility_b2_key'] ?? ''), '/');
    if ($displayKey === '' && preg_match('~\.(jpe?g|png|webp|gif|heic|heif)$~i', (string)($p['b2_key'] ?? ''))) $displayKey = trim((string)$p['b2_key'], '/');
    if ($displayKey === '') return '';
    return '/img.php?'.http_build_query(['file'=>$displayKey,'w'=>$w,'h'=>$h,'fit'=>'contain','q'=>84,'fmt'=>'webp','v'=>7] + photo_rot_query($p));
}
function album_photo_board(int $albumId, ?int $coverPhotoId): void {
    $albumSt = db()->prepare("SELECT id,title,description,event_date,location_name,author_name,copyright_text,notes_internal,source_path,visibility,download_enabled,dbsportas_url,klajunas_url,other_url FROM albums WHERE id=? LIMIT 1");
    $albumSt->execute([$albumId]);
    $albumMeta = $albumSt->fetch() ?: ['id'=>$albumId,'title'=>'','description'=>'','event_date'=>'','location_name'=>'','author_name'=>'','copyright_text'=>'','notes_internal'=>''];
    $ps=db()->prepare("SELECT id,original_filename,b2_key,compatibility_b2_key,thumb_path,preview_path,web_path,preview_status,preview_error,original_format,converted_from_heic,visibility,is_missing,is_cover_candidate,is_downloadable,sort_order,taken_at,camera_make,camera_model,lens_model,focal_length,aperture,shutter_speed,iso_value,width,height,file_size,city,region,country,latitude,longitude,orientation,".(photo_rotation_column() ? 'rotation' : '0 AS rotation')." FROM photos WHERE album_id=? ORDER BY sort_order ASC,taken_at ASC,id ASC LIMIT 800");
    $ps->execute([$albumId]); $rows=$ps->fetchAll();
    $realB2Keys = [];
    $albumSourcePath = trim((string)($albumMeta['source_path'] ?? ''), '/');
    if ($albumSourcePath !== '') {
        try {
            foreach (b2_list_prefix($albumSourcePath, 20) as $file) {
                $name = trim((string)($file['fileName'] ?? ''), '/');
                if ($name !== '' && !str_contains($name, '/archive-originals/') && !str_contains($name, '/jpg-originals/') && takeout_media_file($name)) $realB2Keys[$name] = true;
            }
        } catch (Throwable $e) {
            flash('Could not verify B2 files for album: '.$e->getMessage(), 'err');
        }
    }
    // Dingusiu zyme nusiima PATI. Failu sarasas siam albumui jau perskaitytas,
    // tad papildomo darbo nera: jei eilute pazymeta dingusia, o failas B2 vis
    // delto yra, zyme klaidinga ir nuimama tylomis.
    //
    // Reikalinga todel, kad zyme gali uzdeti nebaigtas B2 sarasas, o naudotojui
    // tai atrodo kaip be prieżasties dingusios nuotraukos. Reikalauti, kad jis
    // suprastu, kas yra "is_missing", ir pats ji nuimtu - netinka.
    if ($realB2Keys) {
        $healIds = [];
        foreach ($rows as $rp) {
            if ((int)($rp['is_missing'] ?? 0) !== 1) continue;
            $rk = trim((string)($rp['b2_key'] ?? ''), '/');
            if ($rk !== '' && isset($realB2Keys[$rk])) $healIds[] = (int)$rp['id'];
        }
        if ($healIds) {
            $in = implode(',', array_fill(0, count($healIds), '?'));
            db()->prepare("UPDATE photos SET is_missing=0, updated_by=? WHERE album_id=? AND id IN ($in)")
                ->execute([$_SESSION['admin']['id'] ?? null, $albumId, ...$healIds]);
            foreach ($rows as $i => $rp) {
                if (in_array((int)$rp['id'], $healIds, true)) $rows[$i]['is_missing'] = 0;
            }
            flash(count($healIds).' nuotraukos vėl rastos B2 — žymė „dingusi" nuimta automatiškai.');
        }
    }
    $defaultVisibility = (string)($albumMeta['visibility'] ?? 'draft') === 'published' ? 'published' : 'draft';
    $albumBatch = album_upload_batch_tuning();
    echo '<section class="card" style="margin-top:18px"><h2>Album photo management</h2><p class="muted">Upload missing/additional photos, choose cover, hide photos, or drag photos to change album order. B2 files are only changed by upload actions.</p>';
    echo '<form method="post" action="?action=reload_album_photos" class="actions" onsubmit="return confirm(\'Reload this album photos? This marks existing DB photos as missing and deletes only B2 originals plus old supplemental metadata. The album marker and album metadata stay in B2.\')"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="album_id" value="'.e($albumId).'"><input type="hidden" name="confirm_reload" value="1"><button class="btn">Reload album photos</button><span class="muted small">Keeps <code>.album-netrinti.json</code> and <code>metadata/metadata.json</code>; use Upload photos after this.</span></form>';
    echo '<form method="post" enctype="multipart/form-data" action="?action=backfill_album_metadata_json" class="actions" style="align-items:end"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="album_id" value="'.e($albumId).'"><div style="flex:0 1 320px;min-width:240px;max-width:320px"><label>Backfill metadata JSON</label><input type="file" name="metadata_json_files[]" multiple accept=".json,application/json"></div><button class="btn">Backfill metadata JSON</button><span class="muted small">JSON only. Updates matching DB photos from Takeout sidecars and writes missing/updated sidecar JSON to B2 metadata.</span></form>';
    $heicNeedJpg = [];
    foreach ($rows as $rp) {
        $rn = (string)($rp['original_filename'] ?? $rp['b2_key'] ?? '');
        if (is_heic_name($rn) && (trim((string)($rp['compatibility_b2_key'] ?? ''), '/') === '' || (string)($rp['preview_status'] ?? '') !== 'ready')) {
            $heicNeedJpg[] = ['id' => (int)$rp['id'], 'name' => $rn];
        }
    }
    echo '<div class="actions" style="align-items:center"><button type="button" class="btn" id="recreateJpgBtn">Recreate JPG previews ('.count($heicNeedJpg).')</button><span class="muted small">HEIC/HEIF be JPG: konvertuojama naršyklėje, JPG keliamas į B2, būsena — lentelėje. Originalai neliečiami.</span></div>';
    echo '<div id="rjPanel" class="card" style="display:none;margin:10px 0"><div class="actions" style="margin:0 0 8px 0"><strong id="rjSum">—</strong><button type="button" class="mini" id="rjCopy">Kopijuoti ataskaitą</button></div><table style="font-size:13px"><tr><th style="width:34px">#</th><th>Failas</th><th style="width:230px">Būsena</th><th>Klaida</th></tr><tbody id="rjBody"></tbody></table></div>';
    echo '<script>(function(){var LIST='.json_encode($heicNeedJpg, JSON_UNESCAPED_UNICODE).';var TOKEN='.json_encode((string)token()).';var AID='.json_encode((string)$albumId).';
var btn=document.getElementById("recreateJpgBtn"),panel=document.getElementById("rjPanel"),body=document.getElementById("rjBody"),sum=document.getElementById("rjSum");if(!btn)return;
function wt(p,ms,l){return Promise.race([p,new Promise(function(_,rej){setTimeout(function(){rej(new Error(l+" neatsako ("+Math.round(ms/1000)+"s)"));},ms);})]);}
function em(e){if(!e)return"nežinoma klaida";if(e.message)return e.message;try{return JSON.stringify(e);}catch(x){return String(e);}}
async function nativeJpeg(blob){var bmp=await createImageBitmap(blob);var c=document.createElement("canvas");c.width=bmp.width;c.height=bmp.height;c.getContext("2d").drawImage(bmp,0,0);return await new Promise(function(res,rej){c.toBlob(function(b){b?res(b):rej(new Error("toBlob tuščias"));},"image/jpeg",0.9);});}
async function toJpeg(blob){var errs=[];
try{var nb=await wt(nativeJpeg(blob),20000,"native");if(nb&&nb.size>500)return nb;errs.push("native: per mažas rezultatas");}catch(e0){errs.push("native: "+em(e0));}
var c=(typeof window.HeicTo==="function")?window.HeicTo:((window.HeicTo&&typeof window.HeicTo.heicTo==="function")?window.HeicTo.heicTo:null);
if(c){try{var q=await wt(c({blob:blob,type:"image/jpeg",quality:0.9}),60000,"heic-to");return Array.isArray(q)?q[0]:q;}catch(e2){errs.push("heic-to: "+em(e2));}}
if(typeof window.heic2any==="function"){try{var o=await wt(window.heic2any({blob:blob,toType:"image/jpeg",quality:0.9}),60000,"heic2any");return Array.isArray(o)?o[0]:o;}catch(e){errs.push("heic2any: "+em(e));}}
throw new Error(errs.join(" | ")||"konverteris nepasiekiamas");}
var LOG=[];
window.addEventListener("unhandledrejection",function(ev){LOG.push({unhandled:em(ev.reason)});});
window.addEventListener("error",function(ev){LOG.push({jsError:(ev.message||"")+" @"+(ev.filename||"").split("/").pop()+":"+(ev.lineno||0)});},true);
function preflight(){var d={ua:navigator.userAgent,crossOriginIsolated:!!self.crossOriginIsolated,sharedArrayBuffer:(typeof SharedArrayBuffer!=="undefined"),heic2any:typeof window.heic2any,HeicTo:typeof window.HeicTo};try{new WebAssembly.Module(new Uint8Array([0,97,115,109,1,0,0,0]));d.wasm="ok";}catch(e){d.wasm="BLOKUOTA: "+em(e);}try{var u=URL.createObjectURL(new Blob(["self.postMessage(1)"],{type:"text/javascript"}));var w=new Worker(u);w.terminate();URL.revokeObjectURL(u);d.blobWorker="ok";}catch(e2){d.blobWorker="BLOKUOTA: "+em(e2);}return d;}
function setSt(tr,txt,color){var c=tr.querySelector(".rj-st");c.dataset.live="";c.textContent=txt;c.style.color=color||"";}
function refreshTile(id,key){var tile=document.querySelector(".photo-tile[data-id=\""+id+"\"]");if(!tile)return;var url="/img.php?file="+encodeURIComponent(key)+"&w=420&h=280&fit=cover&q=76&fmt=webp&r="+Date.now();var img=tile.querySelector("img");if(img){img.src=url;}else{var nt=tile.querySelector(".no-thumb");if(nt){var ni=document.createElement("img");ni.loading="lazy";ni.src=url;nt.replaceWith(ni);}}}
async function sendLog(done,fail){try{var fd=new FormData();fd.append("_token",TOKEN);fd.append("log",JSON.stringify({album_id:AID,done:done,fail:fail,items:LOG}));await fetch("?action=save_recreate_log",{method:"POST",body:fd,credentials:"same-origin"});}catch(e){}}
btn.addEventListener("click",async function(){if(!LIST.length){alert("Nėra HEIC failų be JPG peržiūros.");return;}btn.disabled=true;panel.style.display="block";body.innerHTML="";LOG=[];var PF=preflight();LOG.push({preflight:PF});sum.textContent="Aplinka: wasm="+PF.wasm+" · worker="+PF.blobWorker+" · SAB="+PF.sharedArrayBuffer+" · isolated="+PF.crossOriginIsolated;var done=0,fail=0;
for(var i=0;i<LIST.length;i++){var it=LIST[i];var tr=document.createElement("tr");tr.innerHTML="<td>"+(i+1)+"</td><td>"+it.name+"</td><td class=\"rj-st\">Laukia</td><td class=\"rj-er muted small\" style=\"white-space:pre-wrap\"></td>";body.appendChild(tr);
var rec={id:it.id,name:it.name,steps:[]};LOG.push(rec);var t0=Date.now();
var live=function(txt){var st=tr.querySelector(".rj-st");st.dataset.live=txt;st.textContent=txt;st.style.color="var(--accent-ink)";};
var tick=setInterval(function(){var st=tr.querySelector(".rj-st");if(st&&st.dataset.live)st.textContent=st.dataset.live+" ("+Math.round((Date.now()-t0)/1000)+"s)";},1000);
try{
live("1/3 siunčiamas originalas");
var r=await wt(fetch("?action=photo_heic_blob&id="+encodeURIComponent(it.id),{credentials:"same-origin"}),60000,"atsisiuntimas");
if(!r.ok)throw new Error("originalo atsisiuntimas HTTP "+r.status);
var ct=(r.headers.get("content-type")||"");if(ct.indexOf("text/html")>=0)throw new Error("sesija pasibaigusi — perkraukite puslapį");
var blob=await r.blob();if(blob.size<200)throw new Error("originalas per mažas ("+blob.size+" B)");rec.steps.push("downloaded "+blob.size+"B");
live("2/3 konvertuojama naršyklėje");
var jpg=await toJpeg(blob);rec.steps.push("converted "+(jpg.size||0)+"B");
live("3/3 JPG keliamas į B2");
var fd=new FormData();fd.append("_token",TOKEN);fd.append("photo_id",it.id);fd.append("jpg",jpg,it.name.replace(/\.[^.]+$/,"")+".jpg");
var up=await wt(fetch("?action=save_recreated_jpg",{method:"POST",body:fd,headers:{Accept:"application/json"},credentials:"same-origin"}),120000,"įkėlimas");
var jd=await up.json();if(!jd||!jd.ok)throw new Error((jd&&jd.error)||"įkėlimas nepavyko");
rec.steps.push("uploaded "+jd.jpgKey);rec.ok=true;done++;
setSt(tr,"✓ atlikta ("+Math.round((Date.now()-t0)/1000)+"s)","var(--accent-ink)");
refreshTile(it.id,jd.jpgKey);
}catch(err){fail++;rec.ok=false;rec.error=em(err);setSt(tr,"✗ klaida","#e05252");tr.querySelector(".rj-er").textContent=em(err);}
finally{clearInterval(tick);}
sum.textContent="Vykdoma: "+done+" ✓, "+fail+" ✗ iš "+LIST.length;}
await sendLog(done,fail);
sum.textContent="Baigta: "+done+" ✓, "+fail+" ✗ iš "+LIST.length+". Ataskaita įrašyta Audit žurnale.";btn.disabled=false;});
document.getElementById("rjCopy").addEventListener("click",function(){var txt=JSON.stringify({album:AID,items:LOG},null,1);if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(txt);}else{var ta=document.createElement("textarea");ta.value=txt;document.body.appendChild(ta);ta.select();document.execCommand("copy");ta.remove();}this.textContent="Nukopijuota";});
})();</script>';
    // Nariu ikelimo jungiklis stovi cia, o ne tik Nariu puslapyje: sprendimas
    // "ar sis albumas priima nario nuotraukas" priimamas ziurint i alguma, ne i
    // saraso eilute. Kelio patikra - toggle_member_album viduje.
    // Nariu ikelimo langelis pasalintas 2026-10-03: foto.klajunas.lt/upload/ isjungtas,
    // nariai kelia per upload.klajunas.lt (Uploads/Inbox).
    echo '<form id="albumPhotoUploadForm" method="post" enctype="multipart/form-data" action="?action=upload_album_photos" class="actions" style="align-items:end" data-max-files="'.e((string)(int)$albumBatch['max_files']).'" data-max-bytes="'.e((string)(int)$albumBatch['batch_bytes']).'"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="album_id" value="'.e($albumId).'"><input type="hidden" name="sort_preset" id="uploadSortPreset" value="taken_asc"><div style="flex:0 1 320px;min-width:240px;max-width:320px"><label>Upload missing/additional photos + Takeout JSON sidecars</label><input id="albumPhotoFilesInput" type="file" name="photo_files[]" multiple accept="image/*,video/*,.heic,.heif,.json,application/json"></div><div style="min-width:180px"><label>Duplicate files in B2</label><select name="duplicate_mode"><option value="skip" selected>Skip media, add missing JSON</option><option value="overwrite">Overwrite media and JSON</option></select></div><div style="min-width:180px"><label>Visibility</label>'.status_select('visibility',$defaultVisibility).'</div><button class="primary" id="albumPhotoUploadButton">Upload photos</button></form>';
    echo '<div id="albumPhotoUploadProgress" class="muted small" style="display:none;margin:0 0 8px"></div>';
    echo '<p class="muted small" style="margin:4px 0 14px">Galima įkelti JPG, PNG ir iPhone HEIC/HEIF. HEIC failai saugomi originaliai, o galerijoje rodoma automatiškai sukurta JPG versija. Batch limit: '.e(human_bytes((int)$albumBatch['batch_bytes'])).' / '.e((string)(int)$albumBatch['max_files']).' files ('.e($albumBatch['label']).'). Serverio WAF atmeta uzklausas virs ~12 MB, todel failai siunciami tokiomis partijomis automatiskai. Pavieniai failai, didesni nei 12 MB (pvz., video), per admin neikeliami — juos kelkite i B2 rankiniu budu i albumo <code>originals/</code> aplanka ir paleiskite B2 Sync (scan + create missing), arba laikinai isjunkite WAF hostingo paneleje.</p>';
    // Čia buvo `return` — jis nutraukdavo renderinimą PRIEŠ <script> bloką, tad
    // tuščiame albume upload formos JS visai neįsikraudavo: submit handleris
    // neprisikabindavo, forma keliaudavo įprastu POST'u ir 56 nuotraukos (~260 MB)
    // viršydavo post_max_size -> Internal Server Error. Būtent tuščiame albume,
    // kur įkėlimo labiausiai ir reikia. Dabar tik parodom žinutę; lenta ir JS
    // renderinami toliau (visos žemiau esančios kilpos tuščią $rows praeina).
    if(!$rows) echo '<p class="muted">Šiame albume nuotraukų dar nėra — įkelkite jas per „Upload photos" aukščiau.</p>';
    $realCount = 0;
    foreach ($rows as $row) {
        if (isset($realB2Keys[trim((string)($row['b2_key'] ?? ''), '/')])) $realCount++;
    }
    echo '<p class="muted small">DB photos: '.e((string)count($rows)).' · Real B2 images under source path: '.e((string)count($realB2Keys)).' · DB photos found in B2: '.e((string)$realCount).'</p>';
    if (count($rows) > 0 && ($albumSourcePath === '' || count($realB2Keys) === 0 || $realCount === 0)) {
        echo '<div class="notice err" style="margin:10px 0 14px">';
        echo '<strong>DB/B2 mismatch.</strong> This album has DB photo rows, but no matching real B2 images under the current source path.';
        echo '<div class="actions" style="margin-top:10px">';
        echo '<a class="btn" href="?page=b2#b2-folder-'.e((string)$albumId).'">Open B2 Sync to link/repair</a>';
        echo '<span class="muted small">Use Real B2 folder structure to link this DB album to the real B2 folder, or upload originals again.</span>';
        echo '</div></div>';
    }
    echo '<form method="post" action="?action=save_photo_order" id="orderForm"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="album_id" value="'.e($albumId).'"><input type="hidden" name="order" id="photoOrder"><div class="actions order-save"><label style="display:flex;align-items:center;gap:8px;margin:0"><span class="small muted">Sort preset</span><select id="photoSortPreset" style="max-width:280px"><option value="taken_asc" selected>Taken: oldest first</option><option value="taken_desc">Taken: newest first</option><option value="title_asc">Title: A-Z</option><option value="title_desc">Title: Z-A</option><option value="size_asc">Size: smallest first</option><option value="size_desc">Size: largest first</option><option value="custom">Custom order</option></select></label><button class="primary">Save order</button><span class="muted small" id="orderHint">Taken: oldest first</span></div><div class="photo-board" id="photoBoard">';
    $previewItems = [];
    foreach($rows as $p){
        $img=thumb_url($p); $isCover=((int)$coverPhotoId===(int)$p['id']);
        $b2Key = trim((string)($p['b2_key'] ?? ''), '/');
        $missingCompatibility = is_heic_name((string)($p['original_filename'] ?? $p['b2_key'] ?? '')) && trim((string)($p['compatibility_b2_key'] ?? ''), '/') === '';
        $existsInB2 = $b2Key !== '' && isset($realB2Keys[$b2Key]);
        $taken = '';
        if (!empty($p['taken_at'])) {
            $ts = strtotime((string)$p['taken_at']);
            $taken = $ts ? date('Y-m-d H:i', $ts) : (string)$p['taken_at'];
        }
        $previewItems[] = [
            'id' => (int)$p['id'],
            'title' => (string)$p['original_filename'],
            'previewUrl' => preview_url($p),
            'thumbUrl' => $img,
            'takenAt' => (string)($p['taken_at'] ?? ''),
            'sortOrder' => (int)($p['sort_order'] ?? 0),
            'visibility' => (string)($p['visibility'] ?? ''),
            'b2Status' => $existsInB2 ? 'B2 ok' : 'missing in B2',
            'camera' => trim((string)($p['camera_make'] ?? '').' '.(string)($p['camera_model'] ?? '')),
            'lensModel' => (string)($p['lens_model'] ?? ''),
            'focalLength' => (string)($p['focal_length'] ?? ''),
            'aperture' => (string)($p['aperture'] ?? ''),
            'shutterSpeed' => (string)($p['shutter_speed'] ?? ''),
            'isoValue' => $p['iso_value'] ?? '',
            'width' => $p['width'] ?? '',
            'height' => $p['height'] ?? '',
            'fileSize' => $p['file_size'] ?? '',
            'orientation' => (string)($p['orientation'] ?? ''),
            'location' => trim(implode(', ', array_filter([(string)($p['city'] ?? ''), (string)($p['region'] ?? ''), (string)($p['country'] ?? '')], fn($v)=>$v!==''))),
            'lat' => $p['latitude'] ?? '',
            'lon' => $p['longitude'] ?? '',
        ];
        $previewIndex = count($previewItems) - 1;
        echo '<article class="photo-tile" draggable="true" tabindex="0" role="button" aria-label="Preview '.e($p['original_filename']).'" data-id="'.e($p['id']).'" data-preview-index="'.e($previewIndex).'">'.($isCover?'<span class="cover-flag">Cover</span>':'');
        echo $img?'<img loading="lazy" src="'.e($img).'" alt="'.e($p['original_filename']).'">':'<div class="no-thumb">No thumbnail</div>';
        echo '<div class="photo-tile-body"><div class="photo-title">'.e($p['original_filename']).'</div><div class="muted small">taken '.e($taken ?: 'n/a').'</div><div class="muted small">sort '.e($p['sort_order']).' · '.e($p['visibility']).'</div><div class="muted small">'.($existsInB2 ? '<span class="badge">B2 ok</span>' : '<span class="badge err">missing in B2</span>').((int)($p['is_missing'] ?? 0) ? ' <span class="badge">DB missing</span>' : '').($missingCompatibility ? ' <span class="badge">JPG preview missing</span>' : '').($existsInB2 && !photo_is_previewable((string)$p['original_filename']) ? ' <span class="badge" title="Šio failo peržiūra negalima — tik atsisiuntimas">No preview / Download and view only · '.e(photo_kind_label((string)$p['original_filename'])).'</span>' : '').'</div><div class="photo-tools">';
        echo '<button class="mini quick '.($isCover?'pill-on':'').'" type="button" data-op="cover">Cover</button>';
        echo '<button class="mini quick" type="button" data-op="hide">Hidden</button>';
        $rotNow = (int)($p['rotation'] ?? 0);
        echo '<button class="mini quick" type="button" data-op="rot_ccw" title="Pasukti prieš laikrodžio rodyklę (originalas B2 nekeičiamas)">↺</button>';
        echo '<button class="mini quick" type="button" data-op="rot_cw" title="Pasukti pagal laikrodžio rodyklę (originalas B2 nekeičiamas)">↻</button>';
        if ($rotNow) echo '<button class="mini quick pill-on" type="button" data-op="rot_reset" title="Grąžinti pradinę padėtį">'.e((string)$rotNow).'° ×</button>';
        echo '<a class="btn mini" href="?page=photo_edit&id='.e($p['id']).'&album_id='.e($albumId).'">Edit</a>';
        echo '<button class="mini err delete-photo" type="button">Delete</button>';
        echo '<label class="muted small" style="display:inline-flex;align-items:center;gap:5px;margin:0"><input class="delete-sidecar-toggle" type="checkbox" checked style="width:auto;padding:0"> + JSON</label>';
        echo '</div>';
        // Failo dydis ir matmenys - paskutine eilute po mygtukais. Renkantis, kuria
        // nuotrauka palikti is kelių panasiu, tai pirmas dalykas, i kuri ziurima,
        // o iki siol jo matydavai tik atidares perziura.
        $fsize = (int)($p['file_size'] ?? 0);
        $fw = (int)($p['width'] ?? 0); $fh = (int)($p['height'] ?? 0);
        $dims = ($fw > 0 && $fh > 0) ? ($fw.'×'.$fh) : '';
        $sizeBits = [];
        if ($dims !== '') $sizeBits[] = $dims;
        if ($fsize > 0) $sizeBits[] = human_bytes($fsize);
        echo '<div class="muted small" style="margin-top:6px">'.($sizeBits ? e(implode(' · ', $sizeBits)) : 'dydis nežinomas').'</div>';
        echo '</div></article>';
    }
    echo '</div></form>';
    echo '<div class="photo-preview-overlay" id="photoPreviewOverlay" hidden aria-hidden="true"><div class="photo-preview-shell" role="dialog" aria-modal="true" aria-label="Photo preview" tabindex="-1"><button type="button" class="photo-preview-close" id="photoPreviewClose" aria-label="Close preview"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg></button><button type="button" class="photo-preview-nav prev" id="photoPreviewPrev" aria-label="Previous photo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg></button><button type="button" class="photo-preview-nav next" id="photoPreviewNext" aria-label="Next photo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 5.5 16 12l-6.5 6.5"/></svg></button><div class="photo-preview-media"><img id="photoPreviewImage" alt=""></div><aside class="photo-preview-meta"><div class="preview-topline" id="photoPreviewTopline"></div><h3 class="photo-preview-title" id="photoPreviewTitle"></h3><div class="preview-file" id="photoPreviewFile"></div><dl class="preview-dl" id="photoPreviewExif"></dl><div class="preview-admin" id="photoPreviewAdmin"></div></aside></div></div>';
    echo '<script>
    const previewItems='.json_encode($previewItems, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).';
    const albumMeta='.json_encode($albumMeta, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).';
    const requestedSortPreset='.json_encode((string)($_GET['sort_preset'] ?? ''), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).';
    const metaById=Object.fromEntries(previewItems.map(p=>[String(p.id), p]));
    const board=document.getElementById("photoBoard"), order=document.getElementById("photoOrder"), hint=document.getElementById("orderHint"), sortPreset=document.getElementById("photoSortPreset"), uploadSortPreset=document.getElementById("uploadSortPreset");
    const overlay=document.getElementById("photoPreviewOverlay"), shell=document.querySelector(".photo-preview-shell"), imgEl=document.getElementById("photoPreviewImage"), titleEl=document.getElementById("photoPreviewTitle"), fileEl=document.getElementById("photoPreviewFile"), topEl=document.getElementById("photoPreviewTopline"), exifEl=document.getElementById("photoPreviewExif"), adminEl=document.getElementById("photoPreviewAdmin"), closeBtn=document.getElementById("photoPreviewClose"), prevBtn=document.getElementById("photoPreviewPrev"), nextBtn=document.getElementById("photoPreviewNext");
    let activePreviewIndex=-1;
    let customOrderBackup=[];
    function syncOrder(){order.value=[...board.querySelectorAll(".photo-tile")].map(x=>x.dataset.id).join(",");}
    // Tvarka issaugoma iskart po kiekvieno perkelimo ar rikiavimo pakeitimo -
    // anksciau reikejo spausti "Save order", o pamirsus perkelimas dingdavo.
    let savedOrder=null, saveTimer=null, saveSeq=0;
    function autoSaveOrder(){
        syncOrder();
        if(order.value===savedOrder || order.value==="") return;
        clearTimeout(saveTimer);
        hint.textContent="Saugoma...";
        saveTimer=setTimeout(async()=>{
            const sent=order.value, seq=++saveSeq;
            try{
                const res=await fetch("?action=save_photo_order",{method:"POST",body:new FormData(document.getElementById("orderForm")),headers:{"Accept":"application/json","X-Requested-With":"XMLHttpRequest"},credentials:"same-origin"});
                const data=await res.json().catch(()=>({}));
                if(!res.ok||!data.ok) throw new Error(data.error||("HTTP "+res.status));
                if(seq!==saveSeq) return;
                savedOrder=sent;
                hint.textContent=presetLabel(sortPreset.value)+" · išsaugota ✓";
            }catch(err){
                if(seq!==saveSeq) return;
                hint.textContent="Neišsaugota: "+err.message+" — spausk Save order";
            }
        },400);
    }
    function currentTileIds(){ return [...board.querySelectorAll(".photo-tile")].map(x=>x.dataset.id); }
    function applyTileOrder(ids){
        const tiles=new Map([...board.querySelectorAll(".photo-tile")].map(tile=>[tile.dataset.id, tile]));
        ids.forEach(id=>{ const tile=tiles.get(String(id)); if(tile) board.appendChild(tile); });
    }
    function escapeHtml(v){ return String(v ?? "").replace(/[&<>\"]/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;"}[m])).replace(new RegExp(String.fromCharCode(39),"g"),"&#39;"); }
    function photoSortValue(p){
        const t=Date.parse((p.takenAt || "").replace(" ","T"));
        const taken=Number.isFinite(t) ? t : 0;
        const title=(p.title || "").toLocaleLowerCase("lt-LT");
        const px=(Number(p.width)||0)*(Number(p.height)||0);
        const bytes=Number(p.fileSize)||0;
        return {taken,title,px,bytes};
    }
    function presetLabel(mode){
        return ({
            custom:"Custom order",
            taken_asc:"Taken: oldest first",
            taken_desc:"Taken: newest first",
            title_asc:"Title: A-Z",
            title_desc:"Title: Z-A",
            size_asc:"Size: smallest first",
            size_desc:"Size: largest first",
        })[mode] || "Custom order";
    }
    function idsForMode(mode){
        const tiles=[...board.querySelectorAll(".photo-tile")];
        const compare = {
            taken_asc:(a,b)=>photoSortValue(a).taken-photoSortValue(b).taken || photoSortValue(a).title.localeCompare(photoSortValue(b).title,"lt",{sensitivity:"base"}) || String(a.id).localeCompare(String(b.id)),
            taken_desc:(a,b)=>photoSortValue(b).taken-photoSortValue(a).taken || photoSortValue(a).title.localeCompare(photoSortValue(b).title,"lt",{sensitivity:"base"}) || String(a.id).localeCompare(String(b.id)),
            title_asc:(a,b)=>photoSortValue(a).title.localeCompare(photoSortValue(b).title,"lt",{sensitivity:"base"}) || photoSortValue(a).taken-photoSortValue(b).taken || String(a.id).localeCompare(String(b.id)),
            title_desc:(a,b)=>photoSortValue(b).title.localeCompare(photoSortValue(a).title,"lt",{sensitivity:"base"}) || photoSortValue(a).taken-photoSortValue(b).taken || String(a.id).localeCompare(String(b.id)),
            size_asc:(a,b)=>photoSortValue(a).px-photoSortValue(b).px || photoSortValue(a).bytes-photoSortValue(b).bytes || photoSortValue(a).title.localeCompare(photoSortValue(b).title,"lt",{sensitivity:"base"}) || String(a.id).localeCompare(String(b.id)),
            size_desc:(a,b)=>photoSortValue(b).px-photoSortValue(a).px || photoSortValue(b).bytes-photoSortValue(a).bytes || photoSortValue(a).title.localeCompare(photoSortValue(b).title,"lt",{sensitivity:"base"}) || String(a.id).localeCompare(String(b.id)),
        }[mode];
        if(!compare) return [];
        return tiles.map(tile=>metaById[tile.dataset.id]).filter(Boolean).sort(compare).map(p=>String(p.id));
    }
    function detectCurrentPreset(){
        const current=currentTileIds().join(",");
        const modes=["taken_asc","taken_desc","title_asc","title_desc","size_asc","size_desc"];
        for(const mode of modes){
            if(idsForMode(mode).join(",")===current) return mode;
        }
        return "custom";
    }
    function syncPresetFromCurrent(){
        const mode=detectCurrentPreset();
        sortPreset.value=mode;
        hint.textContent=presetLabel(mode);
        if(uploadSortPreset) uploadSortPreset.value=mode;
    }
    function sortBoard(mode, manual=false){
        const tiles=[...board.querySelectorAll(".photo-tile")];
        if(mode==="custom"){
            if (customOrderBackup.length) applyTileOrder(customOrderBackup);
            if(manual) hint.textContent="Unsaved custom order";
            else hint.textContent="Custom order";
            syncOrder();
            if(uploadSortPreset) uploadSortPreset.value="custom";
            return;
        }
        customOrderBackup=currentTileIds();
        const compare = {
            taken_asc:(a,b)=>photoSortValue(a).taken-photoSortValue(b).taken || photoSortValue(a).title.localeCompare(photoSortValue(b).title,"lt",{sensitivity:"base"}) || String(a.id).localeCompare(String(b.id)),
            taken_desc:(a,b)=>photoSortValue(b).taken-photoSortValue(a).taken || photoSortValue(a).title.localeCompare(photoSortValue(b).title,"lt",{sensitivity:"base"}) || String(a.id).localeCompare(String(b.id)),
            title_asc:(a,b)=>photoSortValue(a).title.localeCompare(photoSortValue(b).title,"lt",{sensitivity:"base"}) || photoSortValue(a).taken-photoSortValue(b).taken || String(a.id).localeCompare(String(b.id)),
            title_desc:(a,b)=>photoSortValue(b).title.localeCompare(photoSortValue(a).title,"lt",{sensitivity:"base"}) || photoSortValue(a).taken-photoSortValue(b).taken || String(a.id).localeCompare(String(b.id)),
            size_asc:(a,b)=>photoSortValue(a).px-photoSortValue(b).px || photoSortValue(a).bytes-photoSortValue(b).bytes || photoSortValue(a).title.localeCompare(photoSortValue(b).title,"lt",{sensitivity:"base"}) || String(a.id).localeCompare(String(b.id)),
            size_desc:(a,b)=>photoSortValue(b).px-photoSortValue(a).px || photoSortValue(b).bytes-photoSortValue(a).bytes || photoSortValue(a).title.localeCompare(photoSortValue(b).title,"lt",{sensitivity:"base"}) || String(a.id).localeCompare(String(b.id)),
        }[mode];
        if (!compare) return;
        tiles.sort((a,b)=>compare(metaById[a.dataset.id], metaById[b.dataset.id])).forEach(tile=>board.appendChild(tile));
        syncOrder();
        hint.textContent=presetLabel(mode)+" · unsaved";
        if(uploadSortPreset) uploadSortPreset.value=mode;
    }
    sortPreset.addEventListener("change",()=>{ sortBoard(sortPreset.value); autoSaveOrder(); });
    syncOrder();
    savedOrder=order.value;
    syncPresetFromCurrent();
    if(["taken_asc","taken_desc","title_asc","title_desc","size_asc","size_desc"].includes(requestedSortPreset)){
        sortPreset.value=requestedSortPreset;
        sortBoard(requestedSortPreset);
    }
    function fmtDate(v){if(!v)return"n/a"; const d=new Date(v.replace(" ","T")); if(Number.isNaN(d.getTime())) return v; return d.toLocaleString("lt-LT",{year:"numeric",month:"2-digit",day:"2-digit",hour:"2-digit",minute:"2-digit"});}
    function fmtBytes(v){const n=Number(v); if(!Number.isFinite(n)||n<=0) return "n/a"; const units=["B","KB","MB","GB"]; let i=0, x=n; while(x>=1024&&i<units.length-1){x/=1024;i++;} return (i===0?Math.round(x):x.toFixed(i===1?1:1))+" "+units[i];}
    function fieldValue(v){ return (v===null || v===undefined || String(v).trim()==="") ? "n/a" : String(v); }
    function adminRows(){
        const rows=[
            ["Album", albumMeta.title || "n/a"],
            ["Description", albumMeta.description || "n/a"],
            ["Location", albumMeta.location_name || "n/a"],
            ["Event date", albumMeta.event_date || "n/a"],
            ["Author", albumMeta.author_name || "n/a"],
            ["Copyright", albumMeta.copyright_text || "n/a"],
        ];
        if ((albumMeta.notes_internal || "").trim() !== "") rows.push(["Admin notes", albumMeta.notes_internal]);
        return rows;
    }
    function renderPreview(i){
        const p=previewItems[i]; if(!p) return;
        activePreviewIndex=i;
        overlay.hidden=false; overlay.classList.add("open"); overlay.setAttribute("aria-hidden","false"); document.body.classList.add("preview-open");
        imgEl.src=p.previewUrl || p.thumbUrl || "";
        imgEl.alt=p.title || "";
        titleEl.textContent=p.title || "Photo";
        topEl.textContent=(p.location ? p.location+" · " : "") + (p.takenAt ? fmtDate(p.takenAt) : "n/a");
        fileEl.textContent=p.title || "";
        const rows=[
            ["Taken", fmtDate(p.takenAt)],
            ["Camera", p.camera || "n/a"],
            ["Lens", p.lensModel || "n/a"],
            ["Focal", p.focalLength || "n/a"],
            ["Aperture", p.aperture || "n/a"],
            ["Shutter", p.shutterSpeed || "n/a"],
            ["ISO", p.isoValue || "n/a"],
            ["Size", (p.width && p.height ? (p.width+" × "+p.height) : "n/a") + " · " + fmtBytes(p.fileSize)],
            ["Orientation", p.orientation || "n/a"],
            ["Location", p.location || "n/a"],
            ["Coords", (p.lat && p.lon) ? (p.lat+", "+p.lon) : "n/a"],
            ["Status", p.visibility || "n/a"]
        ];
        exifEl.innerHTML=rows.map(([k,v])=>`<dt>${escapeHtml(k)}</dt><dd>${escapeHtml(v)}</dd>`).join("");
        const linkRows=[["DB sportas",albumMeta.dbsportas_url],["klajunas.lt",albumMeta.klajunas_url],["Kita nuoroda",albumMeta.other_url]].filter(([,u])=>(u||"").trim()!=="");
        const linksHtml=linkRows.length?"<h4 class=\"preview-section-title\" style=\"margin-top:16px\">Nuorodos</h4><dl class=\"preview-dl preview-dl-admin\">"+linkRows.map(([k,u])=>`<dt>${escapeHtml(k)}</dt><dd><a href="${escapeHtml(u)}" target="_blank" rel="noopener noreferrer" style="color:var(--accent-ink);text-decoration:underline;text-underline-offset:2px;word-break:break-all">${escapeHtml(u.replace(/^https?:\/\//,""))}</a></dd>`).join("")+"</dl>":"";
        adminEl.innerHTML="<h4 class=\"preview-section-title\">Album info</h4><dl class=\"preview-dl preview-dl-admin\">"+adminRows().map(([k,v])=>`<dt>${escapeHtml(k)}</dt><dd>${escapeHtml(fieldValue(v))}</dd>`).join("")+"</dl>"+linksHtml;
        prevBtn.disabled=previewItems.length<2;
        nextBtn.disabled=previewItems.length<2;
        shell.focus();
    }
    function closePreview(){ activePreviewIndex=-1; overlay.classList.remove("open"); overlay.hidden=true; overlay.setAttribute("aria-hidden","true"); document.body.classList.remove("preview-open"); }
    function togglePreview(i){ if(overlay.hidden===false && activePreviewIndex===i){ closePreview(); return; } renderPreview(i); }
    function stepPreview(dir){ if(activePreviewIndex<0 || previewItems.length<2) return; const next=(activePreviewIndex+dir+previewItems.length)%previewItems.length; renderPreview(next); }
    syncOrder();
    let drag=null;
    board.addEventListener("dragstart",e=>{drag=e.target.closest(".photo-tile"); if(drag){drag.classList.add("dragging"); e.dataTransfer.effectAllowed="move";}});
    board.addEventListener("dragend",()=>{if(drag)drag.classList.remove("dragging"); drag=null; customOrderBackup=currentTileIds(); sortPreset.value="custom"; if(uploadSortPreset) uploadSortPreset.value="custom"; autoSaveOrder();});
    board.addEventListener("dragover",e=>{e.preventDefault(); const over=e.target.closest(".photo-tile"); if(!drag||!over||drag===over)return; const r=over.getBoundingClientRect(); const after=e.clientY>r.top+r.height/2 || e.clientX>r.left+r.width/2; board.insertBefore(drag, after?over.nextSibling:over);});
    board.addEventListener("click",async e=>{
        const del=e.target.closest(".delete-photo");
        if(del){
            e.preventDefault();
            e.stopPropagation();
            const tile=del.closest(".photo-tile");
            if(!tile) return;
            const json=!!tile.querySelector(".delete-sidecar-toggle")?.checked;
            if(!confirm("Delete this photo from DB and B2"+(json ? ", including sidecar JSON" : "")+"?")) return;
            const fd=new FormData();
            fd.append("_token","'.e(token()).'");
            fd.append("album_id","'.e($albumId).'");
            fd.append("photo_id",tile.dataset.id);
            if(json) fd.append("delete_sidecar","1");
            del.disabled=true;
            const res=await fetch("?action=delete_album_photo",{method:"POST",body:fd,credentials:"same-origin"});
            if(res.ok) location.reload();
            else { alert(await res.text()); del.disabled=false; }
            return;
        }
        const b=e.target.closest(".quick"); if(b){const tile=b.closest(".photo-tile"); const fd=new FormData(); fd.append("_token","'.e(token()).'"); fd.append("album_id","'.e($albumId).'"); fd.append("photo_id",tile.dataset.id); fd.append("op",b.dataset.op); const res=await fetch("?action=photo_quick",{method:"POST",body:fd}); if(res.ok) location.reload(); return;}
        if (e.target.closest("a,button,input,label")) return;
        const tile=e.target.closest(".photo-tile"); if(!tile) return;
        togglePreview(Number(tile.dataset.previewIndex));
    });
    board.addEventListener("keydown",e=>{const tile=e.target.closest(".photo-tile"); if(!tile) return; if(e.key==="Enter"||e.key===" "){e.preventDefault(); togglePreview(Number(tile.dataset.previewIndex));}});
    overlay.addEventListener("click",e=>{ if(e.target===overlay || e.target.id==="photoPreviewClose") closePreview(); });
    prevBtn.addEventListener("click",()=>stepPreview(-1));
    nextBtn.addEventListener("click",()=>stepPreview(1));
    document.addEventListener("keydown",e=>{ if(overlay.hidden) return; if(e.key==="Escape") closePreview(); else if(e.key==="ArrowLeft"){e.preventDefault(); stepPreview(-1);} else if(e.key==="ArrowRight"){e.preventDefault(); stepPreview(1);} });
    document.getElementById("orderForm").addEventListener("submit",syncOrder);
    const uploadForm=document.getElementById("albumPhotoUploadForm"), uploadInput=document.getElementById("albumPhotoFilesInput"), uploadProgress=document.getElementById("albumPhotoUploadProgress"), uploadButton=document.getElementById("albumPhotoUploadButton");
    if(uploadForm&&uploadInput&&uploadProgress&&uploadButton){
        // Praeito ikelimo praleisti per dideli failai — parodom po perkrovimo.
        try{
            const skipped=sessionStorage.getItem("uploadSkipped");
            if(skipped){
                sessionStorage.removeItem("uploadSkipped");
                uploadProgress.style.display="block";
                uploadProgress.innerHTML="<div class=\"notice err\" style=\"margin:0\"><strong>Dalis failų neįkelta.</strong><div class=\"small\" style=\"margin-top:4px\"></div></div>";
                uploadProgress.querySelector(".small").textContent=skipped;
            }
        }catch(_){}
        const maxFiles=Math.max(1,parseInt(uploadForm.getAttribute("data-max-files")||"18",10));
        const maxBytes=Math.max(1024*1024,parseInt(uploadForm.getAttribute("data-max-bytes")||"41943040",10));
        const heicProgressMessage="Konvertuojama HEIC nuotrauka į JPG peržiūrai...";
        const heicBrowserMessage="Ši naršyklė negali paruošti HEIC nuotraukos peržiūrai.";
        const heicFailedMessage="Nepavyko sukurti JPG peržiūros, tačiau HEIC originalas gali būti įkeltas saugojimui.";
        const heicConversionTimeoutSeconds=45;
        const heicFallbackTimeoutSeconds=12;
        const heicLogs=[];
        function escHtml(s){return String(s||"").replace(/[&<>"\x27]/g,m=>m==="&"?"&amp;":m==="<"?"&lt;":m===">"?"&gt;":m==="\""?"&quot;":"&#039;");}
        function setUploadProgress(message,current,total,detail){
            uploadProgress.style.display="block";
            const pct=total>0?Math.max(0,Math.min(100,Math.round((current/total)*100))):0;
            const meter=total>0?`<div style="height:8px;background:#0d1116;border:1px solid var(--line);border-radius:999px;overflow:hidden;margin:6px 0"><div style="height:100%;width:${pct}%;background:#d97706"></div></div>`:"";
            const counts=total>0?` <span class="badge">${current} / ${total}</span> <span class="badge">${pct}%</span>`:"";
            uploadProgress.innerHTML=`<div>${escHtml(message)}${counts}</div>${meter}${detail?`<div class="muted small">${escHtml(detail)}</div>`:""}`;
        }
        function setUploadBusy(message,detail,elapsed,total){
            uploadProgress.style.display="block";
            const hasTime=typeof elapsed==="number"&&typeof total==="number"&&total>0;
            const pct=hasTime?Math.max(1,Math.min(100,Math.round((elapsed/total)*100))):42;
            const time=hasTime?` <span class="badge">${elapsed} s / ${total} s</span>`:"";
            uploadProgress.innerHTML=`<div>${escHtml(message)}${time}</div><div style="height:8px;background:#0d1116;border:1px solid var(--line);border-radius:999px;overflow:hidden;margin:6px 0"><div style="height:100%;width:${pct}%;background:#d97706"></div></div>${detail?`<div class="muted small">${escHtml(detail)}</div>`:""}`;
        }
        function setUploadWarning(message,detail){
            uploadProgress.style.display="block";
            uploadProgress.innerHTML=`<div style="color:#fecaca;font-weight:800">${escHtml(message)}</div><div style="height:8px;background:#2b1115;border:1px solid #7f1d1d;border-radius:999px;overflow:hidden;margin:6px 0"><div style="height:100%;width:100%;background:#dc2626"></div></div>${detail?`<div class="muted small">${escHtml(detail)}</div>`:""}`;
        }
        function formatUploadBytes(bytes){
            const n=Number(bytes)||0;
            if(n>=1024*1024) return (n/1024/1024).toFixed(1)+" MB";
            if(n>=1024) return (n/1024).toFixed(1)+" KB";
            return n+" B";
        }
        function browserName(){
            const ua=navigator.userAgent||"";
            const platform=navigator.platform||"";
            const isSafari=/Safari/i.test(ua)&&!/Chrome|Chromium|Edg|OPR|Firefox|CriOS|FxiOS/i.test(ua);
            const version=(ua.match(/Version\/([0-9.]+)/)||ua.match(/(?:Chrome|Firefox|Edg|OPR)\/([0-9.]+)/)||[])[1]||"";
            if(isSafari) return (/(Mac|iPhone|iPad|iPod)/i.test(platform)?"Mac Safari":"Safari")+(version?" "+version:"");
            if(/Edg/i.test(ua)) return "Edge"+(version?" "+version:"");
            if(/Firefox|FxiOS/i.test(ua)) return "Firefox"+(version?" "+version:"");
            if(/Chrome|CriOS/i.test(ua)) return "Chrome"+(version?" "+version:"");
            return ua.split(" ").slice(-2).join(" ")||"unknown";
        }
        function isMacSafari(){
            const ua=navigator.userAgent||"", platform=navigator.platform||"";
            return /Safari/i.test(ua)&&!/Chrome|Chromium|Edg|OPR|Firefox|CriOS|FxiOS/i.test(ua)&&/Mac/i.test(platform);
        }
        function renderHeicLogs(activeMessage){
            if(!heicLogs.length) return activeMessage||"";
            const rows=heicLogs.slice(-6).map(log=>{
                const color=log.status==="success"?"#bbf7d0":(log.status==="timeout"||log.status==="error"?"#fecaca":"var(--muted)");
                const status=log.status==="success"?"JPG peržiūra sukurta":(log.status==="started"?"Konvertuojama":"HEIC originalas įkeltas, JPG peržiūra nesukurta");
                const duration=log.durationMs?` · ${(log.durationMs/1000).toFixed(1)} s`:"";
                const method=log.method?` · ${log.method}`:"";
                const error=log.error?` · ${log.error}`:"";
                return `<div style="color:${color}">${escHtml(status)}: ${escHtml(log.name)} (${escHtml(formatUploadBytes(log.size))}${method}${duration}${error})</div>`;
            }).join("");
            return `${activeMessage?`<div>${escHtml(activeMessage)}</div>`:""}<div style="margin-top:6px">${rows}</div>`;
        }
        function setHeicProgress(message,current,total,detail,log){
            uploadProgress.style.display="block";
            const pct=total>0?Math.max(0,Math.min(100,Math.round((current/total)*100))):0;
            const meter=total>0?`<div style="height:8px;background:#0d1116;border:1px solid var(--line);border-radius:999px;overflow:hidden;margin:6px 0"><div style="height:100%;width:${pct}%;background:#d97706"></div></div>`:"";
            const counts=total>0?` <span class="badge">${current} / ${total}</span> <span class="badge">${pct}%</span>`:"";
            const elapsed=log&&log.startedMs?Math.max(0,Math.round((Date.now()-log.startedMs)/1000)):0;
            const time=log?` <span class="badge">${elapsed} s / ${heicConversionTimeoutSeconds} s</span>`:"";
            uploadProgress.innerHTML=`<div>${escHtml(message)}${counts}${time}</div>${meter}${detail?`<div class="muted small">${escHtml(detail)}</div>`:""}${renderHeicLogs("")}`;
        }
        function isHeic(file){return /\.(heic|heif)$/i.test(file.name||"") || /^image\/hei[cf]/i.test(file.type||"");}
        function displayNameForHeic(file, seen){
            const raw=(file.name||"photo.heic").replace(/^.*[\\/]/,"");
            const base=(raw.replace(/\.[^.]+$/,"")||"photo").replace(/[\\\\/:*?"<>|]+/g,"-");
            let name=base+".jpg", n=2;
            while(seen.has(name.toLowerCase())) name=base+"-"+(n++)+".jpg";
            seen.add(name.toLowerCase());
            return name;
        }
        function ensureJpegBlob(blob){
            if(!blob || !blob.size) throw new Error("empty JPG preview");
            if(!/^image\/jpeg$/i.test(blob.type||"")){
                return new Blob([blob],{type:"image/jpeg"});
            }
            return blob;
        }
        function timeoutPromise(seconds,label){
            return new Promise((_,reject)=>setTimeout(()=>reject(new Error((label||"conversion")+" timeout after "+seconds+"s")),seconds*1000));
        }
        async function nativeCanvasHeicToJpeg(file){
            const url=URL.createObjectURL(file);
            try{
                const img=new Image();
                img.decoding="async";
                const loaded=new Promise((resolve,reject)=>{
                    img.onload=resolve;
                    img.onerror=()=>reject(new Error("native HEIC decode failed"));
                });
                img.src=url;
                await loaded;
                const width=img.naturalWidth||img.width, height=img.naturalHeight||img.height;
                if(!width||!height) throw new Error("native HEIC dimensions unavailable");
                const canvas=document.createElement("canvas");
                canvas.width=width; canvas.height=height;
                const ctx=canvas.getContext("2d");
                if(!ctx) throw new Error("canvas unavailable");
                ctx.drawImage(img,0,0,width,height);
                return await new Promise((resolve,reject)=>canvas.toBlob(blob=>blob?resolve(blob):reject(new Error("canvas JPEG export failed")),"image/jpeg",0.9));
            }finally{
                URL.revokeObjectURL(url);
            }
        }
        async function heicToConvert(file){
            const converter=typeof window.HeicTo==="function"?window.HeicTo:(typeof window.heicTo==="function"?window.heicTo:(window.heicTo&&typeof window.heicTo.heicTo==="function"?window.heicTo.heicTo:null));
            if(!converter) throw new Error("heic-to unavailable");
            return await converter({blob:file,type:"image/jpeg",quality:0.9});
        }
        async function heic2anyConvert(file){
            if(typeof window.heic2any!=="function") throw new Error(heicBrowserMessage);
            const converted=await window.heic2any({blob:file,toType:"image/jpeg",quality:0.9});
            return Array.isArray(converted)?converted[0]:converted;
        }
        async function tryHeicMethod(label, fn, timeoutSeconds){
            const value=await Promise.race([fn(),timeoutPromise(timeoutSeconds,label)]);
            return {label,blob:ensureJpegBlob(value)};
        }
        async function convertHeic(file, seen, index, total){
            const log={name:file.name||"",size:file.size||0,browser:browserName(),startedAt:new Date().toISOString(),startedMs:Date.now(),durationMs:0,status:"started",method:"",error:""};
            heicLogs.push(log);
            let tick=null;
            const detail=()=>`Failas ${index} / ${total}: ${file.name||""}. Vienu metu konvertuojamas tik vienas HEIC failas. Naršyklė: ${log.browser}.`;
            setHeicProgress(heicProgressMessage,index,total,detail(),log);
            tick=setInterval(()=>{
                setHeicProgress(heicProgressMessage,index,total,detail(),log);
            },1000);
            await new Promise(requestAnimationFrame);
            try{
                const methods=[];
                if(isMacSafari()) methods.push({label:"native canvas",fn:()=>nativeCanvasHeicToJpeg(file),timeout:heicFallbackTimeoutSeconds});
                methods.push({label:"heic-to",fn:()=>heicToConvert(file),timeout:heicConversionTimeoutSeconds});
                methods.push({label:"heic2any",fn:()=>heic2anyConvert(file),timeout:heicFallbackTimeoutSeconds});
                const errors=[];
                for(const method of methods){
                    const label=method.label, fn=method.fn;
                    log.method=label;
                    setHeicProgress(heicProgressMessage,index,total,detail(),log);
                    try{
                        const converted=await tryHeicMethod(label,fn,method.timeout);
                        log.status="success";
                        log.method=converted.label;
                        log.durationMs=Date.now()-log.startedMs;
                        console.info("HEIC preview conversion",{
                            fileName:log.name,fileSize:log.size,browser:log.browser,startTimestamp:log.startedAt,
                            durationMs:log.durationMs,status:log.status,method:log.method
                        });
                        setHeicProgress("JPG peržiūra sukurta",index,total,detail(),log);
                        return {file:new File([converted.blob],displayNameForHeic(file,seen),{type:"image/jpeg",lastModified:file.lastModified||Date.now()}),skipped:false,reason:"",log};
                    }catch(err){
                        const msg=err&&err.message?err.message:String(err);
                        errors.push(label+": "+msg);
                        console.warn("HEIC preview method failed",{
                            fileName:log.name,fileSize:log.size,browser:log.browser,method:label,error:msg
                        });
                        log.error=errors.join(" | ");
                        setHeicProgress(heicProgressMessage,index,total,detail(),log);
                    }
                }
                throw new Error(errors.length?errors.join(" | "):heicBrowserMessage);
            }catch(err){
                log.durationMs=Date.now()-log.startedMs;
                const msg=err&&err.message?err.message:String(err);
                log.status=msg.includes("timeout")?"timeout":"error";
                log.error=msg;
                console.warn("HEIC preview conversion",{
                    fileName:log.name,fileSize:log.size,browser:log.browser,startTimestamp:log.startedAt,
                    durationMs:log.durationMs,status:log.status,method:log.method,error:log.error
                });
                setHeicProgress("HEIC originalas įkeltas, JPG peržiūra nesukurta",index,total,detail(),log);
                return {file:null,skipped:true,reason:msg,log};
            }finally{
                if(tick) clearInterval(tick);
            }
        }
        async function uploadItems(files){
            const items=[]; const seen=new Set();
            for(let idx=0;idx<files.length;idx++){
                const file=files[idx];
                setUploadProgress("Ruošiami failai įkėlimui...",idx+1,files.length,file.name||"");
                if(isHeic(file)){
                    const result=await convertHeic(file,seen,idx+1,files.length);
                    const jpg=result&&result.file?result.file:null;
                    if(!jpg){
                        setUploadWarning("HEIC originalas įkeltas, JPG peržiūra nesukurta: "+(file.name||""),heicFailedMessage+" Galerijoje peržiūra nebus rodoma, kol nebus JPG versijos. Priežastis: "+((result&&result.reason)||"unknown"));
                        await new Promise(r=>setTimeout(r,900));
                    }
                    items.push({original:file,compatibility:jpg,previewStatus:jpg?"ready":"failed",previewError:jpg?"":((result&&result.reason)||heicFailedMessage)});
                }else{
                    items.push({original:file,compatibility:null,previewStatus:"ready",previewError:""});
                }
            }
            return items;
        }
        function uploadBatches(items){
            const out=[]; let cur=[]; let bytes=0;
            items.forEach(item=>{ const size=(item.original?.size||0)+(item.compatibility?.size||0); if(cur.length&&(cur.length>=maxFiles||bytes+size>maxBytes)){out.push(cur);cur=[];bytes=0;} cur.push(item); bytes+=size; });
            if(cur.length) out.push(cur);
            return out;
        }
        // Progresas skaičiuojamas NUOTRAUKOMIS, ne partijomis: vartotojui rūpi
        // "kiek iš 56 jau įkelta", o partijos yra vidinė techninė detalė.
        function postUploadBatch(fd,batchIndex,batchCount,batchFiles,donePhotos,batchPhotos,totalPhotos){
            return new Promise((resolve,reject)=>{
                const xhr=new XMLHttpRequest();
                xhr.open("POST",uploadForm.action,true);
                xhr.withCredentials=true;
                xhr.setRequestHeader("X-Requested-With","XMLHttpRequest");
                xhr.setRequestHeader("Accept","application/json");
                // Baitai pasiekė serverį, bet darbas dar vyksta: B2 upload + EXIF +
                // DB įrašai. Be šito laikmačio progresas "pakibdavo" ties 100%.
                let busyTimer=null;
                const busyStart=()=>{
                    if(busyTimer)return;
                    const t0=Date.now();
                    const tick=()=>setUploadBusy(
                        "Įkelta "+(donePhotos+batchPhotos)+" / "+totalPhotos+" nuotraukų. Serveris apdoroja: B2 + EXIF + DB ("+Math.round((Date.now()-t0)/1000)+" s)…",
                        "Partija "+batchIndex+" / "+batchCount+": "+batchFiles);
                    tick(); busyTimer=setInterval(tick,1000);
                };
                const busyStop=()=>{ if(busyTimer){clearInterval(busyTimer);busyTimer=null;} };
                xhr.upload.onprogress=e=>{
                    if(e.lengthComputable){
                        const frac=e.total>0?e.loaded/e.total:0;
                        const shown=Math.min(totalPhotos,Math.round(donePhotos+batchPhotos*frac));
                        setUploadProgress("Įkeliama "+shown+" / "+totalPhotos+" nuotraukų",shown,totalPhotos,
                            "Partija "+batchIndex+" / "+batchCount+" · "+Math.round(frac*100)+"% · "+batchFiles);
                        if(e.loaded>=e.total) busyStart();
                    }else{
                        setUploadProgress("Įkeliama "+donePhotos+" / "+totalPhotos+" nuotraukų",donePhotos,totalPhotos,
                            "Partija "+batchIndex+" / "+batchCount+": "+batchFiles);
                    }
                };
                if(xhr.upload) xhr.upload.onload=()=>busyStart();
                xhr.onerror=()=>{ busyStop(); reject(new Error("Network upload error")); };
                xhr.onload=()=>{
                    busyStop();
                    const contentType=xhr.getResponseHeader("Content-Type")||"";
                    const text=xhr.responseText||"";
                    if(!contentType.toLowerCase().includes("application/json")){
                        reject(new Error("Server returned HTML instead of JSON. Upload status could not be confirmed."));
                        return;
                    }
                    let data=null;
                    try{ data=JSON.parse(text); }catch(_){
                        reject(new Error("Server returned invalid JSON. Upload status could not be confirmed."));
                        return;
                    }
                    if(xhr.status<200||xhr.status>=300){
                        reject(new Error((data&&data.error)||(data&&data.message)||"Batch "+batchIndex+" failed with HTTP "+xhr.status));
                        return;
                    }
                    if(data && data.ok===false){
                        reject(new Error(data.error||data.message||"Batch "+batchIndex+" failed"));
                        return;
                    }
                    resolve(data);
                };
                xhr.send(fd);
            });
        }
        uploadForm.addEventListener("submit",async e=>{
            const allFiles=Array.from(uploadInput.files||[]);
            e.preventDefault();
            // Pavienis failas, didesnis už partijos ribą, niekada nepraeis pro
            // serverio WAF (~12 MB kūno limitas). Tokius PRALEIDŽIAM ir keliam
            // likusius — vienas per didelis video neturi blokuoti 55 nuotraukų.
            const limitMb=Math.round(maxBytes/1048576);
            const skipped=[];
            const mb=b=>Math.round((b||0)/1048576);
            const skipNoteOf=list=>list.length
                ? "Praleista "+list.length+" per didelių (>"+limitMb+" MB): "+list.join(", ")
                  +" — juos kelkite į B2 rankiniu būdu į albumo originals/ aplanką ir paleiskite B2 Sync (scan + create missing), arba laikinai išjunkite WAF hostingo panelėje."
                : "";
            // 1) Atmetam per didelius dar PRIEŠ konversiją — nešvaistom laiko
            //    48 MB video konvertavimui, kuris vis tiek nepraeitų pro WAF.
            const files=allFiles.filter(f=>{
                if((f.size||0)>maxBytes){ skipped.push(f.name+" ("+mb(f.size)+" MB)"); return false; }
                return true;
            });
            if(!files.length){
                setUploadProgress("Nėra ką įkelti",0,0,
                    skipped.length ? skipNoteOf(skipped) : "Nepasirinktas nė vienas failas.");
                return;
            }
            uploadButton.disabled=true;
            setUploadProgress("Pradedamas įkėlimas...",0,files.length,skipNoteOf(skipped));
            try{
                const rawItems=await uploadItems(files);
                // 2) Po HEIC konversijos elementas = originalas + JPG kopija. 10 MB
                //    HEIC + 3 MB JPG = 13 MB vienam elementui — jis nesiskaidytų ir
                //    vėl kliūtų WAF, tad tikrinam ir bendrą dydį.
                const items=rawItems.filter(it=>{
                    const total=(it.original&&it.original.size||0)+(it.compatibility&&it.compatibility.size||0);
                    if(total>maxBytes){ skipped.push((it.original&&it.original.name||"?")+" ("+mb(total)+" MB su JPG kopija)"); return false; }
                    return true;
                });
                const skipNote=skipNoteOf(skipped);
                if(!items.length){ uploadButton.disabled=false; setUploadProgress("Nėra ką įkelti",0,0,skipNote); return; }
                const batches=uploadBatches(items);
                const totalPhotos=items.length;
                let donePhotos=0;
                let redirectUrl="?page=album_edit&id='.e($albumId).'";
                for(let i=0;i<batches.length;i++){
                    const fd=new FormData();
                    ["_token","album_id","sort_preset","duplicate_mode","visibility"].forEach(name=>{ const el=uploadForm.querySelector("[name="+name+"]"); if(el) fd.append(name,el.value||""); });
                    batches[i].forEach(item=>{
                        fd.append("photo_files[]",item.original,item.original.webkitRelativePath||item.original.name);
                        fd.append("photo_preview_original_names[]",item.original.name);
                        fd.append("photo_preview_statuses[]",item.previewStatus||"");
                        fd.append("photo_preview_errors[]",item.previewError||"");
                        if(item.compatibility){
                            fd.append("photo_compatibility_files[]",item.compatibility,item.compatibility.name);
                            fd.append("photo_compatibility_original_names[]",item.original.name);
                        }
                    });
                    const batchNames=batches[i].map(item=>item.original.name).slice(0,4).join(", ")+(batches[i].length>4?"...":"");
                    const batchPhotos=batches[i].length;
                    const detail=batchPhotos+" failai: "+batchNames;
                    setUploadProgress("Įkeliama "+donePhotos+" / "+totalPhotos+" nuotraukų",donePhotos,totalPhotos,
                        "Partija "+(i+1)+" / "+batches.length+": "+detail);
                    const result=await postUploadBatch(fd,i+1,batches.length,detail,donePhotos,batchPhotos,totalPhotos);
                    donePhotos+=batchPhotos;
                    if(result&&result.redirect) redirectUrl=result.redirect;
                }
                setUploadProgress("Įkėlimas baigtas: "+donePhotos+" / "+totalPhotos+" nuotraukų. Perkraunama...",donePhotos,totalPhotos,skipNote);
                // Praleisti per dideli failai turi likti matomi ir po perkrovimo,
                // kitaip vartotojas nepastebetu, kad ju truksta.
                if(tooBig.length){
                    try{ sessionStorage.setItem("uploadSkipped",skipNote); }catch(_){}
                }
                location.href=redirectUrl;
            }catch(err){
                uploadButton.disabled=false;
                setUploadProgress("Įkėlimas sustabdytas",0,0,(err&&err.message?err.message:String(err))+(skipNote?" · "+skipNote:""));
            }
        });
    }
    </script></section>';
}

function login_page(): void {
    head('Login');
    echo '<div class="login"><section class="card"><img class="logo" src="/admin/logo.png" alt="Klajunas" onerror="this.style.display=\'none\'"><h1>Admin access</h1><p class="muted">Google login is limited to active accounts in Admins.</p><p class="small muted">Initial superadmins are seeded from the server allowlist.</p>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <div id="g_id_onload" data-client_id="'.e(GOOGLE_CLIENT_ID).'" data-callback="handleGoogleCredential" data-auto_prompt="false"></div>
    <div class="g_id_signin" data-type="standard" data-size="large" data-theme="filled_black" data-text="signin_with" data-shape="rectangular" data-logo_alignment="left"></div>
    <form id="googleCredentialForm" method="post" action="?action=google_login"><input type="hidden" name="_token" value="'.e(token()).'"><input id="googleCredential" type="hidden" name="credential"></form>
    <script>function handleGoogleCredential(r){document.getElementById("googleCredential").value=r.credential;document.getElementById("googleCredentialForm").submit();}</script>
    </section></div>';
    foot('Login');
}

/* ------------------------ Mėnesinė DB kopija į B2 ------------------------ */
// exec/mysqldump šiame hoste užblokuoti, tad dump'as daromas grynu PHP. Paleidžiama
// automatiškai atidarius Dashboard, kai ankstesnė kopija senesnė nei 30 d. Senos
// kopijos iš B2 NEtrinamos (invariantas + storage vieta nerūpi) — retention rankinis.
const DB_BACKUP_INTERVAL_DAYS = 30;

function db_backup_sql_dump(): string {
    $pdo = db();
    $out = "-- foto.klajunas.lt DB dump ".date('Y-m-d H:i:s')."\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n";
    foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        $out .= "DROP TABLE IF EXISTS `$t`;\n".$create[1].";\n\n";
        $batch = [];
        foreach ($pdo->query("SELECT * FROM `$t`", PDO::FETCH_NUM) as $row) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row);
            $batch[] = '('.implode(',', $vals).')';
            if (count($batch) >= 200) { $out .= "INSERT INTO `$t` VALUES\n".implode(",\n", $batch).";\n"; $batch = []; }
        }
        if ($batch) $out .= "INSERT INTO `$t` VALUES\n".implode(",\n", $batch).";\n";
        $out .= "\n";
    }
    return $out."SET FOREIGN_KEY_CHECKS=1;\n";
}

/** Tiek naujausiu DB kopiju saugoma visada, nesvarbu, kokio jos amziaus. */
const DB_BACKUP_KEEP = 5;
/** Uz sita senesnes kopijos trinamos - bet tik tos, kurios netelpa i KEEP. */
const DB_BACKUP_MAX_AGE_DAYS = 90;

/**
 * Trina DB kopija tik tada, kai tenkinamos ABI salygos: ji senesne nei
 * DB_BACKUP_MAX_AGE_DAYS IR nepatenka i DB_BACKUP_KEEP naujausiu.
 *
 * Taip abi taisykles dengia viena kitos spragas. Vien amzius reikstu, kad po
 * ilgesnes pertraukos galima likti be nieko - visos kopijos vienu metu taptu
 * per senos. Vien kiekis reikstu, kad retai kelant kopijas laikytume metu
 * senumo failus be reikalo. Kartu: sviezios niekada netrinamos, o penkiu
 * naujausiu riba yra grindys, zemiau kuriu nenusileidziam niekada.
 *
 * Tai dera ir su paties kibiro taisykle (daysFromHidingToDeleting = 90):
 * istrinta kopija dar 90 dienu lieka atgaunama, tad klaida nera negrizdama.
 *
 * Vardas yra foto_<Y-m-d_His>.sql.gz, todel rikiavimas pagal varda sutampa su
 * rikiavimu pagal laika, o is to paties vardo imamas ir amzius.
 *
 * Trinam TIK tai, kas atitinka toki varda backups/db/ aplanke. Jokio kito failo
 * si funkcija paliesti negali, net jei aplanke kas nors atsirastu.
 */
function db_backup_prune(int $keep = DB_BACKUP_KEEP, int $maxAgeDays = DB_BACKUP_MAX_AGE_DAYS): array {
    $keep = max(1, $keep);
    $found = [];
    foreach (b2_list_prefix('backups/db', 5) as $file) {
        $name = trim((string)($file['fileName'] ?? ''), '/');
        if (!preg_match('~^backups/db/foto_(\d{4})-(\d{2})-(\d{2})_(\d{2})(\d{2})(\d{2})\.sql\.gz$~', $name, $m)) continue;
        $found[$name] = [
            'id' => (string)($file['fileId'] ?? ''),
            'time' => mktime((int)$m[4], (int)$m[5], (int)$m[6], (int)$m[2], (int)$m[3], (int)$m[1]),
        ];
    }
    $total = count($found);
    if ($total <= $keep) return ['total' => $total, 'kept' => $total, 'deleted' => 0, 'too_old' => 0];
    krsort($found);                       // naujausios pirmos
    $cutoff = time() - $maxAgeDays * 86400;
    $candidates = array_slice($found, $keep, null, true);   // uz KEEP ribos
    $deleted = 0; $tooOld = 0;
    foreach ($candidates as $name => $info) {
        if ($info['time'] === false || $info['time'] >= $cutoff) continue;   // dar nesena
        $tooOld++;
        if ($info['id'] === '') continue;
        try { b2_delete_file_version($info['id'], $name); $deleted++; }
        catch (Throwable $e) { /* viena nepavykusi kopija neturi laužyti kitu */ }
    }
    return ['total' => $total, 'kept' => $total - $deleted, 'deleted' => $deleted, 'too_old' => $tooOld];
}

function db_backup_run(): array {
    @set_time_limit(180);
    b2_load_config();
    $sql = db_backup_sql_dump();
    $gz = gzencode($sql, 9);
    if ($gz === false) throw new RuntimeException('gzip nepavyko');
    $key = 'backups/db/foto_'.date('Y-m-d_His').'.sql.gz';
    $upload = b2_upload_url();
    b2_upload_data($gz, $key, 'application/gzip', $upload);
    set_setting('last_db_backup', date('Y-m-d H:i:s'));
    // Valom TIK po sekmingo ikelimo - kitaip nepavykusi kopija galetu istrinti
    // sena ir palikti visai be nieko.
    $prune = db_backup_prune();
    return ['key' => $key, 'sql_bytes' => strlen($sql), 'gz_bytes' => strlen($gz),
            'kept' => $prune['kept'], 'deleted' => $prune['deleted']];
}

/**
 * Kopija dabar, nelaukiant 30 dienu intervalo. Reikalinga pries rizikingus
 * masinius veiksmus (B2 migracijas), kurie keicia photos.b2_key ir
 * albums.source_path daugelyje eiluciu vienu metu.
 */
function db_backup_now(): void {
    require_superadmin();
    csrf();
    $back = (string)($_POST['return_to'] ?? '') === 'b2' ? '?page=b2' : '?page=settings';
    try {
        $info = db_backup_run();
        audit('system', null, 'db_backup', 'DB kopija rankiniu būdu', $info);
        $note = (int)($info['deleted'] ?? 0) > 0 ? ' Senesnių nei '.DB_BACKUP_MAX_AGE_DAYS.' d. ištrinta: '.(int)$info['deleted'].'.' : '';
        flash('DB kopija įkelta į B2: '.$info['key'].' ('.human_bytes((int)$info['gz_bytes']).', SQL '.human_bytes((int)$info['sql_bytes']).'). Saugoma: '.(int)($info['kept'] ?? 0).' kopijos.'.$note);
    } catch (Throwable $e) {
        flash('DB kopija nepavyko: '.$e->getMessage(), 'err');
    }
    go($back);
}

function db_backup_maybe_run(): void {
    try {
        $last = setting('last_db_backup', '');
        if ($last !== '' && (time() - strtotime($last)) < DB_BACKUP_INTERVAL_DAYS * 86400) return;
        $info = db_backup_run();
        audit('system', null, 'db_backup', 'Mėnesinė DB kopija įkelta į B2', $info);
        flash('Mėnesinė DB atsarginė kopija įkelta į B2: '.$info['key'].' ('.human_bytes((int)$info['gz_bytes']).').');
    } catch (Throwable $e) {
        // Niekada nelaužom dashboard'o; nesėkmė matoma Audit žurnale.
        try { audit('system', null, 'db_backup_failed', 'DB kopijos klaida: '.$e->getMessage()); } catch (Throwable $e2) {}
    }
}

function dashboard(): void {
    if (is_superadmin()) db_backup_maybe_run();
    head('Dashboard');
    $albumScope = is_superadmin() ? '' : ' WHERE created_by='.(int)current_admin_id();
    $photoScope = is_superadmin() ? '' : ' WHERE album_id IN (SELECT id FROM albums WHERE created_by='.(int)current_admin_id().')';
    $cards = [
        ['Albums', db()->query("SELECT COUNT(*) FROM albums".$albumScope)->fetchColumn(), '?page=albums', 'Albumų įrašai, tvarka, viršeliai ir B2 keliai.'],
        ['Photos', db()->query("SELECT COUNT(*) FROM photos".$photoScope)->fetchColumn(), '?page=photos', 'Browse and edit the complete photo manifest.'],
        ['Draft albums', db()->query("SELECT COUNT(*) FROM albums".($albumScope ? $albumScope." AND visibility IN ('draft','ready')" : " WHERE visibility IN ('draft','ready')"))->fetchColumn(), '?page=albums&visibility=draft', 'Review unpublished albums.'],
        ['Duplicate albums', db()->query("SELECT COUNT(DISTINCT a.id) FROM albums a JOIN albums b ON a.id<>b.id WHERE a.source_path IS NOT NULL AND a.source_path<>'' AND b.source_path IS NOT NULL AND b.source_path<>'' AND (b.source_path LIKE CONCAT(a.source_path,'-%') OR a.source_path LIKE CONCAT(b.source_path,'-%') OR a.source_path=b.source_path)".(is_superadmin() ? '' : " AND a.created_by=".(int)current_admin_id()))->fetchColumn(), '?page=b2', 'Albumai galimai dubliuoti B2 folderiuose — sujunk per B2 Sync.'],
        ['Missing files', db()->query("SELECT COUNT(*) FROM photos".($photoScope ? $photoScope." AND is_missing=1" : " WHERE is_missing=1"))->fetchColumn(), '?page=photos&missing=1', 'Check DB photos marked as missing in storage.'],
    ];
    if (is_superadmin()) $cards[] = ['Uploads', inbox_pending_count(), '?page=inbox', 'Narių įkeltos siuntos, laukiančios perkėlimo į albumą.'];
    echo '<h1>Dashboard</h1><div class="grid">';
    foreach ($cards as [$label,$value,$url,$hint]) echo '<a class="card gateway-card" href="'.e($url).'"><div class="metric">'.e($value).'</div><div class="muted">'.e($label).'</div><div class="gateway-hint">'.e($hint).'</div></a>';
    echo '</div><div class="grid" style="margin-top:18px"><div class="card dash-panel"><h2>Warnings</h2>';
    // Buve trys ispejimai rode normalia bukle, ne klaidas, ir del to i ju skaicius
    // niekas nebeziurejo:
    //   "Albums without cover" skaiciavo cover_photo_id IS NULL, t.y. albumus su
    //   automatiniu virseliu - o tai numatytasis ir teisingas budas. Rode 272,
    //   nors galerijoje be virselio nera NE VIENO albumo (patikrinta per API).
    //   "Photos without author" rode visas 12 653 nuotraukas, nes autorius
    //   saugomas albume, o ne prie kiekvienos nuotraukos.
    //   "Photos without title" rode 11 760 - pavadinimas nebutinas, galerijoje
    //   rodomas failo vardas.
    // Dabar skaiciuojam tai, kas tikrai sugede arba tikrai trūksta viesai.
    $w = [
        ['HEIC be JPG peržiūros', db()->query("SELECT COUNT(*) FROM photos WHERE LOWER(COALESCE(original_format,file_ext,'')) IN ('heic','heif') AND COALESCE(compatibility_b2_key,'')=''")->fetchColumn(), '?page=photos'],
        ['Albumai be nuotraukų', db()->query("SELECT COUNT(*) FROM albums a WHERE NOT EXISTS (SELECT 1 FROM photos p WHERE p.album_id=a.id)")->fetchColumn(), '?page=albums'],
        ['Albumai be datos', db()->query("SELECT COUNT(*) FROM albums WHERE event_date IS NULL OR event_date=''")->fetchColumn(), '?page=albums'],
        ['Paskelbti albumai be autoriaus', db()->query("SELECT COUNT(*) FROM albums WHERE visibility='published' AND (author_name IS NULL OR author_name='')")->fetchColumn(), '?page=albums'],
        ['Rankinis viršelis be nuotraukos', db()->query("SELECT COUNT(*) FROM albums a WHERE COALESCE(a.cover_mode,'auto')='manual' AND (a.cover_photo_id IS NULL OR NOT EXISTS (SELECT 1 FROM photos p WHERE p.id=a.cover_photo_id AND p.album_id=a.id AND p.visibility='published' AND p.is_missing=0))")->fetchColumn(), '?page=albums'],
    ];
    foreach ($w as [$label,$total,$url]) echo '<div class="dash-row"><span class="badge">'.e($total).'</span><a href="'.e($url).'">'.e($label).'</a></div>';
    echo '</div>';

    // B2 bukle. Skaiciai imami is duomenu bazes, o ne vardijant kibira: visas
    // bucket'as yra apie 26 tukst. objektu, ir jo perskaitymas uztruktu kelias
    // sekundes - prietaisu skydeliui tai per brangu. DB zino tuos pacius
    // dydzius, nes i ja jie irasomi kelimo metu.
    $b2 = db()->query(
        "SELECT COUNT(*) objects,
                COALESCE(SUM(file_size),0) bytes,
                SUM(CASE WHEN COALESCE(compatibility_b2_key,'')<>'' THEN 1 ELSE 0 END) previews,
                SUM(CASE WHEN LOWER(COALESCE(original_format,file_ext,'')) IN ('heic','heif')
                          AND COALESCE(compatibility_b2_key,'')='' THEN 1 ELSE 0 END) heic_no_jpg,
                SUM(CASE WHEN is_missing=1 THEN 1 ELSE 0 END) missing
           FROM photos"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $noPath = (int)db()->query("SELECT COUNT(*) FROM albums WHERE source_path IS NULL OR source_path=''")->fetchColumn();
    // Failu saraso podelis: viena stat() uzklausa, o pasako, ar galerija dar
    // remiasi siandienos vaizdu, ar jau pasenusiu.
    $listDir = dirname(dirname(__DIR__)).'/cache/b2_filelist';
    $listAge = null;
    foreach (glob($listDir.'/*.json') ?: [] as $f) {
        $m = @filemtime($f);
        if ($m !== false && ($listAge === null || $m > $listAge)) $listAge = $m;
    }
    $ageText = $listAge === null ? 'nėra' : (function ($sec) {
        if ($sec < 3600) return max(1, (int)round($sec / 60)).' min.';
        if ($sec < 86400) return round($sec / 3600, 1).' val.';
        return round($sec / 86400, 1).' d.';
    })(time() - $listAge).' senumo';

    echo '<a class="card gateway-card dash-panel" href="?page=b2"><h2>B2 Cloud</h2>'
        .'<div class="metric">'.e(human_bytes((int)($b2['bytes'] ?? 0))).'</div>'
        .'<div class="muted small" style="margin-top:-2px">'.e(number_format((int)($b2['objects'] ?? 0))).' originalų + '
        .e(number_format((int)($b2['previews'] ?? 0))).' JPG peržiūrų</div>'
        .'<div class="dash-row" style="margin-top:6px"><span class="badge">'.e((int)($b2['heic_no_jpg'] ?? 0)).'</span> HEIC be JPG peržiūros</div>'
        .'<div class="dash-row"><span class="badge">'.e((int)($b2['missing'] ?? 0)).'</span> DB rodo į nesantį failą</div>'
        .'<div class="dash-row"><span class="badge">'.e($noPath).'</span> albumai be B2 kelio</div>'
        .'<p class="dash-note">Failų sąrašo podėlis: '.e($ageText).'. Kasdienis redagavimas keičia tik DB; B2 objektus kopijuoja ir trina tik B2 Sync, ir tik patvirtinus.</p>'
        .'</a></div>';
    foot('Dashboard');
}


function album_order_rows(): array {
    return db()->query("SELECT id,event_date,sort_order FROM albums ORDER BY sort_order ASC,event_date ASC,id ASC")->fetchAll();
}
function ensure_album_sort_orders(): void {
    $rows = db()->query("SELECT id,sort_order FROM albums ORDER BY COALESCE(event_date,'9999-12-31') ASC,id ASC")->fetchAll();
    if (!$rows) return;
    $needsInit = false;
    $seen = [];
    foreach ($rows as $r) {
        $sort = (int)($r['sort_order'] ?? 0);
        if ($sort <= 0 || isset($seen[$sort])) { $needsInit = true; break; }
        $seen[$sort] = true;
    }
    if (!$needsInit) return;
    $sort = 10;
    $st = db()->prepare("UPDATE albums SET sort_order=? WHERE id=?");
    foreach ($rows as $r) { $st->execute([$sort, (int)$r['id']]); $sort += 10; }
}
function album_date_key(?string $date): int {
    if (!$date) return PHP_INT_MAX;
    $ts = strtotime($date.' 00:00:00 UTC');
    return $ts ? (int)$ts : PHP_INT_MAX;
}
function place_album_by_date(int $albumId): void {
    if ($albumId <= 0) return;
    ensure_album_sort_orders();
    $st = db()->prepare("SELECT id,event_date FROM albums WHERE id=? LIMIT 1");
    $st->execute([$albumId]);
    $album = $st->fetch(PDO::FETCH_ASSOC);
    if (!$album) return;
    $albumDate = album_date_key($album['event_date'] ?? null);
    $rows = db()->prepare("SELECT id,event_date FROM albums WHERE id<>? ORDER BY sort_order ASC,event_date ASC,id ASC");
    $rows->execute([$albumId]);
    $ordered = [];
    $inserted = false;
    foreach ($rows->fetchAll() as $row) {
        if (!$inserted && $albumDate < album_date_key($row['event_date'] ?? null)) {
            $ordered[] = $albumId;
            $inserted = true;
        }
        $ordered[] = (int)$row['id'];
    }
    if (!$inserted) $ordered[] = $albumId;
    $sort = 10;
    $upd = db()->prepare("UPDATE albums SET sort_order=? WHERE id=?");
    foreach ($ordered as $id) { $upd->execute([$sort, $id]); $sort += 10; }
}
function place_album_by_date_if_unset(int $albumId): void {
    if ($albumId <= 0) return;
    $st = db()->prepare("SELECT sort_order FROM albums WHERE id=? LIMIT 1");
    $st->execute([$albumId]);
    if ((int)($st->fetchColumn() ?: 0) <= 0) place_album_by_date($albumId);
}
function move_album_sort(): void {
    csrf();
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $dir = (string)($_POST['dir'] ?? $_GET['dir'] ?? '');
    if ($id <= 0 || !in_array($dir, ['up','down'], true)) { flash('Invalid album move.', 'err'); go(consume_return_path('?page=albums')); }
    if (!is_superadmin()) require_album_editable_by_id($id);
    ensure_album_sort_orders();
    if (is_superadmin()) {
        $rows = album_order_rows();
    } else {
        $stRows = db()->prepare("SELECT id,event_date,sort_order FROM albums WHERE created_by=? ORDER BY sort_order ASC,event_date ASC,id ASC");
        $stRows->execute([current_admin_id()]);
        $rows = $stRows->fetchAll();
    }
    $idx = null;
    foreach ($rows as $i => $row) if ((int)$row['id'] === $id) { $idx = $i; break; }
    if ($idx === null) { flash('Album not found.', 'err'); go(consume_return_path('?page=albums')); }
    $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
    if (!isset($rows[$swap])) { flash('Album order unchanged.'); go(consume_return_path('?page=albums')); }
    $a = $rows[$idx]; $b = $rows[$swap];
    db()->prepare("UPDATE albums SET sort_order=?,updated_by=? WHERE id=?")->execute([(int)$b['sort_order'], $_SESSION['admin']['id'] ?? null, (int)$a['id']]);
    db()->prepare("UPDATE albums SET sort_order=?,updated_by=? WHERE id=?")->execute([(int)$a['sort_order'], $_SESSION['admin']['id'] ?? null, (int)$b['id']]);
    audit('album', $id, 'reorder', 'Album moved '.$dir);
    flash('Album order updated.');
    go(consume_return_path('?page=albums'));
}

function move_photo_sort(): void {
    csrf();
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $dir = (string)($_POST['dir'] ?? $_GET['dir'] ?? '');
    $back = (string)($_POST['back'] ?? $_SERVER['HTTP_REFERER'] ?? '?page=photos');
    if (preg_match('~^https?://~i', $back)) { $parts = parse_url($back); $back = !empty($parts['query']) ? '?'.$parts['query'] : '?page=photos'; }
    elseif (str_starts_with($back, '/')) { $parts = parse_url($back); $back = !empty($parts['query']) ? '?'.$parts['query'] : '?page=photos'; }
    if (!preg_match('/^\?page=photos/', $back)) $back = '?page=photos';
    if ($id <= 0 || !in_array($dir, ['up','down'], true)) { flash('Invalid photo move.', 'err'); go($back); }
    $photo = is_superadmin() ? null : require_photo_editable_by_id($id);
    if (!$photo) {
        $st = db()->prepare("SELECT id,album_id FROM photos WHERE id=? LIMIT 1");
        $st->execute([$id]);
        $photo = $st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$photo) { flash('Photo not found.', 'err'); go($back); }
    $rows = db()->prepare("SELECT id,sort_order FROM photos WHERE album_id=? ORDER BY sort_order ASC,taken_at ASC,id ASC");
    $rows->execute([(int)$photo['album_id']]);
    $list = $rows->fetchAll(PDO::FETCH_ASSOC);
    $idx = null;
    foreach ($list as $i => $row) if ((int)$row['id'] === $id) { $idx = $i; break; }
    if ($idx === null) { flash('Photo not found in album order.', 'err'); go($back); }
    $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
    if (!isset($list[$swap])) { flash('Photo order unchanged.'); go($back); }
    $a = $list[$idx]; $b = $list[$swap];
    $sortA = (int)($a['sort_order'] ?? 0); $sortB = (int)($b['sort_order'] ?? 0);
    if ($sortA <= 0 || $sortB <= 0 || $sortA === $sortB) {
        $sort = 10;
        $upd = db()->prepare("UPDATE photos SET sort_order=?,updated_by=? WHERE id=? AND album_id=?");
        foreach ($list as $row) { $upd->execute([$sort, $_SESSION['admin']['id'] ?? null, (int)$row['id'], (int)$photo['album_id']]); $sort += 10; }
        $rows->execute([(int)$photo['album_id']]);
        $list = $rows->fetchAll(PDO::FETCH_ASSOC);
        foreach ($list as $i => $row) if ((int)$row['id'] === $id) { $idx = $i; break; }
        $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
        if (!isset($list[$swap])) { flash('Photo order unchanged.'); go($back); }
        $a = $list[$idx]; $b = $list[$swap];
        $sortA = (int)$a['sort_order']; $sortB = (int)$b['sort_order'];
    }
    db()->prepare("UPDATE photos SET sort_order=?,updated_by=? WHERE id=?")->execute([$sortB, $_SESSION['admin']['id'] ?? null, (int)$a['id']]);
    db()->prepare("UPDATE photos SET sort_order=?,updated_by=? WHERE id=?")->execute([$sortA, $_SESSION['admin']['id'] ?? null, (int)$b['id']]);
    audit('photo', $id, 'reorder', 'Photo moved '.$dir);
    go($back);
}

function album_fix_badges(array $r): string {
    $id = (int)$r['id'];
    $missing = (int)($r['missing_count'] ?? 0);
    $previewFailed = (int)($r['preview_failed'] ?? 0);
    $noCover = ((int)($r['cover_photo_id'] ?? 0) === 0);
    $bits = [];
    // Albumas be nuotrauku yra pati sunkiausia bukle: DB irasas yra, o rodyti
    // nera ko. Vieso saraso jis nepasiekia, todel be sio zenkliuko liktu
    // nepastebetas neribotai.
    if ((int)($r['photos_count'] ?? 0) === 0) {
        $bits[] = '<a class="btn mini" style="border-color:var(--err-line);color:#e05b6a" href="?page=b2#b2-folder-'.$id.'" title="Albume nera nei vienos nuotraukos - susieti su B2 arba perkelti i draft">⚠ tuscias</a>';
    }
    if ($missing > 0) {
        $bits[] = '<a class="btn mini" style="border-color:var(--err-line);color:var(--text)" href="?page=photos&album_id='.$id.'&missing=1" title="Peržiūrėti trūkstamus failus / Re-check in B2">⚠ '.$missing.' missing</a>';
    }
    // Nario atsiustos nuotraukos guli draft busenoje ir vieso saraso nepasiekia.
    // Be sio zenkliuko jos tyliai gulėtu neribotai - kaip ir tuscias albumas.
    $memberPending = (int)($r['member_pending'] ?? 0);
    if ($memberPending > 0) {
        $bits[] = '<a class="btn mini" style="border-color:var(--accent-line);color:var(--accent-ink)" href="?page=photos&album_id='.$id.'&source=member&visibility=draft" title="Narių įkeltos nuotraukos laukia peržiūros">&#128100; '.$memberPending.' laukia</a>';
    }
    if ($previewFailed > 0) {
        $bits[] = '<a class="btn mini" href="?page=photos&album_id='.$id.'&nopreview=1" title="Nuotraukos be JPG peržiūros">'.$previewFailed.' no preview</a>';
    }
    $coverMode = (string)($r['cover_mode'] ?? 'auto');
    if ((int)($r['cover_photo_id'] ?? 0) > 0) {
        $bits[] = '<a class="btn mini" href="?page=album_edit&id='.$id.'" title="Pasirinktas viršelis" style="color:var(--muted)">custom cover</a>';
    } elseif ($coverMode === 'none') {
        $bits[] = '<a class="btn mini" href="?page=album_edit&id='.$id.'" title="Viršelis išjungtas — galerija rodo be viršelio" style="border-color:var(--err-line)">default cover</a>';
    } else {
        $bits[] = '<a class="btn mini" href="?page=album_edit&id='.$id.'" title="Numatytas viršelis — automatiškai pirma nuotrauka" style="color:var(--muted)">default cover</a>';
    }
    if (!$bits) return '<span class="muted small">✓ OK</span>';
    return '<div class="actions" style="margin:0;gap:4px;flex-direction:column;align-items:flex-start">'.implode('', $bits).'</div>';
}
function albums(): void {
    head('Albums');
    ensure_album_sort_orders();
    $q = trim((string)($_GET['q'] ?? '')); $vis = trim((string)($_GET['visibility'] ?? '')); if ($vis !== '') $vis = normalize_visibility($vis); $albumFilter=(int)($_GET['album_id'] ?? 0); $tagFilter=(int)($_GET['tag_id'] ?? 0); $yearFilter=(int)($_GET['year'] ?? 0); $coverFilter=(string)($_GET['cover'] ?? ''); if (!in_array($coverFilter, ['custom','default'], true)) $coverFilter=''; $p=[]; $where='WHERE 1=1';
    $sortChoice = (string)($_GET['sort_choice'] ?? '');
    $sortChoiceMap = [
        'event_desc' => ['event_date', 'desc'],
        'event_asc' => ['event_date', 'asc'],
        'title_asc' => ['title', 'asc'],
        'title_desc' => ['title', 'desc'],
        'photos_desc' => ['photos', 'desc'],
        'photos_asc' => ['photos', 'asc'],
        'visibility_asc' => ['visibility', 'asc'],
        'order_asc' => ['order', 'asc'],
    ];
    if (isset($sortChoiceMap[$sortChoice])) {
        [$sort, $dir] = $sortChoiceMap[$sortChoice];
    } else {
        $sort = (string)($_GET['sort'] ?? 'event_date');
        $dir = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortChoice = $sort.'_'.$dir;
    }
    $sortMap = [
        'order' => 'a.sort_order ASC,a.event_date ASC,a.id ASC',
        'title' => 'a.title '.($dir === 'desc' ? 'DESC' : 'ASC').',a.event_date ASC,a.id ASC',
        'event_date' => 'COALESCE(a.event_date,\'9999-12-31\') '.($dir === 'desc' ? 'DESC' : 'ASC').',a.id ASC',
        'photos' => 'photos_count '.($dir === 'desc' ? 'DESC' : 'ASC').',a.sort_order ASC,a.id DESC',
        'visibility' => 'a.visibility '.($dir === 'desc' ? 'DESC' : 'ASC').',a.sort_order ASC,a.id DESC',
    ];
    if (!isset($sortMap[$sort])) { $sort = 'event_date'; $dir = 'desc'; $sortChoice = 'event_desc'; }
    $sortLinks = function(string $key, string $label) use ($q, $vis, $albumFilter, $tagFilter, $yearFilter, $coverFilter): string {
        $base = ['page'=>'albums','q'=>$q,'visibility'=>$vis,'album_id'=>$albumFilter ?: null,'tag_id'=>$tagFilter ?: null,'year'=>$yearFilter ?: null,'cover'=>$coverFilter ?: null,'sort'=>$key];
        $az = '?'.http_build_query(array_filter($base + ['dir'=>'asc'], fn($v) => $v !== '' && $v !== null));
        $za = '?'.http_build_query(array_filter($base + ['dir'=>'desc'], fn($v) => $v !== '' && $v !== null));
        return e($label).' <span class="sort-links"><a href="'.e($az).'" title="Ascending">↑</a> <a href="'.e($za).'" title="Descending">↓</a></span>';
    };
    if ($vis !== '') { $where.=' AND a.visibility=?'; $p[]=$vis; }
    if ($albumFilter > 0) { $where.=' AND a.id=?'; $p[]=$albumFilter; }
    if ($tagFilter > 0) { $where.=' AND EXISTS (SELECT 1 FROM album_tags atf WHERE atf.album_id=a.id AND atf.tag_id=?)'; $p[]=$tagFilter; }
    if ($yearFilter > 0) { $where.=' AND YEAR(a.event_date)=?'; $p[]=$yearFilter; }
    if ($coverFilter === 'custom') { $where.=' AND COALESCE(a.cover_photo_id,0) > 0'; }
    if ($coverFilter === 'default') { $where.=' AND COALESCE(a.cover_photo_id,0) = 0'; }
    if (!is_superadmin()) { $where.=' AND a.created_by=?'; $p[] = current_admin_id(); }
    $where .= search_sql(['a.title','a.slug','a.description','a.source_path','a.author_name','a.location_name','a.sport_type','a.copyright_text','a.dbsportas_url','a.klajunas_url','a.other_url'], $q, $p);
    if (is_superadmin()) {
        $allAlbums = db()->query("SELECT id,title FROM albums ORDER BY COALESCE(event_date,'9999-12-31') ASC,id ASC")->fetchAll();
        $allTags = db()->query("SELECT t.id,t.name,COUNT(DISTINCT at.album_id) albums_count FROM tags t JOIN album_tags at ON at.tag_id=t.id GROUP BY t.id,t.name HAVING albums_count>0 ORDER BY t.name")->fetchAll();
    } else {
        $allAlbumSt = db()->prepare("SELECT id,title FROM albums WHERE created_by=? ORDER BY COALESCE(event_date,'9999-12-31') ASC,id ASC");
        $allAlbumSt->execute([current_admin_id()]);
        $allAlbums = $allAlbumSt->fetchAll();
        $allTagSt = db()->prepare("SELECT t.id,t.name,COUNT(DISTINCT at.album_id) albums_count FROM tags t JOIN album_tags at ON at.tag_id=t.id JOIN albums a ON a.id=at.album_id WHERE a.created_by=? GROUP BY t.id,t.name HAVING albums_count>0 ORDER BY t.name");
        $allTagSt->execute([current_admin_id()]);
        $allTags = $allTagSt->fetchAll();
    }
    $albumSelectLen = 12;
    foreach ($allAlbums as $a) $albumSelectLen = max($albumSelectLen, mb_strlen((string)$a['title'], 'UTF-8') + 2);
    $albumSelectWidth = min(80, max(18, $albumSelectLen));
    $needsFix = (int)($_GET['needs_fix'] ?? 0);
    // photos_count = 0 butinas: albumas be nuotrauku turi missing_count 0
    // (suma per nulį eiluciu), preview_failed 0 ir cover_mode 'auto', tad be
    // sios salygos jis i "tik taisytini" filtra nepakliudavo.
    $havingFix = $needsFix ? " HAVING (photos_count = 0 OR missing_count > 0 OR preview_failed > 0 OR a.cover_mode = 'none')" : '';
    $st = db()->prepare("SELECT a.*, COUNT(p.id) photos_count, COALESCE(SUM(p.is_missing=1),0) missing_count, COALESCE(SUM(".NO_PREVIEW_SQL."),0) preview_failed, COALESCE(SUM(p.source_type='member_upload' AND p.visibility='draft'),0) member_pending FROM albums a LEFT JOIN photos p ON p.album_id=a.id $where GROUP BY a.id{$havingFix} ORDER BY {$sortMap[$sort]} LIMIT 250");
    $st->execute($p); $rows=$st->fetchAll();
    // Vienpusis: albumas = trinamas dublikatas TIK jei egzistuoja ILGESNIS to
    // paties kamieno albumas (šis yra priešdėlio-stub). Turi sutapti su
    // delete_album() logika, kad dialogas negrasintų B2 trynimu, kurio backend
    // nedarytų. Kanoninis (ilgesnis) albumas čia sąmoningai NErodomas kaip dublikatas.
    $dupAlbumIds = [];
    try { $dupAlbumIds = array_map('intval', db()->query("SELECT DISTINCT a.id FROM albums a JOIN albums b ON a.id<>b.id WHERE a.source_path IS NOT NULL AND a.source_path<>'' AND b.source_path LIKE CONCAT(a.source_path,'-%')")->fetchAll(PDO::FETCH_COLUMN)); } catch (Throwable $e) {}
    $albumStatusSelect = str_replace('<select ', '<select onchange="this.form.submit()" style="width:16ch;max-width:16ch" ', status_select('visibility',$vis));
    $back='?'.($_SERVER['QUERY_STRING'] ?? 'page=albums');
    $returnKey = remember_return_path($back);
    $sortSelect = '<select name="sort_choice" onchange="this.form.submit()" style="width:24ch;max-width:100%">';
    foreach ([
        'event_desc' => 'Newest events first',
        'event_asc' => 'Oldest events first',
        'title_asc' => 'Title A-Z',
        'title_desc' => 'Title Z-A',
        'photos_desc' => 'Most photos',
        'photos_asc' => 'Fewest photos',
        'visibility_asc' => 'Visibility',
        'order_asc' => 'Manual order',
    ] as $value => $label) {
        $sortSelect .= '<option value="'.e($value).'"'.($sortChoice === $value ? ' selected' : '').'>'.e($label).'</option>';
    }
    $sortSelect .= '</select>';
    $tagSelect = '<select name="tag_id" onchange="this.form.submit()" style="width:24ch;max-width:100%"><option value="0">Any tag</option>';
    foreach ($allTags as $tag) {
        $tagSelect .= '<option value="'.e($tag['id']).'"'.($tagFilter===(int)$tag['id']?' selected':'').'>'.e($tag['name']).' ('.e((string)$tag['albums_count']).')</option>';
    }
    $tagSelect .= '</select>';
    $yearRowsSql = "SELECT DISTINCT YEAR(event_date) y FROM albums WHERE event_date IS NOT NULL".(is_superadmin() ? '' : ' AND created_by='.(int)current_admin_id())." ORDER BY y DESC";
    $yearSelect = '<select name="year" onchange="this.form.submit()" style="width:12ch;max-width:12ch"><option value="0">Any year</option>';
    try {
        foreach (db()->query($yearRowsSql)->fetchAll(PDO::FETCH_COLUMN) as $yr) {
            $yr = (int)$yr;
            if ($yr <= 0) continue;
            $yearSelect .= '<option value="'.e((string)$yr).'"'.($yearFilter===$yr?' selected':'').'>'.e((string)$yr).'</option>';
        }
    } catch (Throwable $e) {}
    $yearSelect .= '</select>';
    $coverSelect = '<select name="cover" onchange="this.form.submit()" style="width:15ch;max-width:15ch"><option value="">Any cover</option><option value="custom"'.($coverFilter==='custom'?' selected':'').'>Custom cover</option><option value="default"'.($coverFilter==='default'?' selected':'').'>Default cover</option></select>';
    echo '<div class="actions" style="justify-content:space-between;align-items:center"><h1 style="margin:0">Albums</h1><div class="actions" style="margin:0"><button type="button" class="btn" id="albumViewToggle" title="Perjungti tinklelio ir sąrašo rodinį">▦ Tinklelis</button><a class="btn primary" href="?page=album_edit">New album</a>'.($needsFix ? '<a class="btn" style="border-color:var(--accent-line);color:var(--accent-ink)" href="?page=albums">✓ Rodyti visus</a>' : '<a class="btn" style="border-color:var(--err-line)" href="?page=albums&needs_fix=1">⚠ Tik taisytini</a>').(is_superadmin()?'<a class="btn" href="?action=export&type=albums">Export CSV</a>':'').'</div></div><form id="albumFilterForm"><input type="hidden" name="page" value="albums"><input type="hidden" name="sort" value="'.e($sort).'"><input type="hidden" name="dir" value="'.e($dir).'"><div class="actions albumfilter"><input class="filter-q" name="q" placeholder="Paieška: pavadinimas, slug, šaltinis, autorius..." value="'.e($q).'">'.$albumStatusSelect.$sortSelect.$tagSelect.'<select name="album_id" onchange="this.form.submit()" style="width:'.e((string)$albumSelectWidth).'ch;max-width:100%"><option value="0">Any album</option>';
    foreach ($allAlbums as $a) echo '<option value="'.e($a['id']).'"'.($albumFilter===(int)$a['id']?' selected':'').'>'.e($a['title']).'</option>';
    echo '</select>'.$yearSelect.$coverSelect.'</div></form>';
    echo '<form method="post" action="?action=bulk_albums"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="return_key" value="'.e($returnKey).'"><div class="actions bulkbar" style="align-items:center;margin-top:10px"><select name="bulk_action" style="width:24ch;max-width:24ch"><option value="">Bulk action</option><option value="published">Publish selected</option><option value="draft">Move selected to draft</option><option value="private">Set selected private</option><option value="hidden">Hide selected</option></select><button>Apply</button></div><table class="albums-table"><tr><th><input type="checkbox" data-master-check aria-label="Select all"></th><th title="Actions" aria-label="Actions"></th><th><a href="?page=albums">Order</a></th><th>'.$sortLinks('title','Title').'</th><th>Slug</th><th>'.$sortLinks('event_date','Event date').'</th><th>'.$sortLinks('photos','Photos').'</th><th>'.$sortLinks('visibility','Visibility').'</th><th>Needs fixing</th><th>Storage</th><th>Source</th></tr>';
    $lockBudget = 5; // maks. B2 užklausų kešui užpildyti per vieną puslapio užkrovimą
    foreach ($rows as $i => $r) {
        $storage = album_storage_summary($r);
        $lock = album_lock_state($r, $lockBudget);
        $sortControls = '<div class="actions" style="margin:0;gap:4px;align-items:center"><span class="badge">'.e((string)($i+1)).'</span><button class="mini" formmethod="post" formaction="?action=move_album_sort&id='.e($r['id']).'&dir=up" title="Move up"'.($i===0?' disabled':'').'>↑</button><button class="mini" formmethod="post" formaction="?action=move_album_sort&id='.e($r['id']).'&dir=down" title="Move down"'.($i===count($rows)-1?' disabled':'').'>↓</button></div>';
        $canEdit = album_editable_by_current_user($r);
        $isDupRow = in_array((int)$r['id'], $dupAlbumIds, true);
        $deleteConfirm = $isDupRow
            ? 'Ištrinti DUBLIKATĄ „'.addslashes((string)$r['title']).'“?\n\nBus ištrinta: '.(string)$r['photos_count'].' nuotraukų įrašų iš DB IR jo B2 aplanko failai ('.addslashes((string)$r['source_path']).').\n\nKanoninio albumo failai neliečiami. Veiksmas negrįžtamas.'
            : 'Ištrinti albumą „'.addslashes((string)$r['title']).'“ ir jo '.(string)$r['photos_count'].' nuotraukų įrašus iš DB?\n\nB2 failai saugykloje NELIEČIAMI — dings tik DB manifestas.';
        // Užrakintam albumui Delete mygtuko nerodom — backend jį blokuotų, todėl
        // vietoj klaidos rodom 🔒 ženkliuką (žr. Needs fixing stulpelį).
        // Mygtukas rodomas tik juodrasciams ir tik neuzrakintiems albumams. Iki
        // siol kitais atvejais jo tiesiog nebudavo, ir atrodydavo, kad tokio
        // funkcionalumo admin isvis nera - o tikroji priezastis buvo matomumas.
        // Dabar vietoj tuscios vietos pasakom, ko truksta.
        if (!$canEdit || $lock['locked']) {
            $deleteBtn = '';   // uzrakintiems salia rodomas spynos zenklas
        } elseif ((string)$r['visibility'] !== 'draft') {
            // Pastaba rodoma po pavadinimu ($draftOnlyNote), kad Actions stulpelis liktu siauras.
            $deleteBtn = '';
        } else {
            $deleteBtn = '<button class="mini" formmethod="post" formaction="?action=delete_album&id='.e($r['id']).'" style="border-color:var(--err-line);color:#e05b6a" onclick="return confirm(\''.e($deleteConfirm).'\')">Delete</button>';
        }
        $lockBadge = $lock['locked']
            ? '<span class="badge" title="Turinys B2 senesnis nei '.ALBUM_DELETE_LOCK_DAYS.' d. — iš admin nebetrinamas, tik rankiniu būdu B2 serveryje" style="border-color:var(--accent-line);color:var(--accent-ink)">🔒 užrakinta</span>'
            : '';
        $draftOnlyNote = ($canEdit && !$lock['locked'] && (string)$r['visibility'] !== 'draft')
            ? '<br><span class="badge" title="Trinti galima tik juodrasti. Atidaryk albuma, nustatyk Visibility = draft, issaugok - tada Actions stulpelyje atsiras Delete." style="cursor:help;margin-top:4px">trinti: tik draft</span>'
            : '';
        // Sistemos ID po eiles numeriu - nuoroda i vieša /a/id<N>.
        $idTag = '<div class="small" style="margin-top:3px">'.((string)$r['visibility'] === 'published'
            ? '<a class="muted" href="/a/id'.e($r['id']).'" target="_blank" rel="noopener" title="Sistemos ID · atidaryti viešą albumą" style="text-decoration:underline;text-underline-offset:2px">#'.e($r['id']).'</a>'
            : '<span class="muted" title="Sistemos ID (albumas nepaskelbtas - viešo puslapio nėra)">#'.e($r['id']).'</span>').'</div>';
        $actions = '<div class="actions" style="margin:0;gap:4px">'.($canEdit ? '<a class="btn mini" href="?page=album_edit&id='.e($r['id']).'">Edit</a>'.$deleteBtn : '<span class="badge">read-only</span>').'</div>';
        $tagSt=db()->prepare("SELECT t.name FROM tags t JOIN album_tags at ON at.tag_id=t.id WHERE at.album_id=? ORDER BY t.name");
        $tagSt->execute([(int)$r['id']]);
        $tagNames=array_column($tagSt->fetchAll(),'name');
        $titleBits=array_values(array_filter([
            (string)($r['location_name'] ?? ''),
            (string)($r['sport_type'] ?? ''),
            (string)($r['author_name'] ?? ''),
            (string)($r['copyright_text'] ?? ''),
            $tagNames ? '#'.implode(' #', $tagNames) : '',
        ], fn($v) => trim((string)$v) !== ''));
        $titleLink = '<a href="?page=photos&album_id='.e($r['id']).'"><strong>'.e($r['title']).'</strong></a>';
        echo '<tr><td>'.($canEdit ? '<input type="checkbox" name="ids[]" value="'.e($r['id']).'">' : '<span class="muted">•</span>').'</td><td>'.$actions.'</td><td>'.$sortControls.$idTag.'</td><td>'.$titleLink.($titleBits ? '<br><span class="muted">'.e(implode(' · ', $titleBits)).'</span>' : '').$draftOnlyNote.'</td><td>'.e($r['slug']).'</td><td>'.e($r['event_date']).'</td><td>'.e($r['photos_count']).'</td><td><span class="badge">'.e($r['visibility']).'</span></td><td class="small">'.album_fix_badges($r).($lockBadge ? '<br>'.$lockBadge : '').'</td><td class="small">'.$sortControls.'<br><span class="badge">'.e($storage['label']).'</span><br><a class="btn mini" href="?page=b2#b2-folder-'.e($r['id']).'">'.e($storage['action']).'</a></td><td class="small">'.e($r['source_type']).'<br>'.e($r['source_path']).'</td></tr>';
    }
    echo '</table>';

    // Tinklelio rodinys: virselis dominuoja, albumas atpazistamas is nuotraukos.
    // Sarasas lieka valdymui (masiniai veiksmai, rikiavimo rodykles), todel
    // tinklelyje zymimuju langeliu NEdubliuojam - kitaip bulk formai nueitu
    // dvigubi ids[].
    $coverByAlbum = [];
    $albumIds = array_map(static fn($r) => (int)$r['id'], $rows);
    $coverCols = 'p.album_id,p.id,p.b2_key,p.compatibility_b2_key,p.thumb_path,p.preview_path,p.web_path,p.original_filename';
    if ($albumIds) {
        $in = implode(',', array_fill(0, count($albumIds), '?'));
        // Po vieną eilutę albumui: maziausio id nuotrauka (po B2 Sync tai
        // chronologiskai pirmoji, nes eiles tvarka dabar chronologine).
        try {
            $cs = db()->prepare("SELECT $coverCols FROM photos p INNER JOIN (SELECT album_id,MIN(id) mid FROM photos WHERE album_id IN ($in) AND is_missing=0 GROUP BY album_id) f ON f.mid=p.id");
            $cs->execute($albumIds);
            foreach ($cs->fetchAll() as $cr) $coverByAlbum[(int)$cr['album_id']] = $cr;
        } catch (Throwable $e) {}
        // Rankiniu budu parinkti virseliai perrašo numatytuosius.
        $manual = array_values(array_filter(array_map(static fn($r) => (int)($r['cover_photo_id'] ?? 0), $rows)));
        if ($manual) {
            $in2 = implode(',', array_fill(0, count($manual), '?'));
            try {
                $ms = db()->prepare("SELECT $coverCols FROM photos p WHERE p.id IN ($in2) AND p.is_missing=0");
                $ms->execute($manual);
                foreach ($ms->fetchAll() as $cr) $coverByAlbum[(int)$cr['album_id']] = $cr;
            } catch (Throwable $e) {}
        }
    }

    echo '<div class="albumgrid">';
    foreach ($rows as $r) {
        $aid = (int)$r['id'];
        $cover = $coverByAlbum[$aid] ?? null;
        $img = $cover ? thumb_url($cover, 420) : '';
        $visv = (string)($r['visibility'] ?? 'draft');
        $ed = trim((string)($r['event_date'] ?? ''));
        $ee = trim((string)($r['event_date_end'] ?? ''));
        $dateLabel = $ed === '' ? '—' : (($ee !== '' && $ee !== $ed) ? $ed.' – '.$ee : $ed);
        $place = trim((string)($r['location_name'] ?? ''));
        $missing = (int)($r['missing_count'] ?? 0);
        $href = '?page=album_edit&id='.e((string)$aid);
        echo '<article class="acard">';
        echo '<a class="acard-cover" href="'.$href.'">';
        if ($img !== '') echo '<img loading="lazy" src="'.e($img).'" alt="">';
        else echo '<span class="acard-ph">'.e($ed !== '' ? substr($ed, 0, 4) : '?').'</span>';
        if ($missing > 0) echo '<span class="acard-flag">'.e((string)$missing).' trūksta</span>';
        echo '<span class="acard-count">'.e((string)(int)$r['photos_count']).'</span>';
        echo '</a><div class="acard-body">';
        echo '<h3 class="acard-title"><a href="'.$href.'">'.e((string)$r['title']).'</a></h3>';
        echo '<div class="acard-meta"><span>'.e($dateLabel).'</span>';
        echo $place !== '' ? '<span>◈ '.e($place).'</span>' : '<span class="warn">◈ vietovė nenurodyta</span>';
        echo '</div><div><span class="spill s-'.e($visv).'"><span class="d"></span>'.e($visv).'</span></div>';
        echo '</div></article>';
    }
    echo '</div></form>';

    echo '<script>(function(){var b=document.getElementById("albumViewToggle");if(!b)return;function set(g){document.body.classList.toggle("view-grid",g);b.textContent=g?"☰ Sąrašas":"▦ Tinklelis";try{localStorage.setItem("kadmin_albumview",g?"grid":"list");}catch(e){}}var s="grid";try{s=localStorage.getItem("kadmin_albumview")||"grid";}catch(e){}set(s==="grid");b.addEventListener("click",function(){set(!document.body.classList.contains("view-grid"));});})();</script>';
    foot('Albums');
}

function album_edit(): void {
    // ?page=album_edit&slug=<slug> (is viesos /?a=<slug>/edit nuorodos) -> &id=
    if (!(int)($_GET['id'] ?? 0) && trim((string)($_GET['slug'] ?? '')) !== '') {
        $st = db()->prepare("SELECT id FROM albums WHERE slug=? OR TRIM(BOTH '/' FROM source_path)=? ORDER BY slug=? DESC LIMIT 1");
        $wanted = trim((string)$_GET['slug'], "/ ");
        $st->execute([$wanted, $wanted, $wanted]);
        $found = (int)($st->fetchColumn() ?: 0);
        if ($found) go('?page=album_edit&id='.$found);
        flash('Albumas nerastas: '.(string)$_GET['slug'], 'err'); go('?page=albums');
    }
    head(((int)($_GET['id'] ?? 0)) ? 'Edit album' : 'New album');
    $id=(int)($_GET['id'] ?? 0);
    $r=['id'=>0,'title'=>'','slug'=>'','subtitle'=>'','description'=>'','event_date'=>'','event_date_end'=>'','location_name'=>'','sport_type'=>'','author_name'=>'','copyright_text'=>'','cover_mode'=>'auto','visibility'=>'draft','sort_order'=>0,'download_enabled'=>1,'source_path'=>'','seo_title'=>'','seo_description'=>'','dbsportas_url'=>'','klajunas_url'=>'','other_url'=>'','notes_internal'=>''];
    if ($id) { $st=db()->prepare("SELECT * FROM albums WHERE id=?"); $st->execute([$id]); $r=$st->fetch() ?: $r; if (!album_editable_by_current_user($r)) { http_response_code(403); exit('Forbidden'); } }
    echo '<h1>'.($id?'Edit album':'New album').'</h1><form id="albumEditForm" method="post" action="?action=save_album"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="id" value="'.e($id).'">';
    echo '<div class="card" style="padding:18px;margin-bottom:18px"><h2>Album fields</h2><div class="album-fields-grid">';
    echo '<section class="album-field-panel"><h3>System fields</h3><div class="formgrid">';
    $systemFields = ['title'=>'Title','slug'=>'Slug','event_date'=>'Event date','event_date_end'=>'Event date end'];
    foreach ($systemFields as $n=>$l) { $idAttr = $n === 'title' ? ' id="albumTitleInput"' : ($n === 'slug' ? ' id="albumSlugInput"' : ''); $class = ($n === 'title' || $n === 'slug') ? ' class="field-full"' : ''; echo '<div'.$class.'><label>'.e($l).'</label><input'.$idAttr.' name="'.e($n).'" value="'.e($r[$n] ?? '').'"></div>'; }
    echo '<div><label>Visibility</label>'.status_select('visibility',$r['visibility']).'</div><div><label>Sort order</label><input type="number" name="sort_order" value="'.e($r['sort_order']).'"></div>';
    $coverMode = in_array((string)($r['cover_mode'] ?? 'auto'), ['auto','manual','none'], true) ? (string)$r['cover_mode'] : 'auto';
    if (!empty($r['cover_photo_id']) && $coverMode === 'auto') $coverMode = 'manual';
    echo '<div><label>Cover photo</label><select name="cover_photo_id"><option value="__auto__"'.($coverMode==='auto'?' selected':'').'>Auto</option><option value="__none__"'.($coverMode==='none'?' selected':'').'>None</option>';
    if ($id) { $ps=db()->prepare("SELECT id,original_filename FROM photos WHERE album_id=? ORDER BY sort_order,id LIMIT 500"); $ps->execute([$id]); foreach($ps as $p) echo '<option value="'.e($p['id']).'"'.((int)$r['cover_photo_id']===(int)$p['id']?' selected':'').'>'.e($p['original_filename']).'</option>'; }
    echo '</select></div><div class="actions" style="align-items:center;margin-top:22px"><label><input type="checkbox" name="download_enabled" value="1" '.($r['download_enabled']?'checked':'').'> Downloads enabled</label></div></div><p class="muted small">Title/date/slug define the public album identity and ordering. Cover and visibility control public display.</p>';

    // Pastovi dalinimosi nuoroda. Remiasi albumo ID, o ne slug'u: pavadinimai ir
    // keliai laikui begant tikslinami, ID nesikeicia niekada, todel karta issiusta
    // nuoroda nemirsta. Naujam albumui ID dar nera - tada cia nerodom nieko:
    // issaugojus nuoroda atsiranda pati, ir atskirai to aiskinti nereikia.
    if ($id) {
        $shareUrl = 'https://foto.klajunas.lt/a/id'.$id;
        echo '<label style="margin-top:14px;display:block">Pastovi nuoroda</label>'
            .'<div class="actions" style="gap:8px;align-items:center;flex-wrap:wrap">'
            .'<input id="albumShareUrl" readonly value="'.e($shareUrl).'" onfocus="this.select()" style="flex:1;min-width:200px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace">'
            .'<button type="button" class="btn mini" id="albumShareCopy">Kopijuoti</button>'
            .'<a class="btn mini" href="'.e($shareUrl).'" target="_blank" rel="noopener">Atidaryti</a>'
            .'</div>'
            .'<p class="muted small">Dalinkis sia, o ne adresu is narsykles juostos: veikia ir pervadinus albuma. Nuotraukai pridek <code>?f=3</code>.</p>'
            .'<script>document.getElementById("albumShareCopy").addEventListener("click",function(){'
            .'var i=document.getElementById("albumShareUrl"),b=this,t=b.textContent;i.select();'
            .'var ok=function(){b.textContent="Nukopijuota ✓";setTimeout(function(){b.textContent=t},1400)};'
            .'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(i.value).then(ok,function(){try{document.execCommand("copy");ok()}catch(e){}})}'
            .'else{try{document.execCommand("copy");ok()}catch(e){}}});</script>';
    }
    echo '</section>';

    $selectedTagIds = [];
    $tags='';
    if($id){
        $ts=db()->prepare("SELECT t.id,t.name FROM tags t JOIN album_tags at ON at.tag_id=t.id WHERE at.album_id=? ORDER BY t.name");
        $ts->execute([$id]);
        $tagRows=$ts->fetchAll();
        $selectedTagIds=array_map('intval', array_column($tagRows,'id'));
        $tags=implode(', ',array_column($tagRows,'name'));
    }
    $allTags = db()->query("SELECT id,name,type FROM tags ORDER BY FIELD(type,'sport','topic','keyword','location','person','style'), name LIMIT 200")->fetchAll();
    echo '<section class="album-field-panel"><h3>Meta fields</h3><div class="formgrid">';
    foreach (['subtitle'=>'Subtitle','location_name'=>'Location','sport_type'=>'Sport','author_name'=>'Author','copyright_text'=>'Copyright'] as $n=>$l) echo '<div><label>'.e($l).'</label><input name="'.e($n).'" value="'.e($r[$n] ?? '').'"></div>';
    echo '<div class="full"><label>Album tags</label><div class="actions" style="gap:8px;align-items:center;flex-wrap:wrap">';
    foreach ($allTags as $tag) {
        $checked = in_array((int)$tag['id'], $selectedTagIds, true) ? ' checked' : '';
        echo '<label class="badge" style="cursor:pointer"><input type="checkbox" name="tag_ids[]" value="'.e($tag['id']).'"'.$checked.'> '.e($tag['name']).'</label>';
    }
    echo '</div><input name="tags" value="" placeholder="New tags, comma separated (esamos žymos valdomos varnelėmis)" style="margin-top:8px"></div>';
    echo '<div class="full"><label>Internal notes</label><textarea name="notes_internal" rows="3">'.e($r['notes_internal']).'</textarea></div></div></section>';
    $albumDescription = (string)($r['description'] ?: ($r['seo_description'] ?? ''));
    echo '<section class="album-field-panel"><h3>SEO fields</h3><div class="formgrid"><div class="full"><label>SEO title</label><input name="seo_title" value="'.e($r['seo_title']).'"></div><div class="full"><label>Album description // SEO</label><textarea name="description" rows="5">'.e($albumDescription).'</textarea></div><div class="full"><label>dbsportas URL</label><input name="dbsportas_url" value="'.e($r['dbsportas_url'] ?? '').'"></div><div class="full"><label>klajunas URL</label><input name="klajunas_url" value="'.e($r['klajunas_url'] ?? '').'"></div><div class="full"><label>Other URL</label><input name="other_url" value="'.e($r['other_url'] ?? '').'"></div></div><p class="muted small">Album description is reused for SEO/search/browser metadata.</p></section>';
    echo '</div></div><div class="actions"><button class="primary">Save album</button><a class="btn" href="?page=albums">Back</a>'
        .'<span id="albumAutosaveState" class="muted small" aria-live="polite"></span></div></form><script>(function(){var form=document.getElementById("albumEditForm");var title=document.getElementById("albumTitleInput");var slugInput=document.getElementById("albumSlugInput");if(!title||!slugInput)return;var manual=slugInput.value!=="";function slug(v){return (v||"").normalize("NFD").replace(/[\u0300-\u036f]/g,"").toLowerCase().replace(/[^a-z0-9]+/g,"-").replace(/^-+|-+$/g,"");}slugInput.addEventListener("input",function(){manual=true;});slugInput.addEventListener("blur",function(){slugInput.value=slug(slugInput.value);});title.addEventListener("input",function(){if(!manual)slugInput.value=slug(title.value);});if(form)form.addEventListener("submit",function(){slugInput.value=slug(slugInput.value||title.value);});})();</script>';

    // Automatinis irasymas - tik juodrasciams. Paskelbtas albumas matomas
    // visiems, todel jo keitimas lieka samoningas veiksmas su mygtuku; tas pats
    // ir su perjungimu i "published" - jis neirasomas tyliai.
    echo '<script>(function(){'
        .'var form=document.getElementById("albumEditForm");if(!form)return;'
        .'var state=document.getElementById("albumAutosaveState");'
        .'var vis=form.querySelector("[name=visibility]");'
        .'var idField=form.querySelector("[name=id]");'
        .'var isNew='.($id ? '0' : '1').';'
        .'var timer=null,busy=false,pending=false;'
        .'function say(t,err){if(!state)return;state.textContent=t;state.style.color=err?"var(--accent-ink)":"";}'
        .'function draft(){return !vis||vis.value!=="published";}'
        .'function send(){'
            .'if(busy){pending=true;return;}'
            .'var t=form.querySelector("[name=title]");'
            .'if(!t||!t.value.trim()){say("");return;}'
            .'busy=true;say("Įrašom…");'
            .'var fd=new FormData(form);fd.append("ajax","1");'
            .'fetch("?action=save_album",{method:"POST",body:fd,credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest"}})'
            .'.then(function(r){return r.json().catch(function(){throw new Error("HTTP "+r.status);});})'
            .'.then(function(d){'
                .'if(!d||!d.ok)throw new Error((d&&d.error)||"Nepavyko");'
                .'if(d.created&&d.id){location.replace("?page=album_edit&id="+encodeURIComponent(d.id));return;}'
                .'if(idField&&d.id)idField.value=d.id;'
                .'busy=false;say("Įrašyta ✓");'
                .'if(pending){pending=false;send();}'
            .'})'
            .'.catch(function(e){busy=false;say("Neįrašyta: "+e.message,true);});'
        .'}'
        .'function schedule(){if(!draft())return;clearTimeout(timer);timer=setTimeout(send,900);}'
        .'form.addEventListener("input",function(e){if(e.target===vis)return;schedule();});'
        .'form.addEventListener("change",function(e){if(e.target===vis){say("Matomumas keičiamas tik paspaudus „Save album“.");return;}schedule();});'
        // Naujas albumas sukuriamas vos atsiradus pavadinimui - kad ikelimo forma
        // atsirastu nelaukiant, kol naudotojas uzpildys visa kita.
        .'if(isNew){var t0=form.querySelector("[name=title]");if(t0)t0.addEventListener("blur",function(){if(t0.value.trim())send();});}'
    .'})();</script>';
    if ($id) {
        album_photo_board($id, (int)($r['cover_photo_id'] ?? 0));
    } else {
        // Nauju albuma kuriant nuotrauku lentos rodyti dar negalim - jai reikia
        // albumo ID. Bet dvieju etapu ("issaugok, tada grizk ir kelk") is
        // naudotojo nebereikalaujam: ivedus pavadinima juodrastis irasomas pats
        // ir puslapis atsidaro jau su ikelimo forma.
        echo '<section class="card" style="margin-top:18px"><h2>Album photo management</h2>'
            .'<p class="muted" id="albumPhotoHint">Įrašyk pavadinimą — albumas bus sukurtas kaip juodraštis, ir nuotraukų įkėlimas atsiras čia pat. Spausti „Save album“ nereikia.</p>'
            .'</section>';
    }
    foot('Album');
}

function photos(): void {
    head('Photos');
    $q=trim((string)($_GET['q'] ?? '')); $vis=trim((string)($_GET['visibility'] ?? '')); if ($vis !== '') $vis = normalize_visibility($vis); $album=(int)($_GET['album_id'] ?? 0); $missing=(int)($_GET['missing'] ?? 0); $source=(string)($_GET['source'] ?? '') === 'member' ? 'member' : ''; $nopreview=(int)($_GET['nopreview'] ?? 0) === 1 ? 1 : 0; $p=[]; $where='WHERE 1=1';
    $sort=(string)($_GET['sort'] ?? 'order');
    $dir=strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    $sortMap=[
        'order'=>'p.sort_order ASC,p.taken_at ASC,p.id ASC',
        'file'=>'p.original_filename '.($dir==='desc'?'DESC':'ASC').',p.id ASC',
        'album'=>'a.title '.($dir==='desc'?'DESC':'ASC').',p.sort_order ASC,p.id ASC',
        'visibility'=>'p.visibility '.($dir==='desc'?'DESC':'ASC').',p.sort_order ASC,p.id ASC',
        'taken'=>'p.taken_at '.($dir==='desc'?'DESC':'ASC').',p.sort_order ASC,p.id ASC',
        'size'=>'p.file_size '.($dir==='desc'?'DESC':'ASC').',p.sort_order ASC,p.id ASC',
    ];
    if(!isset($sortMap[$sort])) { $sort='order'; $dir='asc'; }
    $sortLinks=function(string $key,string $label) use($q,$vis,$album,$missing,$source,$nopreview): string {
        $base=['page'=>'photos','q'=>$q,'visibility'=>$vis,'album_id'=>$album ?: null,'missing'=>$missing ?: null,'nopreview'=>$nopreview ?: null,'source'=>$source ?: null,'sort'=>$key];
        $az='?'.http_build_query(array_filter($base + ['dir'=>'asc'], fn($v)=>$v!=='' && $v!==null));
        $za='?'.http_build_query(array_filter($base + ['dir'=>'desc'], fn($v)=>$v!=='' && $v!==null));
        return e($label).' <span class="sort-links"><a href="'.e($az).'" title="Ascending">↑</a> <a href="'.e($za).'" title="Descending">↓</a></span>';
    };
    if ($vis !== '') { $where.=' AND p.visibility=?'; $p[]=$vis; } if ($album) { $where.=' AND p.album_id=?'; $p[]=$album; } if ($missing) { $where.=' AND p.is_missing=1'; } if ($source==='member') { $where.=" AND p.source_type='member_upload'"; } if ($nopreview) { $where.=' AND '.NO_PREVIEW_SQL; }
    if (!is_superadmin()) { $where.=' AND a.created_by=?'; $p[] = current_admin_id(); }
    $where .= search_sql(['p.title','p.original_filename','p.b2_key','p.author_name','p.camera_model','p.city','p.country'], $q, $p);
    $st=db()->prepare("SELECT p.*,a.title album_title,a.created_by album_created_by FROM photos p LEFT JOIN albums a ON a.id=p.album_id $where ORDER BY {$sortMap[$sort]} LIMIT 300"); $st->execute($p); $rows=$st->fetchAll();
    if (is_superadmin()) { $albums=db()->query("SELECT id,title FROM albums ORDER BY sort_order,event_date DESC,id DESC")->fetchAll(); } else { $albumSt=db()->prepare("SELECT id,title FROM albums WHERE created_by=? ORDER BY sort_order,event_date DESC,id DESC"); $albumSt->execute([current_admin_id()]); $albums=$albumSt->fetchAll(); }
    $tagRows=db()->query("SELECT id,name FROM tags ORDER BY name LIMIT 500")->fetchAll();
    $tagOptions='<option value="0">Append tag...</option>';
    foreach ($tagRows as $tag) $tagOptions .= '<option value="'.e($tag['id']).'">'.e($tag['name']).'</option>';
    $albumSelectLen = 14;
    foreach ($albums as $a) $albumSelectLen = max($albumSelectLen, min(82, mb_strlen((string)$a['title'], 'UTF-8')) + 2);
    $albumSelectWidth = min(86, max(18, $albumSelectLen));
    // Tikslinio albumo sarasas veiksmui „Priskirti kitam albumui". Kelio
    // buvimas tikrinamas pacioje operacijoje (bulk/move_album): jei albumas be
    // B2 kelio, veiksmas sustoja su nuoroda i Storage path, o ne kopijuoja i
    // niekur.
    $albumTargetOptions = '';
    foreach ($albums as $a) {
        $targetTitle = (string)$a['title'];
        if (mb_strlen($targetTitle, 'UTF-8') > 60) $targetTitle = mb_substr($targetTitle, 0, 59, 'UTF-8').'…';
        $albumTargetOptions .= '<option value="'.e((string)$a['id']).'">'.e($targetTitle).'</option>';
    }
    $photoStatusSelect = str_replace('<select ', '<select onchange="this.form.submit()" style="width:16ch;max-width:16ch" ', status_select('visibility',$vis));
    echo '<h1>Photos</h1><form id="photoFilterForm"><input type="hidden" name="page" value="photos"><input type="hidden" name="missing" value="'.e($missing).'"><input type="hidden" name="source" value="'.e($source).'"><input type="hidden" name="nopreview" value="'.e($nopreview ?: '').'"><input type="hidden" name="sort" value="'.e($sort).'"><input type="hidden" name="dir" value="'.e($dir).'"><div class="actions"><input style="width:320px;max-width:100%" name="q" placeholder="Search filename, title, EXIF, B2 key..." value="'.e($q).'">'.$photoStatusSelect.'<select name="album_id" onchange="this.form.submit()" style="width:'.e((string)$albumSelectWidth).'ch;max-width:100%"><option value="0">Any album</option>';
    foreach ($albums as $a) { $albumOptTitle = (string)$a['title']; if (mb_strlen($albumOptTitle, 'UTF-8') > 82) $albumOptTitle = mb_substr($albumOptTitle, 0, 81, 'UTF-8').'…'; echo '<option value="'.e($a['id']).'"'.($album===(int)$a['id']?' selected':'').' title="'.e((string)$a['title']).'">'.e($albumOptTitle).'</option>'; }
    $back='?'.($_SERVER['QUERY_STRING'] ?? 'page=photos');
    $returnKey = remember_return_path($back);
    $noPreviewChipHref='?'.http_build_query(array_filter(['page'=>'photos','q'=>$q,'visibility'=>$vis,'album_id'=>$album ?: null,'missing'=>$missing ?: null,'source'=>$source ?: null,'nopreview'=>$nopreview ? null : 1], fn($v)=>$v!=='' && $v!==null));
    echo '</select><a class="btn'.($nopreview?' primary':'').'" href="'.e($noPreviewChipHref).'" title="Tik nuotraukos be JPG peržiūros (HEIC be JPG kopijos arba nepavykusi peržiūra)">&#9888; No preview</a>'.(is_superadmin()?'<a class="btn" href="?action=export&type=photos">Export CSV</a>':'').'</div></form>'; if (is_superadmin()) echo '<form method="post" action="?action=create_tag_inline" class="actions"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="return_key" value="'.e($returnKey).'"><input name="name" style="width:22ch;max-width:22ch" placeholder="New tag name"><button class="primary">Create New Tag</button></form>'; echo '<form method="post" action="?action=bulk_photos" onsubmit="if(this.bulk_action.value===&quot;clean_missing&quot;)return confirm(&quot;Sutvarkyti pažymėtų nuotraukų missing įrašus? Eilutės, kurių failai NĖRA B2, bus ištrintos iš DB; kurių failai rasti — atstatytos. B2 failai neliečiami.&quot;);if(this.bulk_action.value===&quot;move_album&quot;){if(!this.target_album_id.value||this.target_album_id.value===&quot;0&quot;){alert(&quot;Pasirink albumą, į kurį priskirti.&quot;);return false;}return confirm(&quot;Priskirti pažymėtas nuotraukas kitam albumui? Failai bus NUKOPIJUOTI į to albumo originals/ aplanką; seni B2 objektai lieka vietoje ir netrinami.&quot;);}"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="return_key" value="'.e($returnKey).'"><div class="actions"><select name="bulk_action" style="width:20ch;max-width:20ch"><option value="">Bulk action</option><option value="published">Publish</option><option value="draft">Move to draft</option><option value="private">Set private</option><option value="hidden">Hide</option><option value="download_on">Downloadable on</option><option value="download_off">Downloadable off</option><option value="recheck_b2">Re-check in B2</option><option value="clean_missing">Clean missing DB rows</option><option value="create_jpg">Create JPG for other media (HEIC/video)</option><option value="rotate_cw">Pasukti ↻ 90°</option><option value="rotate_ccw">Pasukti ↺ 90°</option><option value="rotate_180">Pasukti 180°</option><option value="rotate_reset">Pasukimas: atstatyti</option><option value="move_album">Priskirti kitam albumui &#8594;</option></select><select name="target_album_id" style="width:26ch;max-width:26ch" title="Tikslinis albumas veiksmui &bdquo;Priskirti kitam albumui&ldquo;"><option value="0">&rarr; į albumą…</option>'.$albumTargetOptions.'</select><input style="max-width:180px" name="author_name" placeholder="Set author"><input style="max-width:180px" name="copyright_text" placeholder="Set copyright">'.(is_superadmin() ? '<select name="tag_id_to_add" style="width:22ch;max-width:22ch">'.$tagOptions.'</select>' : '').'<button>Apply</button></div><table><tr><th><input type="checkbox" data-master-check aria-label="Select all"></th><th><a href="?page=photos'.($album?'&album_id='.e((string)$album):'').'">Order</a></th><th>Thumb</th><th>'.$sortLinks('file','File').'</th><th>'.$sortLinks('album','Album').'</th><th>'.$sortLinks('visibility','Visibility').'</th><th>'.$sortLinks('taken','EXIF').'</th><th>'.$sortLinks('size','Size').'</th></tr>';
    foreach ($rows as $i=>$r) {
        $size='';
        if($r['width']&&$r['height']) $size=round(($r['width']*$r['height'])/1000000).' MP<br><span class="muted small">'.e($r['width']).' x '.e($r['height']).'</span>';
        if($r['file_size']) $size.=($size?'<br>':'').'<span class="muted small">'.e(number_format(((int)$r['file_size'])/1048576,1)).' MB</span>';
        $camera=trim((string)(($r['camera_make'] ?? '').' '.($r['camera_model'] ?? '')));
        $lens=trim((string)($r['lens_model'] ?? ''));
        $exifParts=[];
        if(!empty($r['taken_at'])) $exifParts[]='<strong>'.e($r['taken_at']).'</strong>';
        if($camera!=='') $exifParts[]='<span class="muted small">'.e($camera).'</span>';
        if($lens!=='') $exifParts[]='<span class="muted small">'.e($lens).'</span>';
        $exif=$exifParts?implode('<br>',$exifParts):'<span class="muted small">n/a</span>';
        $thumb=thumb_url($r,160);
        $preview=preview_url($r,1800,1400);
        $thumbImg=$thumb?'<img src="'.e($thumb).'" loading="lazy" alt="'.e($r['original_filename']).'" style="width:96px;height:68px;object-fit:cover;border-radius:6px;border:1px solid var(--line);display:block">':'<div class="muted small" style="width:96px;height:68px;border:1px solid var(--line);border-radius:6px;display:grid;place-items:center">No thumb</div>';
        $thumbHtml=$thumb?'<button type="button" class="photo-list-preview-trigger" data-preview="'.e($preview).'" data-title="'.e($r['original_filename']).'" data-key="'.e($r['b2_key']).'" style="display:block;padding:0;border:0;background:transparent;cursor:zoom-in">'.$thumbImg.'</button>':$thumbImg;
        $canEdit = is_superadmin() || ((int)($r['album_created_by'] ?? 0) === current_admin_id());
        $orderControls='<div class="actions" style="margin:0;gap:4px;align-items:center"><span class="badge">'.e((string)($i+1)).'</span><button class="mini" formmethod="post" formaction="?action=move_photo_sort&id='.e($r['id']).'&dir=up" title="Move up"'.($i===0?' disabled':'').'>↑</button><button class="mini" formmethod="post" formaction="?action=move_photo_sort&id='.e($r['id']).'&dir=down" title="Move down"'.($i===count($rows)-1?' disabled':'').'>↓</button>'.($canEdit ? '<a class="btn mini" href="?page=photo_edit&id='.e($r['id']).'">Edit</a>' : '<span class="badge">read-only</span>').'</div>';
        echo '<tr><td>'.($canEdit ? '<input type="checkbox" name="ids[]" value="'.e($r['id']).'" data-fname="'.e($r['original_filename']).'">' : '<span class="muted">•</span>').'</td><td>'.$orderControls.'</td><td>'.$thumbHtml.'</td><td><strong>'.e($r['original_filename']).'</strong><br><span class="muted small">'.e($r['b2_key']).'</span>'.(trim((string)($r['contributor_name'] ?? ''))!=='' ? '<br><span class="badge" title="Įkėlė narys per /upload">&#128100; '.e((string)$r['contributor_name']).'</span>' : '').'</td><td><a href="?page=album_edit&id='.e((string)$r['album_id']).'#albumPhotoUploadForm" title="Atidaryti albumą / įkėlimo formą" style="text-decoration:underline;text-underline-offset:3px">'.e($r['album_title']).'</a></td><td><span class="badge">'.e($r['visibility']).'</span>'.(photo_has_no_preview($r) ? ' <span class="badge err" title="Nėra JPG peržiūros: galerijoje ši nuotrauka nerodoma. Sukurti: pažymėk ir Bulk action → Create JPG">no preview</span>' : '').($r['is_missing']?' <span class="badge">missing</span>':(photo_is_previewable((string)$r['original_filename'])?'':' <span class="badge" title="Šio failo peržiūra negalima — tik atsisiuntimas">No preview / Download and view only · '.e(photo_kind_label((string)$r['original_filename'])).'</span>')).'</td><td>'.$exif.'</td><td>'.$size.'</td></tr>';
    }
    // Tuščias sąrašas be paaiškinimo klaidina: įkėlimo įrankiai yra album_edit,
    // ne čia. Pasakom, kodėl tuščia, ir duodam nuorodą į teisingą vietą.
    if (!$rows) {
        if ($album > 0) {
            $emptyTitle = (string)(db()->query("SELECT title FROM albums WHERE id=".(int)$album)->fetchColumn() ?: '');
            echo '<tr><td colspan="8" style="padding:22px;text-align:center">'
                .'<div class="muted" style="margin-bottom:10px">Albume „'.e($emptyTitle).'" nuotraukų dar nėra.</div>'
                .'<a class="btn primary" href="?page=album_edit&id='.e((string)$album).'#albumPhotoUploadForm">Įkelti nuotraukas →</a>'
                .'</td></tr>';
        } else {
            echo '<tr><td colspan="8" class="muted" style="padding:22px;text-align:center">Pagal filtrus nuotraukų nerasta.</td></tr>';
        }
    }
    echo '</table></form><div class="photo-preview-overlay" id="photoListPreviewOverlay" hidden aria-hidden="true"><div class="photo-preview-shell" role="dialog" aria-modal="true" aria-label="Photo preview" tabindex="-1" style="grid-template-columns:minmax(0,1fr) minmax(260px,320px)"><button type="button" class="photo-preview-close" id="photoListPreviewClose" aria-label="Close preview"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg></button><button type="button" class="photo-preview-nav prev" id="photoListPreviewPrev" aria-label="Previous photo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 5.5 8 12l6.5 6.5"/></svg></button><button type="button" class="photo-preview-nav next" id="photoListPreviewNext" aria-label="Next photo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 5.5 16 12l-6.5 6.5"/></svg></button><div class="photo-preview-media"><img id="photoListPreviewImage" alt=""></div><aside class="photo-preview-meta"><h3 class="photo-preview-title" id="photoListPreviewTitle"></h3><div class="preview-file" id="photoListPreviewKey"></div></aside></div></div><script>(function(){var overlay=document.getElementById("photoListPreviewOverlay");var shell=overlay?overlay.querySelector(".photo-preview-shell"):null;var img=document.getElementById("photoListPreviewImage");var title=document.getElementById("photoListPreviewTitle");var key=document.getElementById("photoListPreviewKey");var close=document.getElementById("photoListPreviewClose");var prev=document.getElementById("photoListPreviewPrev");var next=document.getElementById("photoListPreviewNext");var triggers=Array.prototype.slice.call(document.querySelectorAll(".photo-list-preview-trigger"));var idx=-1;function render(){var btn=triggers[idx];if(!btn||!img)return;img.src=btn.getAttribute("data-preview")||"";img.alt=btn.getAttribute("data-title")||"";if(title)title.textContent=btn.getAttribute("data-title")||"Photo";if(key)key.textContent=btn.getAttribute("data-key")||"";if(prev)prev.disabled=idx<=0;if(next)next.disabled=idx>=triggers.length-1;}function open(btn){if(!overlay||!img)return;idx=triggers.indexOf(btn);if(idx<0)idx=0;render();overlay.hidden=false;overlay.classList.add("open");overlay.setAttribute("aria-hidden","false");document.body.classList.add("preview-open");if(shell)shell.focus();}function step(d){var n=idx+d;if(n<0||n>=triggers.length)return;idx=n;render();}function hide(){if(!overlay)return;overlay.classList.remove("open");overlay.hidden=true;overlay.setAttribute("aria-hidden","true");document.body.classList.remove("preview-open");if(img)img.src="";}triggers.forEach(function(btn){btn.addEventListener("click",function(e){e.preventDefault();open(btn);});});if(close)close.addEventListener("click",hide);if(prev)prev.addEventListener("click",function(e){e.stopPropagation();step(-1);});if(next)next.addEventListener("click",function(e){e.stopPropagation();step(1);});if(overlay)overlay.addEventListener("click",function(e){if(e.target===overlay)hide();});document.addEventListener("keydown",function(e){if(!overlay||overlay.hidden)return;if(e.key==="Escape")hide();if(e.key==="ArrowLeft")step(-1);if(e.key==="ArrowRight")step(1);});})();</script>';
    echo '<script>(function(){var sel=document.querySelector("select[name=bulk_action]");if(!sel)return;var form=sel.closest("form");if(!form)return;var prog=document.createElement("div");prog.className="muted small";prog.style.margin="8px 0";form.insertBefore(prog,form.firstChild);
function isHeicName(n){return /\.(heic|heif)$/i.test(n||"");}
function isVideoName(n){return /\.(mp4|mov|m4v|webm|avi)$/i.test(n||"");}
async function toJpeg(blob){function em(e){if(!e)return"nežinoma klaida";if(e.message)return e.message;try{return JSON.stringify(e);}catch(x){return String(e);}}function wt(pp,ms,l){return Promise.race([pp,new Promise(function(_,rej){setTimeout(function(){rej(new Error(l+" neatsako ("+Math.round(ms/1000)+"s)"));},ms);})]);}async function nativeDecode(b){if(typeof createImageBitmap!=="function")throw new Error("createImageBitmap nepalaikomas");var bmp=await createImageBitmap(b);var w=bmp.width,h=bmp.height;if(!w||!h){bmp.close&&bmp.close();throw new Error("nulinis kadras (natyviai nedekoduota)");}var c=document.createElement("canvas");c.width=w;c.height=h;c.getContext("2d").drawImage(bmp,0,0);bmp.close&&bmp.close();return await new Promise(function(res,rej){c.toBlob(function(x){x&&x.size>0?res(x):rej(new Error("canvas toBlob tuščias"));},"image/jpeg",0.9);});}var errs=[];try{return await wt(nativeDecode(blob),20000,"native");}catch(e0){errs.push("native: "+em(e0));}if(typeof window.heic2any==="function"){try{var o=await wt(window.heic2any({blob:blob,toType:"image/jpeg",quality:0.9}),60000,"heic2any");return Array.isArray(o)?o[0]:o;}catch(e){errs.push("heic2any: "+em(e));}}var c2=(typeof window.HeicTo==="function")?window.HeicTo:((window.HeicTo&&typeof window.HeicTo.heicTo==="function")?window.HeicTo.heicTo:((window.heicTo&&typeof window.heicTo.heicTo==="function")?window.heicTo.heicTo:(typeof window.heicTo==="function"?window.heicTo:null)));if(c2){try{var q=await wt(c2({blob:blob,type:"image/jpeg",quality:0.9}),60000,"heic-to");return Array.isArray(q)?q[0]:q;}catch(e2){errs.push("heic-to: "+em(e2));}}throw new Error("HEIC konversija nepavyko. "+(errs.join(" | ")||"konverteris nepasiekiamas"));}
function videoPoster(blob){return new Promise(function(res,rej){var url=URL.createObjectURL(blob);var v=document.createElement("video");v.muted=true;v.playsInline=true;v.preload="auto";var to=setTimeout(function(){cleanup();rej(new Error("video dekodavimo timeout — kodekas nepalaikomas naršyklės"));},25000);function cleanup(){clearTimeout(to);URL.revokeObjectURL(url);}v.addEventListener("error",function(){cleanup();rej(new Error("naršyklė negali dekoduoti šio video (kodekas)"));});v.addEventListener("loadeddata",function(){try{var done=false;v.currentTime=Math.min(0.5,(v.duration||1)/2);v.addEventListener("seeked",function(){if(done)return;done=true;try{var c=document.createElement("canvas");c.width=v.videoWidth;c.height=v.videoHeight;if(!c.width||!c.height)throw new Error("nulinis video kadras");c.getContext("2d").drawImage(v,0,0);c.toBlob(function(b){cleanup();if(b)res(b);else rej(new Error("canvas toBlob nepavyko"));},"image/jpeg",0.85);}catch(e){cleanup();rej(e);}});}catch(e){cleanup();rej(e);}});v.src=url;});}
form.addEventListener("submit",async function(e){if(sel.value!=="create_jpg")return;e.preventDefault();e.stopImmediatePropagation();var boxes=Array.prototype.filter.call(form.querySelectorAll("input[type=checkbox]"),function(b){return b.name==="ids[]"&&b.checked;});var items=boxes.map(function(b){return {id:b.value,name:b.getAttribute("data-fname")||""};}).filter(function(it){return isHeicName(it.name)||isVideoName(it.name);});if(!items.length){alert("Pažymėtose eilutėse nėra HEIC ar video failų.");return;}if(!confirm("Sukurti JPG "+items.length+" failams? HEIC konvertuojamas, video gauna pirmo kadro JPG. Originalai neliečiami."))return;var tokenEl=form.querySelector("input[name=_token]");var token=tokenEl?tokenEl.value:"";var done=0,fail=0;var LOG=[];for(var i=0;i<items.length;i++){var it=items[i];var t0=Date.now();prog.textContent="Konvertuojama "+(i+1)+" / "+items.length+": "+it.name;var hbT=setInterval(function(){prog.textContent="Konvertuojama "+(i+1)+" / "+items.length+": "+it.name+" ("+Math.round((Date.now()-t0)/1000)+"s)";},1000);try{var r=await fetch("?action=photo_heic_blob&id="+encodeURIComponent(it.id),{credentials:"same-origin"});if(!r.ok)throw new Error("originalo atsisiuntimas HTTP "+r.status);var rct=(r.headers.get("content-type")||"");if(rct.indexOf("text/html")>=0)throw new Error("sesija pasibaigusi — perkraukite puslapį ir prisijunkite iš naujo");var blob=await r.blob();if(blob.size<200)throw new Error("originalas per mažas ("+blob.size+" B)");var jpg=isVideoName(it.name)?await videoPoster(blob):await toJpeg(blob);var fd=new FormData();fd.append("_token",token);fd.append("photo_id",it.id);fd.append("jpg",jpg,(it.name||"media").replace(/\.[^.]+$/,"")+".jpg");var up=await fetch("?action=save_recreated_jpg",{method:"POST",body:fd,headers:{Accept:"application/json"},credentials:"same-origin"});var jd=await up.json();if(!jd||!jd.ok)throw new Error((jd&&jd.error)||"įkėlimas nepavyko");done++;LOG.push({id:it.id,name:it.name,ok:true});}catch(err){fail++;LOG.push({id:it.id,name:it.name,ok:false,error:(err&&err.message?err.message:String(err))});prog.textContent="Klaida ("+it.name+"): "+(err&&err.message?err.message:err);await new Promise(function(x){setTimeout(x,1500);});}finally{clearInterval(hbT);}}try{var lf=new FormData();lf.append("_token",token);lf.append("log",JSON.stringify({album_id:0,done:done,fail:fail,items:LOG}));await fetch("?action=save_recreate_log",{method:"POST",body:lf,credentials:"same-origin"});}catch(e0){}prog.textContent="Baigta: "+done+" JPG sukurta, "+fail+" nepavyko. Perkraunama...";setTimeout(function(){location.reload();},1600);},true);})();</script>';
    foot('Photos');
}

function photo_edit(): void {
    head('Edit photo');
    $id=(int)($_GET['id'] ?? 0); $st=db()->prepare("SELECT * FROM photos WHERE id=?"); $st->execute([$id]); $r=$st->fetch();
    if (!$r) { flash('Photo not found.', 'err'); go('?page=photos'); }
    if (!is_superadmin()) require_photo_editable_by_id($id);
    $albumSt=db()->prepare("SELECT * FROM albums WHERE id=? LIMIT 1"); $albumSt->execute([(int)$r['album_id']]); $album=$albumSt->fetch() ?: [];
    if (is_superadmin()) { $albums=db()->query("SELECT id,title FROM albums ORDER BY sort_order,event_date DESC,id DESC")->fetchAll(); } else { $albumStList=db()->prepare("SELECT id,title FROM albums WHERE created_by=? ORDER BY sort_order,event_date DESC,id DESC"); $albumStList->execute([current_admin_id()]); $albums=$albumStList->fetchAll(); }
    $returnAlbumId=(int)($_GET['album_id'] ?? $r['album_id']);
    $defaults=[
        'title' => (string)($r['title'] ?? $r['original_filename'] ?? ''),
        'caption' => (string)($r['caption'] ?? ''),
        'alt_text' => (string)($r['alt_text'] ?? ''),
        'author_name' => (string)($r['author_name'] ?: ($album['author_name'] ?? '')),
        'copyright_text' => (string)($r['copyright_text'] ?: ($album['copyright_text'] ?? '')),
        'credit_line' => (string)($r['credit_line'] ?? ''),
        'taken_at' => (string)($r['taken_at'] ?: (!empty($album['event_date']) ? $album['event_date'].' 00:00:00' : '')),
        'city' => (string)($r['city'] ?: ($album['location_name'] ?? '')),
        'country' => (string)($r['country'] ?: ($album['country_code'] ?? '')),
        'sort_order' => (string)($r['sort_order'] ?? 0),
        'visibility' => (string)($r['visibility'] ?? 'draft'),
        'description' => (string)($r['description'] ?: ($album['description'] ?? '')),
        'notes_internal' => (string)($r['notes_internal'] ?? ''),
        'is_downloadable' => (int)($r['is_downloadable'] ?? 0),
        'is_cover_candidate' => (int)($r['is_cover_candidate'] ?? 0),
        'is_missing' => (int)($r['is_missing'] ?? 0),
    ];
    $metadata = [];
    if (!empty($r['metadata_json'])) {
        $decoded = json_decode((string)$r['metadata_json'], true);
        if (is_array($decoded)) $metadata = $decoded;
    }
    $jsonViews = takeout_image_views($metadata);
    $jsonTaken = $metadata['photoTakenTime']['formatted'] ?? ($metadata['photoTakenTime']['timestamp'] ?? null);
    $jsonGeo = [];
    if (!empty($metadata['geoData']) && is_array($metadata['geoData'])) $jsonGeo = $metadata['geoData'];
    $storageRows = [
        ['File', $r['original_filename'] ?? 'n/a'],
        ['Storage path', $r['b2_key'] ?? 'n/a'],
        ['Compatibility JPG path', $r['compatibility_b2_key'] ?? 'n/a'],
        ['Source type', $r['source_type'] ?? 'n/a'],
        ['MIME', $r['mime_type'] ?? 'n/a'],
        ['Extension', $r['file_ext'] ?? 'n/a'],
        ['Original format', $r['original_format'] ?? 'n/a'],
        ['Preview status', $r['preview_status'] ?? 'n/a'],
        ['Converted from HEIC', !empty($r['converted_from_heic']) ? 'yes' : 'no'],
        ['Preview error', $r['preview_error'] ?? 'n/a'],
        ['Thumb path', $r['thumb_path'] ?? 'n/a'],
        ['Preview path', $r['preview_path'] ?? 'n/a'],
        ['Web path', $r['web_path'] ?? 'n/a'],
        ['File size', $r['file_size'] ? human_bytes((int)$r['file_size']) : 'n/a'],
        ['Views', $r['photo_views'] ?? $jsonViews ?? 'n/a'],
        ['Dimensions', ($r['width'] && $r['height']) ? ($r['width'].' × '.$r['height']) : 'n/a'],
        ['Taken at', $defaults['taken_at'] ?: 'n/a'],
        ['JSON taken', $jsonTaken ? (is_array($jsonTaken) ? json_encode($jsonTaken) : (string)$jsonTaken) : 'n/a'],
        ['Camera', trim((string)($r['camera_make'] ?? '').' '.(string)($r['camera_model'] ?? '')) ?: 'n/a'],
        ['Lens', $r['lens_model'] ?: 'n/a'],
        ['Focal length', $r['focal_length'] ?: 'n/a'],
        ['Aperture', $r['aperture'] ?: 'n/a'],
        ['Shutter', $r['shutter_speed'] ?: 'n/a'],
        ['ISO', $r['iso_value'] ?: 'n/a'],
        ['Orientation', $r['orientation'] ?: 'n/a'],
        ['Location', trim(implode(', ', array_filter([$defaults['city'], $defaults['country']], fn($v)=>trim((string)$v)!=='')) ) ?: 'n/a'],
        ['Coords', ($r['latitude'] !== null && $r['longitude'] !== null) ? ($r['latitude'].', '.$r['longitude']) : ((isset($jsonGeo['latitude'], $jsonGeo['longitude'])) ? ($jsonGeo['latitude'].', '.$jsonGeo['longitude']) : 'n/a')],
        ['Metadata JSON', !empty($r['metadata_json']) ? 'present' : 'n/a'],
    ];
    $downloadChecked = (!empty($defaults['is_downloadable']) && !empty($album['download_enabled'])) ? 'checked' : '';
    echo '<h1>Edit photo</h1><form method="post" action="?action=save_photo"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="id" value="'.e($id).'"><input type="hidden" name="return_album_id" value="'.e($returnAlbumId).'"><div class="formgrid"><div><label>Album</label><select name="album_id">';
    foreach ($albums as $a) echo '<option value="'.e($a['id']).'"'.((int)$r['album_id']===(int)$a['id']?' selected':'').'>'.e($a['title']).'</option>';
    echo '</select></div>';
    foreach (['title'=>'Title','caption'=>'Caption','alt_text'=>'Alt text','author_name'=>'Author','copyright_text'=>'Copyright','credit_line'=>'Credit','taken_at'=>'Taken at','city'=>'City','country'=>'Country','sort_order'=>'Sort order'] as $n=>$l) echo '<div><label>'.e($l).'</label><input name="'.e($n).'" value="'.e($defaults[$n] ?? '').'"></div>';
    $tags=''; $ts=db()->prepare("SELECT t.name FROM tags t JOIN photo_tags pt ON pt.tag_id=t.id WHERE pt.photo_id=? ORDER BY t.name");$ts->execute([$id]);$tags=implode(', ',array_column($ts->fetchAll(),'name'));
    echo '<div><label>Visibility</label>'.status_select('visibility',$defaults['visibility']).'</div>'.(is_superadmin() ? '<div><label>Tags, comma separated</label><input name="tags" value="'.e($tags).'"></div>' : '').'</div><label>Description</label><textarea name="description" rows="4">'.e($defaults['description']).'</textarea><label>Internal notes</label><textarea name="notes_internal" rows="3">'.e($defaults['notes_internal']).'</textarea><div class="actions"><label><input type="checkbox" name="is_downloadable" value="1" '.$downloadChecked.'> Downloadable</label><input type="hidden" name="is_cover_candidate" value="'.e($defaults['is_cover_candidate']).'"><input type="hidden" name="is_missing" value="'.e($defaults['is_missing']).'"></div><div class="card"><h2>Storage / EXIF</h2><dl class="preview-dl">';
    foreach ($storageRows as [$label,$value]) echo '<dt>'.e($label).'</dt><dd>'.e($value).'</dd>';
    echo '</dl></div><div class="actions"><button class="primary">Save photo</button><a class="btn" href="?page=album_edit&id='.e($returnAlbumId).'">Back to album</a></div></form>';
    foot('Photo');
}

function tags(): void {
    require_superadmin();
    head('Tags');
    $rows=db()->query("SELECT t.*, (SELECT COUNT(*) FROM photo_tags pt WHERE pt.tag_id=t.id) photos_count, (SELECT COUNT(*) FROM album_tags at WHERE at.tag_id=t.id) albums_count FROM tags t ORDER BY name LIMIT 300")->fetchAll();
    echo '<h1>Tags</h1><form class="actions" method="post" action="?action=save_tag"><input type="hidden" name="_token" value="'.e(token()).'"><input name="name" style="max-width:260px" placeholder="New tag"><select name="type" style="max-width:160px"><option>keyword</option><option>sport</option><option>topic</option><option>person</option><option>location</option><option>style</option></select><button class="primary">Create New Tag</button></form><table><tr><th>ID</th><th>Name</th><th>Slug</th><th>Type</th><th>Photos</th><th>Number of Albums</th><th></th></tr>';
    foreach ($rows as $r) {
        $albumLink = (int)$r['albums_count'] > 0 ? '<a class="btn mini" href="?page=albums&tag_id='.e($r['id']).'">'.e($r['albums_count']).'</a>' : '<span class="badge">0</span>';
        $confirmText = 'Ištrinti žymą „'.$r['name'].'"? Ji bus nuimta nuo '.(int)$r['albums_count'].' albumų ir '.(int)$r['photos_count'].' nuotraukų. Albumai ir nuotraukos lieka.';
        $deleteForm = '<form method="post" action="?action=delete_tag" style="margin:0" onsubmit="return confirm(this.dataset.confirm)" data-confirm="'.e($confirmText).'"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="tag_id" value="'.e($r['id']).'"><button class="btn mini danger" style="border-color:var(--err-line)">Delete</button></form>';
        echo '<tr><td>'.e($r['id']).'</td><td>'.e($r['name']).'</td><td>'.e($r['slug']).'</td><td>'.e($r['type']).'</td><td>'.e($r['photos_count']).'</td><td>'.$albumLink.'</td><td>'.$deleteForm.'</td></tr>';
    }
    echo '</table>'; foot('Tags');
}

function save_admin(): void {
    require_superadmin();
    csrf();
    $id=(int)($_POST['id'] ?? 0);
    $email=strtolower(trim((string)($_POST['email'] ?? '')));
    $name=trim((string)($_POST['name'] ?? ''));
    $role=in_array((string)($_POST['role'] ?? 'user'), ['user','superadmin'], true) ? (string)($_POST['role'] ?? 'user') : 'user';
    $active=isset($_POST['is_active']) ? 1 : 0;
    $googleSub=trim((string)($_POST['google_sub'] ?? ''));
    $avatarUrl=trim((string)($_POST['avatar_url'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Valid email is required.', 'err'); go('?page=admins'); }
    if ($id > 0) {
        db()->prepare("UPDATE admins SET email=?, name=?, role=?, is_active=?, google_sub=?, avatar_url=? WHERE id=?")->execute([$email, $name !== '' ? $name : $email, $role, $active, $googleSub !== '' ? $googleSub : null, $avatarUrl !== '' ? $avatarUrl : null, $id]);
        audit('admin', $id, 'update', 'Admin updated: '.$email);
        flash('Admin updated: '.$email);
    } else {
        db()->prepare("INSERT INTO admins(email,name,role,is_active,google_sub,avatar_url,last_login_at) VALUES(?,?,?,?,?,?,NULL) ON DUPLICATE KEY UPDATE name=VALUES(name), role=VALUES(role), is_active=VALUES(is_active), google_sub=COALESCE(NULLIF(VALUES(google_sub),''), google_sub), avatar_url=COALESCE(NULLIF(VALUES(avatar_url),''), avatar_url)")->execute([$email, $name !== '' ? $name : $email, $role, $active, $googleSub !== '' ? $googleSub : null, $avatarUrl !== '' ? $avatarUrl : null]);
        $savedId = (int)db()->lastInsertId();
        if ($savedId <= 0) { $savedId = (int)db()->query("SELECT id FROM admins WHERE email=".db()->quote($email)." LIMIT 1")->fetchColumn(); }
        audit('admin', $savedId ?: null, 'create', 'Admin created: '.$email);
        flash('Admin saved: '.$email);
    }
    go('?page=admins');
}

/* ----------------------------- Nariai (/upload) ---------------------------- */

function member_upload_base_url(): string {
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'foto.klajunas.lt');
    $host = preg_replace('/[^A-Za-z0-9.:-]/', '', $host) ?: 'foto.klajunas.lt';
    return 'https://'.$host.'/upload/';
}

function save_member(): void {
    require_superadmin();
    csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $name  = trim((string)($_POST['name'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { flash('Reikalingas galiojantis el. paštas.', 'err'); go('?page=members'); }
    // Nario pridejimas NIEKADA nezemina esamos roles: jei si pasta jau turi
    // superadmin teises, "pridedu kaip nari" neturi jo nuzeminti iki member.
    $st = db()->prepare("SELECT id, role FROM admins WHERE email=? LIMIT 1");
    $st->execute([$email]);
    $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($existing && in_array(strtolower((string)$existing['role']), ['superadmin','editor'], true)) {
        db()->prepare("UPDATE admins SET is_active=1, name=COALESCE(NULLIF(?,''),name) WHERE id=?")->execute([$name, (int)$existing['id']]);
        flash('Paskyra '.$email.' jau turi „'.$existing['role'].'" teises — jai narių įkėlimas leidžiamas be pakeitimų.');
        go('?page=members');
    }
    db()->prepare("INSERT INTO admins(email,name,role,is_active) VALUES(?,?, 'member',1) ON DUPLICATE KEY UPDATE name=COALESCE(NULLIF(VALUES(name),''),name), role='member', is_active=1")
        ->execute([$email, $name !== '' ? $name : $email]);
    audit('member', null, 'member_add', 'Narys pridėtas: '.$email);
    flash('Narys pridėtas: '.$email.'. Jis gali jungtis per Google adresu '.member_upload_base_url());
    go('?page=members');
}

function toggle_member_active(): void {
    require_superadmin();
    csrf();
    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare("SELECT id,email,role,is_active FROM admins WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $back = (string)($_POST['back'] ?? '?page=members');
    if (!preg_match('/^\?page=members/', $back)) $back = '?page=members';
    if (!$row) { flash('Narys nerastas.', 'err'); go($back); }
    if (strtolower((string)$row['role']) !== 'member') { flash('Šis puslapis valdo tik „member" roles paskyras — admin\'us keisk Admins skiltyje.', 'err'); go($back); }
    $next = (int)$row['is_active'] === 1 ? 0 : 1;
    db()->prepare("UPDATE admins SET is_active=? WHERE id=?")->execute([$next, $id]);
    audit('member', $id, $next ? 'member_enable' : 'member_disable', ($next ? 'Narys įjungtas: ' : 'Nario prieiga atšaukta: ').$row['email']);
    flash($next ? 'Nario prieiga atstatyta: '.$row['email'] : 'Nario prieiga atšaukta: '.$row['email']);
    go($back);
}

function toggle_member_album(): void {
    require_superadmin();
    csrf();
    $id = (int)($_POST['album_id'] ?? 0);
    $album = member_album_by_id($id);
    $back = (string)($_POST['back'] ?? '?page=members');
    if (!preg_match('/^\?page=(members|album_edit)/', $back)) $back = '?page=members';
    if (!$album) { flash('Albumas nerastas.', 'err'); go($back); }
    $next = (int)($album['accepts_member_uploads'] ?? 0) === 1 ? 0 : 1;
    if ($next === 1 && member_album_prefix($album) === '') {
        flash('Albumas „'.$album['title'].'" neturi B2 kelio. Nustatyk Storage path — kitaip nario failas neturėtų kur atsidurti.', 'err', '?page=album_edit&id='.$id, 'Nustatyti kelią');
        go($back);
    }
    db()->prepare("UPDATE albums SET accepts_member_uploads=?, updated_by=? WHERE id=?")->execute([$next, current_admin_id() ?: null, $id]);
    audit('album', $id, $next ? 'member_uploads_on' : 'member_uploads_off', ($next ? 'Narių įkėlimai įjungti: ' : 'Narių įkėlimai išjungti: ').$album['title']);
    flash($next ? 'Narių įkėlimai įjungti: '.$album['title'] : 'Narių įkėlimai išjungti: '.$album['title']);
    go($back);
}

function create_member_invite(): void {
    require_superadmin();
    csrf();
    $label = trim((string)($_POST['label'] ?? ''));
    $albumId = (int)($_POST['album_id'] ?? 0);
    $maxPhotos = max(0, min(20000, (int)($_POST['max_photos'] ?? 0)));
    $days = max(0, min(365, (int)($_POST['expires_days'] ?? 30)));
    if ($label === '') { flash('Įrašyk, kam ši nuoroda skirta — vėliau iš „ab12cd" nebeatsiminsi.', 'err'); go('?page=members'); }
    if ($albumId > 0) {
        $album = member_album_by_id($albumId);
        if (!$album) { flash('Albumas nerastas.', 'err'); go('?page=members'); }
        if (member_album_prefix($album) === '') { flash('Albumas „'.$album['title'].'" neturi B2 kelio — pirma nustatyk Storage path.', 'err', '?page=album_edit&id='.$albumId, 'Nustatyti kelią'); go('?page=members'); }
    }
    // 32 baitai -> 43 simboliu URL-safe tokenas. DB laikomas tik SHA-256, todel
    // nuoroda parodoma VIENA KARTA - veliau jos atkurti neimanoma (ir neturi buti).
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $expires = $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null;
    db()->prepare("INSERT INTO member_invites(token_hash,token_hint,label,album_id,max_photos,expires_at,is_active,created_by) VALUES(?,?,?,?,?,?,1,?)")
        ->execute([member_invite_token_hash($token), substr($token, 0, 6), $label, $albumId ?: null, $maxPhotos, $expires, current_admin_id() ?: null]);
    $inviteId = (int)db()->lastInsertId();
    $_SESSION['member_invite_new'] = ['id' => $inviteId, 'url' => member_upload_base_url().'?k='.$token, 'label' => $label];
    audit('member_invite', $inviteId, 'create', 'Kvietimo nuoroda sukurta: '.$label, ['album_id'=>$albumId ?: null,'max_photos'=>$maxPhotos,'expires_at'=>$expires]);
    flash('Kvietimo nuoroda sukurta. Nukopijuok ją dabar — vėliau nebus rodoma.');
    go('?page=members');
}

function revoke_member_invite(): void {
    require_superadmin();
    csrf();
    $id = (int)($_POST['id'] ?? 0);
    $st = db()->prepare("SELECT id,label FROM member_invites WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) { flash('Kvietimas nerastas.', 'err'); go('?page=members'); }
    db()->prepare("UPDATE member_invites SET is_active=0 WHERE id=?")->execute([$id]);
    audit('member_invite', $id, 'revoke', 'Kvietimo nuoroda atšaukta: '.$row['label']);
    flash('Kvietimo nuoroda atšaukta: '.$row['label']);
    go('?page=members');
}

function members_page(): void {
    require_superadmin();
    head('Nariai');
    $baseUrl = member_upload_base_url();
    $fresh = $_SESSION['member_invite_new'] ?? null;
    unset($_SESSION['member_invite_new']);
    $pending = member_pending_counts();
    $pendingTotal = array_sum($pending);

    echo '<h1>Nariai ir jų įkėlimai</h1>';
    echo '<div class="card" style="margin-bottom:16px"><h2>Kaip tai veikia</h2>'
        .'<p class="muted" style="margin:0 0 10px">Narys atsidaro <a href="'.e($baseUrl).'" target="_blank" rel="noopener" style="text-decoration:underline;text-underline-offset:3px">'.e($baseUrl).'</a>, prisijungia (Google arba kvietimo nuoroda), pasirenka vieną iš žemiau pažymėtų albumų ir įkelia originalus. Failai keliauja tiesiai į to albumo B2 aplanką <code>originals/</code> — archyvas susitvarko pats, niekas neperkeliama ir netrinama.</p>'
        .'<p class="muted" style="margin:0">Visos nario nuotraukos gimsta <strong>draft</strong> būsenoje ir viešai nematomos, kol jų nepaskelbi.'
        .($pendingTotal > 0 ? ' Šiuo metu peržiūros laukia <strong>'.e((string)$pendingTotal).'</strong>.' : '').'</p></div>';

    if (is_array($fresh) && !empty($fresh['url'])) {
        echo '<div class="card" style="margin-bottom:16px;border-color:var(--accent-line)"><h2>Nauja kvietimo nuoroda: '.e((string)$fresh['label']).'</h2>'
            .'<p class="muted" style="margin:0 0 10px">Ši nuoroda rodoma <strong>vieną kartą</strong> — duomenų bazėje laikomas tik jos kriptografinis atspaudas, tad atkurti nebus galima. Nukopijuok ir perduok nariui saugiu kanalu.</p>'
            .'<div class="actions"><input id="freshInviteUrl" readonly value="'.e((string)$fresh['url']).'" style="flex:1 1 420px;min-width:260px"><button type="button" class="primary" id="copyInviteUrl">Kopijuoti</button></div>'
            .'<script>(function(){var b=document.getElementById("copyInviteUrl"),i=document.getElementById("freshInviteUrl");if(!b||!i)return;b.addEventListener("click",function(){i.select();i.setSelectionRange(0,999999);try{document.execCommand("copy");b.textContent="Nukopijuota ✓";}catch(e){b.textContent="Kopijuok ranka";}});})();</script></div>';
    }

    /* --- Albumai, atviri nariams --- */
    $albums = db()->query("SELECT id,title,event_date,source_path,visibility,accepts_member_uploads,(SELECT COUNT(*) FROM photos WHERE photos.album_id=albums.id) photos_count FROM albums ORDER BY accepts_member_uploads DESC, COALESCE(event_date,'0000-00-00') DESC, id DESC LIMIT 400")->fetchAll();
    $open = array_values(array_filter($albums, fn($a) => (int)$a['accepts_member_uploads'] === 1));
    echo '<div class="card" style="margin-bottom:16px"><h2>Albumai, atviri narių įkėlimams ('.e((string)count($open)).')</h2>';
    if (!$open) {
        echo '<p class="muted">Kol kas nė vieno. Kol neatidarysi bent vieno albumo, narys matys tuščią sąrašą ir nieko įkelti negalės.</p>';
    } else {
        echo '<table><tr><th>Albumas</th><th>Data</th><th>B2 kelias</th><th>Laukia peržiūros</th><th></th></tr>';
        foreach ($open as $a) {
            $aid = (int)$a['id'];
            $waiting = (int)($pending[$aid] ?? 0);
            echo '<tr><td><a href="?page=album_edit&id='.e((string)$aid).'" style="text-decoration:underline;text-underline-offset:3px">'.e((string)$a['title']).'</a></td>'
                .'<td>'.e((string)($a['event_date'] ?? '')).'</td>'
                .'<td><span class="muted small">'.e((string)$a['source_path']).'</span></td>'
                .'<td>'.($waiting > 0 ? '<a class="btn mini" href="?page=photos&album_id='.e((string)$aid).'&source=member&visibility=draft">'.e((string)$waiting).' peržiūrėti →</a>' : '<span class="muted small">—</span>').'</td>'
                .'<td><form method="post" action="?action=toggle_member_album" style="margin:0"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="album_id" value="'.e((string)$aid).'"><button class="mini">Uždaryti</button></form></td></tr>';
        }
        echo '</table>';
    }
    echo '<h3>Atidaryti dar vieną albumą</h3><form method="post" action="?action=toggle_member_album" class="actions" style="margin:0"><input type="hidden" name="_token" value="'.e(token()).'"><select name="album_id" style="flex:1 1 380px;max-width:520px">';
    foreach ($albums as $a) {
        if ((int)$a['accepts_member_uploads'] === 1) continue;
        $noPath = member_album_prefix($a) === '';
        echo '<option value="'.e((string)$a['id']).'">'.e((string)$a['title']).($a['event_date'] ? ' · '.e((string)$a['event_date']) : '').($noPath ? ' — ⚠ be B2 kelio' : '').'</option>';
    }
    echo '</select><button class="primary">Atidaryti narių įkėlimams</button></form></div>';

    /* --- Nariai su Google prisijungimu --- */
    $members = db()->query("SELECT id,email,name,is_active,last_login_at FROM admins WHERE role='member' ORDER BY is_active DESC, email")->fetchAll();
    echo '<div class="card" style="margin-bottom:16px"><h2>Nariai su Google prisijungimu ('.e((string)count($members)).')</h2>'
        .'<p class="muted" style="margin:0 0 12px">Įleidžiami tik čia išvardyti el. paštai. Nario paskyra neduoda jokios prieigos prie admin\'o — tik prie /upload.</p>'
        .'<form method="post" action="?action=save_member" class="actions" style="margin:0 0 14px"><input type="hidden" name="_token" value="'.e(token()).'">'
        .'<input name="email" placeholder="vardas@gmail.com" style="flex:1 1 260px;max-width:340px"><input name="name" placeholder="Vardas Pavardė (nebūtina)" style="flex:1 1 220px;max-width:300px"><button class="primary">Pridėti narį</button></form>';
    if ($members) {
        echo '<table><tr><th>El. paštas</th><th>Vardas</th><th>Būsena</th><th>Paskutinis prisijungimas</th><th></th></tr>';
        foreach ($members as $m) {
            $active = (int)$m['is_active'] === 1;
            echo '<tr><td>'.e((string)$m['email']).'</td><td>'.e((string)$m['name']).'</td>'
                .'<td>'.($active ? '<span class="badge">aktyvus</span>' : '<span class="badge" style="border-color:var(--err-line)">atšauktas</span>').'</td>'
                .'<td><span class="muted small">'.e((string)($m['last_login_at'] ?? '—')).'</span></td>'
                .'<td><form method="post" action="?action=toggle_member_active" style="margin:0"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="id" value="'.e((string)$m['id']).'"><button class="mini">'.($active ? 'Atšaukti prieigą' : 'Atstatyti').'</button></form></td></tr>';
        }
        echo '</table>';
    } else {
        echo '<p class="muted">Narių dar nėra.</p>';
    }
    echo '</div>';

    /* --- Kvietimo nuorodos --- */
    echo '<div class="card" style="margin-bottom:16px"><h2>Kvietimo nuorodos (be paskyros)</h2>'
        .'<p class="muted" style="margin:0 0 12px">Vienkartiniams talkininkams, kurie neturi Google paskyros arba kurių nenori įrašinėti į narių sąrašą. Kas turi nuorodą — tas gali kelti, todėl duok jai trumpą galiojimą ir nuotraukų limitą (0 = be limito).</p>'
        .'<form method="post" action="?action=create_member_invite" class="actions" style="margin:0 0 14px"><input type="hidden" name="_token" value="'.e(token()).'">'
        .'<div style="flex:1 1 220px"><label>Kam skirta</label><input name="label" placeholder="pvz. Jonas — Pirmoji lyga"></div>'
        .'<div style="flex:1 1 260px"><label>Albumas</label><select name="album_id"><option value="0">Bet kuris atviras albumas</option>';
    foreach ($albums as $a) {
        if (member_album_prefix($a) === '') continue;
        echo '<option value="'.e((string)$a['id']).'">'.e((string)$a['title']).($a['event_date'] ? ' · '.e((string)$a['event_date']) : '').'</option>';
    }
    echo '</select></div>'
        .'<div style="flex:0 1 130px"><label>Galioja (d.)</label><input name="expires_days" type="number" min="0" max="365" value="30"></div>'
        .'<div style="flex:0 1 170px"><label>Nuotraukų limitas</label><input name="max_photos" type="number" min="0" max="20000" value="300"></div>'
        .'<button class="primary" style="align-self:end">Sukurti nuorodą</button></form>'
        .'<p class="muted small" style="margin:0 0 14px">Prie albumo pririšta nuoroda leidžia kelti būtent į jį — nesvarbu, ar tas albumas įtrauktas į atvirų sąrašą. Atšaukti ją galima tik čia, mygtuku „Atšaukti". Nuoroda be albumo rodo narui visą atvirų albumų sąrašą.</p>';
    $invites = db()->query("SELECT i.*, a.title album_title FROM member_invites i LEFT JOIN albums a ON a.id=i.album_id ORDER BY i.is_active DESC, i.id DESC LIMIT 100")->fetchAll();
    if ($invites) {
        echo '<table><tr><th>Kam</th><th>Albumas</th><th>Būsena</th><th>Įkelta</th><th>Galioja iki</th><th></th></tr>';
        $now = time();
        foreach ($invites as $inv) {
            $expired = !empty($inv['expires_at']) && strtotime((string)$inv['expires_at']) < $now;
            $usedUp = (int)$inv['max_photos'] > 0 && (int)$inv['photos_done'] >= (int)$inv['max_photos'];
            $state = (int)$inv['is_active'] !== 1 ? 'atšaukta' : ($expired ? 'pasibaigusi' : ($usedUp ? 'limitas išnaudotas' : 'aktyvi'));
            $stateStyle = $state === 'aktyvi' ? '' : ' style="border-color:var(--err-line)"';
            echo '<tr><td><strong>'.e((string)$inv['label']).'</strong><br><span class="muted small">…'.e((string)$inv['token_hint']).'… · sukurta '.e((string)$inv['created_at']).'</span></td>'
                .'<td>'.($inv['album_id'] ? e((string)$inv['album_title']) : '<span class="muted small">bet kuris atviras</span>').'</td>'
                .'<td><span class="badge"'.$stateStyle.'>'.e($state).'</span></td>'
                .'<td>'.e((string)$inv['photos_done']).((int)$inv['max_photos'] > 0 ? ' / '.e((string)$inv['max_photos']).' nuotr.' : ' nuotr.').'<br><span class="muted small">'.e((string)$inv['uploads_done']).' įkėlimai</span></td>'
                .'<td><span class="muted small">'.e((string)($inv['expires_at'] ?? 'neribotai')).'</span></td>'
                .'<td>'.((int)$inv['is_active'] === 1 ? '<form method="post" action="?action=revoke_member_invite" style="margin:0"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="id" value="'.e((string)$inv['id']).'"><button class="mini">Atšaukti</button></form>' : '').'</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    /* --- Paskutiniai ikelimai --- */
    $uploads = member_recent_uploads(40);
    echo '<div class="card"><h2>Paskutiniai narių įkėlimai</h2>';
    if (!$uploads) {
        echo '<p class="muted">Kol kas nieko.</p>';
    } else {
        echo '<table><tr><th>Kada</th><th>Kas</th><th>Albumas</th><th>Failai</th><th>Dydis</th></tr>';
        foreach ($uploads as $u) {
            echo '<tr><td><span class="muted small">'.e((string)$u['created_at']).'</span></td>'
                .'<td>'.e((string)$u['contributor_name']).($u['contributor_email'] ? '<br><span class="muted small">'.e((string)$u['contributor_email']).'</span>' : '').'</td>'
                .'<td><a href="?page=photos&album_id='.e((string)$u['album_id']).'&source=member" style="text-decoration:underline;text-underline-offset:3px">'.e((string)($u['album_title'] ?? '—')).'</a></td>'
                .'<td>'.e((string)$u['files_stored']).' įkelta'.((int)$u['files_skipped'] > 0 ? ', '.e((string)$u['files_skipped']).' praleista' : '').'</td>'
                .'<td><span class="muted small">'.e(human_bytes((int)$u['bytes_stored'])).'</span></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    foot('Nariai');
}

function admins(): void {
    require_superadmin();
    head('Admins');
    $editId = (int)($_GET['id'] ?? 0);
    $edit = ['id'=>0,'email'=>'','name'=>'','role'=>'user','is_active'=>1,'google_sub'=>'','avatar_url'=>''];
    if ($editId > 0) { $st = db()->prepare("SELECT * FROM admins WHERE id=? LIMIT 1"); $st->execute([$editId]); $edit = $st->fetch(PDO::FETCH_ASSOC) ?: $edit; }
    echo '<h1>Admins</h1><div class="card" style="margin-bottom:16px"><h2>'.($editId ? 'Edit admin' : 'Add admin').'</h2><form method="post" action="?action=save_admin"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="id" value="'.e((string)($edit['id'] ?? 0)).'"><div class="formgrid"><div><label>Email</label><input name="email" value="'.e((string)($edit['email'] ?? '')).'" placeholder="name@gmail.com"></div><div><label>Name</label><input name="name" value="'.e((string)($edit['name'] ?? '')).'"></div><div><label>Role</label><select name="role"><option value="user"'.((string)($edit['role'] ?? '')==='user'?' selected':'').'>user</option><option value="superadmin"'.((string)($edit['role'] ?? '')==='superadmin'?' selected':'').'>superadmin</option></select></div><div><label>Google sub</label><input name="google_sub" value="'.e((string)($edit['google_sub'] ?? '')).'"></div><div><label>Avatar URL</label><input name="avatar_url" value="'.e((string)($edit['avatar_url'] ?? '')).'"></div><div class="actions" style="align-items:center;margin-top:22px"><label><input type="checkbox" name="is_active" value="1" '.(!empty($edit['is_active']) ? 'checked' : '').'> Active</label></div></div><div class="actions"><button class="primary">Save admin</button>'.($editId ? '<a class="btn" href="?page=admins">Cancel</a>' : '').'</div></form></div>';
    $rows=db()->query("SELECT * FROM admins ORDER BY id")->fetchAll();
    echo '<table><tr><th>ID</th><th>Email</th><th>Name</th><th>Role</th><th>Active</th><th>Last login</th><th>Action</th></tr>';
    foreach ($rows as $r) echo '<tr><td>'.e($r['id']).'</td><td>'.e($r['email']).'</td><td>'.e($r['name']).'</td><td>'.e($r['role']).'</td><td>'.e($r['is_active']).'</td><td>'.e($r['last_login_at']).'</td><td><a class="btn mini" href="?page=admins&id='.e($r['id']).'">Edit</a></td></tr>';
    echo '</table>'; foot('Admins');
}

/**
 * Renders an entity reference as a link when we can point at something real.
 * album/takeout -> album editor; photo -> the album's photo list.
 */
function audit_entity_cell(array $r): string {
    $type = (string)$r['entity_type'];
    $id   = (int)($r['entity_id'] ?? 0);
    $label = $type.($id ? ' #'.$id : '');

    if ($id <= 0) return '<span class="muted">'.e($label).'</span>';

    if ($type === 'album' || $type === 'takeout') {
        $title = (string)($r['album_title'] ?? '');
        $text  = $title !== '' ? $title : $label;
        return '<a class="btn mini" href="?page=album_edit&id='.e((string)$id).'" title="Atidaryti albumą">'.e($text).'</a>';
    }
    if ($type === 'photo') {
        $albumId = (int)($r['photo_album_id'] ?? 0);
        if ($albumId > 0) {
            $title = (string)($r['photo_album_title'] ?? '');
            $text  = $title !== '' ? $title : ('albumas #'.$albumId);
            return '<div style="display:flex;flex-direction:column;align-items:flex-start;gap:4px">'
                 . '<a class="btn mini" href="?page=photos&album_id='.e((string)$albumId).'" title="Atidaryti albumo nuotraukas">'.e($text).'</a>'
                 . '<span class="muted small">'.e($label).'</span>'
                 . '</div>';
        }
    }
    return '<span class="muted">'.e($label).'</span>';
}

/** Shared filter parsing for the Access log page and its CSV export. */
function access_filters(): array {
    $from = trim((string)($_GET['from'] ?? ''));
    $to   = trim((string)($_GET['to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-30 days'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');
    if ($from > $to) [$from, $to] = [$to, $from];

    $albumId = (int)($_GET['album_id'] ?? 0);
    $country = strtoupper(trim((string)($_GET['country'] ?? '')));
    if (!preg_match('/^[A-Z]{2}$/', $country)) $country = '';
    $device = (string)($_GET['device'] ?? '');
    if (!in_array($device, ['desktop','mobile','tablet'], true)) $device = '';

    $where = "WHERE l.occurred_at >= ? AND l.occurred_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $args  = [$from, $to];
    if ($albumId > 0) { $where .= " AND l.album_id = ?";  $args[] = $albumId; }
    if ($country !== '') { $where .= " AND l.country = ?"; $args[] = $country; }
    if ($device !== '')  { $where .= " AND l.device = ?";  $args[] = $device; }

    return compact('from','to','albumId','country','device','where','args');
}

function access_export_csv(): void {
    require_superadmin();
    $f = access_filters();
    $st = db()->prepare("SELECT l.occurred_at, COALESCE(a.title, l.album_path) album, l.album_path,
                                l.ip_address, l.country, l.device, l.browser, l.os, l.referer
                           FROM access_logs l LEFT JOIN albums a ON a.id = l.album_id
                           {$f['where']} ORDER BY l.occurred_at DESC LIMIT 50000");
    $st->execute($f['args']);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="access-log-'.$f['from'].'_'.$f['to'].'.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, kad Excel atpažintų UTF-8
    // LT/EU Excel numatytasis stulpelių skirtukas yra kabliataškis, ne kablelis —
    // su kableliu visos reikšmės sukristų į vieną stulpelį (net su BOM).
    $sep = ';';
    fputcsv($out, ['Data','Laikas','Albumas','Albumo kelias','IP','Šalis','Įrenginys','Naršyklė','OS','Referer'], $sep);
    foreach ($st as $r) {
        $ts = (string)$r['occurred_at'];
        fputcsv($out, [
            substr($ts, 0, 10), substr($ts, 11, 8),
            $r['album'], $r['album_path'], $r['ip_address'], $r['country'],
            $r['device'], $r['browser'], $r['os'], $r['referer'],
        ], $sep);
    }
    fclose($out);
    exit;
}

// Retention: IP adresas yra asmens duomenys, tad access_logs įrašai negali būti
// laikomi neribotai. Automatiškai išvalomi senesni nei ACCESS_LOG_RETENTION_DAYS.
const ACCESS_LOG_RETENTION_DAYS = 180;
function access_log_purge_old(): int {
    try {
        $st = db()->prepare("DELETE FROM access_logs WHERE occurred_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $st->execute([ACCESS_LOG_RETENTION_DAYS]);
        return $st->rowCount();
    } catch (Throwable $e) { return 0; }
}

function access_page(): void {
    require_superadmin();
    head('Access');
    access_log_purge_old();
    $f = access_filters();

    $countSt = db()->prepare("SELECT COUNT(*) total, COUNT(DISTINCT l.ip_address) visitors
                                FROM access_logs l {$f['where']}");
    $countSt->execute($f['args']);
    $totals = $countSt->fetch() ?: ['total'=>0,'visitors'=>0];

    $rowsSt = db()->prepare("SELECT l.*, COALESCE(a.title, l.album_path) album_title
                               FROM access_logs l LEFT JOIN albums a ON a.id = l.album_id
                               {$f['where']} ORDER BY l.occurred_at DESC LIMIT 500");
    $rowsSt->execute($f['args']);
    $rows = $rowsSt->fetchAll();

    $topSt = db()->prepare("SELECT COALESCE(a.title, l.album_path) album, l.album_id, COUNT(*) c
                              FROM access_logs l LEFT JOIN albums a ON a.id = l.album_id
                              {$f['where']} GROUP BY l.album_id, album ORDER BY c DESC LIMIT 8");
    $topSt->execute($f['args']);
    $top = $topSt->fetchAll();

    $albums = db()->query("SELECT id,title FROM albums ORDER BY COALESCE(event_date,'9999-12-31') DESC, id DESC")->fetchAll();
    $countries = db()->query("SELECT DISTINCT country FROM access_logs WHERE country IS NOT NULL ORDER BY country")->fetchAll(PDO::FETCH_COLUMN);

    $qs = ['page'=>'access','from'=>$f['from'],'to'=>$f['to'],'album_id'=>$f['albumId'] ?: null,'country'=>$f['country'] ?: null,'device'=>$f['device'] ?: null];
    $exportUrl = '?'.http_build_query(array_filter(['action'=>'access_export'] + $qs, fn($v) => $v !== null && $v !== ''));

    echo '<div class="actions" style="justify-content:space-between;align-items:center"><h1 style="margin:0">Access log</h1>'
        .'<a class="btn" href="'.e($exportUrl).'">Export CSV</a></div>';

    echo '<form class="actions" style="align-items:end;gap:10px">'
        .'<input type="hidden" name="page" value="access">'
        // Native date inputs render in the browser's locale (dd/mm/yyyy in most
        // setups) and HTML cannot override that, so use ISO text fields instead.
        .'<div><label>Nuo</label><input name="from" value="'.e($f['from']).'" placeholder="YYYY-MM-DD" pattern="\d{4}-\d{2}-\d{2}" inputmode="numeric" autocomplete="off" style="width:14ch;font-variant-numeric:tabular-nums"></div>'
        .'<div><label>Iki</label><input name="to" value="'.e($f['to']).'" placeholder="YYYY-MM-DD" pattern="\d{4}-\d{2}-\d{2}" inputmode="numeric" autocomplete="off" style="width:14ch;font-variant-numeric:tabular-nums"></div>'
        .'<div><label>Albumas</label><select name="album_id" style="width:30ch;max-width:100%"><option value="0">Visi albumai</option>';
    foreach ($albums as $a) {
        echo '<option value="'.e((string)$a['id']).'"'.($f['albumId'] === (int)$a['id'] ? ' selected' : '').'>'.e($a['title']).'</option>';
    }
    echo '</select></div><div><label>Šalis</label><select name="country" style="width:12ch"><option value="">Visos</option>';
    foreach ($countries as $c) {
        echo '<option value="'.e((string)$c).'"'.($f['country'] === $c ? ' selected' : '').'>'.e((string)$c).'</option>';
    }
    echo '</select></div><div><label>Įrenginys</label><select name="device" style="width:14ch"><option value="">Visi</option>';
    foreach (['desktop'=>'Kompiuteris','mobile'=>'Telefonas','tablet'=>'Planšetė'] as $k => $lbl) {
        echo '<option value="'.e($k).'"'.($f['device'] === $k ? ' selected' : '').'>'.e($lbl).'</option>';
    }
    echo '</select></div><button class="primary">Filtruoti</button></form>';

    echo '<div class="grid" style="margin:14px 0">'
        .'<div class="card"><div class="muted small">Peržiūrų</div><div class="metric">'.e((string)$totals['total']).'</div></div>'
        .'<div class="card"><div class="muted small">Unikalių IP</div><div class="metric">'.e((string)$totals['visitors']).'</div></div>'
        .'<div class="card"><div class="muted small">Laikotarpis</div><div style="font-weight:700;margin-top:6px">'.e($f['from']).' — '.e($f['to']).'</div></div>'
        .'</div>';

    if ($top) {
        echo '<div class="card" style="margin-bottom:14px"><h2>Populiariausi albumai</h2><table><tr><th>Albumas</th><th>Peržiūrų</th></tr>';
        foreach ($top as $t) {
            $name = (int)($t['album_id'] ?? 0) > 0
                ? '<a href="?page=album_edit&id='.e((string)$t['album_id']).'">'.e((string)$t['album']).'</a>'
                : e((string)$t['album']);
            echo '<tr><td>'.$name.'</td><td>'.e((string)$t['c']).'</td></tr>';
        }
        echo '</table></div>';
    }

    echo '<table><tr><th>Data ir laikas</th><th>Albumas</th><th>Šalis</th><th>IP</th><th>Įrenginys</th><th>Naršyklė / OS</th><th>Referer</th></tr>';
    if (!$rows) echo '<tr><td colspan="7" class="muted">Pagal filtrus įrašų nėra. Registravimas įjungtas — duomenys kaupsis nuo pirmo albumo atidarymo viešoje galerijoje.</td></tr>';
    foreach ($rows as $r) {
        $ts = (string)$r['occurred_at'];
        $album = (int)($r['album_id'] ?? 0) > 0
            ? '<a href="?page=album_edit&id='.e((string)$r['album_id']).'">'.e((string)$r['album_title']).'</a>'
            : e((string)$r['album_title']);
        $ref = (string)($r['referer'] ?? '');
        echo '<tr>'
            .'<td style="white-space:nowrap"><strong>'.e(substr($ts,0,10)).'</strong><br><span class="muted small">'.e(substr($ts,11,8)).'</span></td>'
            .'<td class="small">'.$album.'</td>'
            .'<td>'.($r['country'] ? '<span class="badge">'.e((string)$r['country']).'</span>' : '<span class="muted">—</span>').'</td>'
            .'<td class="small muted">'.e((string)$r['ip_address']).'</td>'
            .'<td class="small">'.e((string)$r['device']).'</td>'
            .'<td class="small">'.e(trim((string)$r['browser'].' / '.(string)$r['os'], ' /')).'</td>'
            .'<td class="small muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis">'.($ref !== '' ? e($ref) : '—').'</td>'
            .'</tr>';
    }
    echo '</table>';
    echo '<p class="muted small" style="margin-top:12px">Registruojami tik viešos galerijos albumų atidarymai (ne kiekvienas paveikslėlis). Robotai (Googlebot ir pan.) praleidžiami. Šalis imama iš Cloudflare. IP adresai yra asmens duomenys — saugok tik tiek, kiek reikia.</p>';
    foot('Access');
}

function audit_page(): void {
    require_superadmin();
    head('Audit');

    $entity = trim((string)($_GET['entity'] ?? ''));
    $action = trim((string)($_GET['act'] ?? ''));
    $actor  = (int)($_GET['actor'] ?? 0);
    $dir    = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

    $where = 'WHERE 1=1'; $args = [];
    if ($entity !== '') { $where .= ' AND l.entity_type = ?'; $args[] = $entity; }
    if ($action !== '') { $where .= ' AND l.action = ?';      $args[] = $action; }
    if ($actor  >  0)   { $where .= ' AND l.admin_id = ?';    $args[] = $actor; }

    // Rows without created_at (legacy) are kept last on DESC and first on ASC by
    // falling back to id, which preserves the real insertion order either way.
    $order = $dir === 'asc'
        ? 'ORDER BY COALESCE(l.created_at, \'1970-01-01\') ASC, l.id ASC'
        : 'ORDER BY COALESCE(l.created_at, \'1970-01-01\') DESC, l.id DESC';

    // Resolve the entity to a real album (directly, or via the photo's album) so
    // each row can link straight to where the action happened.
    $st = db()->prepare(
        "SELECT l.*, a.email actor,
                al.title  AS album_title,
                p.album_id AS photo_album_id,
                pa.title  AS photo_album_title
           FROM audit_logs l
           LEFT JOIN admins a  ON a.id = l.admin_id
           LEFT JOIN albums al ON al.id = l.entity_id AND l.entity_type IN ('album','takeout')
           LEFT JOIN photos p  ON p.id  = l.entity_id AND l.entity_type = 'photo'
           LEFT JOIN albums pa ON pa.id = p.album_id
           $where $order LIMIT 300"
    );
    $st->execute($args);
    $rows = $st->fetchAll();

    $entities = db()->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);
    $actions  = db()->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
    $actors   = db()->query("SELECT DISTINCT a.id, a.email FROM audit_logs l JOIN admins a ON a.id=l.admin_id ORDER BY a.email")->fetchAll();

    $sortLink = function(string $d, string $label) use ($entity, $action, $actor): string {
        $q = array_filter(['page'=>'audit','entity'=>$entity,'act'=>$action,'actor'=>$actor ?: null,'dir'=>$d],
            fn($v) => $v !== '' && $v !== null);
        return '<a href="?'.e(http_build_query($q)).'" title="'.e($label).'">'.($d === 'asc' ? '↑' : '↓').'</a>';
    };

    // Title left, filters right — one compact row, same shape as the other pages.
    echo '<div class="actions" style="justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">'
        .'<h1 style="margin:0">Audit log</h1>'
        .'<form class="actions" style="margin:0;gap:8px;align-items:center;flex-wrap:wrap">'
        .'<input type="hidden" name="page" value="audit">'
        .'<input type="hidden" name="dir" value="'.e($dir).'">'
        .'<select name="entity" onchange="this.form.submit()" style="width:15ch" title="Entity" aria-label="Entity"><option value="">Visi entity</option>';
    foreach ($entities as $x) {
        echo '<option value="'.e((string)$x).'"'.($entity === $x ? ' selected' : '').'>'.e((string)$x).'</option>';
    }
    echo '</select><select name="act" onchange="this.form.submit()" style="width:20ch" title="Action" aria-label="Action"><option value="">Visi action</option>';
    foreach ($actions as $x) {
        echo '<option value="'.e((string)$x).'"'.($action === $x ? ' selected' : '').'>'.e((string)$x).'</option>';
    }
    echo '</select><select name="actor" onchange="this.form.submit()" style="width:22ch" title="Actor" aria-label="Actor"><option value="0">Visi actor</option>';
    foreach ($actors as $x) {
        echo '<option value="'.e((string)$x['id']).'"'.($actor === (int)$x['id'] ? ' selected' : '').'>'.e((string)$x['email']).'</option>';
    }
    echo '</select>'
        .($entity !== '' || $action !== '' || $actor > 0 ? '<a class="btn mini" href="?page=audit" title="Išvalyti filtrus">✕</a>' : '')
        .'</form></div>';

    echo '<table><tr>'
        .'<th style="white-space:nowrap">Data ir laikas <span class="sort-links">'.$sortLink('asc','Seniausi pirma').' '.$sortLink('desc','Naujausi pirma').'</span></th>'
        .'<th>Entity</th><th>Action</th><th>Actor</th><th>Summary</th></tr>';
    if (!$rows) echo '<tr><td colspan="5" class="muted">Pagal filtrus įrašų nėra.</td></tr>';
    foreach ($rows as $r) {
        $ts = (string)($r['created_at'] ?? '');
        $when = $ts !== ''
            ? '<strong>'.e(substr($ts, 0, 10)).'</strong><br><span class="muted small">'.e(substr($ts, 11, 8)).'</span>'
            : '<span class="muted">—</span>';
        echo '<tr>'
            .'<td style="white-space:nowrap;vertical-align:top">'.$when.'</td>'
            .'<td class="small" style="vertical-align:top">'.audit_entity_cell($r).'</td>'
            .'<td style="vertical-align:top"><span class="badge">'.e($r['action']).'</span></td>'
            .'<td class="small muted" style="vertical-align:top">'.e((string)$r['actor']).'</td>'
            .'<td class="small" style="vertical-align:top">'.e((string)$r['summary']).'</td>'
            .'</tr>';
    }
    echo '</table>'; foot('Audit');
}

function setting(string $key, string $default=''): string {
    $s=db()->prepare("SELECT value FROM settings WHERE `key`=?"); $s->execute([$key]); $v=$s->fetchColumn();
    if(!$v) return $default; $j=json_decode((string)$v,true); return is_scalar($j)?(string)$j:$default;
}
function ini_bytes(string $value): int {
    $value=trim($value); if($value==='') return 0;
    $unit=strtolower(substr($value,-1)); $n=(float)$value;
    return match($unit){'g'=>(int)($n*1073741824),'m'=>(int)($n*1048576),'k'=>(int)($n*1024),default=>(int)$n};
}
/**
 * Perrasinėja viso albumo sort_order pagal pasirinkta rikiavima.
 *
 * Iki siol "Sort preset" ikelimo formoje nieko nerikiavo - jis tik nurodydavo,
 * kuri reiksme pazymeti sarase po perkrovimo. Nuotraukos gaudavo sort_order
 * ikelimo eile, todel pasirinkus "Taken: oldest first" albumas likdavo suriktas
 * pagal failu vardus, o sarasas rodydavo "Custom order".
 *
 * Tvarka ta pati kaip narsykleje (photoSortValue): pagrindinis laukas, tada
 * pavadinimas, tada id - kad vienodos reiksmes visada issideliotu vienodai.
 */
function apply_album_sort_preset(int $albumId, string $preset): bool {
    $orders = [
        'taken_asc'  => 'CASE WHEN taken_at IS NULL THEN 1 ELSE 0 END ASC, taken_at ASC, original_filename ASC, id ASC',
        'taken_desc' => 'CASE WHEN taken_at IS NULL THEN 1 ELSE 0 END ASC, taken_at DESC, original_filename ASC, id ASC',
        'title_asc'  => 'original_filename ASC, id ASC',
        'title_desc' => 'original_filename DESC, id ASC',
        'size_asc'   => 'file_size ASC, original_filename ASC, id ASC',
        'size_desc'  => 'file_size DESC, original_filename ASC, id ASC',
    ];
    if (!isset($orders[$preset])) return false;   // 'custom' ir nezinomi - nieko nedarom
    $st = db()->prepare("SELECT id FROM photos WHERE album_id=? ORDER BY ".$orders[$preset]);
    $st->execute([$albumId]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) return false;
    $up = db()->prepare("UPDATE photos SET sort_order=? WHERE id=? AND album_id=?");
    $n = 0;
    foreach ($ids as $pid) { $n += 10; $up->execute([$n, (int)$pid, $albumId]); }
    return true;
}

function human_bytes(int $bytes): string {
    if($bytes>=1073741824) return round($bytes/1073741824,2).' GB';
    if($bytes>=1048576) return round($bytes/1048576,1).' MB';
    if($bytes>=1024) return round($bytes/1024,1).' KB';
    return $bytes.' B';
}
function upload_limits(): array {
    $post=ini_bytes((string)ini_get('post_max_size')); $file=ini_bytes((string)ini_get('upload_max_filesize')); $mem=ini_bytes((string)ini_get('memory_limit'));
    $files=(int)ini_get('max_file_uploads'); $time=(int)ini_get('max_execution_time');
    $album=(int)(min(array_filter([$post,$file*$files ?: 0,$mem ?: $post]))*0.75);
    return ['post_max_size'=>$post,'upload_max_filesize'=>$file,'max_file_uploads'=>$files,'memory_limit'=>$mem,'max_execution_time'=>$time,'recommended_album_bytes'=>$album];
}
function takeout_stage_effective_chunk(int $chunk, int $budget): int {
    $chunk = max(8192, $chunk);
    $budget = max(32768, $budget);
    return max(8192, min($chunk, (int)floor(($budget - 4096) * 3 / 4)));
}
function takeout_stage_estimated_post_bytes(int $chunk, int $budget): int {
    $effective = takeout_stage_effective_chunk($chunk, $budget);
    return (int)ceil($effective * 4 / 3) + 4096;
}
function takeout_stage_profile_presets(): array {
    return [
        'safe' => ['chunk' => 24576, 'label' => 'Safe / 24 KB'],
        'safe_plus' => ['chunk' => 81920, 'label' => 'Safe+ / 80 KB'],
        'balanced' => ['chunk' => 262144, 'label' => 'Balanced / 256 KB'],
        'fast' => ['chunk' => 524288, 'label' => 'Fast / 512 KB'],
        'very_fast' => ['chunk' => 1048576, 'label' => 'Very fast / 1 MB'],
        'mb2' => ['chunk' => 2 * 1048576, 'label' => '2 MB'],
        'mb4' => ['chunk' => 4 * 1048576, 'label' => '4 MB'],
        'mb8' => ['chunk' => 8 * 1048576, 'label' => '8 MB'],
        'mb16' => ['chunk' => 16 * 1048576, 'label' => '16 MB'],
        'mb32' => ['chunk' => 32 * 1048576, 'label' => '32 MB'],
        'mb64' => ['chunk' => 64 * 1048576, 'label' => '64 MB'],
    ];
}
/** Max raw chunk bytes that fit in one JSON POST given PHP post_max_size (base64 + overhead). */
function takeout_stage_max_chunk_bytes(?int $postMax = null): int {
    if ($postMax === null) $postMax = max(131072, (int)upload_limits()['post_max_size']);
    $hardCap = 64 * 1048576; 
    $phpCap = (int)floor(($postMax * 0.85 - 8192) * 3 / 4);
    return max(8192, min($hardCap, $phpCap));
}
function takeout_stage_budget_for_chunk(int $chunk, int $postMax): int {
    $need = (int)ceil($chunk * 4 / 3) + 8192;
    return (int)min(max(120000, $need), (int)($postMax * 0.85));
}
function takeout_stage_upload_tuning(): array {
    $profile = setting('takeout_stage_profile', 'balanced');
    foreach (['waf_safe' => 'safe', 'standard' => 'safe'] as $legacy => $mapped) {
        if ($profile === $legacy) $profile = $mapped;
    }
    $limits = upload_limits();
    $postMax = max(131072, (int)$limits['post_max_size']);
    $maxChunk = takeout_stage_max_chunk_bytes($postMax);
    $customChunk = (int)setting('takeout_stage_chunk_bytes', '0');
    $customBudget = (int)setting('takeout_stage_post_budget', '0');
    if ($profile === 'custom' && $customChunk > 0) {
        $chunk = max(8192, min($customChunk, $maxChunk));
        $budget = $customBudget > 0 ? $customBudget : takeout_stage_budget_for_chunk($chunk, $postMax);
        $label = 'Custom';
        if ($customChunk > $maxChunk) $label .= ' (capped to '.human_bytes($maxChunk).')';
        return ['profile' => 'custom', 'chunk' => $chunk, 'budget' => max(32768, $budget), 'label' => $label, 'max_chunk' => $maxChunk];
    }
    $presets = takeout_stage_profile_presets();
    if (!isset($presets[$profile])) $profile = 'balanced';
    $requested = (int)$presets[$profile]['chunk'];
    $chunk = min($requested, $maxChunk);
    $label = (string)$presets[$profile]['label'];
    if ($chunk < $requested) $label .= ' (capped to '.human_bytes($chunk).')';
    return [
        'profile' => $profile,
        'chunk' => $chunk,
        'budget' => takeout_stage_budget_for_chunk($chunk, $postMax),
        'label' => $label,
        'max_chunk' => $maxChunk,
    ];
}
/** Multipart album upload batches follow the same Takeout profile (no base64 overhead). */
function album_upload_batch_tuning(): array {
    $tuning = takeout_stage_upload_tuning();
    $limits = upload_limits();
    $postCap = (int)floor($limits['post_max_size'] * 0.85);
    // Hostingo mod_security atmeta POST kūnus > 12,5 MB (SecRequestBodyLimit) su
    // HTML 500 dar PRIEŠ PHP — išmatuota 2026-07-17: 12 MB praeina, 13 MB ne.
    // Todėl partijos riba 11 MB su atsarga multipart antraštėms, o ne 64 MB.
    $hardCap = 11 * 1048576;
    $chunk = (int)$tuning['chunk'];
    if ($chunk < 262144) {
        $batchBytes = min(max((int)$limits['recommended_album_bytes'], 41943040), $postCap, $hardCap);
    } else {
        $batchBytes = min($chunk, $postCap, $hardCap);
    }
    $batchBytes = max(1048576, $batchBytes);
    $maxFiles = max(1, min((int)$limits['max_file_uploads'], 100));
    return [
        'batch_bytes' => $batchBytes,
        'max_files' => $maxFiles,
        'label' => (string)$tuning['label'],
        'profile' => (string)$tuning['profile'],
    ];
}
function safe_b2_name(string $name, int $max=180): string {
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $stem = $ext !== '' ? substr($name, 0, -(strlen($ext)+1)) : $name;
    $stem = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $stem) ?: $stem;
    $stem = trim((string)(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $stem) ?: ''), '-._');
    $stem = $stem !== '' ? $stem : 'file';
    $ext = $ext !== '' ? strtolower((string)(preg_replace('/[^a-zA-Z0-9]+/', '', $ext) ?: $ext)) : '';
    $base = $ext !== '' ? $stem.'.'.$ext : $stem;
    return substr($base, 0, $max);
}
function takeout_stage_root(): string {
    $root = dirname(__DIR__, 2).'/cache/takeout_imports';
    if (!is_dir($root)) @mkdir($root, 0775, true);
    if (!is_dir($root) || !is_writable($root)) {
        $root = sys_get_temp_dir().'/klajunas_takeout_imports';
        if (!is_dir($root)) @mkdir($root, 0775, true);
    }
    return $root;
}
function takeout_stage_disk_free(): ?int {
    $free = @disk_free_space(takeout_stage_root());
    return $free === false ? null : (int)$free;
}
function posted_text(string $name): string {
    $b64 = (string)($_POST[$name.'_b64'] ?? '');
    if ($b64 !== '') {
        $decoded = base64_decode($b64, true);
        if (is_string($decoded)) return trim($decoded);
    }
    return trim((string)($_POST[$name] ?? ''));
}
function takeout_json_text(?array $json, array $keys): string {
    if (!$json) return '';
    foreach ($keys as $key) {
        if (!array_key_exists($key, $json)) continue;
        $value = $json[$key];
        if (is_scalar($value)) {
            $text = trim((string)$value);
            if ($text !== '') return $text;
        }
    }
    return '';
}
function takeout_json_time($node): ?string {
    if (is_array($node)) {
        $ts = isset($node['timestamp']) ? (int)$node['timestamp'] : 0;
        if ($ts > 0) return gmdate('Y-m-d H:i:s', $ts);
        foreach (['formatted','date','datetime','time'] as $key) {
            if (empty($node[$key]) || !is_scalar($node[$key])) continue;
            $parsed = strtotime((string)$node[$key]);
            if ($parsed) return gmdate('Y-m-d H:i:s', $parsed);
        }
        return null;
    }
    if (is_numeric($node) && (int)$node > 0) return gmdate('Y-m-d H:i:s', (int)$node);
    if (is_string($node) && trim($node) !== '') {
        $parsed = strtotime($node);
        if ($parsed) return gmdate('Y-m-d H:i:s', $parsed);
    }
    return null;
}
function takeout_web_upload_without_camera(?array $meta, array $exif = []): bool {
    if (!$meta || empty($meta['googlePhotosOrigin']['webUpload']) || !is_array($meta['googlePhotosOrigin']['webUpload'])) return false;
    foreach (['camera_make','camera_model','lens_model','focal_length','aperture','shutter_speed','iso_value'] as $key) {
        if (($exif[$key] ?? null) !== null && (string)$exif[$key] !== '') return false;
    }
    return true;
}
function takeout_photo_taken_time(?array $meta, array $exif = []): ?string {
    if (!$meta || takeout_web_upload_without_camera($meta, $exif)) return null;
    return json_time($meta['photoTakenTime'] ?? null);
}
function takeout_album_date(?array $albumJson): ?string {
    if (!$albumJson) return null;
    foreach (['date','albumDate','creationTime','albumCreationTime','startTime','startDate','eventDate','coverDate'] as $key) {
        if (!array_key_exists($key, $albumJson)) continue;
        $date = takeout_json_time($albumJson[$key]);
        if ($date) return substr($date, 0, 10);
    }
    return null;
}
function takeout_album_visibility(?array $albumJson, string $fallback='draft'): string {
    if (!$albumJson) return normalize_visibility($fallback);
    $raw = strtolower(trim(takeout_json_text($albumJson, ['access','visibility','privacy','sharedStatus'])));
    if ($raw === '') return normalize_visibility($fallback);
    if (in_array($raw, ['public','published','shared','anyone','anyonewithlink','anyone_with_link'], true)) return 'published';
    if (in_array($raw, ['private','restricted'], true)) return 'private';
    if (in_array($raw, ['draft','ready','hidden','archived'], true)) return normalize_visibility($raw);
    return normalize_visibility($fallback);
}
function takeout_event_date(?array $albumJson, array $sidecars, array $files): ?string {
    $date = takeout_album_date($albumJson);
    if ($date) return $date;
    $best = null;
    foreach ($sidecars as $j) {
        $t = takeout_photo_taken_time($j);
        if ($t && ($best === null || strcmp($t, $best) < 0)) $best = $t;
    }
    if ($best) return substr($best, 0, 10);
    foreach ($files as $f) {
        if (preg_match('/(19|20)\d{2}[-_.]?\d{2}[-_.]?\d{2}/', $f['base'], $m)) {
            $raw = preg_replace('/[^0-9]/', '', $m[0]);
            return substr($raw,0,4).'-'.substr($raw,4,2).'-'.substr($raw,6,2);
        }
    }
    return null;
}
/**
 * Kanoninis B2 aplanko kelias: albums/YYYY/<datos-raktas>__<Pavadinimas>
 *
 * Data priekyje, kaip ir vietiniame archyve - taip B2 rikiuojasi
 * chronologiskai, o vietinis aplankas su B2 aplanku sutampa pazodziui, tad
 * juos lyginant nebereikia miglotos paieskos pagal zodzius.
 *
 * Datos raktas atkartoja archyvo standarta:
 *   vienos dienos renginys            -> 2022-05-06
 *   keliu dienu tame paciame menesyje -> 2022-05-06_08
 *   keliu dienu per menesio riba       -> 2022-07-31_08-02
 */
/**
 * Romeniska eiles numeri aplanko varde keiciam iprastu: "XVI-begimas" -> "16-begimas".
 *
 * Keiciamas TIK pirmas zodis ir tik tada, kai jis tikrai romeniskas skaicius:
 * atgal paverstas i romeniska turi sutapti raide i raide (todel "LC" - is "LČ" -
 * atkrenta, nes 150 rasoma "CL"), o reiksme turi buti <= 100 (todel "MIX",
 * kuris formaliai yra 1009, lieka nepaliestas).
 */
function roman_head_to_arabic(string $name): string {
    if (!preg_match('/^([IVXLCDM]+)(?=$|[-_])/', $name, $m)) return $name;
    $token = $m[1];
    $map = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000];
    $total = 0; $prev = 0;
    for ($i = strlen($token) - 1; $i >= 0; $i--) {
        $cur = $map[$token[$i]];
        if ($cur < $prev) { $total -= $cur; } else { $total += $cur; $prev = $cur; }
    }
    if ($total < 1 || $total > 100) return $name;
    $back = ''; $n = $total;
    foreach ([1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC',
              50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'] as $v => $r) {
        while ($n >= $v) { $back .= $r; $n -= $v; }
    }
    if ($back !== $token) return $name;
    return (string)$total . substr($name, strlen($token));
}

function canonical_album_prefix(string $title, ?string $eventDate = null, ?string $eventDateEnd = null): string {
    // Pavadinimo gale einantys skliaustai su metais ("(2016)", "(Telšiai, 2017)")
    // yra rodymo dalykas, ne kelio: vieta turi atskira lauka, o metai i kelia
    // pridedami zemiau. Ju cia nenuimant, vietos irasymas i pavadinima butu
    // pakeites 163 albumu kelius ir pareikalaves antro perkelimo be jokios
    // naudos.
    // Skliaustus su metais nuimam VISUR, ne tik gale: pavadinimas gali tureti
    // priesaga po ju ("(Suginčiai, 2023) - E. Songailos nuotraukos"). Skliaustai
    // be keturzenklio skaiciaus ("(Spring cup WRE, ilga)", "(50)") lieka - jie
    // yra pavadinimo dalis.
    $title = preg_replace('/\s*\(([^()]*\d{4}[^()]*)\)/', '', $title) ?? $title;
    $title = trim((string)preg_replace('/\s{2,}/', ' ', $title));
    $year = $eventDate ? substr($eventDate, 0, 4) : date('Y');
    $name = b2_folder_slug($title);

    if (!$eventDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
        // Be datos metai butu siandienos - t.y. melagingi. Tokie albumai guli
        // atskirai, kad kelias nesakytu to, ko nezinom.
        return 'albums/nezinoma/'.$name;
    }

    $dateKey = $eventDate;
    $end = trim((string)$eventDateEnd);
    if ($end !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) && $end > $eventDate) {
        [$sy, $sm, $sd] = explode('-', $eventDate);
        [$ey, $em, $ed] = explode('-', $end);
        if ($sy === $ey) $dateKey .= ($sm === $em) ? '_'.$ed : '_'.$em.'-'.$ed;
        else            $dateKey .= '_'.$end;
    }

    // Pavadinime jau esancia data ar metus nuimam - kitaip jie kartotusi
    // ir varde, ir priesagoje ("2022-05-06__Vilnius-2022-05-06").
    $name = preg_replace('/[-_]?'.preg_quote($eventDate, '/').'/', '', $name) ?? $name;
    if ($end !== '') $name = preg_replace('/[-_]?'.preg_quote($end, '/').'/', '', $name) ?? $name;
    $name = preg_replace('/[-_]?'.preg_quote($year, '/').'(?=$|[-_])/', '', $name) ?? $name;
    // Pavadinimuose datos intervalas rasomas sutrumpintai: "(2024-08-17/18)" arba
    // "(2006-08-13/16)". Nuemus tik pradzios data, varde likdavo nuolauza -
    // "...cempionatas-08-18-Estija", "Visaginas-3x4_16". Nuimam ir ja: data vieta
    // yra datos raktas, ne pavadinimas.
    $name = preg_replace_callback('/[-_](\d{2})-(\d{2})(?=$|[-_])/', function ($m) {
        return ((int)$m[1] >= 1 && (int)$m[1] <= 12 && (int)$m[2] >= 1 && (int)$m[2] <= 31) ? '' : $m[0];
    }, $name) ?? $name;
    $name = preg_replace_callback('/[-_](\d{2})(?=$|[-_])/', function ($m) {
        return ((int)$m[1] >= 1 && (int)$m[1] <= 31) ? '' : $m[0];
    }, $name) ?? $name;
    $name = trim((string)preg_replace('/[-_]{2,}/', '-', $name), '-_');
    if ($name === '') $name = 'albumas';
    // Metai grazinami i pavadinimo gala. Auksciau jie nuimami tam, kad butu
    // nesvarbu, kokiu pavidalu ivesti ("Telse 2017", "2017 m. Telse",
    // "Telse (2017-08-12)") - po nuemimo visi virsta vienodu vardu, o cia
    // pridedami vienodai. Aplanko varde metai visada matomi ir visada gale.
    $name = roman_head_to_arabic($name);
    $name .= '-'.$year;

    return 'albums/'.$year.'/'.$dateKey.'__'.$name;
}
function canonical_album_prefix_js(string $title, ?string $eventDate = null): string {
    return canonical_album_prefix($title, $eventDate);
}
function canonical_path_equal(string $a, string $b): bool {
    return strtolower(trim($a, "/ \t\n\r\0\x0B")) === strtolower(trim($b, "/ \t\n\r\0\x0B"));
}
/* Ar kelias "pakankamai kanoninis", kad jo nesiulytume pervadinti.
 * Skirtumas tik datos intervalo priesagoje ("2021-01-10_17__..." pries
 * "2021-01-10__...") nera priezastis kopijuoti viso aplanko B2: vienos dienos
 * renginys pabaigos datos neturi, o Takeout/senas kelias ja kartais turi.
 * Naudojama TIK busenai ir pasiulymams - B2 operacijos lygina tiksliai
 * (canonical_path_equal). */
function canonical_path_without_date_range(string $path): string {
    $path = canonical_path_key($path);
    return (string)(preg_replace('~(/\d{4}-\d{2}-\d{2})_(?:\d{4}-\d{2}-\d{2}|\d{2}-\d{2}|\d{2})__~', '$1__', $path) ?? $path);
}
function canonical_path_equivalent(string $a, string $b): bool {
    return canonical_path_equal($a, $b) || canonical_path_without_date_range($a) === canonical_path_without_date_range($b);
}
function canonical_path_key(string $path): string {
    return strtolower(trim($path, "/ \t\n\r\0\x0B"));
}
function album_match_key_from_text(string $text, ?string $eventDate = null): string {
    $value = slug($text);
    if ($eventDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
        $dateSlug = slug($eventDate);
        $year = substr($eventDate, 0, 4);
        $value = preg_replace('/-?'.preg_quote($dateSlug, '/').'$/', '', $value) ?? $value;
        $value = preg_replace('/-?'.preg_quote($year, '/').'$/', '', $value) ?? $value;
    }
    return trim($value, '-');
}
function album_match_key_from_prefix(string $prefix): string {
    $date = b2_album_event_date_from_prefix($prefix);
    return album_match_key_from_text(basename(trim($prefix, '/')), $date);
}
function album_match_keys(array $album): array {
    $date = isset($album['event_date']) && $album['event_date'] !== '' ? (string)$album['event_date'] : null;
    $keys = [];
    foreach ([(string)($album['title'] ?? ''), (string)($album['slug'] ?? ''), basename((string)($album['source_path'] ?? ''))] as $value) {
        $key = album_match_key_from_text($value, $date);
        if ($key !== '') $keys[$key] = true;
    }
    $canonical = canonical_album_prefix((string)($album['title'] ?? ''), $date);
    $canonicalKey = album_match_key_from_text(basename($canonical), $date);
    if ($canonicalKey !== '') $keys[$canonicalKey] = true;
    return array_keys($keys);
}
function likely_album_for_b2_prefix(string $prefix, array $albums): ?array {
    $prefixDate = b2_album_event_date_from_prefix($prefix);
    $prefixKey = album_match_key_from_prefix($prefix);
    if ($prefixKey === '') return null;
    foreach ($albums as $album) {
        $albumDate = isset($album['event_date']) && $album['event_date'] !== '' ? (string)$album['event_date'] : null;
        if ($prefixDate && $albumDate && $prefixDate !== $albumDate) continue;
        foreach (album_match_keys($album) as $key) {
            if ($key === $prefixKey) return $album;
        }
    }
    return null;
}
function duplicate_b2_prefixes_for_album(array $album, array $folderRows, string $exceptPrefix = ''): array {
    $exceptKey = canonical_path_key($exceptPrefix);
    $albumId = (int)($album['id'] ?? 0);
    $out = [];
    foreach ($folderRows as $row) {
        $prefix = (string)($row['prefix'] ?? '');
        if ($prefix === '' || canonical_path_key($prefix) === $exceptKey) continue;
        $likely = likely_album_for_b2_prefix($prefix, [$album]);
        if (!$likely || (int)($likely['id'] ?? 0) !== $albumId) continue;
        $out[] = $row;
    }
    return $out;
}
function album_storage_summary(array $album): array {
    $current = trim((string)($album['source_path'] ?? ''), '/');
    $canonical = canonical_album_prefix((string)($album['title'] ?? ''), $album['event_date'] ?? null, $album['event_date_end'] ?? null);
    if (canonical_path_equivalent($current, $canonical)) {
        return ['label' => 'canonical', 'action' => 'View B2'];
    }
    if ($current === '') {
        return ['label' => 'missing target', 'action' => 'Fix in B2'];
    }
    return ['label' => 'not canonical', 'action' => 'Make canonical'];
}
function takeout_unified_prefix(string $title, ?string $eventDate): string {
    return canonical_album_prefix($title, $eventDate);
}
function takeout_clean_event_date($date): ?string {
    $date = trim((string)$date);
    if ($date === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('Event date must use YYYY-MM-DD format.');
    }
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    if (!checkdate($m, $d, $y)) throw new RuntimeException('Event date is not valid.');
    return $date;
}
function takeout_clean_prefix($prefix): string {
    $prefix = str_replace('\\', '/', trim((string)$prefix));
    $prefix = trim((string)(preg_replace('~/+~', '/', $prefix) ?: $prefix), '/');
    if ($prefix === '') return '';
    if (preg_match('/[\x00-\x1F\x7F]/', $prefix)) throw new RuntimeException('Target B2 folder contains control characters.');
    foreach (explode('/', $prefix) as $part) {
        if ($part === '' || $part === '.' || $part === '..') throw new RuntimeException('Target B2 folder contains an unsafe path segment.');
    }
    if (!preg_match('~^albums/\d{4}/[A-Za-z0-9._/-]+$~', $prefix)) {
        throw new RuntimeException('Target B2 folder must look like albums/YYYY/album-slug.');
    }
    return $prefix;
}
function takeout_stage_uploaded_files(array $files, ?string $token=null): array {
    if ($token !== null && $token !== '' && !preg_match('/^[a-f0-9]{24}$/', $token)) {
        throw new RuntimeException('Invalid Takeout staging token.');
    }
    $token = $token ?: bin2hex(random_bytes(12));
    $dir = takeout_stage_root().'/'.$token;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException('Could not create Takeout staging directory.');
    $existing = glob($dir.'/*') ?: [];
    $offset = count($existing);
    $staged = [];
    foreach ($files as $i => $f) {
        $target = $dir.'/'.str_pad((string)($offset + $i), 5, '0', STR_PAD_LEFT).'_'.safe_b2_name($f['base'], 160);
        if (!@move_uploaded_file($f['tmp'], $target) && !@rename($f['tmp'], $target)) {
            throw new RuntimeException('Could not stage upload: '.$f['name']);
        }
        $f['tmp'] = $target;
        $f['stage_token'] = $token;
        $staged[] = $f;
    }
    return $staged;
}
function takeout_stage_payload_files(array $files, ?string $token=null): array {
    if ($token !== null && $token !== '' && !preg_match('/^[a-f0-9]{24}$/', $token)) {
        throw new RuntimeException('Invalid Takeout staging token.');
    }
    $token = $token ?: bin2hex(random_bytes(12));
    $dir = takeout_stage_root().'/'.$token;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException('Could not create Takeout staging directory.');
    $existing = glob($dir.'/*') ?: [];
    $offset = count($existing);
    $staged = [];
    foreach ($files as $i => $f) {
        $name = str_replace('\\', '/', (string)($f['name'] ?? 'file'));
        $base = basename($name) ?: 'file';
        $data = (string)($f['data'] ?? '');
        if ($data === '') continue;
        $bytes = base64_decode($data, true);
        if (!is_string($bytes)) throw new RuntimeException('Invalid encoded file payload: '.$base);
        $target = $dir.'/'.str_pad((string)($offset + $i), 5, '0', STR_PAD_LEFT).'_'.safe_b2_name($base, 160);
        if (file_put_contents($target, $bytes, LOCK_EX) === false) {
            throw new RuntimeException('Could not stage upload: '.$name);
        }
        $staged[] = [
            'tmp'=>$target,
            'name'=>$name,
            'base'=>$base,
            'size'=>filesize($target) ?: strlen($bytes),
            'type'=>(string)($f['type'] ?? 'application/octet-stream'),
            'stage_token'=>$token,
        ];
    }
    return $staged;
}
function takeout_stage_manifest_path(string $token): string {
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) throw new RuntimeException('Invalid Takeout staging token.');
    return takeout_stage_root().'/'.$token.'/_manifest.json';
}
function takeout_read_stage_manifest(string $token): array {
    $path = takeout_stage_manifest_path($token);
    if (!is_file($path)) return [];
    $j = json_decode((string)file_get_contents($path), true);
    return is_array($j) ? $j : [];
}
function takeout_write_stage_manifest(string $token, array $manifest): void {
    $path = takeout_stage_manifest_path($token);
    if (file_put_contents($path, json_encode(array_values($manifest), JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        throw new RuntimeException('Could not save Takeout staging manifest.');
    }
}
function takeout_stage_payload_chunk(array $payload): array {
    $token = (string)($payload['token'] ?? '');
    if ($token !== '' && !preg_match('/^[a-f0-9]{24}$/', $token)) throw new RuntimeException('Invalid Takeout staging token.');
    $token = $token ?: bin2hex(random_bytes(12));
    $dir = takeout_stage_root().'/'.$token;
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new RuntimeException('Could not create Takeout staging directory.');
    $seq = max(0, (int)($payload['seq'] ?? 0));
    $chunk = max(0, (int)($payload['chunk'] ?? 0));
    $chunks = max(1, (int)($payload['chunks'] ?? 1));
    $name = str_replace('\\', '/', (string)($payload['name'] ?? 'file'));
    $base = basename($name) ?: 'file';
    $type = (string)($payload['type'] ?? 'application/octet-stream');
    $target = $dir.'/'.str_pad((string)$seq, 5, '0', STR_PAD_LEFT).'_'.safe_b2_name($base, 160);
    $bytes = base64_decode((string)($payload['data'] ?? ''), true);
    if (!is_string($bytes)) throw new RuntimeException('Invalid encoded file payload: '.$base);
    if ($chunk === 0 && is_file($target)) @unlink($target);
    if (file_put_contents($target, $bytes, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Could not stage upload: '.$name);
    }
    $complete = $chunk >= $chunks - 1;
    if ($complete) {
        $manifest = takeout_read_stage_manifest($token);
        $manifest[$seq] = [
            'tmp'=>$target,
            'name'=>$name,
            'base'=>$base,
            'size'=>filesize($target) ?: (int)($payload['size'] ?? 0),
            'type'=>$type ?: 'application/octet-stream',
            'stage_token'=>$token,
        ];
        ksort($manifest, SORT_NUMERIC);
        takeout_write_stage_manifest($token, $manifest);
    }
    return ['token'=>$token, 'complete'=>$complete];
}
function takeout_build_plan(array $files, array $opts=[]): array {
    $albumJson = null; $sidecars = [];
    $lockPrefix = !empty($opts['lock_prefix']);
    foreach($files as $f){
        if(!preg_match('/\.json$/i',$f['base'])) continue;
        $j=json_decode((string)file_get_contents($f['tmp']),true); if(!is_array($j)) continue;
        if(strtolower($f['base'])==='metadata.json') $albumJson=$j;
        if(takeout_photo_metadata_json($f['base'], $j)){
            $photoName=google_photo_name($f['base'],$j);
            $sidecars[$photoName]=$j;
            if(!empty($j['title'])) $sidecars[basename((string)$j['title'])]=$j;
        }
    }
    $folder=takeout_album_folder($files);
    $title=trim((string)($opts['album_title'] ?? '')) ?: takeout_json_text($albumJson, ['title','name','albumTitle']) ?: trim((string)($folder ?: 'Takeout album'));
    $description = array_key_exists('description', $opts) ? trim((string)$opts['description']) : takeout_json_text($albumJson, ['description','summary','albumDescription']);
    $eventDate = takeout_clean_event_date($opts['event_date'] ?? '') ?? takeout_event_date($albumJson, $sidecars, $files);
    $prefix = $lockPrefix ? takeout_clean_prefix($opts['prefix'] ?? '') : takeout_unified_prefix($title, $eventDate);
    $visibility = array_key_exists('visibility', $opts) ? normalize_visibility((string)$opts['visibility']) : takeout_album_visibility($albumJson, 'draft');
    $media=[]; $seq=1; $total=0; $jsonCount=0; $sidecarCount=0;
    foreach($files as $f){
        if(preg_match('/\.json$/i',$f['base'])) { $jsonCount++; continue; }
        if(!takeout_media_file($f['base'])) continue;
        $target = str_pad((string)$seq, 4, '0', STR_PAD_LEFT).'_'.safe_b2_name($f['base']);
        $j = $sidecars[$f['base']] ?? null;
        $taken = takeout_photo_taken_time($j);
        $media[] = [
            'seq'=>$seq,
            'name'=>$f['name'],
            'base'=>$f['base'],
            'tmp'=>$f['tmp'],
            'size'=>$f['size'],
            'type'=>$f['type'] ?: 'application/octet-stream',
            'target_name'=>$target,
            'b2_key'=>$prefix.'/originals/'.$target,
            'sidecar_key'=>$prefix.'/metadata/'.$target.'.supplemental-metadata.json',
            'has_sidecar'=>$j !== null,
            'taken_at'=>$taken,
        ];
        $total += (int)$f['size'];
        if($j !== null) $sidecarCount++;
        $seq++;
    }
    return [
        'token'=>$files[0]['stage_token'] ?? '',
        'title'=>$title,
        'event_date'=>$eventDate,
        'description'=>$description,
        'prefix'=>$prefix,
        'visibility'=>$visibility,
        'folder'=>$folder,
        'album_json'=>$albumJson,
        'files'=>$files,
        'options'=>[
            'upload_json'=>!empty($opts['upload_json']),
            'overwrite_db'=>array_key_exists('overwrite_db',$opts) ? !empty($opts['overwrite_db']) : true,
        ],
        'media'=>$media,
        'counts'=>['media'=>count($media),'json'=>$jsonCount,'sidecars'=>$sidecarCount,'bytes'=>$total],
    ];
}
function takeout_cleanup_plan(array $plan): void {
    $token = (string)($plan['token'] ?? '');
    if ($token === '' || !preg_match('/^[a-f0-9]{24}$/', $token)) return;
    $dir = takeout_stage_root().'/'.$token;
    if (!is_dir($dir)) return;
    foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
    @rmdir($dir);
}
function settings_page(): void {
    require_superadmin();
    head('Settings');
    $fields=['b2_prefix'=>'Default B2 prefix'];
    $takeoutProfile = setting('takeout_stage_profile', 'balanced');
    $takeoutTuning = takeout_stage_upload_tuning();
    echo '<h1>Settings</h1><form method="post" action="?action=save_settings"><input type="hidden" name="_token" value="'.e(token()).'"><div class="formgrid">';
    foreach($fields as $k=>$l) echo '<div><label>'.e($l).'</label><input name="'.e($k).'" value="'.e(setting($k)).'"></div>';
    b2_load_config();
    $b2Eff = function(string $c): string { return defined($c) ? (string)constant($c) : ''; };
    $b2Src = function(string $key): string { return trim(setting($key)) !== '' ? 'DB override' : 'b2-config.php'; };
    echo '<div style="grid-column:1/-1;margin-top:6px"><h2 style="margin:0">Backblaze B2 API</h2><p class="muted small" style="margin:6px 0 0">Tuščia reikšmė = naudojama b2-config.php failo reikšmė (rodoma kaip placeholder). Pakeitus raktus, autorizacijos cache išvalomas automatiškai.</p></div>';
    echo '<div><label>B2 Key ID <span class="muted">· '.e($b2Src('b2_api_key_id')).'</span></label><input name="b2_api_key_id" value="'.e(setting('b2_api_key_id')).'" placeholder="'.e($b2Eff('B2_KEY_ID')).'" autocomplete="off"></div>';
    echo '<div><label>B2 Application Key <span class="muted">· '.e($b2Src('b2_api_app_key')).'</span></label><input type="password" name="b2_api_app_key" value="" placeholder="'.((trim(setting('b2_api_app_key')) !== '' || $b2Eff('B2_APP_KEY') !== '') ? 'saugoma — įrašykite tik keisdami' : '').'" autocomplete="new-password"></div>';
    echo '<div><label>B2 Bucket name <span class="muted">· '.e($b2Src('b2_api_bucket')).'</span></label><input name="b2_api_bucket" value="'.e(setting('b2_api_bucket')).'" placeholder="'.e($b2Eff('B2_BUCKET')).'" autocomplete="off"></div>';
    echo '<div><label>B2 Bucket ID <span class="muted">· '.e($b2Src('b2_api_bucket_id')).'</span></label><input name="b2_api_bucket_id" value="'.e(setting('b2_api_bucket_id')).'" placeholder="'.e($b2Eff('B2_BUCKET_ID')).'" autocomplete="off"></div>';
    // Inbox vartai. Kodas laikomas atviru tekstu samoningai: adminas ji turi
    // matyti, kad galetu perduoti klubo grupei, o jis saugo ne duomenis, o tik
    // ikelimo forma nuo praeiviu.
    $inboxOn = setting('inbox_enabled', '1') !== '0';
    echo '<div style="grid-column:1/-1;margin-top:6px"><h2 style="margin:0">Uploads - nuotraukų įkėlimas</h2><p class="muted small" style="margin:6px 0 0">Narių puslapis: <a href="https://upload.klajunas.lt/" target="_blank" rel="noopener" style="text-decoration:underline;text-underline-offset:3px"><code>https://upload.klajunas.lt/</code></a>. Be kodo jis neprima nieko. Gautos siuntos laukia <a href="?page=inbox" style="text-decoration:underline">Uploads</a> lange.</p></div>';
    echo '<div><label>Upload chunk size</label><select id="takeoutProfile" name="takeout_stage_profile">';
    foreach (takeout_stage_profile_presets() as $v => $preset) {
        echo '<option value="'.e($v).'"'.($takeoutTuning['profile']===$v?' selected':'').'>'.e($preset['label']).'</option>';
    }
    echo '<option value="custom"'.($takeoutTuning['profile']==='custom'?' selected':'').'>Custom (bytes below)</option>';
    // Abu baitu laukai priklauso tik "Custom" pasirinkimui: prie bet kurio
    // preseto takeout_stage_upload_tuning() ju net neskaito. Anksciau jie stovejo
    // lange visada ir atrode kaip du nustatymai, kuriu niekas nepildo, - todel
    // rodomi tik tada, kai tikrai veikia.
    $takeoutCustom = $takeoutTuning['profile'] === 'custom';
    echo '</select></div>';
    echo '<div><label>Upload On/Off</label><select name="inbox_enabled"><option value="1"'.($inboxOn?' selected':'').'>taip</option><option value="0"'.(!$inboxOn?' selected':'').'>ne</option></select></div>';
    echo '<div><label>Verification code</label><input name="inbox_access_code" value="'.e(setting('inbox_access_code')).'" autocomplete="off" placeholder="tuščia = įkėlimas išjungtas"></div>';
    echo '<div class="takeout-custom"'.($takeoutCustom ? '' : ' hidden').'><label>Custom chunk bytes</label><input name="takeout_stage_chunk_bytes" value="'.e(setting('takeout_stage_chunk_bytes')).'" placeholder="262144"></div>';
    echo '<div class="takeout-custom"'.($takeoutCustom ? '' : ' hidden').'><label>Custom JSON POST budget bytes</label><input name="takeout_stage_post_budget" value="'.e(setting('takeout_stage_post_budget')).'" placeholder="auto"></div>';
    echo '</div><p class="muted">Takeout staging sends base64 JSON chunks. Album photo upload reuses this profile for multipart batch size (256 KB+ profiles). WAF (~128 KB) allows <strong>Safe</strong> and <strong>Safe+</strong> Takeout modes. Max raw Takeout chunk: <strong>'.e(human_bytes(takeout_stage_max_chunk_bytes((int)upload_limits()['post_max_size']))).'</strong>. Album batch now: <strong>'.e(human_bytes((int)album_upload_batch_tuning()['batch_bytes'])).'</strong>.</p><p class="muted">B2 raktai saugomi settings DB lentelėje kaip override virš b2-config.php failo; Application Key niekada nerodomas.</p><button class="primary">Save settings</button></form>';
    echo '<script>(function(){var sel=document.getElementById("takeoutProfile");if(!sel)return;var boxes=document.querySelectorAll(".takeout-custom");function sync(){boxes.forEach(function(b){b.hidden=sel.value!=="custom";});}sel.addEventListener("change",sync);sync();})();</script>';
    echo '<div class="card" style="margin-top:16px"><h2>B2 ryšio testas</h2><p class="muted small">Patikrina autorizaciją ir bucket pasiekiamumą su šiuo metu galiojančiais raktais (DB override arba b2-config.php). Cache prieš testą išvalomas.</p><form method="post" action="?action=b2_test" class="actions" style="margin:0"><input type="hidden" name="_token" value="'.e(token()).'"><button class="btn">Test B2 connection</button></form></div>';
    foot('Settings');
}
function import_page(): void {
    require_superadmin();
    head('CSV Import / Export');
    $albums=db()->query("SELECT id,title,source_path FROM albums ORDER BY sort_order,event_date DESC,id DESC")->fetchAll();
    echo '<h1>CSV / JSON Overlay</h1><div class="grid csvgrid"><div class="card"><h2>Export</h2><p><a class="btn" href="?action=export&type=albums">Albums CSV</a> <a class="btn" href="?action=export&type=photos">Photos CSV</a></p><form class="actions" method="get"><input type="hidden" name="action" value="export_overlay"><select name="album_id"><option value="0">Select album for overlay JSON...</option>';
    foreach($albums as $a) echo '<option value="'.e($a['id']).'">'.e($a['title']).'</option>';
    echo '</select><button>Export JSON bundle</button><button name="zip" value="1">Export Takeout-style ZIP</button></form></div><div class="card"><h2>Import CSV preview</h2><form method="post" enctype="multipart/form-data" action="?action=import_preview"><input type="hidden" name="_token" value="'.e(token()).'"><label>Type</label><select name="type"><option value="albums">Albums</option><option value="photos">Photos</option></select><label>CSV file</label><input type="file" name="csv" accept=".csv,text/csv"><label>Kaip elgtis su jau esamais duomenimis</label><select name="mode"><option value="fill">Papildyti tuščius laukus (nieko netrina)</option><option value="append">Pridėti prie esamo teksto</option><option value="overwrite">Perrašyti viską iš CSV</option></select><p class="muted small" style="margin:6px 0 0">Numatytasis „papildyti“ rašo tik ten, kur DB laukas tuščias, todėl rankiniai taisymai admin sąsajoje išlieka. „Perrašyti“ pakeičia laukus CSV reikšmėmis, o tuščias CSV laukas ištrina esamą reikšmę.</p><div class="actions"><button class="primary">Preview CSV</button></div></form></div></div>';
    if(!empty($_SESSION['csv_preview'])){ $p=$_SESSION['csv_preview']; echo '<div class="card" style="margin-top:16px"><h2>Peržiūra: '.e($p['type']).' · režimas: '.e((string)($p['mode'] ?? 'fill')).'</h2><p class="muted">'.count($p['rows']).' rows. Apply will create/update DB manifest only.</p><form method="post" action="?action=import_apply"><input type="hidden" name="_token" value="'.e(token()).'"><button class="primary">Apply import</button></form><table><tr>'; foreach(array_keys($p['rows'][0] ?? []) as $h) echo '<th>'.e($h).'</th>'; echo '</tr>'; foreach(array_slice($p['rows'],0,20) as $r){echo '<tr>'; foreach($r as $v) echo '<td>'.e($v).'</td>'; echo '</tr>'; } echo '</table></div>'; }
    echo '<div class="card" style="margin-top:16px"><h2>Google Photos Takeout JSON overlay import</h2><form method="post" enctype="multipart/form-data" action="?action=import_google_overlay"><input type="hidden" name="_token" value="'.e(token()).'"><div class="formgrid"><div><label>Target album</label><select name="album_id"><option value="0">Create/update from metadata.json</option>';
    foreach($albums as $a) echo '<option value="'.e($a['id']).'">'.e($a['title']).($a['source_path']?' · '.$a['source_path']:'').'</option>';
    echo '</select></div><div><label>B2 source path fallback</label><input name="source_path" placeholder="Album folder / B2 prefix"></div></div><label>Takeout JSON files</label><input type="file" name="json_files[]" accept=".json,application/json" multiple webkitdirectory><div class="actions"><label><input type="checkbox" name="create_missing" value="1" checked> Create missing DB photo rows from JSON names</label><button class="primary">Import JSON overlay</button></div><p class="muted">Use album metadata.json plus photo *.supplemental-metadata.json files. This imports overlay metadata into DB and does not touch B2 files.</p></form></div>';
    foot('CSV Import / Export');
}
/**
 * B2 raktas -> albumo aplanko prefiksas, arba '' jei tai ne albumas.
 *
 * Albumai gyvena tik dviejose formose: albums/YYYY/slug ir paveldeta YYYY/slug.
 * Anksciau cia buvo atsargine grandis "return $parts[0]", kuri bet kokia
 * saknini aplanka paversdavo albumo kandidatu - todel backups/db/*.sql.gz
 * atsidurdavo albumu sarase ir siulydavo "Create DB album" is DB kopijos.
 */
function b2_storage_album_prefix_from_key(string $key): string {
    $key = trim($key, '/');
    if ($key === '') return '';
    $parts = explode('/', $key);
    if (($parts[0] ?? '') === 'albums' && isset($parts[1], $parts[2])) {
        return 'albums/'.$parts[1].'/'.$parts[2];
    }
    if (preg_match('/^\d{4}$/', (string)($parts[0] ?? '')) && isset($parts[1])) {
        return $parts[0].'/'.$parts[1];
    }
    return '';
}
function b2_storage_root_prefix_from_key(string $key): string {
    $key = trim($key, '/');
    if ($key === '') return '';
    $parts = explode('/', $key);
    return $parts[0] ?? '';
}
// Visas B2 sarasas B2 puslapiui - skaitomas VIENA karta per uzklausa. Anksciau
// saknu ir aplanku lenteles ji skaite atskirai: ~25 000 failu archyvui tai
// dvigubai daugiau B2 kreipiniu ir ~22 s puslapio krovimo.
function b2_storage_full_listing(): array {
    static $cache = null;
    if ($cache === null) {
        $files = b2_list_prefix('', 100);
        $cache = [$files, b2_last_list_truncated()];
    }
    b2_last_list_truncated($cache[1]);
    return $cache[0];
}
function b2_storage_root_rows(): array {
    $rows = [];
    foreach (b2_storage_full_listing() as $file) {
        $name = (string)($file['fileName'] ?? '');
        $root = b2_storage_root_prefix_from_key($name);
        if ($root === '') continue;
        if (!isset($rows[$root])) $rows[$root] = ['prefix'=>$root,'files'=>0,'bytes'=>0];
        $rows[$root]['files']++;
        $rows[$root]['bytes'] += (int)($file['contentLength'] ?? 0);
    }
    ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($rows);
}
function b2_compatibility_key_for_original(string $originalKey, array $files): ?string {
    $originalKey = trim($originalKey, '/');
    if ($originalKey === '' || !str_contains($originalKey, '/originals/')) return null;
    $candidate = preg_replace('~/originals/([^/]+)\.[^.]+$~', '/jpg-originals/$1.jpg', $originalKey);
    if (!is_string($candidate) || $candidate === $originalKey) return null;
    foreach ($files as $file) {
        $key = trim((string)($file['fileName'] ?? ''), '/');
        if ($key === $candidate) return $candidate;
    }
    return null;
}
function b2_storage_folder_rows(): array {
    $rows = [];
    foreach (b2_storage_full_listing() as $file) {
        $name = (string)($file['fileName'] ?? '');
        $folder = b2_storage_album_prefix_from_key($name);
        if ($folder === '') continue;
        if (!isset($rows[$folder])) $rows[$folder] = ['prefix'=>$folder,'files'=>0,'images'=>0,'image_keys'=>[],'bytes'=>0,'sample'=>''];
        $rows[$folder]['files']++;
        if (!str_contains($name, '/archive-originals/') && !str_contains($name, '/jpg-originals/') && takeout_media_file($name)) {
            $rows[$folder]['images']++;
            $rows[$folder]['image_keys'][] = trim($name, '/');
        }
        $rows[$folder]['bytes'] += (int)($file['contentLength'] ?? 0);
        if ($rows[$folder]['sample'] === '') $rows[$folder]['sample'] = $name;
    }
    ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($rows);
}
function b2_album_title_from_prefix(string $prefix): string {
    $prefix = trim($prefix, '/');
    $base = basename($prefix) ?: $prefix;
    $title = str_replace(['_', '-'], ' ', $base);
    $title = trim((string)(preg_replace('/\s+/', ' ', $title) ?: $title));
    return $title !== '' ? $title : 'B2 album';
}
function b2_album_event_date_from_prefix(string $prefix): ?string {
    if (preg_match('/(^|[^\d])(\d{4})[-_ ]?(\d{2})[-_ ]?(\d{2})([^\d]|$)/', $prefix, $m)) {
        return $m[2].'-'.$m[3].'-'.$m[4];
    }
    return null;
}
/**
 * Sidecar key for a media key: <prefix>/originals/foo.jpg -> <prefix>/metadata/foo.jpg.supplemental-metadata.json
 */
function b2_sidecar_key_for_media(string $mediaKey): string {
    // dirname() grazina kelia BE pabaigos bruksnio, todel keiciam priesaga,
    // o ne '/originals/' - kitaip pakeitimas niekada nesuveikia.
    $dir = dirname($mediaKey);
    if (str_ends_with($dir, '/originals')) {
        $dir = substr($dir, 0, -strlen('/originals')).'/metadata';
    }
    return $dir.'/'.basename($mediaKey).'.supplemental-metadata.json';
}

/**
 * Fotografavimo laikas is Takeout sidecar failo, gulincio salia B2.
 * Grazina 'Y-m-d H:i:s' arba null. Sidecar parsiunciamas tik jei jis tikrai
 * yra listinge — taip isvengiam 404 uzklausu kiekvienai nuotraukai.
 */
function b2_taken_at_from_sidecar(array $auth, string $sidecarKey): ?string {
    try {
        $raw = b2_download_key($auth, defined('B2_BUCKET') ? (string)B2_BUCKET : '', $sidecarKey);
    } catch (Throwable $e) {
        return null;
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) return null;
    $ts = $json['photoTakenTime']['timestamp'] ?? null;
    if ($ts !== null && $ts !== '' && is_numeric($ts)) return gmdate('Y-m-d H:i:s', (int)$ts);
    $formatted = trim((string)($json['photoTakenTime']['formatted'] ?? ''));
    if ($formatted !== '') {
        $t = strtotime($formatted);
        if ($t !== false) return gmdate('Y-m-d H:i:s', $t);
    }
    return null;
}

function b2_create_photo_rows_from_prefix(int $albumId, string $prefix): int {
    $prefix = trim($prefix, '/');
    if ($albumId <= 0 || $prefix === '') return 0;
    $files = b2_list_prefix($prefix, 20);
    $existing = db()->prepare("SELECT id FROM photos WHERE album_id=? AND b2_key=? LIMIT 1");
    $insert = db()->prepare("INSERT INTO photos(uuid,album_id,source_type,b2_bucket,b2_key,compatibility_b2_key,original_filename,file_ext,original_format,converted_from_heic,preview_status,preview_error,thumb_path,preview_path,web_path,mime_type,file_size,taken_at,sort_order,visibility,is_downloadable,is_missing,synced_at,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),?,?)");
    $maxSort = (int)db()->query("SELECT COALESCE(MAX(sort_order),0) FROM photos WHERE album_id=".$albumId)->fetchColumn();

    // 1) Atrenkam naujus media failus ir issiaiskinam ju fotografavimo laika.
    //    Kaip ir Takeout importe, eiliskumas turi buti chronologinis, o ne
    //    abecelinis: B2 listingas rikiuoja pagal varda, o failu vardai daznai
    //    su chronologija nesusije (Facebook ID, keliu kameru mixas).
    $sidecarKeys = [];
    foreach ($files as $file) {
        $name = trim((string)($file['fileName'] ?? ''), '/');
        if ($name !== '' && str_contains($name, '/metadata/')) $sidecarKeys[$name] = true;
    }
    $auth = null;
    $pending = [];
    foreach ($files as $file) {
        $key = trim((string)($file['fileName'] ?? ''), '/');
        if ($key === '' || str_contains($key, '/archive-originals/') || str_contains($key, '/jpg-originals/') || !takeout_media_file($key)) continue;
        if (str_contains($key, '/metadata/')) continue;
        $existing->execute([$albumId, $key]);
        if ($existing->fetchColumn()) continue;

        $takenAt = null;
        $sidecarKey = b2_sidecar_key_for_media($key);
        if (isset($sidecarKeys[$sidecarKey])) {
            if ($auth === null) $auth = b2_auth();
            $takenAt = b2_taken_at_from_sidecar($auth, $sidecarKey);
        }
        $pending[] = ['key' => $key, 'file' => $file, 'taken_at' => $takenAt];
    }

    // 2) Chronologiskai; be laiko likusius dedam gale natūralia vardu tvarka,
    //    kad ju tarpusavio eile isliktu prognozuojama.
    usort($pending, function (array $a, array $b) {
        $at = (string)($a['taken_at'] ?? '');
        $bt = (string)($b['taken_at'] ?? '');
        if ($at !== '' && $bt !== '') {
            $cmp = strcmp($at, $bt);
            if ($cmp !== 0) return $cmp;
        } elseif ($at !== '' || $bt !== '') {
            return $at !== '' ? -1 : 1;
        }
        return strnatcasecmp(basename($a['key']), basename($b['key']));
    });

    $created = 0;
    foreach ($pending as $item) {
        $key = $item['key'];
        $file = $item['file'];
        $maxSort += 10;
        $originalFormat = photo_original_format($key);
        $compatibilityKey = b2_compatibility_key_for_original($key, $files);
        $previewState = photo_preview_state($originalFormat, $compatibilityKey, 'B2 kataloge nėra JPG peržiūros failo.');
        $insert->execute([
            uid(), $albumId, 'b2_sync', defined('B2_BUCKET') ? (string)B2_BUCKET : null, $key, $compatibilityKey, basename($key),
            pathinfo($key, PATHINFO_EXTENSION), $originalFormat, $previewState['converted_from_heic'], $previewState['preview_status'], $previewState['preview_error'], $previewState['thumb_path'], $previewState['preview_path'], $previewState['web_path'], (string)($file['contentType'] ?? 'image/jpeg'), (int)($file['contentLength'] ?? 0),
            $item['taken_at'], $maxSort, 'published', 1, $_SESSION['admin']['id'] ?? null, $_SESSION['admin']['id'] ?? null
        ]);
        persist_photo_metadata_json((int)db()->lastInsertId());
        $created++;
    }
    return $created;
}
function b2_link_photo_rows_to_prefix(int $albumId, string $prefix): array {
    $prefix = trim($prefix, '/');
    if ($albumId <= 0 || $prefix === '') return ['created'=>0,'updated'=>0,'relinked'=>0,'missing'=>0,'real_images'=>0,'found'=>0];
    // Pabaigoje nerastos eilutes zymimos missing - tik pagal PILNA sarasa.
    $files = b2_list_prefix($prefix, 50, true);
    $images = [];
    foreach ($files as $file) {
        $key = trim((string)($file['fileName'] ?? ''), '/');
        if ($key === '' || str_contains($key, '/metadata/') || str_contains($key, '/archive-originals/') || str_contains($key, '/jpg-originals/') || !takeout_media_file($key)) continue;
        $images[$key] = $file;
    }
    if (!$images) return ['created'=>0,'updated'=>0,'relinked'=>0,'missing'=>0,'real_images'=>0,'found'=>0];

    $photoRows = db()->prepare("SELECT id,b2_key,original_filename,stored_filename,sort_order FROM photos WHERE album_id=? ORDER BY sort_order ASC,id ASC");
    $photoRows->execute([$albumId]);
    $byKey = [];
    $byBase = [];
    while ($row = $photoRows->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$row['id'];
        $key = trim((string)($row['b2_key'] ?? ''), '/');
        if ($key !== '') $byKey[$key] = $row;
        foreach ([(string)($row['stored_filename'] ?? ''), (string)($row['original_filename'] ?? ''), basename($key)] as $name) {
            $base = strtolower(basename(trim($name)));
            if ($base !== '' && !isset($byBase[$base])) $byBase[$base] = $row;
        }
    }

    $maxSort = (int)db()->query("SELECT COALESCE(MAX(sort_order),0) FROM photos WHERE album_id=".(int)$albumId)->fetchColumn();
    $update = db()->prepare("UPDATE photos SET source_type='b2_link', b2_bucket=?, b2_key=?, compatibility_b2_key=?, stored_filename=?, original_filename=?, file_ext=?, original_format=?, converted_from_heic=?, preview_status=?, preview_error=?, thumb_path=?, preview_path=?, web_path=?, mime_type=?, file_size=?, is_missing=0, synced_at=NOW(), updated_by=? WHERE id=? AND album_id=?");
    $insert = db()->prepare("INSERT INTO photos(uuid,album_id,source_type,b2_bucket,b2_key,compatibility_b2_key,original_filename,stored_filename,file_ext,original_format,converted_from_heic,preview_status,preview_error,thumb_path,preview_path,web_path,mime_type,file_size,sort_order,visibility,is_downloadable,is_missing,synced_at,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),?,?)");
    $created = 0; $updated = 0; $relinked = 0; $seenIds = [];
    foreach ($images as $key => $file) {
        $base = basename($key);
        $row = $byKey[$key] ?? ($byBase[strtolower($base)] ?? null);
        if ($row) {
            $id = (int)$row['id'];
            $oldKey = trim((string)($row['b2_key'] ?? ''), '/');
            $originalFormat = photo_original_format($base);
            $compatibilityKey = b2_compatibility_key_for_original($key, $files);
            $previewState = photo_preview_state($originalFormat, $compatibilityKey, 'B2 kataloge nėra JPG peržiūros failo.');
            $update->execute([
                defined('B2_BUCKET') ? (string)B2_BUCKET : null,
                $key,
                $compatibilityKey,
                $base,
                $base,
                pathinfo($base, PATHINFO_EXTENSION),
                $originalFormat,
                $previewState['converted_from_heic'],
                $previewState['preview_status'],
                $previewState['preview_error'],
                $previewState['thumb_path'],
                $previewState['preview_path'],
                $previewState['web_path'],
                (string)($file['contentType'] ?? 'image/jpeg'),
                (int)($file['contentLength'] ?? 0),
                $_SESSION['admin']['id'] ?? null,
                $id,
                $albumId
            ]);
            $seenIds[$id] = true;
            $updated++;
            if ($oldKey !== $key) $relinked++;
        } else {
            $maxSort += 10;
            $originalFormat = photo_original_format($base);
            $compatibilityKey = b2_compatibility_key_for_original($key, $files);
            $previewState = photo_preview_state($originalFormat, $compatibilityKey, 'B2 kataloge nėra JPG peržiūros failo.');
            $insert->execute([
                uid(), $albumId, 'b2_link', defined('B2_BUCKET') ? (string)B2_BUCKET : null, $key, $compatibilityKey, $base, $base,
                pathinfo($base, PATHINFO_EXTENSION), $originalFormat, $previewState['converted_from_heic'], $previewState['preview_status'], $previewState['preview_error'], $previewState['thumb_path'], $previewState['preview_path'], $previewState['web_path'], (string)($file['contentType'] ?? 'image/jpeg'), (int)($file['contentLength'] ?? 0),
                $maxSort, 'published', 1, $_SESSION['admin']['id'] ?? null, $_SESSION['admin']['id'] ?? null
            ]);
            $id = (int)db()->lastInsertId();
            $seenIds[$id] = true;
            persist_photo_metadata_json($id);
            $created++;
        }
    }

    $missing = 0;
    $mark = db()->prepare("UPDATE photos SET is_missing=1, updated_by=? WHERE id=? AND album_id=?");
    $photoRows->execute([$albumId]);
    while ($row = $photoRows->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$row['id'];
        if (isset($seenIds[$id])) continue;
        $mark->execute([$_SESSION['admin']['id'] ?? null, $id, $albumId]);
        $missing++;
    }
    return ['created'=>$created,'updated'=>$updated,'relinked'=>$relinked,'missing'=>$missing,'real_images'=>count($images),'found'=>count($seenIds)];
}
function normalized_photo_duplicate_key(string $name): string {
    $base = strtolower(basename(trim($name)));
    return preg_replace('/^\d{4}_/', '', $base) ?? $base;
}
function cleanup_missing_photo_rows(): void {
    csrf(); b2_load_config();
    $albumId = (int)($_POST['album_id'] ?? 0);
    $album = require_album_editable_by_id($albumId);
    $prefix = album_storage_base_prefix((string)($album['source_path'] ?? ''));
    if ($prefix === '') $prefix = album_storage_base_prefix((string)(album_photo_prefix_guess($albumId) ?? ''));
    $realKeys = [];
    if ($prefix !== '') {
        foreach (b2_list_prefix($prefix, 50, true) as $fileRow) {
            $realKeys[trim((string)($fileRow['fileName'] ?? ''), '/')] = true;
        }
    }
    $st = db()->prepare("SELECT id, b2_key FROM photos WHERE album_id=? AND is_missing=1");
    $st->execute([$albumId]);
    $deleted = 0; $restored = 0;
    $delTags = db()->prepare("DELETE FROM photo_tags WHERE photo_id=?");
    $delRow = db()->prepare("DELETE FROM photos WHERE id=? AND album_id=? AND is_missing=1");
    $restore = db()->prepare("UPDATE photos SET is_missing=0, synced_at=NOW(), updated_by=? WHERE id=?");
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $key = trim((string)($row['b2_key'] ?? ''), '/');
        if ($key !== '' && isset($realKeys[$key])) {
            $restore->execute([$_SESSION['admin']['id'] ?? null, (int)$row['id']]);
            $restored++;
            continue;
        }
        $delTags->execute([(int)$row['id']]);
        $delRow->execute([(int)$row['id'], $albumId]);
        $deleted++;
    }
    db()->prepare("UPDATE albums SET cover_photo_id=NULL WHERE id=? AND cover_photo_id IS NOT NULL AND cover_photo_id NOT IN (SELECT id FROM photos WHERE album_id=?)")->execute([$albumId, $albumId]);
    audit('photo', $albumId, 'cleanup_missing_rows', "Missing rows cleanup: deleted $deleted, restored $restored", ['album_id'=>$albumId,'deleted'=>$deleted,'restored'=>$restored]);
    flash("Missing DB įrašų tvarkymas: $deleted ištrinta (failų B2 nėra), $restored atstatyta (failas rastas B2). B2 failai neliesti.");
    go((string)($_POST['return_to'] ?? '') === 'b2' ? '?page=b2#b2-folder-'.$albumId : '?page=album_edit&id='.$albumId);
}
function delete_missing_duplicate_photo_rows(int $albumId): int {
    $st = db()->prepare("SELECT id,b2_key,original_filename,stored_filename,is_missing FROM photos WHERE album_id=? ORDER BY is_missing ASC,id ASC");
    $st->execute([$albumId]);
    $active = [];
    $missing = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $keys = [];
        foreach ([(string)($row['b2_key'] ?? ''), (string)($row['stored_filename'] ?? ''), (string)($row['original_filename'] ?? '')] as $name) {
            $key = normalized_photo_duplicate_key($name);
            if ($key !== '') $keys[$key] = true;
        }
        if ((int)($row['is_missing'] ?? 0) === 1) $missing[(int)$row['id']] = array_keys($keys);
        else foreach (array_keys($keys) as $key) $active[$key] = true;
    }
    if (!$active || !$missing) return 0;
    $del = db()->prepare("DELETE FROM photos WHERE id=? AND album_id=? AND is_missing=1");
    $deleted = 0;
    foreach ($missing as $id => $keys) {
        foreach ($keys as $key) {
            if (!isset($active[$key])) continue;
            $del->execute([$id, $albumId]);
            $deleted++;
            break;
        }
    }
    return $deleted;
}
function consolidate_album_photo_rows_to_prefix(int $albumId, string $prefix): array {
    $prefix = trim($prefix, '/');
    if ($albumId <= 0 || $prefix === '') return ['merged'=>0,'deleted'=>0,'published'=>0];
    $st = db()->prepare("SELECT * FROM photos WHERE album_id=? ORDER BY is_missing ASC, visibility='published' DESC, id ASC");
    $st->execute([$albumId]);
    $groups = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $key = normalized_photo_duplicate_key((string)($row['b2_key'] ?? ''));
        if ($key === '') $key = normalized_photo_duplicate_key((string)($row['stored_filename'] ?? ''));
        if ($key === '') $key = normalized_photo_duplicate_key((string)($row['original_filename'] ?? ''));
        if ($key === '') continue;
        $groups[$key][] = $row;
    }
    $merge = db()->prepare("UPDATE photos SET source_type='b2_link', visibility=?, is_missing=0, photo_views=COALESCE(photo_views,?), title=COALESCE(NULLIF(title,''),NULLIF(?,'')), description=COALESCE(NULLIF(description,''),NULLIF(?,'')), taken_at=COALESCE(taken_at,?), camera_make=COALESCE(NULLIF(camera_make,''),NULLIF(?,'')), camera_model=COALESCE(NULLIF(camera_model,''),NULLIF(?,'')), lens_model=COALESCE(NULLIF(lens_model,''),NULLIF(?,'')), focal_length=COALESCE(NULLIF(focal_length,''),NULLIF(?,'')), aperture=COALESCE(NULLIF(aperture,''),NULLIF(?,'')), shutter_speed=COALESCE(NULLIF(shutter_speed,''),NULLIF(?,'')), iso_value=COALESCE(iso_value,?), width=COALESCE(width,?), height=COALESCE(height,?), metadata_json=COALESCE(metadata_json,?), sort_order=LEAST(sort_order,?), updated_by=? WHERE id=? AND album_id=?");
    $del = db()->prepare("DELETE FROM photos WHERE id=? AND album_id=?");
    $setPublished = db()->prepare("UPDATE photos SET source_type='b2_link', visibility='published', is_missing=0, updated_by=? WHERE id=? AND album_id=?");
    $merged = 0; $deleted = 0; $published = 0;
    foreach ($groups as $rows) {
        if (count($rows) < 2) continue;
        $targetRows = array_values(array_filter($rows, fn($r) => str_starts_with(trim((string)($r['b2_key'] ?? ''), '/'), $prefix.'/')));
        if (!$targetRows) continue;
        usort($targetRows, function($a, $b) {
            $ap = (string)($a['visibility'] ?? '') === 'published' ? 0 : 1;
            $bp = (string)($b['visibility'] ?? '') === 'published' ? 0 : 1;
            if ($ap !== $bp) return $ap <=> $bp;
            return ((int)($a['is_missing'] ?? 0)) <=> ((int)($b['is_missing'] ?? 0)) ?: ((int)$a['id'] <=> (int)$b['id']);
        });
        $keep = $targetRows[0];
        $keepId = (int)$keep['id'];
        $wantPublished = false;
        foreach ($rows as $row) {
            if ((string)($row['visibility'] ?? '') === 'published') $wantPublished = true;
            if ((int)$row['id'] === $keepId) continue;
            $merge->execute([
                $wantPublished ? 'published' : (string)($keep['visibility'] ?? 'published'),
                $row['photo_views'] ?? null,
                $row['title'] ?? '',
                $row['description'] ?? '',
                $row['taken_at'] ?? null,
                $row['camera_make'] ?? '',
                $row['camera_model'] ?? '',
                $row['lens_model'] ?? '',
                $row['focal_length'] ?? '',
                $row['aperture'] ?? '',
                $row['shutter_speed'] ?? '',
                $row['iso_value'] ?? null,
                $row['width'] ?? null,
                $row['height'] ?? null,
                $row['metadata_json'] ?? null,
                (int)($row['sort_order'] ?? 0),
                $_SESSION['admin']['id'] ?? null,
                $keepId,
                $albumId,
            ]);
            $del->execute([(int)$row['id'], $albumId]);
            $merged++;
            $deleted++;
        }
        if ($wantPublished && (string)($keep['visibility'] ?? '') !== 'published') {
            $setPublished->execute([$_SESSION['admin']['id'] ?? null, $keepId, $albumId]);
            $published++;
        }
    }
    return ['merged'=>$merged,'deleted'=>$deleted,'published'=>$published];
}
function create_db_album_from_b2_prefix(): void {
    require_superadmin(); csrf(); b2_load_config();
    $prefix = trim((string)($_POST['prefix'] ?? ''), '/');
    if ($prefix === '') { flash('B2 folder prefix is required.', 'err'); go('?page=b2'); }
    if (b2_prefix_image_count($prefix) <= 0) { flash('DB album was not created: selected B2 folder has no images.', 'err'); go('?page=b2'); }
    $existing = db()->prepare("SELECT id FROM albums WHERE source_path=? LIMIT 1");
    $existing->execute([$prefix]);
    $albumId = (int)$existing->fetchColumn();
    if (!$albumId) {
        $albums = db()->query("SELECT id,title,slug,event_date,source_path FROM albums ORDER BY id")->fetchAll();
        $likely = likely_album_for_b2_prefix($prefix, $albums);
        if ($likely) {
            flash('DB album was not created: this B2 folder looks like existing album "'.$likely['title'].'". Use Link/merge to existing DB album instead.', 'err');
            go('?page=b2#b2-folder-'.$likely['id']);
        }
    }
    if (!$albumId) {
        $title = b2_album_title_from_prefix($prefix);
        $date = b2_album_event_date_from_prefix($prefix);
        $slug = slug($title);
        $baseSlug = $slug; $i = 2;
        $slugExists = db()->prepare("SELECT id FROM albums WHERE slug=? LIMIT 1");
        while (true) {
            $slugExists->execute([$slug]);
            if (!$slugExists->fetchColumn()) break;
            $slug = $baseSlug.'-'.$i++;
        }
        db()->prepare("INSERT INTO albums(uuid,source_type,source_path,slug,title,event_date,visibility,download_enabled,created_by,updated_by) VALUES(?,?,?,?,?,?,'published',1,?,?)")
            ->execute([uid(),'b2_link',$prefix,$slug,$title,$date,$_SESSION['admin']['id'] ?? null,$_SESSION['admin']['id'] ?? null]);
        $albumId = (int)db()->lastInsertId();
        place_album_by_date($albumId);
        persist_album_metadata_json($albumId);
    }
    $created = b2_create_photo_rows_from_prefix($albumId, $prefix);
    audit('album',$albumId,'create_from_b2','DB album created/filled from B2 folder',['prefix'=>$prefix,'created_photos'=>$created]);
    flash('DB album linked to B2 folder. Created '.$created.' photo rows.');
    go('?page=b2#b2-folder-'.$albumId);
}
function link_b2_prefix_to_album(): void {
    require_superadmin(); csrf(); b2_load_config();
    $prefix = trim((string)($_POST['prefix'] ?? ''), '/');
    $albumId = (int)($_POST['album_id'] ?? 0);
    if ($prefix === '' || $albumId <= 0) { flash('B2 folder and album are required.', 'err'); go('?page=b2'); }
    if (b2_prefix_image_count($prefix) <= 0) { flash('B2 folder was not linked: selected B2 folder has no images.', 'err'); go('?page=b2'); }
    $album = require_album_editable_by_id($albumId);
    $stats = b2_link_photo_rows_to_prefix($albumId, $prefix);
    try {
        if ((int)$stats['real_images'] <= 0) throw new RuntimeException('selected B2 folder has no images.');
        $found = album_db_photos_found_in_b2($albumId, $prefix);
        $totalSt = db()->prepare("SELECT COUNT(*) FROM photos WHERE album_id=? AND is_missing=0");
        $totalSt->execute([$albumId]);
        $total = (int)$totalSt->fetchColumn();
        if ($total > 0 && $found < $total) throw new RuntimeException($found.' of '.$total.' DB photos found in B2.');
    } catch (Throwable $e) {
        flash('B2 folder was not linked: '.$e->getMessage(), 'err');
        go('?page=b2#b2-folder-'.$albumId);
    }
    db()->prepare("UPDATE albums SET source_path=NULL, source_type='manual', updated_by=? WHERE source_path=? AND id<>?")->execute([$_SESSION['admin']['id'] ?? null, $prefix, $albumId]);
    db()->prepare("UPDATE albums SET source_type='b2_link', source_path=?, updated_by=? WHERE id=?")->execute([$prefix, $_SESSION['admin']['id'] ?? null, $albumId]);
    db()->prepare("UPDATE photos SET is_missing=0, updated_by=? WHERE album_id=? AND b2_key LIKE ?")->execute([$_SESSION['admin']['id'] ?? null, $albumId, $prefix.'/%']);
    $consolidated = consolidate_album_photo_rows_to_prefix($albumId, $prefix);
    $deduped = delete_missing_duplicate_photo_rows($albumId);
    persist_album_metadata_json($albumId);
    audit('album',$albumId,'link_b2_prefix','B2 folder linked to DB album',['prefix'=>$prefix,'created_photos'=>$stats['created'],'updated_photos'=>$stats['updated'],'relinked_photos'=>$stats['relinked'],'missing_photos'=>$stats['missing'],'consolidated_photos'=>$consolidated,'deduped_missing_photos'=>$deduped,'old_source_path'=>$album['source_path'] ?? null]);
    flash('B2 folder linked to album. Created '.$stats['created'].', updated '.$stats['updated'].', relinked '.$stats['relinked'].' photo rows'.($consolidated['deleted'] ? ', consolidated '.$consolidated['deleted'].' duplicates' : '').($deduped ? ', removed '.$deduped.' missing duplicates' : '').'.');
    go('?page=b2#b2-folder-'.$albumId);
}
function merge_b2_prefix_into_album(): void {
    require_superadmin(); csrf(); b2_load_config();
    $sourcePrefix = trim((string)($_POST['prefix'] ?? ''), '/');
    $albumId = (int)($_POST['album_id'] ?? 0);
    $targetPrefix = trim((string)($_POST['target_prefix'] ?? ''), '/');
    if ($sourcePrefix === '' || $albumId <= 0) { flash('B2 folder and album are required.', 'err'); go('?page=b2'); }
    $album = require_album_editable_by_id($albumId);
    if ($targetPrefix === '') {
        $targetPrefix = trim((string)($album['source_path'] ?? ''), '/');
    }
    if ($targetPrefix === '') {
        $targetPrefix = canonical_album_prefix((string)($album['title'] ?? ''), $album['event_date'] ?? null, $album['event_date_end'] ?? null);
    }
    if ($targetPrefix === '') { flash('Target B2 source path is required.', 'err'); go('?page=b2'); }

    $sourceFiles = count(b2_prefix_file_map($sourcePrefix, 20));
    $sourceImages = b2_prefix_image_count($sourcePrefix);
    if ($sourceFiles <= 0) { flash('Merge failed: source B2 folder has no files.', 'err'); go('?page=b2#b2-folder-'.$albumId); }

    $copied = 0; $skipped = 0; $deleted = 0;
    if (!canonical_path_equal($sourcePrefix, $targetPrefix)) {
        $copy = b2_copy_prefix_chunk($sourcePrefix, $targetPrefix);
        $copied = (int)($copy['copied'] ?? 0);
        $skipped = (int)($copy['skipped'] ?? 0);
        $missingTargets = (int)($copy['missing_targets'] ?? 0);
        if ((int)($copy['remaining'] ?? 0) > 0 || $missingTargets > 0) {
            flash('Merge copied '.$copied.' files, but '.(int)$copy['remaining'].' remain and '.$missingTargets.' target files are not verified yet. Run merge again to continue; DB was not changed yet.', 'err');
            go('?page=b2#b2-folder-'.$albumId);
        }
        $targetImages = b2_prefix_image_count($targetPrefix);
        if ($targetImages < $sourceImages) {
            flash('Merge stopped before DB update: target B2 folder has '.$targetImages.' images, source has '.$sourceImages.'.', 'err');
            go('?page=b2#b2-folder-'.$albumId);
        }
    }

    db()->beginTransaction();
    try {
        db()->prepare("UPDATE albums SET source_type='b2_link', source_path=?, updated_by=? WHERE id=?")
            ->execute([$targetPrefix, $_SESSION['admin']['id'] ?? null, $albumId]);
        db()->prepare("UPDATE albums SET source_path=NULL, source_type='manual', updated_by=? WHERE source_path=? AND id<>?")
            ->execute([$_SESSION['admin']['id'] ?? null, $targetPrefix, $albumId]);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        flash('Merge failed before photo sync: '.$e->getMessage(), 'err');
        go('?page=b2#b2-folder-'.$albumId);
    }

    $stats = b2_link_photo_rows_to_prefix($albumId, $targetPrefix);
    $consolidated = consolidate_album_photo_rows_to_prefix($albumId, $targetPrefix);
    $deduped = delete_missing_duplicate_photo_rows($albumId);
    $verify = b2_album_mapping_status($albumId, $targetPrefix);
    // Fatalinė tik reali problema: target tuščias arba tikslinio albumo eilutės neranda savo failų.
    // Orphan B2 failai (perteklius target folderyje be DB eilutės) NEBLOKUOJA merge — juos galima
    // išvalyti atskirai; source folderio ištrynimas jų vis tiek nepašalintų (jie kitame folderyje).
    if ((int)$verify['b2_images'] <= 0 || (int)$verify['db_found'] < (int)$verify['db_active']) {
        flash('Merge saved DB path, but verify reports mismatch: DB photos '.$verify['db_active'].', B2 images '.$verify['b2_images'].', found '.$verify['db_found'].', orphan B2 '.$verify['orphan_b2_images'].'.', 'err');
        go('?page=b2#b2-folder-'.$albumId);
    }

    // Sujungus: pašalinti dublikatą DB albumą, kuris rodė į source B2 folderį — jo nuotraukos
    // (dublikatai) ir failai jau atstovaujami tiksliniame albume. Tik DB įrašai; B2 nepaliestas čia.
    $mergedAlbums = 0; $mergedPhotoRows = 0;
    if (!canonical_path_equal($sourcePrefix, $targetPrefix)) {
        $dup = db()->prepare("SELECT id FROM albums WHERE source_path=? AND id<>?");
        $dup->execute([$sourcePrefix, $albumId]);
        foreach (array_map('intval', $dup->fetchAll(PDO::FETCH_COLUMN)) as $dupId) {
            db()->prepare("DELETE pt FROM photo_tags pt JOIN photos p ON p.id=pt.photo_id WHERE p.album_id=?")->execute([$dupId]);
            $delPh = db()->prepare("DELETE FROM photos WHERE album_id=?"); $delPh->execute([$dupId]); $mergedPhotoRows += $delPh->rowCount();
            db()->prepare("DELETE FROM album_tags WHERE album_id=?")->execute([$dupId]);
            db()->prepare("UPDATE albums SET cover_photo_id=NULL WHERE id=?")->execute([$dupId]);
            db()->prepare("DELETE FROM albums WHERE id=?")->execute([$dupId]);
            $mergedAlbums++;
        }
    }

    if (!canonical_path_equal($sourcePrefix, $targetPrefix)) {
        $delete = b2_delete_prefix_chunk($sourcePrefix);
        $deleted = (int)($delete['deleted'] ?? 0);
        if ((int)($delete['remaining'] ?? 0) > 0) {
            flash('Merge completed, but old B2 source still has '.(int)$delete['remaining'].' files. Run cleanup again from B2 Sync.', 'err');
            go('?page=b2#b2-folder-'.$albumId);
        }
    }
    persist_album_metadata_json($albumId);
    audit('album',$albumId,'merge_b2_prefix','B2 folder merged into existing DB album',[
        'source_prefix'=>$sourcePrefix,
        'target_prefix'=>$targetPrefix,
        'source_files'=>$sourceFiles,
        'source_images'=>$sourceImages,
        'copied'=>$copied,
        'skipped'=>$skipped,
        'deleted_old_files'=>$deleted,
        'created_photos'=>$stats['created'],
        'updated_photos'=>$stats['updated'],
        'relinked_photos'=>$stats['relinked'],
        'consolidated_photos'=>$consolidated,
        'deduped_missing_photos'=>$deduped,
        'removed_duplicate_albums'=>$mergedAlbums,
        'removed_duplicate_photo_rows'=>$mergedPhotoRows,
        'orphan_b2_left'=>(int)$verify['orphan_b2_images'],
    ]);
    $orphanNote = (int)$verify['orphan_b2_images'] > 0 ? ' Liko '.(int)$verify['orphan_b2_images'].' orphan B2 failų (perteklius target folderyje) — jei nori, išvalyk atskirai.' : '';
    $dupNote = $mergedAlbums > 0 ? ' Pašalintas '.$mergedAlbums.' dublikatas DB albumas ('.$mergedPhotoRows.' eilutės).' : '';
    flash('B2 folder merged into existing album. Target: '.$targetPrefix.'. Copied '.$copied.', skipped '.$skipped.', deleted old '.$deleted.'. Photos: created '.$stats['created'].', updated '.$stats['updated'].', relinked '.$stats['relinked'].'.'.$dupNote.$orphanNote);
    go('?page=b2#b2-folder-'.$albumId);
}
function render_b2_storage_panel(): void {
    $albums = db()->query("SELECT id,title,slug,event_date,source_path FROM albums ORDER BY COALESCE(event_date,'9999-12-31') DESC,id DESC LIMIT 500")->fetchAll();
    $rootRows = [];
    $folderRows = [];
    $folderError = '';
    try {
        $rootRows = b2_storage_root_rows();
        $folderRows = b2_storage_folder_rows();
        // Nukirptas sarasas rodytu velyvesnius aplankus kaip "nera B2" /
        // "DB/B2 mismatch" - o pagal tai lengva padaryti zalingu sutvarkymu.
        if (b2_last_list_truncated()) {
            $folderError = 'B2 sąrašas NEPILNAS (daugiau nei 100 000 failų) — žemiau esantys „nėra B2" / „mismatch" gali būti klaidingi. Nieko netaisykite pagal šią lentelę.';
        }
    } catch (Throwable $e) {
        $folderError = $e->getMessage();
    }
    $dbByPath = [];
    foreach ($albums as $a) {
        $path = trim((string)($a['source_path'] ?? ''), '/');
        if ($path !== '') $dbByPath[canonical_path_key($path)] = $a;
    }
    $realFolders = [];
    foreach ($folderRows as $row) {
        $realFolders[(string)$row['prefix']] = $row;
    }
    $realFolderKeys = [];
    foreach ($folderRows as $row) {
        $realFolderKeys[canonical_path_key((string)$row['prefix'])] = true;
    }

    echo '<div class="card" style="border-color:var(--accent-line)"><h2>Prieš masinę migraciją</h2><p class="muted">Kanoninio kelio keitimas masiškai keičia <code>photos.b2_key</code> ir <code>albums.source_path</code>. Automatinė kopija daroma kas 30 d. — prieš tokį darbą verta turėti šviežią.</p><p class="muted small">Paskutinė kopija: '.e(setting('last_db_backup', '') ?: 'nėra').'</p><form method="post" action="?action=db_backup_now" onsubmit="return confirm(\'Daryti DB kopiją į B2 dabar?\')"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="return_to" value="b2"><button class="primary">Daryti DB kopiją dabar</button></form></div>'.'<div class="card" id="b2-storage"><h2>B2 Storage</h2><p class="muted">Real bucket folders are listed from B2. Use copy + delete only when the real folder differs from the canonical DB target.</p>';
    if ($folderError !== '') {
        echo '<div class="flash err">B2 folder listing failed: '.e($folderError).'</div>';
    } else {
        echo '<h3>B2 cloud usage by root folder</h3><table><tr><th>Root folder</th><th>Total files</th><th>Total size</th></tr>';
        foreach ($rootRows as $row) {
            echo '<tr><td><strong>'.e($row['prefix']).'</strong></td><td>'.e((string)$row['files']).'</td><td>'.e(human_bytes((int)$row['bytes'])).'</td></tr>';
        }
        if (!$rootRows) echo '<tr><td colspan="3" class="muted">No B2 files found.</td></tr>';
        echo '</table>';
        // B2 grazina aplankus abecele, todel to paties albumo kanoninis ir senas
        // kelias atsidurdavo skirtingose sarasо vietose, o tarp ju isiterpdavo
        // svetimi albumai. Surikiuojam taip, kad susije keliai butu greta:
        // pirma grupuojam pagal albuma, grupeje - kanoninis kelias virsuje.
        $groupKey = [];
        foreach ($folderRows as $gi => $grow) {
            $gp = (string)($grow['prefix'] ?? '');
            $galbum = $dbByPath[canonical_path_key($gp)] ?? null;
            if (!$galbum) $galbum = likely_album_for_b2_prefix($gp, $albums);
            if ($galbum) {
                $gtarget = canonical_album_prefix((string)$galbum['title'], $galbum['event_date'] ?? null, $galbum['event_date_end'] ?? null);
                $groupKey[$gi] = sprintf('a%010d|%d|%s', (int)$galbum['id'], canonical_path_equivalent($gp, $gtarget) ? 0 : 1, $gp);
            } else {
                $groupKey[$gi] = 'z|0|'.$gp;
            }
        }
        uksort($folderRows, function ($x, $y) use ($groupKey) {
            return strcmp($groupKey[$x] ?? (string)$x, $groupKey[$y] ?? (string)$y);
        });
        echo '<h3>Real B2 folder structure</h3><div class="tablewrap"><table><tr><th>Albumas ir B2 aplankas</th><th>Nuotr.</th><th>Files</th><th>Size</th><th>Status</th><th>Action</th></tr>';
        foreach ($folderRows as $row) {
            $prefix = (string)$row['prefix'];
            $imageCount = (int)($row['images'] ?? 0);
            $album = $dbByPath[canonical_path_key($prefix)] ?? null;
            $rowId = $album ? ' id="b2-folder-'.e((string)$album['id']).'"' : '';
            $status = $album ? 'DB linked to real B2 folder' : 'not linked in DB';
            $roleHtml = '';   // butina nunulinti: kitaip zyme persineštu is praeitos eilutes
            $action = '<span class="muted small">No action needed</span>';
            $dbCell = '<span class="muted">n/a</span>';
            if ($album) {
                $target = canonical_album_prefix((string)$album['title'], $album['event_date'] ?? null, $album['event_date_end'] ?? null);
                $isCanonical = canonical_path_equivalent($prefix, $target);
                // Dvi to paties albumo eilutes atrodo vienodai, todel is ju
                // neaisku, kuri yra tikslas, o kuri senas kelias. Pazymim.
                $roleHtml = $isCanonical
                    ? '<span class="role role-canon">kanoninis kelias</span>'
                    : '<span class="role role-old">senas kelias</span>';
                $duplicateFolders = duplicate_b2_prefixes_for_album($album, $folderRows, $prefix);
                $targetExists = isset($realFolderKeys[canonical_path_key($target)]);
                $verify = b2_album_mapping_status_from_keys((int)$album['id'], (array)($row['image_keys'] ?? []));
                $status = $isCanonical ? 'DB and B2 match canonical' : 'real B2 folder differs from canonical';
                if ($imageCount === 0) $status = 'DB linked, no B2 images';
                if ($duplicateFolders) $status = 'DB linked, duplicate B2 folders exist';
                if (!$verify['ok']) $status = 'DB/B2 mismatch';
                $statusDetails = '<br><span class="muted small">DB photos: '.e((string)$verify['db_active']).' · B2 images: '.e((string)$verify['b2_images']).' · found: '.e((string)$verify['db_found']).' · missing rows: '.e((string)$verify['missing_db_rows']).' · orphan B2: '.e((string)$verify['orphan_b2_images']).'</span>';
                $dbCell = '<a class="btn mini" href="?page=album_edit&id='.e($album['id']).'">'.e($album['title']).'</a>';
                $action = '<div class="actions" style="margin:0;gap:6px;align-items:flex-start;flex-direction:column">';
                if (!$isCanonical && $targetExists && !canonical_path_equal($prefix, $target)) {
                    $action .= '<form method="post" action="?action=merge_b2_prefix_into_album" class="actions b2-merge-form" style="margin:0" data-mode="canonical" data-album="'.e($album['title']).'" data-current="'.e($prefix).'" data-target="'.e($target).'" data-files="'.e((string)$row['files']).'"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="prefix" value="'.e($prefix).'"><input type="hidden" name="album_id" value="'.e($album['id']).'"><input type="hidden" name="target_prefix" value="'.e($target).'"><button class="primary mini">Sulieti į kanoninį</button><span class="muted small">Kanoniniame kelyje jau yra dalis failų (nebaigta migracija). Šis veiksmas ją pabaigia: '.e($target).'</span></form>';
                } elseif (!$isCanonical) {
                    $action .= '<form method="post" action="?action=save_storage_path" class="actions b2-move-form" style="margin:0" data-mode="canonical" data-album="'.e($album['title']).'" data-current="'.e($prefix).'" data-target="'.e($target).'" data-files="'.e((string)$row['files']).'"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="id" value="'.e($album['id']).'"><input type="hidden" name="original_source_path" value="'.e((string)($album['source_path'] ?? '')).'"><input type="hidden" name="detected_source_path" value="'.e($prefix).'"><input type="hidden" name="source_path" value="'.e($target).'"><input type="hidden" name="return_to" value="b2"><input type="hidden" name="confirm_storage_migration" value="1"><button class="primary mini">Make canonical</button><span class="muted small">Target: '.e($target).'</span></form>';
                }
                foreach ($duplicateFolders as $dup) {
                    $dupPrefix = (string)($dup['prefix'] ?? '');
                    if ($dupPrefix === '') continue;
                    // Jei dublikatas yra kaip tik kanoninis kelias, tai ne
                    // dublikatas, o migracijos tikslas. Traukti ji atgal i sena
                    // aplanka reikstu migracija atsukti - tokio veiksmo nesiulom.
                    if (canonical_path_equal($dupPrefix, $target)) continue;
                    $action .= '<form method="post" action="?action=merge_b2_prefix_into_album" class="actions b2-merge-form" style="margin:0" data-mode="merge-duplicate" data-album="'.e($album['title']).'" data-current="'.e($dupPrefix).'" data-target="'.e($prefix).'" data-files="'.e((string)($dup['files'] ?? 0)).'"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="prefix" value="'.e($dupPrefix).'"><input type="hidden" name="album_id" value="'.e($album['id']).'"><input type="hidden" name="target_prefix" value="'.e($prefix).'"><button class="primary mini">Merge duplicate here</button><span class="muted small">'.e($dupPrefix).' → '.e($prefix).'</span></form>';
                }
                if ($duplicateFolders) {
                    $action .= '<span class="muted small">Savas pavadinimas neleidžiamas, kol yra dublikatinių B2 aplankų — pirma sulieti.</span>';
                } else {
                    $action .= '<form method="post" action="?action=save_storage_path" class="actions b2-move-form" style="margin:0" data-mode="custom" data-album="'.e($album['title']).'" data-current="'.e($prefix).'" data-target="" data-files="'.e((string)$row['files']).'"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="id" value="'.e($album['id']).'"><input type="hidden" name="original_source_path" value="'.e((string)($album['source_path'] ?? '')).'"><input type="hidden" name="detected_source_path" value="'.e($prefix).'"><input type="hidden" name="return_to" value="b2"><input type="hidden" name="confirm_storage_migration" value="1"><input name="source_path" required value="'.e($target).'" style="min-width:280px"><button class="mini">Make custom</button></form>';
                }
                if ((int)$verify['missing_db_rows'] > 0) {
                    $action .= '<form method="post" action="?action=cleanup_missing_photo_rows" class="actions" style="margin:0" onsubmit="return confirm(\'Sutvarkyti '.e((string)$verify['missing_db_rows']).' missing DB įrašų? Eilutės, kurių failai rasti B2, bus atstatytos; kurių failų nėra — ištrintos iš DB. B2 failai neliečiami.\')"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="album_id" value="'.e($album['id']).'"><input type="hidden" name="return_to" value="b2"><button class="mini">Clean missing DB rows ('.e((string)$verify['missing_db_rows']).')</button></form>';
                }
                $action .= '</div>';
            } else {
                $statusDetails = '';
                $roleHtml = '';
                $likelyAlbum = likely_album_for_b2_prefix($prefix, $albums);
                if ($likelyAlbum) {
                    $likelyTarget = canonical_album_prefix((string)$likelyAlbum['title'], $likelyAlbum['event_date'] ?? null, $likelyAlbum['event_date_end'] ?? null);
                    $roleHtml = canonical_path_equivalent($prefix, $likelyTarget)
                        ? '<span class="role role-canon">kanoninis kelias</span>'
                        : '<span class="role role-old">senas kelias</span>';
                }
                $albumOptions = '<option value="0">Link to DB album...</option>';
                foreach ($albums as $a) {
                    $albumOptions .= '<option value="'.e($a['id']).'"'.($likelyAlbum && (int)$likelyAlbum['id'] === (int)$a['id'] ? ' selected' : '').'>'.e($a['title']).'</option>';
                }
                if ($likelyAlbum) {
                    $mergeTarget = trim((string)($likelyAlbum['source_path'] ?? ''), '/');
                    if ($mergeTarget === '') $mergeTarget = canonical_album_prefix((string)$likelyAlbum['title'], $likelyAlbum['event_date'] ?? null, $likelyAlbum['event_date_end'] ?? null);
                    $dbCell = '<a class="btn mini" href="?page=album_edit&id='.e($likelyAlbum['id']).'">'.e($likelyAlbum['title']).'</a><br><span class="muted small">Likely existing DB album. Source: '.e((string)($likelyAlbum['source_path'] ?? '')).'</span>';
                    $status = 'not linked, likely duplicate of DB album';
                    $action = '<form method="post" action="?action=merge_b2_prefix_into_album" class="actions b2-merge-form" style="margin:0 0 6px 0;gap:6px;align-items:flex-start;flex-direction:column" data-album="'.e($likelyAlbum['title']).'" data-current="'.e($prefix).'" data-target="'.e($mergeTarget).'" data-files="'.e((string)$row['files']).'"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="prefix" value="'.e($prefix).'"><input type="hidden" name="album_id" value="'.e($likelyAlbum['id']).'"><label class="small muted">Merge target B2 source path</label><input name="target_prefix" required value="'.e($mergeTarget).'" style="min-width:320px"><button class="primary mini">Merge into existing DB album</button></form>';
                    $action .= '<span class="muted small">Create DB album disabled to avoid duplicate album rows.</span>';
                } else {
                    $action = '<form method="post" action="?action=merge_b2_prefix_into_album" class="actions b2-merge-form" style="margin:0 0 6px 0;gap:6px;align-items:flex-start;flex-direction:column" data-current="'.e($prefix).'" data-target="'.e($prefix).'" data-files="'.e((string)$row['files']).'"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="prefix" value="'.e($prefix).'"><select name="album_id" style="width:24ch;max-width:24ch">'.$albumOptions.'</select><label class="small muted">Merge target B2 source path</label><input name="target_prefix" required value="'.e($prefix).'" style="min-width:320px"><button class="mini">Merge/link to DB album</button></form>';
                    $action .= '<form method="post" action="?action=create_db_album_from_b2_prefix" class="actions" style="margin:0"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="prefix" value="'.e($prefix).'"><button class="primary mini">Create DB album</button></form>';
                }
            }
            // Ilgas busenos tekstas ir veiksmu krūva issipusdavo i kelias
            // desimtis pikseliu auksčio, todel per visa ekrana matydavosi vos
            // keturi albumai. Dabar eilute yra viena linija: trumpas zenkliukas
            // ir ⓘ, o visos detales bei antriniai veiksmai - isskleidziamoje
            // eiluteje po ja.
            [$sevClass, $sevLabel] = b2_status_severity($status);
            $detailId = 'b2d-'.md5($prefix);
            $statusCell = '<span class="spill s-'.$sevClass.'" title="'.e($status).'"><span class="d"></span>'.e($sevLabel).'</span>'
                . ' <button type="button" class="infobtn" data-detail="'.e($detailId).'" aria-expanded="false" aria-controls="'.e($detailId).'" title="Rodyti detales">i</button>';
            // "albums/YYYY/" kartojasi kiekvienoje eiluteje ir nieko neskiria -
            // rodom ji blankiai, kad akis kabintusi uz pacio pavadinimo.
            $prefixHtml = preg_match('~^(albums/\d{4}/)(.*)$~', $prefix, $pm)
                ? '<span class="dim">'.e(substr($pm[1], 7)).'</span><strong>'.e($pm[2]).'</strong>'
                : '<strong>'.e($prefix).'</strong>';
            // Veiksmu buna iki penkiu, todel jie deda i isskleidziama meniu,
            // o ne i stulpeli. <details> duoda veikianti dropdown be JS ir su
            // klaviatura, o formos viduje lieka tokios pat.
            $hasActions = str_contains($action, '<form');
            $actionCell = $hasActions
                ? '<details class="rowmenu"><summary>'.e(b2_primary_hint($status)).'</summary><div class="rowmenu-pop">'.$action.'</div></details>'
                : '<span class="muted small">—</span>';
            echo '<tr'.$rowId.' class="b2-row"><td class="b2-album">'.$dbCell.'<div class="b2-prefix">'.$prefixHtml.(isset($roleHtml) && $roleHtml !== '' ? ' '.$roleHtml : '').'</div></td><td class="num">'.e((string)$imageCount).'</td><td class="num">'.e((string)$row['files']).'</td><td class="num">'.e(human_bytes((int)$row['bytes'])).'</td><td>'.$statusCell.'</td><td class="b2-quick">'.$actionCell.'</td></tr>';
            echo '<tr class="b2-detail" id="'.e($detailId).'" hidden><td colspan="6"><div class="b2-detail-in"><div class="b2-detail-status"><strong>'.e($status).'</strong>'.($album ? '<div class=\"muted small\" style=\"margin-top:4px\">DB source: '.e((string)($album['source_path'] ?? '')).'</div>' : '').$statusDetails.'</div></div></td></tr>';
        }
        if (!$folderRows) echo '<tr><td colspan="6" class="muted">No B2 files found.</td></tr>';
        echo '</table>';
        echo '<script>(function(){'
            .'document.querySelectorAll(".infobtn[data-detail]").forEach(function(b){b.addEventListener("click",function(){var r=document.getElementById(b.getAttribute("data-detail"));if(!r)return;var open=r.hasAttribute("hidden");if(open){r.removeAttribute("hidden");}else{r.setAttribute("hidden","");}b.setAttribute("aria-expanded",String(open));b.classList.toggle("on",open);});});'
            // Vienu metu atviras tik vienas meniu; klik salia arba Esc uzdaro.
            .'var menus=document.querySelectorAll("details.rowmenu");'
            .'menus.forEach(function(m){m.addEventListener("toggle",function(){if(m.open)menus.forEach(function(o){if(o!==m)o.open=false;});});});'
            .'document.addEventListener("click",function(e){menus.forEach(function(m){if(m.open&&!m.contains(e.target))m.open=false;});});'
            .'document.addEventListener("keydown",function(e){if(e.key==="Escape")menus.forEach(function(m){m.open=false;});});'
            .'})();</script>';

        $missingRows = [];
        $countPhotos = db()->prepare("SELECT COUNT(*) total, COALESCE(SUM(is_missing=1),0) missing FROM photos WHERE album_id=?");
        foreach ($albums as $a) {
            $sourcePath = trim((string)($a['source_path'] ?? ''), '/');
            if ($sourcePath === '') continue;
            if (isset($realFolderKeys[canonical_path_key($sourcePath)])) continue;
            $countPhotos->execute([(int)$a['id']]);
            $counts = $countPhotos->fetch() ?: ['total' => 0, 'missing' => 0];
            $missingRows[] = [$a, (int)($counts['total'] ?? 0), (int)($counts['missing'] ?? 0)];
        }
        if ($missingRows) {
            echo '<h3 style="margin-top:18px">DB albums missing in real B2</h3><p class="muted small">These albums exist in DB, but their assigned source path is not present in the real B2 folder list. Public gallery will not show their photos until B2 has files again.</p><table><tr><th>DB album</th><th>DB source path</th><th>DB photos</th><th>Action</th></tr>';
            foreach ($missingRows as [$a, $totalPhotos, $missingPhotos]) {
                echo '<tr><td><strong>'.e($a['title']).'</strong><br><span class="muted small">ID '.e((string)$a['id']).' · '.e((string)($a['event_date'] ?? '')).'</span></td><td>'.e((string)$a['source_path']).'</td><td>'.e((string)$totalPhotos).' total'.($missingPhotos ? ' · '.e((string)$missingPhotos).' missing' : '').'</td><td><a class="btn mini" href="?page=album_edit&id='.e($a['id']).'">Upload / manage</a></td></tr>';
            }
            echo '</table>';
        }
    }

    echo '<div id="b2MoveProgress" style="display:none;position:fixed;inset:0;z-index:90;background:rgba(5,8,10,.76);backdrop-filter:blur(3px);place-items:center"><div class="card" style="max-width:560px;width:calc(100% - 40px)"><h2 style="margin-top:0">Moving B2 storage...</h2><p class="muted">Copy + delete is running on the server. Keep this tab open until the page reloads.</p><div style="height:10px;border-radius:999px;background:#0d1116;border:1px solid var(--line);overflow:hidden"><div style="width:45%;height:100%;background:#995f20;animation:b2move 1.2s ease-in-out infinite"></div></div><p class="small muted" id="b2MoveProgressText" style="margin-bottom:0"></p></div></div><style>@keyframes b2move{0%{transform:translateX(-110%)}100%{transform:translateX(230%)}}tr:target{outline:2px solid var(--accent-line);outline-offset:-2px;background:var(--accent-soft)}</style><script>(function(){function showProgress(textValue){var overlay=document.getElementById("b2MoveProgress");var text=document.getElementById("b2MoveProgressText");if(text)text.textContent=textValue;if(overlay)overlay.style.display="grid";}document.querySelectorAll(".b2-move-form").forEach(function(form){form.addEventListener("submit",function(e){var files=form.getAttribute("data-files")||"0";var current=form.getAttribute("data-current")||"";var input=form.querySelector("[name=source_path]");var target=(input&&input.value?input.value:form.getAttribute("data-target")||"").trim();var album=form.getAttribute("data-album")||"this album";if(!target){e.preventDefault();return false;}var label=form.getAttribute("data-mode")==="canonical"?"canonical":"custom";var msg="Move B2 storage to "+label+" path?\\n\\nAlbum: "+album+"\\nFiles: "+files+"\\nFrom: "+current+"\\nTo: "+target+"\\n\\nBus nukopijuoti TIK šio albumo failai (jo nuotraukos, JPG peržiūros, metaduomenys), patikrintas SHA1, perjungta DB ir ištrintos tik patikrintos jų senos kopijos. Kitiems albumams priklausantys ar nepriskirti failai (pvz. perkeltų nuotraukų kopijos) liks sename aplanke.";if(!window.confirm(msg)){e.preventDefault();return false;}showProgress("Moving "+files+" files from "+current+" to "+target);});});document.querySelectorAll(".b2-merge-form").forEach(function(form){form.addEventListener("submit",function(e){var files=form.getAttribute("data-files")||"0";var current=form.getAttribute("data-current")||"";var input=form.querySelector("[name=target_prefix]");var target=(input&&input.value?input.value:form.getAttribute("data-target")||"").trim();var album=form.getAttribute("data-album")||"selected DB album";if(!target){e.preventDefault();return false;}var msg="Merge this B2 folder into one DB album?\\n\\nAlbum: "+album+"\\nFiles: "+files+"\\nFrom B2 folder: "+current+"\\nSingle album source path: "+target+"\\n\\nThis will copy files to the target path, update DB photos from target, and delete the old B2 folder after verification.";if(!window.confirm(msg)){e.preventDefault();return false;}showProgress("Merging "+files+" files from "+current+" into "+target);});});})();</script></div>';
}
function b2_page(): void {
    require_superadmin();
    b2_load_config();
    head('B2 Sync');
    $defaultBucket=defined('B2_BUCKET') ? (string)B2_BUCKET : '';
    // Eigai: nuo kurio paleidimo ieskoti naujo ir ar kuris nors dar vyksta
    // (atidarius puslapi is naujo eiga rodoma toliau).
    $lastRunId = 0; $runningRunId = 0;
    try {
        $lastRunId = (int)db()->query("SELECT COALESCE(MAX(id),0) FROM b2_sync_runs")->fetchColumn();
        $runningRunId = (int)db()->query("SELECT COALESCE(MAX(id),0) FROM b2_sync_runs WHERE status='running' AND started_at > NOW() - INTERVAL 15 MINUTE")->fetchColumn();
    } catch (Throwable $e) {}
    echo '<h1>B2 Sync</h1><div class="card"><form method="post" action="?action=b2_log" id="b2SyncForm" data-last-run="'.e((string)$lastRunId).'" data-running-run="'.e((string)$runningRunId).'"><input type="hidden" name="_token" value="'.e(token()).'"><div class="formgrid"><div><label>Bucket</label><input name="bucket" value="'.e($defaultBucket).'"></div><div><label>Prefix</label><input name="prefix" value="'.e(setting('b2_prefix')).'" placeholder="Empty = same allowed albums as frontend"></div><div><label>Mode</label><select name="mode"><option value="scan + update existing" selected>scan + update existing</option><option value="scan + create missing">scan + create missing</option><option value="scan only">scan only</option></select></div></div><div class="actions"><label><input type="checkbox" name="mark_missing" value="1"> Suderinti missing žymes: atstatyti rastas B2, pažymėti dingusias (tik pilnam B2 sąrašui, su saugikliu)</label><label><input type="checkbox" name="dry_run" value="1"> Dry run (nieko nekeisti, tik parodyti)</label></div><p class="muted">Sync reads B2 storage and updates DB overlay rows. Empty prefix uses the same allowed album prefixes as the public frontend. B2 folder names are virtual; deleting every file under a folder makes that folder disappear from B2 listings.</p><button class="primary" id="b2SyncBtn">Run sync</button></form>'
        .'<div id="b2SyncProgress" style="display:none;margin-top:14px">'
        .'<div style="height:12px;border-radius:999px;background:var(--card-bg,#0d1116);border:1px solid var(--line);overflow:hidden"><div id="b2SyncBar" style="width:0;height:100%;background:var(--accent-line,#995f20);transition:width .4s ease"></div></div>'
        .'<p id="b2SyncText" style="margin:8px 0 2px;font-weight:600"></p><p id="b2SyncSub" class="small muted" style="margin:0"></p></div></div>'
        .'<script>(function(){'
        .'var form=document.getElementById("b2SyncForm");if(!form)return;'
        .'var box=document.getElementById("b2SyncProgress"),bar=document.getElementById("b2SyncBar"),txt=document.getElementById("b2SyncText"),sub=document.getElementById("b2SyncSub"),btn=document.getElementById("b2SyncBtn");'
        .'var after=+form.getAttribute("data-last-run")||0,runId=+form.getAttribute("data-running-run")||0,timer=null;'
        .'var names={start:"Pradedama",list:"Skaitomas B2 sąrašas",files:"Tikrinami failai",reconcile:"Derinamos missing žymės",albums:"Baigiami albumai"};'
        .'function num(n){return (+n||0).toLocaleString("lt-LT");}'
        .'function fmt(x){if(x==null)return "…";x=Math.max(0,Math.round(x));var m=Math.floor(x/60),s=x%60;return (m?m+" min ":"")+s+" s";}'
        .'function render(r){box.style.display="block";'
        .'if(r.status==="running"){var st=(r.stages&&r.stages[r.stage])||{done:0,total:0};var tot=st.total||(r.stage==="list"?r.est_files:0);'
        .'bar.style.width=(r.pct||0)+"%";'
        .'txt.textContent=(names[r.stage]||r.stage)+": "+num(st.done)+(tot?" / "+(st.total?"":"~")+num(tot):"")+" įrašų";'
        .'sub.textContent=(r.pct!=null?r.pct.toFixed(1).replace(".",",")+" %":"")+" · praėjo "+fmt(r.elapsed)+" · liko ~"+fmt(r.eta);return;}'
        .'var res=r.result||{};bar.style.width=r.status==="finished"?"100%":bar.style.width;'
        .'if(r.status==="finished"){txt.textContent="Baigta"+(res.dry_run?" (DRY RUN — niekas nepakeista)":"")+": "+num(res.photos)+" nuotr., B2 failų "+num(res.b2_files)+(res.listing_complete===false?" (SĄRAŠAS NEPILNAS)":"");'
        .'sub.textContent=(res.missing_restored!=null?"Missing: "+num(res.missing_restored)+" atstatyta, "+num(res.missing_marked)+" pažymėta"+(res.missing_skipped?" — "+res.missing_skipped:"")+". ":"")+"Lentelė žemiau atsinaujins perkrovus puslapį.";}'
        .'else{txt.textContent="Nepavyko: "+(res.error||r.status);sub.textContent="";}}'
        .'function stop(){clearTimeout(timer);timer=null;btn.disabled=false;}'
        .'function poll(){var url=runId?"?action=b2_sync_status&id="+runId:"?action=b2_sync_status&after="+after;'
        .'fetch(url,{credentials:"same-origin",headers:{"Accept":"application/json","X-Requested-With":"XMLHttpRequest"}}).then(function(x){return x.json();})'
        .'.then(function(j){var r=j&&j.run;if(r){runId=r.id;render(r);if(r.status!=="running"){stop();return;}}timer=setTimeout(poll,1000);})'
        .'.catch(function(){timer=setTimeout(poll,2000);});}'
        .'form.addEventListener("submit",function(e){e.preventDefault();if(timer)return;runId=0;btn.disabled=true;box.style.display="block";bar.style.width="0";txt.textContent="Paleidžiama…";sub.textContent="";'
        .'fetch(form.getAttribute("action"),{method:"POST",body:new FormData(form),credentials:"same-origin",headers:{"X-Requested-With":"XMLHttpRequest","Accept":"application/json"}})'
        .'.then(function(x){return x.json().catch(function(){return {ok:false,error:"HTTP "+x.status};});})'
        .'.then(function(j){if(j&&j.ok===false&&!runId){stop();txt.textContent=j.error||"Klaida";}})'
        // Rysys gali nutrukti (pvz. Cloudflare 100 s riba) - sync tesiasi serveryje, eiga rodo poll.
        .'.catch(function(){});'
        .'timer=setTimeout(poll,700);});'
        .'if(runId){btn.disabled=true;poll();}'
        .'})();</script>';
    render_b2_sync_runs();
    // Atstatymas po klaidingo zymejimo. "is_missing" yra tik veliava - failai B2
    // lieka vietoje, - todel ji nuimti saugu ir grizti atgal galima bet kada.
    // Reikalingas todel, kad nebaigtas B2 sarasas gali pazymeti tukstancius
    // tvarkingu eiluciu, o atsukti tai kitaip butu galima tik per SQL.
    $missingNow = (int)db()->query("SELECT COUNT(*) FROM photos WHERE is_missing=1")->fetchColumn();
    if ($missingNow > 0) {
        $confirmMsg = 'Grazinti '.$missingNow.' nuotraukas i galerija?';
        echo '<div class="card" style="margin-top:14px;border-color:var(--err-line)">'
            .'<h2>'.e((string)$missingNow).' nuotraukos nerodomos galerijoje</h2>'
            .'<p class="muted">Jos pažymėtos kaip dingusios iš saugyklos. Jei failai ten vis dėlto yra, '
            .'tai klaidinga žymė — dažniausiai ją palieka nebaigtas patikrinimas. Mygtukas grąžina nuotraukas '
            .'atgal į galeriją; patys failai neliečiami, o klaidos atveju žymė uždedama iš naujo per patikrinimą.</p>'
            .'<form method="post" action="?action=clear_missing_flags" onsubmit="return confirm(&quot;' . e($confirmMsg) . '&quot;)">'
            .'<input type="hidden" name="_token" value="'.e(token()).'">'
            .'<button class="btn" style="border-color:var(--err-line)">Grąžinti nuotraukas į galeriją</button>'
            .'</form></div>';
    }
    render_b2_storage_panel();
    foot('B2 Sync');
}
// Paskutiniai B2 Sync paleidimai - kad butu matyti ir nutruke ('running')
// ar nepavyke, o ne tik audit'e esantys sekmingi.
function render_b2_sync_runs(): void {
    try {
        $runs = db()->query("SELECT id,prefix,mode,options,status,result,started_at,finished_at FROM b2_sync_runs ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return; }
    if (!$runs) return;
    echo '<div class="card"><h2>Paskutiniai B2 Sync paleidimai</h2><table><tr><th>#</th><th>Pradžia</th><th>Režimas</th><th>Būsena</th><th>Rezultatas</th></tr>';
    foreach ($runs as $r) {
        $opts = json_decode((string)($r['options'] ?? ''), true) ?: [];
        $res = json_decode((string)($r['result'] ?? ''), true) ?: [];
        $bits = [];
        foreach (['photos'=>'nuotr.','b2_files'=>'B2 failų','missing_restored'=>'atstatyta','missing_marked'=>'pažymėta missing','restored'=>'atstatyta','marked'=>'pažymėta missing','error'=>'klaida'] as $k => $label) {
            if (isset($res[$k]) && $res[$k] !== '' ) $bits[] = $label.': '.(is_scalar($res[$k]) ? (string)$res[$k] : json_encode($res[$k]));
        }
        if (isset($res['listing_complete']) && !$res['listing_complete']) $bits[] = 'SĄRAŠAS NEPILNAS';
        foreach (['missing_skipped','mark_skipped'] as $k) if (!empty($res[$k])) $bits[] = (string)$res[$k];
        $status = (string)$r['status'];
        if ($status === 'running' && strtotime((string)$r['started_at']) < time() - 900) $status = 'nutrūko?';
        echo '<tr><td>'.e((string)$r['id']).'</td><td class="small">'.e((string)$r['started_at']).'</td><td class="small">'.e((string)$r['mode']).(!empty($opts['mark_missing']) ? ' + missing' : '').(!empty($opts['dry_run']) ? ' (dry)' : '').($r['prefix'] !== '' && $r['prefix'] !== null ? '<br>'.e((string)$r['prefix']) : '').'</td><td><span class="badge">'.e($status).'</span></td><td class="small">'.e(implode(' · ', $bits)).'</td></tr>';
    }
    echo '</table></div>';
}
/**
 * Inbox - laikina nariu ikelimu talpykla.
 *
 * Nariai kelia per vieša upload.klajunas.lt puslapi (server/klajunas.lt/upload/index.php): jis
 * praso bendro klubo kodo, laikino pavadinimo ir keliancio vardo, o failus
 * deda i B2 prefiksa "inbox/<data>-<pavadinimas>-<zetonas>/originals/".
 *
 * Kodel ne tiesiai i albuma: nario siunta beveik niekada nesutampa su albumu -
 * ji buna be datos, su atsitiktine tvarka ir dazniausiai tik dalis renginio.
 * Todel ji laukia cia, kol kas nors nusprendzia, i kuri albuma ji keliauja.
 *
 * Perkelimas nieko nesiuncia per PHP: B2 kopijuoja failus savo viduje
 * (b2_copy_prefix_chunk), o nuotrauku eilutes sukuria tas pats kelias, kaip ir
 * "create DB album from B2 folder" - b2_create_photo_rows_from_prefix().
 * Originalai inbox'e lieka tol, kol ju rankomis neistrinsi - taip po nevykusio
 * perkelimo yra i ka grizti.
 *
 * Lenteles tokios pacios kaip server/klajunas.lt/upload/index.php
 * inbox_ensure_schema() - keiciant viena vieta, keisti abi.
 */
function ensure_inbox_schema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS inbox_batches (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token CHAR(16) NOT NULL UNIQUE, title VARCHAR(191) NOT NULL, uploader_name VARCHAR(191) NOT NULL, note VARCHAR(500) NULL, event_date DATE NULL, b2_prefix VARCHAR(500) NOT NULL, files_count INT UNSIGNED NOT NULL DEFAULT 0, bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(32) NOT NULL DEFAULT 'open', album_id BIGINT UNSIGNED NULL, imported_at TIMESTAMP NULL, ip_address VARCHAR(45) NULL, user_agent VARCHAR(255) NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX inbox_batches_status_idx(status,created_at)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS inbox_files (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, batch_id BIGINT UNSIGNED NOT NULL, b2_key VARCHAR(500) NOT NULL, thumb_b2_key VARCHAR(500) NULL, original_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(191) NULL, file_size BIGINT UNSIGNED NOT NULL DEFAULT 0, checksum_sha1 CHAR(40) NULL, width INT UNSIGNED NULL, height INT UNSIGNED NULL, taken_at TIMESTAMP NULL, status VARCHAR(32) NOT NULL DEFAULT 'stored', photo_id BIGINT UNSIGNED NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, INDEX inbox_files_batch_idx(batch_id), INDEX inbox_files_name_idx(batch_id,original_filename)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}
function inbox_batch_row(int $id): ?array {
    if ($id <= 0) return null;
    $st = db()->prepare("SELECT * FROM inbox_batches WHERE id=? LIMIT 1");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function inbox_pending_count(): int {
    try { ensure_inbox_schema(); } catch (Throwable $e) { return 0; }
    return (int)db()->query("SELECT COUNT(*) FROM inbox_batches WHERE status<>'imported'")->fetchColumn();
}
/**
 * Miniatiura (arba originalas su full=1) is B2. Per img.php sito nepadarysi:
 * jis atiduoda tik "albums/" prefiksa, o inbox failai specialiai guli uz jo.
 */
function inbox_thumb(): void {
    require_superadmin();
    $st = db()->prepare("SELECT b2_key,thumb_b2_key,mime_type,original_filename FROM inbox_files WHERE id=? LIMIT 1");
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) { http_response_code(404); exit('not found'); }
    $full = !empty($_GET['full']);
    $key = trim((string)($full ? $f['b2_key'] : ($f['thumb_b2_key'] ?? '')), '/');
    if ($key === '') { http_response_code(404); exit('no preview'); }
    b2_load_config();
    try {
        $auth = b2_auth();
        $bytes = b2_download_key($auth, defined('B2_BUCKET') ? (string)B2_BUCKET : '', $key);
    } catch (Throwable $e) { http_response_code(502); exit('b2 error'); }
    header('Content-Type: '.($full ? (string)($f['mime_type'] ?: 'application/octet-stream') : 'image/jpeg'));
    header('Content-Length: '.strlen($bytes));
    header('Content-Disposition: inline; filename="'.safe_b2_name((string)$f['original_filename']).'"');
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}
/**
 * Kodas ir jungiklis tiesiai Uploads lange. Tie patys du nustatymai yra ir
 * Settings lange, bet busena matai butent cia, o jungimas per kita puslapi buvo
 * zingsnis be reikalo.
 */
function inbox_settings(): void {
    require_superadmin(); csrf(); ensure_inbox_schema();
    $msgs = [];
    if (array_key_exists('code', $_POST)) {
        $code = trim((string)$_POST['code']);
        set_setting('inbox_access_code', $code);
        audit('settings', null, 'inbox_code', $code === '' ? 'Nariu ikelimo kodas isvalytas' : 'Nariu ikelimo kodas pakeistas');
        $msgs[] = $code === '' ? 'Kodas išvalytas — įkėlimas nieko nepriims.' : 'Kodas išsaugotas.';
    }
    if (array_key_exists('enabled', $_POST)) {
        $enable = (string)$_POST['enabled'] === '1';
        set_setting('inbox_enabled', $enable ? '1' : '0');
        audit('settings', null, 'inbox_enabled', $enable ? 'Nariu ikelimas ijungtas' : 'Nariu ikelimas isjungtas');
        if (!$enable) $msgs[] = 'Įkėlimas išjungtas.';
        elseif (trim(setting('inbox_access_code')) === '') $msgs[] = 'Įjungta, bet kol nėra kodo, puslapis vis tiek nieko nepriima.';
        else $msgs[] = 'Įkėlimas įjungtas.';
    }
    flash($msgs ? implode(' ', $msgs) : 'Niekas nepakeista.');
    go('?page=inbox');
}
function inbox_page(): void {
    require_superadmin();
    ensure_inbox_schema();
    $batchId = (int)($_GET['batch'] ?? 0);
    if ($batchId > 0) { inbox_batch_page($batchId); return; }

    head('Uploads');
    // Perkeltos siuntos (status='imported') rodomos tik archyve - pagrindiniame
    // sarase lieka tai, ka dar reikia sutvarkyti.
    $archive = (string)($_GET['archive'] ?? '') === '1';
    $rows = db()->query("SELECT b.*, a.title album_title FROM inbox_batches b LEFT JOIN albums a ON a.id=b.album_id WHERE b.status".($archive ? "='imported'" : "<>'imported'")." ORDER BY b.created_at DESC LIMIT 200")->fetchAll();
    $archivedCount = (int)db()->query("SELECT COUNT(*) FROM inbox_batches WHERE status='imported'")->fetchColumn();
    $pendingCount = (int)db()->query("SELECT COUNT(*) FROM inbox_batches WHERE status<>'imported'")->fetchColumn();
    $code = trim(setting('inbox_access_code'));
    $on = setting('inbox_enabled', '1') !== '0';
    echo '<h1>Uploads - nuotraukų įkėlimas</h1>';
    // Busena be jungiklio buvo pusė atsakymo: matai, kad išjungta, o jungti eini
    // i kita puslapi. Kodas ir jungiklis sedi tuose paciuose settings raktuose
    // kaip ir Settings lange - tas pats nustatymas, tik po ranka.
    $live = $on && $code !== '';
    echo '<div class="card"><h2>Nuoroda nariams</h2>'
        .'<p><a href="https://upload.klajunas.lt/" target="_blank" rel="noopener" style="text-decoration:underline;text-underline-offset:3px"><code>https://upload.klajunas.lt/</code></a> · būsena: '
        .($live ? '<span class="badge" style="border-color:var(--accent-line);color:var(--accent-ink)">įjungta</span>'
                : '<span class="badge">'.($on ? 'neveikia — nėra kodo' : 'išjungta').'</span>').'</p>'
        .'<form method="post" action="?action=inbox_settings" class="actions" style="margin:10px 0 0">'
        .'<input type="hidden" name="_token" value="'.e(token()).'">'
        .'<div style="flex:1;min-width:180px;max-width:300px"><input name="code" value="'.e($code).'" placeholder="kodas nariams" autocomplete="off"></div>'
        .'<button class="btn">Išsaugoti kodą</button></form>'
        // Atskira forma: jungiklis neturi nesiotis kodo lauko, antraip
        // nepatvirtintas redagavimas nukeliautu i DB kartu su paspaudimu.
        .'<form method="post" action="?action=inbox_settings" class="actions" style="margin:10px 0 0">'
        .'<input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="enabled" value="'.($on ? '0' : '1').'">'
        .'<button class="'.($on ? 'btn' : 'primary').'">'.($on ? 'Išjungti įkėlimą' : 'Įjungti įkėlimą').'</button></form>'
        .'<p class="muted small" style="margin:12px 0 0">Kodą dalinkis klubo grupėje. Nariai mato tik įkėlimo formą — nei galerijos, nei kitų siuntų jie nepasiekia. Tie patys laukai yra ir <a href="?page=settings" style="text-decoration:underline">Settings</a> lange.</p></div>';

    $tab = fn(bool $on, string $href, string $label) => '<a class="'.($on ? 'btn primary' : 'btn').'" href="'.$href.'">'.e($label).'</a>';
    echo '<div class="actions" style="margin:16px 0 10px">'.$tab(!$archive, '?page=inbox', 'Laukia sutvarkymo ('.$pendingCount.')').$tab($archive, '?page=inbox&archive=1', 'Archyvas — perkelta ('.$archivedCount.')').'</div>';
    if (!$rows) { echo '<p class="muted">'.($archive ? 'Archyvas tuščias.' : 'Nesutvarkytų siuntų nėra — viskas perkelta.').'</p>'; foot('Uploads'); return; }
    echo '<table><tr><th>Gauta</th><th>Laikinas pavadinimas</th><th>Kas įkėlė</th><th>Failai</th><th>Dydis</th><th>Būsena</th><th></th></tr>';
    foreach ($rows as $r) {
        $imported = (string)$r['status'] === 'imported';
        $badge = $imported
            ? '<span class="badge" style="border-color:var(--accent-line);color:var(--accent-ink)">perkelta</span>'
            : '<span class="badge">laukia</span>';
        echo '<tr>'
            .'<td class="small">'.e(substr((string)$r['created_at'], 0, 16)).'</td>'
            .'<td><a href="?page=inbox&batch='.(int)$r['id'].'" style="font-weight:750;text-decoration:underline;text-underline-offset:3px">'.e($r['title']).'</a>'
            .((string)($r['note'] ?? '') !== '' ? '<br><span class="muted small">'.e($r['note']).'</span>' : '')
            .((string)($r['event_date'] ?? '') !== '' ? '<br><span class="badge">'.e($r['event_date']).'</span>' : '').'</td>'
            .'<td>'.((string)$r['uploader_name'] !== '' ? e($r['uploader_name']) : '<span class="muted">nenurodyta</span>').'</td>'
            .'<td>'.e((int)$r['files_count']).'</td>'
            .'<td class="small">'.e(human_bytes((int)$r['bytes_total'])).'</td>'
            .'<td class="small">'.$badge.($imported && $r['album_title'] ? '<br><a class="small" href="?page=album_edit&id='.(int)$r['album_id'].'" style="text-decoration:underline">'.e($r['album_title']).'</a>' : '').'</td>'
            .'<td><a class="btn mini" href="?page=inbox&batch='.(int)$r['id'].'">Peržiūrėti</a></td>'
            .'</tr>';
    }
    echo '</table>';
    foot('Uploads');
}
function inbox_batch_page(int $batchId): void {
    $batch = inbox_batch_row($batchId);
    if (!$batch) { flash('Siunta nerasta.', 'err'); go('?page=inbox'); }
    head('Uploads · '.(string)$batch['title']);
    $imported = (string)$batch['status'] === 'imported';
    $albumId = (int)($batch['album_id'] ?? 0);
    $files = db()->prepare("SELECT * FROM inbox_files WHERE batch_id=? ORDER BY COALESCE(taken_at,created_at), id");
    $files->execute([$batchId]);
    $files = $files->fetchAll(PDO::FETCH_ASSOC);

    echo '<p><a class="btn mini" href="?page=inbox">← Visos siuntos</a></p>';
    echo '<h1>'.e($batch['title']).'</h1>';
    echo '<div class="card"><div class="formgrid" style="margin:0">'
        .'<div><label>Kas įkėlė</label><div>'.((string)$batch['uploader_name'] !== '' ? e($batch['uploader_name']) : '<span class="muted">nenurodyta</span>').'</div></div>'
        .'<div><label>Gauta</label><div>'.e(substr((string)$batch['created_at'], 0, 16)).'</div></div>'
        .'<div><label>Renginio data</label><div>'.((string)($batch['event_date'] ?? '') !== '' ? e($batch['event_date']) : '<span class="muted">nenurodyta</span>').'</div></div>'
        .'<div><label>Failai</label><div>'.e(count($files)).' · '.e(human_bytes((int)$batch['bytes_total'])).'</div></div>'
        .'</div>'
        .((string)($batch['note'] ?? '') !== '' ? '<p style="margin:12px 0 0"><label>Pastaba</label>'.e($batch['note']).'</p>' : '')
        .'<p class="muted small" style="margin:12px 0 0">B2: <code>'.e($batch['b2_prefix']).'</code></p></div>';

    if ($imported) {
        echo '<div class="card"><h2>Perkelta</h2><p>Failai nukopijuoti į albumą'
            .($albumId ? ' <a href="?page=album_edit&id='.$albumId.'" style="text-decoration:underline">#'.$albumId.'</a>' : '')
            .' '.e(substr((string)($batch['imported_at'] ?? ''), 0, 16)).'.</p>'
            .'<p class="muted small">Originalai inbox\'e tebeguli — tai antra kopija. Kai albumas peržiūrėtas, siuntą galima ištrinti.</p></div>';
    } else {
        $albums = db()->query("SELECT id,title,event_date,source_path FROM albums ORDER BY event_date DESC, id DESC LIMIT 500")->fetchAll();
        echo '<div class="card"><h2>Perkelti į galeriją</h2>';
        if ($albumId > 0) {
            echo '<p class="muted">Perkėlimas pradėtas į albumą #'.$albumId.', bet dar nebaigtas (B2 kopijavimas dalinamas į dalis, kad netilptų į PHP laiko ribą).</p>'
                .'<form method="post" action="?action=inbox_import" class="actions" style="margin:0"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="batch_id" value="'.$batchId.'"><button class="primary">Tęsti perkėlimą</button></form>';
        } else {
            echo '<form method="post" action="?action=inbox_import"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="batch_id" value="'.$batchId.'"><input type="hidden" name="mode" value="new">'
                .'<h3>Naujas albumas</h3><div class="formgrid" style="margin-top:0">'
                .'<div><label>Pavadinimas</label><input name="title" value="'.e($batch['title']).'"></div>'
                .'<div><label>Renginio data</label><input name="event_date" placeholder="YYYY-MM-DD" pattern="\d{4}-\d{2}-\d{2}" maxlength="10" value="'.e((string)($batch['event_date'] ?? '')).'"></div>'
                .'</div><p class="muted small">Albumas sukuriamas kaip <strong>draft</strong> — viešoje galerijoje jis nepasirodys, kol pats jo nepaskelbsi. B2 kelias sudaromas kanoniškai (albums/metai/pavadinimas), kaip ir visur kitur.</p>'
                .'<button class="primary">Sukurti albumą ir perkelti</button></form>';
            echo '<h3>Arba į esamą albumą</h3>'
                .'<form method="post" action="?action=inbox_import"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="batch_id" value="'.$batchId.'"><input type="hidden" name="mode" value="existing">'
                .'<div class="formgrid" style="margin-top:0"><div><label>Albumas</label><select name="album_id">';
            foreach ($albums as $a) {
                echo '<option value="'.(int)$a['id'].'">'.e($a['title']).((string)($a['event_date'] ?? '') !== '' ? ' ('.e($a['event_date']).')' : '').((string)($a['source_path'] ?? '') === '' ? ' — be B2 kelio' : '').'</option>';
            }
            echo '</select></div></div><p class="muted small">Nuotraukos keliauja į to albumo B2 folderį; albumas privalo turėti B2 kelią.</p><button class="btn">Perkelti į pasirinktą albumą</button></form>';
        }
        echo '</div>';
    }

    echo '<div class="card"><h2>Failai</h2>';
    if (!$files) {
        echo '<p class="muted">Siunta tuščia — narys atidarė formą, bet nieko neįkėlė.</p>';
    } else {
        echo '<div class="photo-board">';
        foreach ($files as $f) {
            $fid = (int)$f['id'];
            echo '<div class="photo-tile" style="cursor:default">';
            if ((string)($f['thumb_b2_key'] ?? '') !== '') {
                echo '<a href="?action=inbox_thumb&id='.$fid.'&full=1" target="_blank" rel="noopener"><img loading="lazy" src="?action=inbox_thumb&id='.$fid.'" alt=""></a>';
            } else {
                echo '<div class="no-thumb">'.e(strtoupper((string)pathinfo((string)$f['original_filename'], PATHINFO_EXTENSION))).'</div>';
            }
            echo '<div class="photo-tile-body"><div class="photo-title" title="'.e($f['original_filename']).'">'.e($f['original_filename']).'</div>'
                .'<div class="muted small">'.e(human_bytes((int)$f['file_size']))
                .((string)($f['taken_at'] ?? '') !== '' ? ' · '.e(substr((string)$f['taken_at'], 0, 16)) : '').'</div>';
            if (!$imported) {
                echo '<form method="post" action="?action=inbox_delete_file" class="photo-tools" onsubmit="return confirm(\'Ištrinti šį failą iš inbox?\')">'
                    .'<input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="file_id" value="'.$fid.'">'
                    .'<button class="btn mini" style="border-color:var(--err-line)">Ištrinti</button></form>';
            }
            echo '</div></div>';
        }
        echo '</div>';
    }
    echo '</div>';

    echo '<div class="card"><h2>Ištrinti siuntą</h2><p class="muted small">Pašalina '.e(count($files)).' failus iš B2 inbox\'o ir siuntos įrašą. '
        .($imported ? 'Albume esančios kopijos lieka nepaliestos.' : '<strong>Į galeriją dar neperkelta — kopijų niekur kitur nėra.</strong>')
        .'</p><form method="post" action="?action=inbox_delete" onsubmit="return confirm(\'Tikrai ištrinti visą siuntą iš B2?\')" class="actions" style="margin:0">'
        .'<input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="batch_id" value="'.$batchId.'">'
        .'<button class="btn" style="border-color:var(--err-line);color:#e05b6a">Ištrinti siuntą</button></form></div>';
    foot('Uploads');
}
/**
 * Fotografavimo laikas is inbox_files i ka tik sukurtas photos eilutes.
 *
 * Anksciau ji nesdavo Takeout pavidalo sidecar failas, gulintis salia B2 - bet
 * tai reiske dar viena POST'a i B2 kiekvienai nariu ikeltai nuotraukai. Laikas
 * ir taip nuskaitomas is EXIF ikelimo metu ir guli DB, tad uztenka ji perkelti.
 * Vardai sutampa: b2_create_photo_rows_from_prefix() i original_filename deda
 * basename(rakto), o kopijuojant i albuma vardas nesikeicia.
 */
function inbox_apply_taken_at(int $batchId, int $albumId): int {
    $rows = db()->prepare("SELECT b2_key, taken_at FROM inbox_files WHERE batch_id=? AND taken_at IS NOT NULL");
    $rows->execute([$batchId]);
    $upd = db()->prepare("UPDATE photos SET taken_at=? WHERE album_id=? AND original_filename=? AND taken_at IS NULL");
    $n = 0;
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $upd->execute([(string)$r['taken_at'], $albumId, basename((string)$r['b2_key'])]);
        $n += $upd->rowCount();
    }
    return $n;
}
function inbox_import(): void {
    require_superadmin(); csrf(); b2_load_config(); ensure_inbox_schema();
    $batchId = (int)($_POST['batch_id'] ?? 0);
    $batch = inbox_batch_row($batchId);
    if (!$batch) { flash('Siunta nerasta.', 'err'); go('?page=inbox'); }
    if ((string)$batch['status'] === 'imported') { flash('Ši siunta jau perkelta.', 'err'); go('?page=inbox&batch='.$batchId); }
    $back = '?page=inbox&batch='.$batchId;

    $albumId = (int)($batch['album_id'] ?? 0);
    $createdNew = false;
    if ($albumId > 0) {
        // Antras (ir tolesni) paspaudimai: albumas jau parinktas pirmo karto metu.
        $st = db()->prepare("SELECT * FROM albums WHERE id=? LIMIT 1");
        $st->execute([$albumId]);
        $album = $st->fetch(PDO::FETCH_ASSOC);
        if (!$album) { flash('Albumas, į kurį buvo pradėta kelti, nebeegzistuoja.', 'err'); go($back); }
        $prefix = trim((string)($album['source_path'] ?? ''), '/');
    } elseif ((string)($_POST['mode'] ?? 'new') === 'existing') {
        $albumId = (int)($_POST['album_id'] ?? 0);
        $album = require_album_editable_by_id($albumId);
        $prefix = trim((string)($album['source_path'] ?? ''), '/');
    } else {
        $title = trim((string)($_POST['title'] ?? '')) !== '' ? trim((string)$_POST['title']) : (string)$batch['title'];
        $date = trim((string)($_POST['event_date'] ?? ''));
        if ($date === '') $date = (string)($batch['event_date'] ?? '');
        if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { flash('Data turi būti YYYY-MM-DD.', 'err'); go($back); }
        $prefix = canonical_album_prefix($title, $date !== '' ? $date : null);
        $exists = db()->prepare("SELECT id FROM albums WHERE source_path=? LIMIT 1");
        $exists->execute([$prefix]);
        $albumId = (int)$exists->fetchColumn();
        if (!$albumId) {
            $slug = slug($title);
            $base = $slug; $i = 2;
            $slugExists = db()->prepare("SELECT id FROM albums WHERE slug=? LIMIT 1");
            while (true) {
                $slugExists->execute([$slug]);
                if (!$slugExists->fetchColumn()) break;
                $slug = $base.'-'.$i++;
            }
            $who = (string)$batch['uploader_name'] !== '' ? (string)$batch['uploader_name'] : 'vardas nenurodytas';
            $notes = 'Iš nario įkėlimo: '.$who.((string)($batch['note'] ?? '') !== '' ? ' · '.(string)$batch['note'] : '');
            db()->prepare("INSERT INTO albums(uuid,source_type,source_path,slug,title,event_date,visibility,download_enabled,notes_internal,created_by,updated_by) VALUES(?,'inbox',?,?,?,?,'draft',1,?,?,?)")
                ->execute([uid(), $prefix, $slug, $title, $date !== '' ? $date : null, $notes, $_SESSION['admin']['id'] ?? null, $_SESSION['admin']['id'] ?? null]);
            $albumId = (int)db()->lastInsertId();
            $createdNew = true;
            place_album_by_date($albumId);
            persist_album_metadata_json($albumId);
        }
    }
    if ($albumId <= 0 || $prefix === '') { flash('Albumas neturi B2 kelio — pirma nustatyk jį albumo lange.', 'err'); go($back); }
    db()->prepare("UPDATE inbox_batches SET album_id=? WHERE id=?")->execute([$albumId, $batchId]);

    // B2 kopijuoja savo viduje; per PHP nekeliauja nei vienas baitas. Dalimis -
    // 300 failu kopijavimas nesutilptu i max_execution_time.
    $source = trim((string)$batch['b2_prefix'], '/');
    $copy = b2_copy_prefix_chunk($source.'/originals', $prefix.'/originals');
    if ((int)$copy['remaining'] > 0) {
        flash('Nukopijuota '.(int)$copy['copied'].', liko '.(int)$copy['remaining'].' failų — spausk „Tęsti perkėlimą“.', 'ok');
        go($back);
    }
    b2_copy_prefix_chunk($source.'/metadata', $prefix.'/metadata');
    $created = b2_create_photo_rows_from_prefix($albumId, $prefix);
    $dated = inbox_apply_taken_at($batchId, $albumId);
    // Naujame albume eiliskuma galima nustatyti saugiai - jame dar nieko nebuvo.
    // I esama albuma tik iraseme laikus: jo tvarka gali buti sudeliota ranka, ir
    // perrikiuoti ja be klausimo butu ne musu reikalas.
    if ($createdNew && $dated > 0) apply_album_sort_preset($albumId, 'taken_asc');
    db()->prepare("UPDATE inbox_batches SET status='imported', imported_at=NOW() WHERE id=?")->execute([$batchId]);
    db()->prepare("UPDATE inbox_files SET status='imported' WHERE batch_id=?")->execute([$batchId]);
    gallery_list_cache_invalidate_album($albumId);
    audit('album', $albumId, 'inbox_import', 'Nario įkėlimas perkeltas į albumą', ['batch'=>$batchId, 'prefix'=>$prefix, 'copied'=>(int)$copy['copied'], 'created_photos'=>$created]);
    flash('Perkelta: '.(int)$copy['copied'].' failai į B2, '.$created.' naujos nuotraukos albume'
        .($dated > 0 ? ', '.$dated.' su fotografavimo laiku' : '').'.', 'ok', '?page=album_edit&id='.$albumId, 'Atidaryti albumą');
    go($back);
}
function inbox_delete_file(): void {
    require_superadmin(); csrf(); b2_load_config(); ensure_inbox_schema();
    $st = db()->prepare("SELECT * FROM inbox_files WHERE id=? LIMIT 1");
    $st->execute([(int)($_POST['file_id'] ?? 0)]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) { flash('Failas nerastas.', 'err'); go('?page=inbox'); }
    b2_delete_exact_key((string)$f['b2_key']);
    if ((string)($f['thumb_b2_key'] ?? '') !== '') b2_delete_exact_key((string)$f['thumb_b2_key']);
    db()->prepare("DELETE FROM inbox_files WHERE id=?")->execute([(int)$f['id']]);
    db()->prepare("UPDATE inbox_batches SET files_count=GREATEST(files_count-1,0), bytes_total=GREATEST(CAST(bytes_total AS SIGNED)-?,0) WHERE id=?")
        ->execute([(int)$f['file_size'], (int)$f['batch_id']]);
    audit('inbox', (int)$f['batch_id'], 'delete_file', 'Failas ištrintas iš inbox', ['key'=>(string)$f['b2_key']]);
    flash('Failas ištrintas.');
    go('?page=inbox&batch='.(int)$f['batch_id']);
}
function inbox_delete(): void {
    require_superadmin(); csrf(); b2_load_config(); ensure_inbox_schema();
    $batchId = (int)($_POST['batch_id'] ?? 0);
    $batch = inbox_batch_row($batchId);
    if (!$batch) { flash('Siunta nerasta.', 'err'); go('?page=inbox'); }
    $prefix = trim((string)$batch['b2_prefix'], '/');
    $res = $prefix !== '' ? b2_delete_prefix_chunk($prefix) : ['deleted'=>0, 'remaining'=>0];
    if ((int)$res['remaining'] > 0) {
        flash('Ištrinta '.(int)$res['deleted'].' failų, liko '.(int)$res['remaining'].' — spausk dar kartą.', 'ok');
        go('?page=inbox&batch='.$batchId);
    }
    db()->prepare("DELETE FROM inbox_files WHERE batch_id=?")->execute([$batchId]);
    db()->prepare("DELETE FROM inbox_batches WHERE id=?")->execute([$batchId]);
    audit('inbox', $batchId, 'delete', 'Nario įkėlimo siunta ištrinta', ['prefix'=>$prefix, 'deleted'=>(int)$res['deleted']]);
    flash('Siunta ištrinta ('.(int)$res['deleted'].' failai B2).');
    go('?page=inbox');
}
function zip_page(): void {
    require_superadmin();
    head('ZIP Downloads');
    $albums=db()->query("SELECT id,title,slug FROM albums ORDER BY sort_order,event_date DESC,id DESC")->fetchAll();
    $maxLen = 18;
    foreach ($albums as $a) $maxLen = max($maxLen, mb_strlen((string)$a['title'], 'UTF-8'));
    $albumSelectWidth = min(90, max(28, $maxLen + 4));
    $firstSlug = $albums ? (string)($albums[0]['slug'] ?: slug((string)$albums[0]['title'])) : 'photos';
    echo '<h1>ZIP Downloads</h1><div class="card"><form method="post" action="?action=zip_request"><input type="hidden" name="_token" value="'.e(token()).'"><div class="formgrid zip-formgrid"><div style="min-width:'.e((string)$albumSelectWidth).'ch"><label>Album</label><select id="zipAlbumSelect" name="album_id" style="width:'.e((string)$albumSelectWidth).'ch;max-width:100%">';
    foreach($albums as $a) {
        $slugValue = (string)($a['slug'] ?: slug((string)$a['title']));
        echo '<option value="'.e($a['id']).'" data-slug="'.e($slugValue).'">'.e($a['title']).'</option>';
    }
    echo '</select></div><div><label>Variant</label><select name="variant"><option>originals</option><option>previews</option><option>web</option></select></div><div><label>Output filename</label><input id="zipOutputFilename" name="output_filename" value="'.e($firstSlug).'.zip"></div><div class="actions" style="align-items:center;margin-top:28px;gap:8px;white-space:nowrap"><label style="display:inline-flex;align-items:center;gap:8px;margin:0"><input type="checkbox" name="only_published" value="1" checked> Only published</label></div></div><p class="muted">Large ZIP creation is intentionally request-only for now. Shared hosting should generate ZIPs in small batches or via CLI/cron.</p><button class="primary">Create ZIP request</button></form></div><script>(function(){var select=document.getElementById("zipAlbumSelect");var output=document.getElementById("zipOutputFilename");if(!select||!output)return;function setName(){var opt=select.options[select.selectedIndex];var slug=opt?opt.getAttribute("data-slug"):"photos";output.value=(slug||"photos")+".zip";}select.addEventListener("change",setName);setName();})();</script>';
    $rows=db()->query("SELECT z.*,a.title album FROM zip_downloads z LEFT JOIN albums a ON a.id=z.album_id ORDER BY z.id DESC LIMIT 50")->fetchAll(); echo '<h2>ZIP jobs</h2><table><tr><th>ID</th><th>Album</th><th>Variant</th><th>Status</th><th>Files</th><th>Created</th></tr>'; foreach($rows as $r) echo '<tr><td>'.e($r['id']).'</td><td>'.e($r['album']).'</td><td>'.e($r['variant']).'</td><td>'.e($r['status']).'</td><td>'.e($r['files_count']).'</td><td>'.e($r['created_at']).'</td></tr>'; echo '</table>'; foot('ZIP Downloads');
}
function render_takeout_preview(array $plan): void {
    head('Takeout Import Preview');
    $eventDate = (string)($plan['event_date'] ?? '');
    $description = (string)($plan['description'] ?? '');
    $visibility = (string)($plan['visibility'] ?? 'draft');
    echo '<h1>Preview Google Takeout import</h1><form method="post" action="?action=takeout_confirm" id="takeoutPreviewForm"><input type="hidden" name="_token" value="'.e(token()).'"><input type="hidden" name="initial_prefix" value="'.e($plan['prefix']).'"><div class="card"><h2>Album target</h2><div class="formgrid"><div><label>Album title</label><input name="album_title" id="takeoutAlbumTitle" value="'.e($plan['title']).'" required></div><div><label>Event date</label><input type="text" name="event_date" id="takeoutEventDate" value="'.e($eventDate).'" placeholder="YYYY-MM-DD" pattern="\d{4}-\d{2}-\d{2}" inputmode="numeric" autocomplete="off"></div><div><label>Target B2 folder</label><input name="prefix" id="takeoutPrefix" value="'.e($plan['prefix']).'" required></div><div><label>Visibility</label><select name="visibility">';
    foreach(STATUSES as $s) echo '<option value="'.e($s).'"'.($visibility===$s?' selected':'').'>'.e($s).'</option>';
    echo '</select></div></div><label>Description</label><textarea name="description" rows="4">'.e($description).'</textarea><table>';
    $rows=[['Media files',(string)$plan['counts']['media']],['JSON files',(string)$plan['counts']['json']],['Matched sidecars',$plan['counts']['sidecars'].' / '.$plan['counts']['media']],['Upload size',human_bytes((int)$plan['counts']['bytes'])]];
    foreach($rows as [$k,$v]) echo '<tr><th>'.e($k).'</th><td>'.e($v).'</td></tr>';
    $limits = upload_limits();
    $stagedTotal = count((array)($plan['files'] ?? []));
    $batchHint = 'Upload additional batches before confirming if the original folder had more files than shown here. Current PHP batch capacity: up to '.(int)$limits['max_file_uploads'].' files and about '.human_bytes((int)$limits['recommended_album_bytes']).' per request.';
    $mediaTotal = (int)($plan['counts']['media'] ?? 0);
    $planComplete = $mediaTotal > 0 && (int)($plan['counts']['sidecars'] ?? 0) >= $mediaTotal && $mediaTotal <= 100;
    echo '</table><p class="muted small">Target B2 folder follows Album title and Event date until you type a custom folder manually. File plan is recalculated from these final values when you confirm the import.</p><p class="muted small">'.e($batchHint).'</p><div class="actions"><label><input type="checkbox" name="confirm_takeout_import" value="1" required> I confirm this will upload these files to B2 and create/update DB manifest</label><button class="primary">Confirm import to B2</button><a class="btn" href="?action=takeout_cancel">Cancel</a></div></div></form>';
    if ($planComplete) {
        echo '<p class="muted small" style="margin:14px 0 0">Planas atrodo pilnas — '.e((string)$mediaTotal).' media failai, visi sidecars sutapo. <a href="#" id="takeoutBatchToggle" style="text-decoration:underline;text-underline-offset:3px">Reikia įkelti daugiau failų? Pridėti dar vieną batch…</a></p>';
    }
    echo '<div class="card" id="takeoutBatchCard" style="margin-top:16px'.($planComplete ? ';display:none' : '').'"><h2>Add another Takeout batch</h2><form method="post" enctype="multipart/form-data" action="?action=ti_append"><input type="hidden" name="_token" value="'.e(token()).'"><label>More files from the same Takeout album folder</label><input type="file" name="takeout_files[]" multiple webkitdirectory><div class="actions"><button class="primary">Stage more files</button><span class="muted small">Currently staged: '.e((string)$stagedTotal).' files. Add media plus their *.supplemental-metadata.json files, then confirm once the plan count matches the album.</span></div></form></div><script>(function(){var f=document.getElementById("takeoutPreviewForm");if(!f)return;var title=document.getElementById("takeoutAlbumTitle");var date=document.getElementById("takeoutEventDate");var prefix=document.getElementById("takeoutPrefix");if(!title||!date||!prefix)return;function clean(v){return String(v||"").replace(/^\\/+|\\/+$/g,"");}function slug(v){return (v||"").replace(/\\//g,"_").normalize("NFD").replace(/[\\u0300-\\u036f]/g,"").toLowerCase().replace(/[^a-z0-9_]+/g,"-").replace(/^[-_]+|[-_]+$/g,"")||"album-"+new Date().toISOString().slice(0,10).replace(/-/g,"");}function canonical(){var d=date.value||"";var y=d.slice(0,4)||String(new Date().getFullYear());var name=slug(title.value);if(/^\\d{4}-\\d{2}-\\d{2}$/.test(d)){var ds=slug(d);var ey=d.slice(0,4);if(name.indexOf(ds)===-1){if(name.endsWith("-"+ey))name=name.slice(0,-5);name=(name+"-"+ds).replace(/^-+|-+$/g,"");}}return "albums/"+y+"/"+name;}var lastAuto=canonical();var manual=clean(prefix.value)!==""&&clean(prefix.value)!==clean(lastAuto);function updatePlanKeys(){var p=clean(prefix.value);document.querySelectorAll(".takeout-target-key").forEach(function(cell){var suffix=cell.getAttribute("data-suffix")||"";cell.textContent=p+(suffix?"/"+suffix:"");});}function sync(force){var current=clean(prefix.value);var wasAuto=current===""||current===clean(lastAuto);if(force||!manual||wasAuto){lastAuto=canonical();prefix.value=lastAuto;manual=false;updatePlanKeys();}}prefix.addEventListener("input",function(){var current=clean(prefix.value);manual=current!==""&&current!==clean(lastAuto);updatePlanKeys();});["input","change","keyup","paste"].forEach(function(evt){title.addEventListener(evt,function(){setTimeout(function(){sync(false);},0);});date.addEventListener(evt,function(){setTimeout(function(){sync(false);},0);});});sync(false);})();</script>';
    echo '<style>@keyframes tkmove{0%{transform:translateX(-110%)}100%{transform:translateX(280%)}}</style><div id="takeoutConfirmProgress" style="display:none;position:fixed;inset:0;z-index:90;background:rgba(5,8,10,.78)"><div style="max-width:460px;margin:26vh auto 0;background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:26px 24px;text-align:center"><h3 style="margin:0 0 8px">Keliama į B2…</h3><div id="takeoutConfirmProgressText" class="muted" style="margin-bottom:14px"></div><div style="height:8px;border-radius:99px;background:var(--panel2);overflow:hidden;border:1px solid var(--line)"><div style="width:36%;height:100%;background:var(--accent);border-radius:99px;animation:tkmove 1.3s linear infinite"></div></div><p class="muted small" style="margin:12px 0 0">Neuždarykite ir neperkraukite lango — failai keliami į B2 ir rašomi į DB serveryje.</p></div></div>';
    echo '<script>(function(){var toggle=document.getElementById("takeoutBatchToggle");var card=document.getElementById("takeoutBatchCard");if(toggle&&card){toggle.addEventListener("click",function(e){e.preventDefault();card.style.display="";toggle.parentNode.style.display="none";card.scrollIntoView({behavior:"smooth",block:"center"});});}var f=document.getElementById("takeoutPreviewForm");var ov=document.getElementById("takeoutConfirmProgress");var txt=document.getElementById("takeoutConfirmProgressText");if(f&&ov){f.addEventListener("submit",function(){ov.style.display="block";var total='.$mediaTotal.';var t0=Date.now();function tick(){var s=Math.round((Date.now()-t0)/1000);if(txt)txt.textContent=total+" failų · praėjo "+s+" s (apytiksliai 1–3 s failui)";}tick();setInterval(tick,1000);});}})();</script>';
    echo '<div class="card" style="margin-top:16px"><h2>File plan</h2><table><tr><th>#</th><th>Original</th><th>Target B2 key</th><th>Taken</th><th>Sidecar</th><th>Size</th></tr>';
    foreach(array_slice($plan['media'],0,120) as $m){
        $key = (string)$m['b2_key'];
        $prefixForSuffix = trim((string)($plan['prefix'] ?? ''), '/');
        $suffix = $prefixForSuffix !== '' && str_starts_with($key, $prefixForSuffix . '/') ? substr($key, strlen($prefixForSuffix) + 1) : $key;
        echo '<tr><td>'.e($m['seq']).'</td><td>'.e($m['base']).'</td><td class="takeout-target-key" data-suffix="'.e($suffix).'">'.e($key).'</td><td>'.e($m['taken_at'] ?: 'n/a').'</td><td>'.($m['has_sidecar']?'yes':'generated').'</td><td>'.e(human_bytes((int)$m['size'])).'</td></tr>';
    }
    echo '</table>';
    if(count($plan['media'])>120) echo '<p class="muted">Showing first 120 files only.</p>';
    echo '</div>';
    foot('Takeout Import Preview');
}
function save_takeout_stage_profile(): void {
    require_superadmin();
    csrf();
    $profile = (string)($_POST['takeout_stage_profile'] ?? 'balanced');
    $allowed = array_keys(takeout_stage_profile_presets());
    $allowed[] = 'custom';
    if (!in_array($profile, $allowed, true)) $profile = 'balanced';
    set_setting('takeout_stage_profile', $profile);
    audit('settings', null, 'takeout_stage_profile', 'Takeout staging profile set to '.$profile);
    flash('Takeout staging profile updated: '.takeout_stage_upload_tuning()['label'].'.');
    go('?page=takeout');
}
function takeout_page(): void {
    require_superadmin();
    $l=upload_limits();
    $tuning = takeout_stage_upload_tuning();
    $estPost = takeout_stage_estimated_post_bytes((int)$tuning['chunk'], (int)$tuning['budget']);
    head('Takeout Import');
    $diskFree = takeout_stage_disk_free();
    if ($diskFree !== null && $diskFree < 600 * 1048576) {
        echo '<div class="notice err" style="margin-bottom:16px"><strong>Low disk space:</strong> about '.e(human_bytes($diskFree)).' free on the staging volume. Large imports need roughly the full album size free here. HTTP 503 often means disk full or PHP worker crash — free space in hosting panel before retrying.</div>';
    }
    echo '<h1>Google Takeout → B2 Import</h1>';
    echo '<div class="card" style="margin-top:16px"><h2>Staging speed</h2><form method="post" action="?action=save_takeout_stage_profile" class="actions" style="align-items:end"><input type="hidden" name="_token" value="'.e(token()).'"><div style="flex:0 1 320px;min-width:240px;max-width:320px"><label>Upload mode</label><select id="takeoutUploadMode" name="takeout_stage_profile" onchange="this.form.submit()">';
    foreach (takeout_stage_profile_presets() as $v => $preset) {
        echo '<option value="'.e($v).'"'.($tuning['profile']===$v?' selected':'').'>'.e($preset['label']).'</option>';
    }
    echo '</select></div></form><p class="muted small">Active: <strong>'.e($tuning['label']).'</strong> · estimated JSON request <strong>'.e(human_bytes($estPost)).'</strong> · server max raw chunk <strong>'.e(human_bytes((int)($tuning['max_chunk'] ?? 0))).'</strong>. Album photo upload uses the same profile for multipart batch size (from 256 KB up). <strong>Safe</strong> / <strong>Safe+</strong> work with WAF on (~35 KB / ~113 KB per request). WAF off for larger modes. Custom: <a href="?page=settings">Settings</a>.</p></div>';
    echo '<div class="card" style="margin-top:16px"><h2>Small album web import</h2><form id="takeoutBatchForm" method="post" action="?action=ti_payload_build" data-max-files="2" data-max-bytes="'.e((string)(int)$tuning['chunk']).'" data-post-budget="'.e((string)(int)$tuning['budget']).'" data-heic-supported="'.(heic_upload_supported()?'1':'0').'"><input type="hidden" name="_token" value="'.e(token()).'"><label>Takeout album folder/files</label><input id="takeoutFilesInput" type="file" name="takeout_files[]" multiple webkitdirectory><div id="takeoutBatchHint" class="muted small" style="margin-top:8px"></div><div class="formgrid"><div><label>Album title override</label><input name="album_title" placeholder="From metadata.json if empty"></div><div><label>B2 prefix override</label><input name="prefix" placeholder="auto: albums/year/date_slug"></div><div><label>Visibility</label>'.status_select('visibility','published').'</div></div><div class="actions"><label><input type="checkbox" name="upload_json" value="1" checked> Upload metadata JSON files to B2</label><label title="Updates DB album/photo fields from Takeout JSON data: title, description, event/taken date, views, GPS and metadata JSON."><input type="checkbox" name="overwrite_db" value="1" checked> Update DB overlay <span class="muted small">updates DB fields from JSON metadata</span></label></div><button class="primary" id="takeoutBatchButton">Preview import plan</button><p class="muted">The browser stages photos, videos, documents, spreadsheets, PDFs, CSV files, and Takeout JSON sidecars in very small chunks to avoid web server request blocking.</p></form><div id="takeoutBatchProgress" class="muted small" style="display:none;margin-top:12px"></div></div>';
    echo '<script>(function(){var form=document.getElementById("takeoutBatchForm");var input=document.getElementById("takeoutFilesInput");var hint=document.getElementById("takeoutBatchHint");var progress=document.getElementById("takeoutBatchProgress");var button=document.getElementById("takeoutBatchButton");if(!form||!input||!progress)return;var POST_BUDGET=Math.max(32768,parseInt(form.getAttribute("data-post-budget")||"100000",10));var configured=Math.max(8192,parseInt(form.getAttribute("data-max-bytes")||"8192",10));var chunkSize=Math.min(configured,Math.floor((POST_BUDGET-4096)*3/4));var heicSupported=form.getAttribute("data-heic-supported")==="1";var heicMessage="HEIC formato nuotraukų šis serveris šiuo metu negali apdoroti. Prašome įkelti JPG arba PNG.";function hasHeic(files){return Array.from(files||[]).some(function(f){return /\.(heic|heif)$/i.test(f.name||"");});}function human(bytes){if(bytes>=1048576)return (bytes/1048576).toFixed(1)+" MB";if(bytes>=1024)return (bytes/1024).toFixed(1)+" KB";return bytes+" B";}function isJson(f){return /\\.json$/i.test(f.name||"");}function isMetadata(f){return /(^|\\/)metadata\\.json$/i.test(f.webkitRelativePath||f.name||"");}function isSupported(f){return /\\.(jpe?g|png|webp|heic|heif|gif|mp4|mov|m4v|avi|webm|pdf|csv|docx?|xlsx?)$/i.test(f.name||"");}function orderedFiles(){return Array.from(input.files||[]).filter(function(f){return isJson(f)||isSupported(f);}).sort(function(a,b){var aw=isMetadata(a)?0:(isSupported(a)?1:(isJson(a)?2:3));var bw=isMetadata(b)?0:(isSupported(b)?1:(isJson(b)?2:3));return aw-bw || (a.webkitRelativePath||a.name).localeCompare(b.webkitRelativePath||b.name);});}function describe(){var files=orderedFiles();var total=files.reduce(function(n,f){return n+(f.size||0);},0);if(hint)hint.textContent=files.length+" supported files, "+human(total)+" selected. Staging uses "+human(chunkSize)+" raw chunks; each JSON request budget "+human(POST_BUDGET)+".";}input.addEventListener("change",describe);function checked(n){var el=form.querySelector("[name="+n+"]");return !!(el&&el.checked);}function val(n){var el=form.querySelector("[name="+n+"]");return el?el.value:"";}function readChunk(file,start,end){return new Promise(function(resolve,reject){var r=new FileReader();r.onload=function(){var s=String(r.result||"");resolve(s.replace(/^data:[^,]*,/,""));};r.onerror=function(){reject(new Error("Could not read "+(file.name||"file")));};r.readAsDataURL(file.slice(start,end));});}async function postJson(url,payload){var attempt=0;while(attempt<4){attempt++;var res=await fetch(url,{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},body:JSON.stringify(payload),credentials:"same-origin"});var text=await res.text();if(!res.ok){if(attempt<4&&(res.status===502||res.status===503||res.status===504)){progress.textContent="Server busy (HTTP "+res.status+"), retry "+attempt+"/3...";await new Promise(function(r){setTimeout(r,1500*attempt);});continue;}throw new Error("HTTP "+res.status+": "+text.slice(0,180));}try{return JSON.parse(text);}catch(e){return {};}}throw new Error("Request failed after retries");}form.addEventListener("submit",async function(e){e.preventDefault();e.stopImmediatePropagation();var files=orderedFiles();if(!files.length){progress.style.display="block";progress.textContent="No supported files selected.";return;}button.disabled=true;progress.style.display="block";var token="";var csrf=form.querySelector("[name=_token]").value;try{for(var i=0;i<files.length;i++){var file=files[i];var chunks=Math.max(1,Math.ceil((file.size||0)/chunkSize));for(var c=0;c<chunks;c++){var start=c*chunkSize;var end=Math.min(file.size,start+chunkSize);progress.textContent="Staging file "+(i+1)+" / "+files.length+", chunk "+(c+1)+" / "+chunks+" ("+human(end-start)+" raw): "+(file.webkitRelativePath||file.name);var data=await readChunk(file,start,end);var out=await postJson("?action=ti_payload_stage",{_token:csrf,token:token,seq:i,name:file.webkitRelativePath||file.name||"file",type:file.type||"application/octet-stream",size:file.size||0,chunk:c,chunks:chunks,data:data});token=out.token||token;}}progress.textContent="Building import preview...";await postJson("?action=ti_payload_build",{_token:csrf,token:token,album_title:val("album_title"),prefix:val("prefix"),visibility:val("visibility"),upload_json:checked("upload_json"),overwrite_db:checked("overwrite_db")});window.location="?page=takeout_preview_pending";}catch(err){button.disabled=false;progress.textContent="Upload stopped: "+(err&&err.message?err.message:String(err));}},true);describe();})();</script>';
    echo '<div class="grid" style="margin-top:16px"><div class="card"><h2>Web upload limits</h2><table><tr><th>Setting</th><th>Value</th></tr>';
    foreach(['post_max_size','upload_max_filesize','memory_limit','recommended_album_bytes'] as $k) echo '<tr><td>'.e($k).'</td><td>'.e(human_bytes((int)$l[$k])).'</td></tr>';
    echo '<tr><td>max_file_uploads</td><td>'.e($l['max_file_uploads']).' files per batch</td></tr><tr><td>max_execution_time</td><td>'.e($l['max_execution_time']).' sec</td></tr><tr><td>max_takeout_chunk</td><td>'.e(human_bytes((int)($tuning['max_chunk'] ?? takeout_stage_max_chunk_bytes((int)$l['post_max_size'])))).' raw per JSON request</td></tr></table><p class="muted">Each staging request is base64 JSON (~4/3 larger than raw chunk). WAF often blocks above 128 KB. App hard cap: 64 MB raw chunk.</p></div>';
    echo '<div class="card"><h2>B2 naming rule</h2><p><code>albums/{year}/{yyyy-mm-dd}_{event-slug}/originals/0001_original.ext</code></p><p><code>albums/{year}/{yyyy-mm-dd}_{event-slug}/metadata/*.json</code></p><p class="muted">If B2 prefix is empty, admin derives it from Takeout metadata title and event date.</p></div></div>';
    if(!empty($_SESSION['takeout_preview']) && is_array($_SESSION['takeout_preview'])) {
        echo '<div class="card" style="margin-top:16px"><h2>Pending preview</h2><p class="muted">'.e($_SESSION['takeout_preview']['title'] ?? '').' → '.e($_SESSION['takeout_preview']['prefix'] ?? '').'</p><p><a class="btn" href="?page=takeout_preview_pending">Open pending preview</a> <a class="btn" href="?action=takeout_cancel">Cancel pending import</a></p></div>';
    }
    foot('Takeout Import');
}

function placeholder(string $title, string $body): void {
    head($title); echo '<h1>'.e($title).'</h1><div class="card"><p class="muted">'.e($body).'</p><p>This area is ready for the next implementation step and keeps the manifest-only rule.</p></div>'; foot($title);
}

function sync_tags(string $kind, int $id, string $csv, array $tagIds = []): void {
    $pivot=$kind==='album'?'album_tags':'photo_tags'; $idcol=$kind.'_id';
    db()->prepare("DELETE FROM $pivot WHERE $idcol=?")->execute([$id]);
    foreach(array_unique(array_filter(array_map('intval', $tagIds))) as $tid){
        $exists=(int)db()->query("SELECT COUNT(*) FROM tags WHERE id=".$tid)->fetchColumn();
        if($exists) db()->prepare("INSERT IGNORE INTO $pivot($idcol,tag_id) VALUES(?,?)")->execute([$id,$tid]);
    }
    foreach(array_filter(array_map('trim', explode(',', $csv))) as $name){
        db()->prepare("INSERT IGNORE INTO tags(name,slug,type) VALUES(?,?, 'keyword')")->execute([$name,slug($name)]);
        $tid=(int)db()->query("SELECT id FROM tags WHERE slug=".db()->quote(slug($name)))->fetchColumn();
        if($tid) db()->prepare("INSERT IGNORE INTO $pivot($idcol,tag_id) VALUES(?,?)")->execute([$id,$tid]);
    }
}
function save_album(): void {
    csrf();
    // Automatinis juodrascio irasymas kviecia ta pati funkcija, tik laukia JSON.
    // Taip nera dvieju skirtingu irasymo keliu, kurie laikui begant issiskirtu.
    $ajax = (string)($_POST['ajax'] ?? '') === '1';
    $id=(int)($_POST['id'] ?? 0); $title=trim((string)($_POST['title'] ?? ''));
    if ($title==='') {
        if ($ajax) json_error('Title is required.', 422);
        flash('Title is required.', 'err'); go('?page=album_edit&id='.$id);
    }
    $slug=slug(trim((string)($_POST['slug'] ?? '')) ?: $title);
    $coverPost = (string)($_POST['cover_photo_id'] ?? '__auto__');
    $coverMode = 'auto';
    $coverPhotoId = null;
    if ($coverPost === '__none__') {
        $coverMode = 'none';
    } elseif ($coverPost !== '' && $coverPost !== '__auto__') {
        $coverMode = 'manual';
        $coverPhotoId = (int)$coverPost ?: null;
    }
    $existingDownloadEnabled = null;
    if ($id) {
        $q = db()->prepare("SELECT download_enabled,slug FROM albums WHERE id=? LIMIT 1");
        $q->execute([$id]);
        $existingRow = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        $existingDownloadEnabled = (int)($existingRow['download_enabled'] ?? 1);
    }
    $oldSlug = (string)($existingRow['slug'] ?? '');
    $albumDescription = $_POST['description'] ?: null;
    $visibility = visibility_from_input($_POST['visibility'] ?? '');
    if ($visibility === null) {
        $msg = invalid_visibility_message($_POST['visibility'] ?? '');
        if ($ajax) json_error($msg, 422);
        flash($msg, 'err'); go($id ? '?page=album_edit&id='.$id : '?page=albums');
    }
    $data=[$title,$slug,$_POST['subtitle'] ?: null,$albumDescription,$_POST['event_date'] ?: null,$_POST['event_date_end'] ?: null,$_POST['location_name'] ?: null,$_POST['sport_type'] ?: null,$_POST['author_name'] ?: null,$_POST['copyright_text'] ?: null,$coverPhotoId,$coverMode,$visibility,(int)($_POST['sort_order'] ?? 0),isset($_POST['download_enabled'])?1:0,$_POST['seo_title'] ?: null,$albumDescription,$_POST['dbsportas_url'] ?: null,$_POST['klajunas_url'] ?: null,$_POST['other_url'] ?: null,$_POST['notes_internal'] ?: null,$_SESSION['admin']['id'] ?? null];
    $wasNew = ($id === 0);
    if ($id) {
        require_album_editable_by_id($id);
        db()->prepare("UPDATE albums SET title=?,slug=?,subtitle=?,description=?,event_date=?,event_date_end=?,location_name=?,sport_type=?,author_name=?,copyright_text=?,cover_photo_id=?,cover_mode=?,visibility=?,sort_order=?,download_enabled=?,seo_title=?,seo_description=?,dbsportas_url=?,klajunas_url=?,other_url=?,notes_internal=?,updated_by=?,updated_at=NOW() WHERE id=?")->execute([...$data,$id]);
        if ($oldSlug !== '' && $oldSlug !== $slug) {
            // Senas slug'as lieka alias'u, kad jau isdalintos nuorodos veiktu.
            db()->prepare("INSERT INTO album_slug_aliases(slug,album_id) VALUES(?,?) ON DUPLICATE KEY UPDATE album_id=VALUES(album_id)")->execute([$oldSlug, $id]);
            audit('album',$id,'slug_change','Slug: '.$oldSlug.' → '.$slug,['old'=>$oldSlug,'new'=>$slug]);
        }
        if ((int)($existingDownloadEnabled ?? 1) !== 0 && (int)($data[14] ?? 1) === 0) {
            db()->prepare("UPDATE photos SET is_downloadable=0, updated_by=? WHERE album_id=?")->execute([$_SESSION['admin']['id'] ?? null, $id]);
        }
        audit('album',$id,'update','Album updated: '.$title);
    } else {
        $sourcePath = canonical_album_prefix($title, $_POST['event_date'] ?: null, $_POST['event_date_end'] ?? null);
        db()->prepare("INSERT INTO albums(uuid,title,slug,subtitle,description,event_date,event_date_end,location_name,sport_type,author_name,copyright_text,cover_photo_id,cover_mode,visibility,sort_order,download_enabled,source_path,seo_title,seo_description,dbsportas_url,klajunas_url,other_url,notes_internal,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([uid(),$title,$slug,$_POST['subtitle'] ?: null,$albumDescription,$_POST['event_date'] ?: null,$_POST['event_date_end'] ?: null,$_POST['location_name'] ?: null,$_POST['sport_type'] ?: null,$_POST['author_name'] ?: null,$_POST['copyright_text'] ?: null,$coverPhotoId,$coverMode,$visibility,(int)($_POST['sort_order'] ?? 0),isset($_POST['download_enabled'])?1:0,$sourcePath,$_POST['seo_title'] ?: null,$albumDescription,$_POST['dbsportas_url'] ?: null,$_POST['klajunas_url'] ?: null,$_POST['other_url'] ?: null,$_POST['notes_internal'] ?: null,$_SESSION['admin']['id'] ?? null,$_SESSION['admin']['id'] ?? null]);
        $id=(int)db()->lastInsertId(); place_album_by_date($id); audit('album',$id,'create','Album created: '.$title);
    }
    persist_album_metadata_json($id);
    sync_tags('album',$id,(string)($_POST['tags'] ?? ''), array_map('intval', $_POST['tag_ids'] ?? []));
    if ($ajax) {
        // Naujam albumui grazinam ID: puslapis pagal ji atsidaro jau su nuotrauku
        // sekcija, ir naudotojui nereikia nei spausti "Save album", nei eiti i
        // albuma atskirai.
        json_exit(['ok' => true, 'id' => $id, 'created' => $wasNew, 'title' => $title]);
    }
    flash('Album saved.'); go(consume_return_path('?page=albums'));
}

function save_storage_path(): void {
    require_superadmin();
    csrf();
    $id=(int)($_POST['id'] ?? 0);
    if ($id <= 0) { flash('Album not found.', 'err'); go(consume_return_path('?page=albums')); }
    $st = db()->prepare("SELECT * FROM albums WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $album = $st->fetch(PDO::FETCH_ASSOC);
    if (!$album) { flash('Album not found.', 'err'); go(consume_return_path('?page=albums')); }
    $syncFromTitle = !empty($_POST['sync_from_title']);
    $titleSnapshot = trim((string)($_POST['title_snapshot'] ?? ''));
    $newSourcePath = trim((string)($_POST['source_path'] ?? ''));
    $oldSourcePath = trim((string)($_POST['original_source_path'] ?? (string)($album['source_path'] ?? '')));
    $detectedSourcePath = trim((string)($_POST['detected_source_path'] ?? ''));
    $renameConfirmed = !empty($_POST['confirm_storage_migration']);
    $returnPage = (string)($_POST['return_to'] ?? 'album');
    $backUrl = $returnPage === 'b2' ? '?page=b2#b2-folder-'.$id : '?page=album_edit&id='.$id;
    if ($syncFromTitle) {
        $newSourcePath = canonical_album_prefix($titleSnapshot !== '' ? $titleSnapshot : (string)($album['title'] ?? ''), $album['event_date'] ?? null, $album['event_date_end'] ?? null);
    }
    if ($newSourcePath === '') {
        flash('Storage path is required.', 'err');
        go($backUrl);
    }
    $detectedMoveNeeded = $detectedSourcePath !== '' && $detectedSourcePath !== $newSourcePath;
    if ($newSourcePath === $oldSourcePath && !$detectedMoveNeeded) {
        flash('Storage path unchanged.');
        go($backUrl);
    }
    if (!$renameConfirmed) {
        flash('Confirm the B2 storage move, then submit again.', 'err');
        go($backUrl);
    }
    $guess = album_storage_base_prefix(album_photo_prefix_guess($id));
    $renameSourcePath = $oldSourcePath;
    if ($detectedMoveNeeded) {
        $renameSourcePath = $detectedSourcePath;
    } elseif ($guess !== null && $guess !== '' && $guess !== $newSourcePath) {
        $renameSourcePath = $guess;
    } elseif ($renameSourcePath === '' || $renameSourcePath === $newSourcePath) {
        $renameSourcePath = $guess ?: $renameSourcePath;
    }
    // 2026-09-25: perkeliami TIK albumui priklausantys failai. Senas kelias
    // kopijuodavo ir trindavo VISA aplanka (zr. b2_owned_migration_plan).
    storage_migrate_album_owned($id, (string)($renameSourcePath !== '' ? $renameSourcePath : $oldSourcePath), $newSourcePath, $backUrl);
}

/* Albumo B2 perkelimo planas - TIK albumui priklausantys failai (gryna
 * funkcija, be B2/DB: testuojama atskirai).
 *
 * Kodel: 2026-09-24 albumus skaidem per "Priskirti kitam albumui" (kopijuoja,
 * seni failai lieka). Senas "Make canonical" kopijuodavo VISA aplanka, pagal
 * jame rastus vaizdus kurdavo DB eilutes ir trindavo VISA sena aplanka. Taip
 * perkeltos nuotraukos grizdavo i senaji albuma, o bendro aplanko (pvz. #48 ir
 * #566) kito albumo failai butu istrinti.
 *
 * Priklauso albumui:
 *  - failai, i kuriuos rodo SIO albumo eilutes ($albumRefs);
 *  - ju metadata/<vardas>.* sidecar'ai;
 *  - jpg-originals/, archive-originals/, thumbs/, previews/ su tuo paciu kamienu;
 *  - albumo failai: metadata/metadata.json, .album-netrinti.json.
 * Svetimi (i juos rodo KITU albumu eilutes) ir nepriskirti (visa kita, pvz.
 * perkeltu nuotrauku likuciai) - nekopijuojami ir netrinami.
 *
 * $srcFiles/$dstFiles: [pilnas vardas => ['size'=>int,'sha1'=>string]].
 */
function b2_owned_migration_plan(string $src, string $dst, array $albumRefs, array $otherRefs, array $srcFiles, array $dstFiles): array {
    $src = trim($src, '/'); $dst = trim($dst, '/');
    $other = [];
    foreach ($otherRefs as $r) { $r = trim((string)$r, '/'); if ($r !== '') $other[$r] = true; }
    $refsUnderSrc = []; $outside = [];
    foreach ($albumRefs as $r) {
        $r = trim((string)$r, '/');
        if ($r === '') continue;
        if (str_starts_with($r, $src.'/')) $refsUnderSrc[$r] = true;
        elseif (!str_starts_with($r, $dst.'/')) $outside[dirname($r)] = true;
    }
    $bases = []; $stems = [];
    foreach (array_keys($refsUnderSrc) as $r) {
        $b = basename($r); $bases[$b] = true;
        $stems[(string)pathinfo($b, PATHINFO_FILENAME)] = true;
    }
    $owned = []; $foreign = []; $unowned = []; $missingSrc = [];
    foreach (array_keys($refsUnderSrc) as $r) if (!isset($srcFiles[$r])) $missingSrc[] = $r;
    foreach ($srcFiles as $name => $_) {
        $name = trim((string)$name, '/');
        if (!str_starts_with($name, $src.'/')) continue;
        $rel = substr($name, strlen($src) + 1);
        if (isset($other[$name])) { $foreign[] = $name; continue; }
        $b = basename($rel);
        $isOwned = isset($refsUnderSrc[$name])
            || $rel === '.album-netrinti.json' || $rel === 'metadata/metadata.json';
        if (!$isOwned && str_starts_with($rel, 'metadata/')) {
            foreach ($bases as $ob => $_x) { if (str_starts_with($b, $ob.'.')) { $isOwned = true; break; } }
        }
        if (!$isOwned && preg_match('~^(jpg-originals|archive-originals|thumbs|previews)/~', $rel)) {
            $isOwned = isset($stems[(string)pathinfo($b, PATHINFO_FILENAME)]);
        }
        if ($isOwned) $owned[$name] = $dst.'/'.$rel; else $unowned[] = $name;
    }
    $expected = array_flip(array_values($owned));
    $dstConflicts = [];
    foreach ($dstFiles as $d => $meta) {
        $d = trim((string)$d, '/');
        if (!str_starts_with($d, $dst.'/')) continue;
        if (isset($other[$d])) { $dstConflicts[] = $d.' (kito albumo)'; continue; }
        if (!isset($expected[$d])) { $dstConflicts[] = $d; continue; }
        $s = array_search($d, $owned, true);
        if ($s !== false && !b2_same_content($srcFiles[$s] ?? [], (array)$meta)) $dstConflicts[] = $d.' (kitas turinys)';
    }
    // Failai, i kuriuos rodo IR sis, IR kitas albumas - bendri; jiems perkelti
    // reikia zmogaus sprendimo (pvz. #48 ir #566 viename aplanke).
    $shared = array_values(array_filter(array_keys($refsUnderSrc), fn($r) => isset($other[$r])));
    return ['owned'=>$owned, 'foreign'=>$foreign, 'unowned'=>$unowned, 'outside'=>array_keys($outside), 'shared'=>$shared,
            'dst_conflicts'=>$dstConflicts, 'missing_src'=>$missingSrc];
}
// Ar du B2 failai to paties turinio: SHA1, o jei jo nera (didelis failas) - dydis.
function b2_same_content(array $a, array $b): bool {
    if (!isset($a['size'], $b['size']) || (int)$a['size'] !== (int)$b['size']) return false;
    $sa = preg_replace('~^unverified:~', '', (string)($a['sha1'] ?? ''));
    $sb = preg_replace('~^unverified:~', '', (string)($b['sha1'] ?? ''));
    if ($sa === '' || $sb === '' || $sa === 'none' || $sb === 'none') return true;
    return strtolower($sa) === strtolower($sb);
}
// Sena kopija trinama TIK jei tame paciame santykiniame kelyje naujame aplanke
// guli identiska kopija ir i sena failo varda nerodo joks kitas albumas.
function b2_owned_deletable(string $src, string $dst, array $owned, array $otherRefs, array $srcFiles, array $dstFiles): array {
    $other = [];
    foreach ($otherRefs as $r) $other[trim((string)$r, '/')] = true;
    $out = [];
    foreach ($owned as $s => $d) {
        if (isset($other[$s]) || !isset($srcFiles[$s], $dstFiles[$d])) continue;
        if (b2_same_content($srcFiles[$s], $dstFiles[$d])) $out[] = $s;
    }
    return $out;
}
function storage_album_ref_columns(): array {
    static $cols = null;
    if ($cols !== null) return $cols;
    $cols = [];
    foreach (['b2_key','compatibility_b2_key','original_b2_key','thumb_path','preview_path','web_path'] as $c) {
        try { if (db()->query("SHOW COLUMNS FROM photos LIKE '".$c."'")->fetch()) $cols[] = $c; } catch (Throwable $e) {}
    }
    return $cols;
}
function storage_album_refs(int $albumId, bool $others, array $prefixes): array {
    $cols = storage_album_ref_columns();
    $out = [];
    foreach ($cols as $c) {
        $w = []; $p = [$albumId];
        foreach ($prefixes as $pre) { $w[] = "$c LIKE ?"; $p[] = trim($pre, '/').'/%'; }
        $sql = "SELECT $c FROM photos WHERE album_id".($others ? '<>' : '=')."?".($others ? ' AND ('.implode(' OR ', $w).')' : " AND $c IS NOT NULL AND $c<>''");
        $st = db()->prepare($sql); $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $v) { $v = trim((string)$v, '/'); if ($v !== '') $out[$v] = true; }
    }
    return array_keys($out);
}
function storage_b2_meta_map(string $prefix): array {
    $map = [];
    foreach (b2_list_prefix($prefix, 50, true) as $f) {
        $n = trim((string)($f['fileName'] ?? ''), '/');
        if ($n === '') continue;
        $map[$n] = ['size'=>(int)($f['contentLength'] ?? 0), 'sha1'=>(string)($f['contentSha1'] ?? ''), 'file'=>$f];
    }
    return $map;
}
/* Vykdo b2_owned_migration_plan: kopijuoja -> tikrina SHA1 -> perjungia DB ->
 * trina tik patikrintas senas priklausanciu failu kopijas. Nutrukus laiko
 * ribai kartojama tuo paciu mygtuku (jau nukopijuoti failai praleidziami). */
function storage_migrate_album_owned(int $id, string $srcPath, string $dst, string $backUrl): void {
    $src = album_storage_base_prefix($srcPath);
    $dst = trim($dst, '/');
    if ($src === '' || canonical_path_equal($src, $dst)) { flash('Senas ir naujas kelias sutampa - nieko nekeičiama.', 'err'); go($backUrl); }
    b2_load_config();
    try {
        $albumRefs = storage_album_refs($id, false, []);
        $otherRefs = storage_album_refs($id, true, [$src, $dst]);
        $srcFiles = storage_b2_meta_map($src);
        $dstFiles = storage_b2_meta_map($dst);
    } catch (Throwable $e) {
        flash('Perkėlimas sustabdytas, nieko nepakeista: '.$e->getMessage(), 'err'); go($backUrl);
    }
    $plan = b2_owned_migration_plan($src, $dst, $albumRefs, $otherRefs, $srcFiles, $dstFiles);
    if ($plan['outside']) {
        flash('Perkėlimas sustabdytas, nieko nepakeista: albumo nuotraukos yra ir kituose aplankuose ('.implode(', ', array_slice($plan['outside'], 0, 5)).'). Pirma sutvarkyk jas (Priskirti kitam albumui / B2 Sync).', 'err'); go($backUrl);
    }
    if ($plan['shared']) {
        flash('Perkėlimas sustabdytas, nieko nepakeista: '.count($plan['shared']).' šio albumo failus naudoja ir kitas albumas (pvz. '.$plan['shared'][0].'). Pirma nuspręsk, kuriam albumui jie priklauso.', 'err'); go($backUrl);
    }
    if ($plan['dst_conflicts']) {
        flash('Perkėlimas sustabdytas, nieko nepakeista: tiksliniame aplanke "'.$dst.'" yra šiam albumui nepriklausančių failų: '.implode(', ', array_slice($plan['dst_conflicts'], 0, 5)).(count($plan['dst_conflicts']) > 5 ? ' …' : '').'.', 'err'); go($backUrl);
    }
    if (!$plan['owned']) { flash('Perkėlimas sustabdytas: sename aplanke "'.$src.'" nerasta šiam albumui priklausančių failų.', 'err'); go($backUrl); }
    // Kopijavimas (porcijomis; jau esantys identiski praleidziami).
    $deadline = microtime(true) + b2_time_budget();
    $copied = 0; $remaining = 0;
    foreach ($plan['owned'] as $s => $d) {
        if (isset($dstFiles[$d]) && b2_same_content($srcFiles[$s], $dstFiles[$d])) continue;
        if (microtime(true) > $deadline) { $remaining++; continue; }
        @set_time_limit(30);
        try { b2_copy_file_version($srcFiles[$s]['file'], $d); $copied++; }
        catch (Throwable $e) { flash('Kopijavimo klaida ('.$s.'): '.$e->getMessage().' DB nepakeista.', 'err'); go($backUrl); }
    }
    if ($remaining > 0) {
        flash('Nukopijuota '.$copied.', liko '.$remaining.' failų. DB dar nepakeista - spausk tą patį mygtuką dar kartą.'); go($backUrl);
    }
    // Patikra: kiekvienas priklausantis failas naujoje vietoje, tas pats turinys.
    try { $dstFiles = storage_b2_meta_map($dst); }
    catch (Throwable $e) { flash('Patikra nepavyko, DB nepakeista: '.$e->getMessage(), 'err'); go($backUrl); }
    $bad = [];
    foreach ($plan['owned'] as $s => $d) if (!isset($dstFiles[$d]) || !b2_same_content($srcFiles[$s], $dstFiles[$d])) $bad[] = $d;
    if ($bad) { flash('Patikra nepavyko ('.count($bad).' failai, pvz. '.$bad[0].'). DB nepakeista.', 'err'); go($backUrl); }
    // DB perjungimas - tik sio albumo eilutes, visuose kelio stulpeliuose.
    try {
        db()->beginTransaction();
        foreach (storage_album_ref_columns() as $c) {
            db()->prepare("UPDATE photos SET $c = CONCAT(?, SUBSTRING($c, ?)), updated_by=? WHERE album_id=? AND $c LIKE ?")
                ->execute([$dst, strlen($src) + 1, $_SESSION['admin']['id'] ?? null, $id, $src.'/%']);
        }
        db()->prepare("UPDATE albums SET source_path=?, updated_by=? WHERE id=?")->execute([$dst, $_SESSION['admin']['id'] ?? null, $id]);
        $st = db()->prepare("SELECT b2_key FROM photos WHERE album_id=? AND is_missing=0 AND b2_key IS NOT NULL AND b2_key<>''");
        $st->execute([$id]);
        $notFound = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) { $k = trim((string)$k, '/'); if (!isset($dstFiles[$k])) $notFound[] = $k; }
        if ($notFound) throw new RuntimeException(count($notFound).' DB nuotraukų nerasta naujame aplanke (pvz. '.$notFound[0].')');
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash('DB perjungimas atšauktas, niekas nepakeista: '.$e->getMessage(), 'err'); go($backUrl);
    }
    persist_album_metadata_json($id);
    audit('album', $id, 'storage_move_owned', 'B2 perkelta (tik albumo failai): '.$src.' -> '.$dst,
        ['owned'=>count($plan['owned']), 'copied'=>$copied, 'unowned_left'=>count($plan['unowned']), 'foreign_left'=>count($plan['foreign'])]);
    // Seni failai: trinam tik identiskai nukopijuotas priklausancias kopijas.
    $deleted = 0; $delLeft = 0;
    try {
        $otherNow = storage_album_refs($id, true, [$src]);
        $deletable = b2_owned_deletable($src, $dst, $plan['owned'], $otherNow, $srcFiles, $dstFiles);
        $deadline = microtime(true) + b2_time_budget();
        foreach ($deletable as $s) {
            if (microtime(true) > $deadline) { $delLeft++; continue; }
            @set_time_limit(30);
            b2_delete_file_version((string)($srcFiles[$s]['file']['fileId'] ?? ''), $s);
            $deleted++;
        }
    } catch (Throwable $e) {
        flash('DB perjungta, bet senų kopijų valymas nepavyko: '.$e->getMessage(), 'err');
    }
    $msg = 'Perkelta į "'.$dst.'": '.count($plan['owned']).' albumo failai (nukopijuota '.$copied.'), patikrinta SHA1, DB perjungta. Senų kopijų ištrinta '.$deleted.'.';
    if ($delLeft > 0) $msg .= ' Liko ištrinti '.$delLeft.' - B2 puslapyje senas aplankas liks, jį galima išvalyti pakartotinai.';
    if ($plan['unowned']) $msg .= ' Sename aplanke palikta '.count($plan['unowned']).' šiam albumui nepriklausančių failų (pvz. perkeltų nuotraukų kopijos) - jie netrinti.';
    if ($plan['foreign']) $msg .= ' Palikta '.count($plan['foreign']).' kito albumo failų - jie netrinti.';
    flash($msg);
    go($backUrl);
}

function save_photo(): void {
    csrf(); $id=(int)($_POST['id'] ?? 0);
    $existingPhoto = $id > 0 ? require_photo_editable_by_id($id) : null;
    $targetAlbumId = (int)($_POST['album_id'] ?? 0);
    if ($targetAlbumId > 0 && !is_superadmin()) require_album_editable_by_id($targetAlbumId);
    $albumDownloadEnabled = 1;
    if (!empty($_POST['album_id'])) {
        $q = db()->prepare("SELECT download_enabled FROM albums WHERE id=? LIMIT 1");
        $q->execute([$targetAlbumId]);
        $albumDownloadEnabled = (int)$q->fetchColumn() ?: 0;
    }
    $downloadable = isset($_POST['is_downloadable']) ? 1 : 0;
    $photoVisibility = visibility_from_input($_POST['visibility'] ?? '');
    if ($photoVisibility === null) { flash(invalid_visibility_message($_POST['visibility'] ?? ''), 'err'); go('?page=photo_edit&id='.$id); }
    db()->prepare("UPDATE photos SET album_id=?,title=?,caption=?,alt_text=?,author_name=?,copyright_text=?,credit_line=?,taken_at=?,city=?,country=?,sort_order=?,visibility=?,description=?,notes_internal=?,is_downloadable=?,is_cover_candidate=?,is_missing=?,updated_by=? WHERE id=?")
        ->execute([$targetAlbumId,$_POST['title'] ?: null,$_POST['caption'] ?: null,$_POST['alt_text'] ?: null,$_POST['author_name'] ?: null,$_POST['copyright_text'] ?: null,$_POST['credit_line'] ?: null,$_POST['taken_at'] ?: null,$_POST['city'] ?: null,$_POST['country'] ?: null,(int)$_POST['sort_order'],$photoVisibility,$_POST['description'] ?: null,$_POST['notes_internal'] ?: null,$downloadable,isset($_POST['is_cover_candidate'])?1:0,isset($_POST['is_missing'])?1:0,$_SESSION['admin']['id'] ?? null,$id]);
    persist_photo_metadata_json($id);
    sync_tags('photo',$id,(string)($_POST['tags'] ?? ''));
    $returnAlbumId=(int)($_POST['return_album_id'] ?? $targetAlbumId ?? 0);
    audit('photo',$id,'update','Photo updated'); flash('Photo saved.'); go($returnAlbumId > 0 ? '?page=album_edit&id='.$returnAlbumId : '?page=photos');
}

function uploaded_album_photo_files(): array {
    $f = $_FILES['photo_files'] ?? null;
    if (!$f || empty($f['tmp_name'])) return [];
    $compatibilityByOriginal = [];
    $cf = $_FILES['photo_compatibility_files'] ?? null;
    $originalNames = (array)($_POST['photo_compatibility_original_names'] ?? []);
    if ($cf && !empty($cf['tmp_name'])) {
        foreach ((array)$cf['tmp_name'] as $i => $tmp) {
            $err = (int)($cf['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($err !== UPLOAD_ERR_OK) continue;
            $original = basename(str_replace('\\', '/', (string)($originalNames[$i] ?? '')));
            if ($original === '') continue;
            $name = str_replace('\\', '/', (string)($cf['name'][$i] ?? ''));
            $compatibilityByOriginal[strtolower($original)] = [
                'tmp' => $tmp,
                'name' => $name,
                'base' => basename($name),
                'size' => (int)($cf['size'][$i] ?? 0),
                'type' => (string)($cf['type'][$i] ?? 'application/octet-stream'),
            ];
        }
    }
    $previewMetaByOriginal = [];
    $previewNames = (array)($_POST['photo_preview_original_names'] ?? []);
    $previewStatuses = (array)($_POST['photo_preview_statuses'] ?? []);
    $previewErrors = (array)($_POST['photo_preview_errors'] ?? []);
    foreach ($previewNames as $i => $name) {
        $base = strtolower(basename(str_replace('\\', '/', (string)$name)));
        if ($base === '') continue;
        $status = strtolower(trim((string)($previewStatuses[$i] ?? '')));
        if (!in_array($status, ['ready','failed','unsupported','pending'], true)) $status = '';
        $previewMetaByOriginal[$base] = [
            'status' => $status,
            'error' => trim((string)($previewErrors[$i] ?? '')),
        ];
    }
    $out = [];
    foreach ((array)$f['tmp_name'] as $i => $tmp) {
        $err = (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) continue;
        $name = str_replace('\\', '/', (string)($f['name'][$i] ?? ''));
        $base = basename($name);
        $row = ['tmp'=>$tmp,'name'=>$name,'base'=>$base,'size'=>(int)($f['size'][$i] ?? 0),'type'=>(string)($f['type'][$i] ?? 'application/octet-stream')];
        $compatibility = $compatibilityByOriginal[strtolower($base)] ?? null;
        if ($compatibility) $row['compatibility_file'] = $compatibility;
        $previewMeta = $previewMetaByOriginal[strtolower($base)] ?? null;
        if ($previewMeta) {
            $row['preview_status'] = $previewMeta['status'];
            $row['preview_error'] = $previewMeta['error'];
        }
        $out[] = $row;
    }
    return $out;
}
function b2_album_file_maps(string $prefix): array {
    $prefix = trim($prefix, '/');
    $keys = [];
    $originalByBase = [];
    $originalBySafeBase = [];
    $originalInfo = [];
    if ($prefix === '') return [$keys, $originalByBase, $originalBySafeBase, $originalInfo];
    foreach (b2_list_prefix($prefix, 20) as $file) {
        $key = trim((string)($file['fileName'] ?? ''), '/');
        if ($key === '') continue;
        $keys[$key] = $file;
        if (!str_starts_with($key, $prefix.'/originals/')) continue;
        if (!takeout_media_file($key)) continue;
        $stored = basename($key);
        $base = preg_replace('/^\d{3,6}_/', '', $stored) ?: $stored;
        $originalByBase[strtolower($base)][] = $key;
        $originalBySafeBase[strtolower(safe_b2_name($base))][] = $key;
        $originalInfo[$key] = $file;
    }
    return [$keys, $originalByBase, $originalBySafeBase, $originalInfo];
}
function album_upload_candidate_keys(array $byBase, array $bySafeBase, string $base): array {
    $keys = [];
    foreach ((array)($byBase[strtolower($base)] ?? []) as $key) $keys[$key] = true;
    foreach ((array)($bySafeBase[strtolower(safe_b2_name($base))] ?? []) as $key) $keys[$key] = true;
    return array_keys($keys);
}
function same_upload_identity(array $file, array $exif, ?array $photo, ?array $b2File): bool {
    $b2Size = isset($b2File['contentLength']) ? (int)$b2File['contentLength'] : 0;
    $dbSize = $photo && isset($photo['file_size']) ? (int)$photo['file_size'] : 0;
    $knownSize = $b2Size > 0 ? $b2Size : $dbSize;
    if ($knownSize > 0 && (int)$file['size'] > 0 && $knownSize !== (int)$file['size']) return false;
    if ($photo) {
        if (!empty($photo['original_filename']) && strcasecmp((string)$photo['original_filename'], (string)$file['base']) !== 0) return false;
        foreach (['width', 'height'] as $field) {
            $incoming = isset($exif[$field]) ? (int)$exif[$field] : 0;
            $stored = isset($photo[$field]) ? (int)$photo[$field] : 0;
            if ($incoming > 0 && $stored > 0 && $incoming !== $stored) return false;
        }
        if (!empty($exif['mime_type']) && !empty($photo['mime_type']) && strcasecmp((string)$exif['mime_type'], (string)$photo['mime_type']) !== 0) return false;
    }
    return true;
}
/* Galerijos (b2-gallery.php) B2 failu saraso podelis albumo aplankui.
 * Galerija rodo tik tas DB nuotraukas, kuriu failas yra siame sarase, o sarasas
 * laikomas 6 h. Be isvalymo ka tik ikeltos ar perkeltos nuotraukos esamame
 * albume viesai nesimatydavo iki 6 h (2026-09-26, #1 ir #624 po Flickr).
 * Kelias ir raktas turi sutapti su b2-gallery.php: <domeno katalogas>/cache/
 * b2_filelist/sha256(B2_BUCKET_ID|source_path/).json. admin/ yra public_html
 * viduje, todel domeno katalogas - dirname(__DIR__, 2). */
function gallery_list_cache_invalidate(string $sourcePath): bool {
    $sourcePath = trim($sourcePath, '/');
    if ($sourcePath === '') return false;
    if (!defined('B2_BUCKET_ID')) { try { b2_load_config(); } catch (Throwable $e) { return false; } }
    if (!defined('B2_BUCKET_ID')) return false;
    $file = dirname(__DIR__, 2).'/cache/b2_filelist/'.hash('sha256', (string)B2_BUCKET_ID.'|'.$sourcePath.'/').'.json';
    return is_file($file) ? @unlink($file) : false;
}
function gallery_list_cache_invalidate_album(int $albumId): void {
    if ($albumId <= 0) return;
    try {
        $st = db()->prepare("SELECT source_path FROM albums WHERE id=? LIMIT 1");
        $st->execute([$albumId]);
        gallery_list_cache_invalidate((string)($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        // Podelio isvalymas - ne kritinis: blogiausiu atveju nuotraukos pasirodys po TTL.
    }
}
function admin_img_cache_dir(): string {
    $dir = dirname(__DIR__) . '/cache/b2_img_cache';
    return is_dir($dir) ? $dir : '';
}
function purge_img_cache_for_b2_key(string $key): void {
    if ($key === '' || !defined('B2_BUCKET')) return;
    $dir = admin_img_cache_dir();
    if ($dir === '') return;
    $variants = [
        [420, 280, 'cover', 76, 'webp'],
        [160, 280, 'cover', 76, 'webp'],
        [1600, 1200, 'contain', 84, 'webp'],
        [420, 0, 'cover', 76, 'webp'],
        [1400, 0, 'cover', 83, 'webp'],
    ];
    foreach ($variants as [$w, $h, $fit, $q, $fmt]) {
        $hash = hash('sha256', (string)B2_BUCKET . '|' . $key . "|w={$w}|h={$h}|fit={$fit}|q={$q}|fmt={$fmt}");
        foreach (glob($dir.'/'.$hash.'.*') ?: [] as $path) @unlink($path);
    }
}
function album_photo_for_takeout_json(int $albumId, string $name): ?array {
    $targetKey = takeout_name_key($name);
    if ($targetKey === '') return null;
    $st = db()->prepare("SELECT * FROM photos WHERE album_id=? ORDER BY id ASC");
    $st->execute([$albumId]);
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $candidates = [
            (string)($row['original_filename'] ?? ''),
            (string)($row['stored_filename'] ?? ''),
            basename((string)($row['b2_key'] ?? '')),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== '' && takeout_name_key($candidate) === $targetKey) return $row;
        }
    }
    return null;
}
function apply_takeout_json_to_photo(int $photoId, array $j): void {
    $taken = takeout_photo_taken_time($j);
    $lat = takeout_geo_value($j, 'latitude');
    $lon = takeout_geo_value($j, 'longitude');
    $views = takeout_image_views($j);
    db()->prepare("UPDATE photos SET photo_views=COALESCE(?,photo_views), title=COALESCE(NULLIF(?,''),title), description=COALESCE(NULLIF(?,''),description), taken_at=COALESCE(?,taken_at), latitude=COALESCE(?,latitude), longitude=COALESCE(?,longitude), metadata_json=?, updated_by=? WHERE id=?")
        ->execute([$views,(string)($j['title'] ?? ''),(string)($j['description'] ?? ''),$taken,$lat?:null,$lon?:null,json_encode($j, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$_SESSION['admin']['id']??null,$photoId]);
    persist_photo_metadata_json($photoId);
}
function uploaded_album_metadata_json_files(): array {
    $f = $_FILES['metadata_json_files'] ?? null;
    if (!$f || empty($f['tmp_name'])) return [];
    $out = [];
    foreach ((array)$f['tmp_name'] as $i => $tmp) {
        $err = (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) continue;
        $name = str_replace('\\', '/', (string)($f['name'][$i] ?? ''));
        $base = basename($name);
        if (!preg_match('/\.json$/i', $base)) continue;
        $json = json_decode((string)file_get_contents((string)$tmp), true);
        if (!is_array($json)) continue;
        $out[] = [
            'tmp' => (string)$tmp,
            'name' => $name,
            'base' => $base,
            'size' => (int)($f['size'][$i] ?? 0),
            'type' => (string)($f['type'][$i] ?? 'application/json'),
        ];
    }
    return $out;
}
function backfill_album_metadata_json(): void {
    csrf(); ensure_overlay_schema(); b2_load_config();
    $albumId = (int)($_POST['album_id'] ?? 0);
    $album = require_album_editable_by_id($albumId);
    $prefix = trim((string)($album['source_path'] ?? ''), '/');
    if ($prefix === '') { flash('Album has no B2 source path. Set storage path first.', 'err'); go('?page=album_edit&id='.$albumId); }
    $files = uploaded_album_metadata_json_files();
    if (!$files) { flash('No valid JSON metadata files selected.', 'err'); go('?page=album_edit&id='.$albumId); }

    [$albumJson, , $jsonFiles] = takeout_json_maps($files);
    append_album_takeout_internal_notes($albumId, $albumJson, $jsonFiles);

    $upload = b2_upload_url();
    $b2Keys = [];
    foreach (b2_list_prefix($prefix.'/metadata', 20) as $file) {
        $name = trim((string)($file['fileName'] ?? ''), '/');
        if ($name !== '') $b2Keys[$name] = true;
    }

    $jsonRead = count($jsonFiles);
    $updated = 0;
    $skipped = 0;
    $sidecarsUploaded = 0;
    $seenPhotoIds = [];

    if ($albumJson) {
        b2_upload_data(json_encode($albumJson, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), $prefix.'/metadata/metadata.json', 'application/json', $upload);
        $sidecarsUploaded++;
    }

    foreach ($jsonFiles as $jsonName => $jsonInfo) {
        $j = $jsonInfo['json'] ?? null;
        if (!takeout_photo_metadata_json((string)$jsonName, is_array($j) ? $j : null)) continue;
        if (!is_array($j)) { $skipped++; continue; }
        $photoName = google_photo_name((string)$jsonName, $j);
        $photo = album_photo_for_takeout_json($albumId, $photoName);
        if (!$photo && !empty($j['title'])) $photo = album_photo_for_takeout_json($albumId, basename((string)$j['title']));
        if (!$photo) { $skipped++; continue; }
        $photoId = (int)$photo['id'];
        if (!isset($seenPhotoIds[$photoId])) {
            apply_takeout_json_to_photo($photoId, $j);
            $seenPhotoIds[$photoId] = true;
            $updated++;
        }
        $stored = (string)($photo['stored_filename'] ?? '');
        if ($stored === '') $stored = basename((string)($photo['b2_key'] ?? ''));
        if ($stored === '') continue;
        $sidecarKey = $prefix.'/metadata/'.$stored.'.supplemental-metadata.json';
        b2_upload_data(json_encode($j, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), $sidecarKey, 'application/json', $upload);
        $b2Keys[$sidecarKey] = true;
        $sidecarsUploaded++;
    }

    persist_album_metadata_json($albumId);
    audit('photo', $albumId, 'metadata_backfill', 'Album metadata JSON backfilled', ['json_read'=>$jsonRead,'photos_updated'=>$updated,'sidecars_uploaded'=>$sidecarsUploaded,'skipped'=>$skipped]);
    flash("Metadata backfill finished: $jsonRead JSON files read, $updated photos updated, $sidecarsUploaded JSON files written to B2, $skipped skipped.");
    go('?page=album_edit&id='.$albumId);
}
function upload_album_photos(): void {
    csrf(); ensure_overlay_schema(); b2_load_config();
    $albumId = (int)($_POST['album_id'] ?? 0);
    $jsonMode = wants_json_response();
    $album = require_album_editable_by_id($albumId);
    $files = uploaded_album_photo_files();
    if (!$files) {
        if ($jsonMode) json_exit(['ok' => false, 'error' => 'No photo files selected.'], 400);
        flash('No photo files selected.', 'err'); go('?page=album_edit&id='.$albumId);
    }
    [$albumJson,$jsonByBase,$jsonFiles] = takeout_json_maps($files);
    $prefix = trim((string)($album['source_path'] ?? ''), '/');
    if ($prefix === '') {
        if ($jsonMode) json_exit(['ok' => false, 'error' => 'Album has no B2 source path. Set storage path first.'], 400);
        flash('Album has no B2 source path. Set storage path first.', 'err'); go('?page=album_edit&id='.$albumId);
    }
    $duplicateMode = (string)($_POST['duplicate_mode'] ?? 'skip');
    if (!in_array($duplicateMode, ['skip', 'overwrite'], true)) $duplicateMode = 'skip';
    $returnSortPreset = (string)($_POST['sort_preset'] ?? '');
    if (!in_array($returnSortPreset, ['taken_asc','taken_desc','title_asc','title_desc','size_asc','size_desc','custom'], true)) $returnSortPreset = '';
    // Tuscia -> pagal albuma; nezinoma (pvz. 'public' be alias'o) -> klaida, ne tylus draft.
    $visibility = visibility_from_input($_POST['visibility'] ?? '', ((string)($album['visibility'] ?? '') === 'published') ? 'published' : 'draft');
    if ($visibility === null) {
        $msg = invalid_visibility_message($_POST['visibility'] ?? '');
        if ($jsonMode) json_error($msg, 400);
        flash($msg, 'err'); go('?page=album_edit&id='.$albumId);
    }
    $albumDownloadEnabled = (int)($album['download_enabled'] ?? 1);
    $maxSort = (int)db()->query("SELECT COALESCE(MAX(sort_order),0) FROM photos WHERE album_id=".$albumId)->fetchColumn();
    $count = (int)db()->query("SELECT COUNT(*) FROM photos WHERE album_id=".$albumId)->fetchColumn();
    $upload = b2_upload_url();
    ensure_album_marker_file($album, $upload);
    [$b2Keys, $b2OriginalByBase, $b2OriginalBySafeBase, $b2OriginalInfo] = b2_album_file_maps($prefix);
    $uploaded = 0; $overwritten = 0; $compatibilityUploaded = 0; $compatibilityMissing = 0; $compatibilityInvalid = 0; $jsonUploaded = 0; $skipped = 0; $created = 0; $updated = 0; $jsonBackfilled = 0;
    $byKey = db()->prepare("SELECT id FROM photos WHERE b2_key=? LIMIT 1");
    $byAlbumKey = db()->prepare("SELECT * FROM photos WHERE album_id=? AND b2_key=? LIMIT 1");
    $byOriginal = db()->prepare("SELECT * FROM photos WHERE album_id=? AND original_filename=? ORDER BY is_missing ASC, id ASC");

    if ($albumJson) {
        $albumMetaKey = $prefix.'/metadata/metadata.json';
        if ($duplicateMode === 'overwrite' || !isset($b2Keys[$albumMetaKey])) {
            b2_upload_data(json_encode($albumJson, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), $albumMetaKey, 'application/json', $upload);
            $b2Keys[$albumMetaKey] = ['fileName' => $albumMetaKey];
            $jsonUploaded++;
        }
    }

    foreach ($files as $file) {
        if (preg_match('/\.json$/i', $file['base'])) continue;
        if (!takeout_media_file($file['base'])) { $skipped++; continue; }
        $compatibilityFile = isset($file['compatibility_file']) && is_array($file['compatibility_file']) ? $file['compatibility_file'] : null;
        $isHeicOriginal = is_heic_name((string)$file['base']);
        $validCompatibility = false;
        if ($compatibilityFile) {
            $validCompatibility = valid_generated_heic_display((string)$compatibilityFile['tmp'], (string)$compatibilityFile['base']);
            if (!$validCompatibility) {
                $compatibilityFile = null;
                $compatibilityInvalid++;
                $file['preview_error'] = HEIC_INVALID_DISPLAY_MESSAGE;
            }
        }
        if ($isHeicOriginal && !$compatibilityFile) $compatibilityMissing++;

        $dbOriginalName = (string)$file['base'];
        $j = takeout_json_for_file($jsonByBase, $dbOriginalName);
        $metadataFile = $compatibilityFile ?: $file;
        $exif = image_metadata((string)$metadataFile['tmp'], (string)$metadataFile['base']);
        if ($isHeicOriginal) $exif['mime_type'] = image_mime_from_name((string)$file['base']) ?: 'image/heic';
        $identityFile = $file;
        $identityFile['base'] = $dbOriginalName;
        $byOriginal->execute([$albumId, $dbOriginalName]);
        $sameNameRows = $byOriginal->fetchAll(PDO::FETCH_ASSOC);
        $existing = null;
        foreach ($sameNameRows as $row) {
            if ((int)($row['is_missing'] ?? 0) === 1 && same_upload_identity($identityFile, $exif, $row, null)) {
                $existing = $row;
                break;
            }
        }
        $realDuplicateKey = '';
        foreach ($sameNameRows as $row) {
            $rowKey = trim((string)($row['b2_key'] ?? ''), '/');
            if ($rowKey === '' || !isset($b2Keys[$rowKey])) continue;
            if (!same_upload_identity($identityFile, $exif, $row, $b2Keys[$rowKey])) continue;
            $existing = $row;
            $realDuplicateKey = $rowKey;
            break;
        }
        if ($realDuplicateKey === '') {
            foreach (album_upload_candidate_keys($b2OriginalByBase, $b2OriginalBySafeBase, $file['base']) as $candidateKey) {
                $byAlbumKey->execute([$albumId, $candidateKey]);
                $candidateRow = $byAlbumKey->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!same_upload_identity($identityFile, $exif, $candidateRow, $b2OriginalInfo[$candidateKey] ?? $b2Keys[$candidateKey] ?? null)) continue;
                $existing = $candidateRow ?: $existing;
                $realDuplicateKey = $candidateKey;
                break;
            }
        }
        if (!$existing && $realDuplicateKey !== '') {
            $byAlbumKey->execute([$albumId, $realDuplicateKey]);
            $existing = $byAlbumKey->fetch(PDO::FETCH_ASSOC);
        }

        if ($realDuplicateKey !== '') {
            $key = $realDuplicateKey;
            $targetName = basename($key);
        } else {
            // Pirmiausia bandom svaru varda be jokio priesdelio. "0001_" buvo
            // kabinamas visiems failams, nors jo vienintele funkcija - garantuoti
            // unikalu B2 rakta. Del jo vardai galerijoje atrodydavo kaip
            // "0001_inbound2439752568...jpg", o rikiuojant pagal pavadinima jis
            // dar ir nustelbdavo tikraji varda. Dabar priesdelis atsiranda tik
            // tada, kai svarus vardas jau uzimtas.
            $cleanName = safe_b2_name($file['base']);
            $targetName = $cleanName;
            $key = $prefix.'/originals/'.$targetName;
            while (true) {
                $byKey->execute([$key]);
                $keyOwner = (int)$byKey->fetchColumn();
                if ((!$keyOwner || ($existing && $keyOwner === (int)$existing['id'])) && !isset($b2Keys[$key])) break;
                $count++;
                $targetName = str_pad((string)$count, 4, '0', STR_PAD_LEFT).'_'.$cleanName;
                $key = $prefix.'/originals/'.$targetName;
            }
        }

        $compatibilityKey = null;
        if ($compatibilityFile) {
            $compatBase = preg_replace('~\.[^.]+$~', '', $targetName) ?: $targetName;
            $compatibilityTargetName = $compatBase.'.jpg';
            $compatibilityKey = $prefix.'/jpg-originals/'.$compatibilityTargetName;
        }
        $shouldUploadMedia = $realDuplicateKey === '' || $duplicateMode === 'overwrite';
        if ($shouldUploadMedia) {
            b2_upload_file($file['tmp'], $key, image_mime_from_name((string)$file['base']) ?: ($file['type'] ?: 'application/octet-stream'), $upload);
            $uploaded++;
            if ($compatibilityFile && $compatibilityKey) {
                b2_upload_file((string)$compatibilityFile['tmp'], $compatibilityKey, 'image/jpeg', $upload);
                $b2Keys[$compatibilityKey] = ['fileName' => $compatibilityKey, 'contentLength' => (int)($compatibilityFile['size'] ?? 0)];
                $compatibilityUploaded++;
            }
            if ($realDuplicateKey !== '') {
                $overwritten++;
                purge_img_cache_for_b2_key($key);
                if ($compatibilityKey) purge_img_cache_for_b2_key($compatibilityKey);
            }
            $b2Keys[$key] = ['fileName' => $key, 'contentLength' => $file['size']];
        } else {
            $existingCompat = $existing ? trim((string)($existing['compatibility_b2_key'] ?? ''), '/') : '';
            if ($existingCompat !== '' && isset($b2Keys[$existingCompat])) {
                $compatibilityKey = $existingCompat;
            } elseif ($compatibilityFile && $compatibilityKey) {
                b2_upload_file((string)$compatibilityFile['tmp'], $compatibilityKey, 'image/jpeg', $upload);
                $b2Keys[$compatibilityKey] = ['fileName' => $compatibilityKey, 'contentLength' => (int)($compatibilityFile['size'] ?? 0)];
                $compatibilityUploaded++;
                purge_img_cache_for_b2_key($compatibilityKey);
            } elseif ($existingCompat !== '') {
                $compatibilityKey = $existingCompat;
            }
            $skipped++;
        }
        $taken = takeout_photo_taken_time($j, $exif);
        $taken = $taken ?: $exif['taken_at'];
        $lat = $j ? takeout_geo_value($j, 'latitude') : null;
        $lon = $j ? takeout_geo_value($j, 'longitude') : null;
        $lat = $lat ?: $exif['latitude'];
        $lon = $lon ?: $exif['longitude'];
        $views = $j ? takeout_image_views($j) : null;
        $metaJson = $j ? json_encode($j, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null;
        $b2Size = (int)($b2OriginalInfo[$key]['contentLength'] ?? $file['size']);
        $sidecarKey = $prefix.'/metadata/'.$targetName.'.supplemental-metadata.json';
        $originalFormat = photo_original_format($dbOriginalName);
        $previewState = photo_preview_state($originalFormat, $compatibilityKey, photo_preview_error_from_upload($file));

        if ($existing) {
            $pid = (int)$existing['id'];
            db()->prepare("UPDATE photos SET source_type='takeout_upload', b2_bucket=?, b2_key=?, compatibility_b2_key=COALESCE(?,compatibility_b2_key), stored_filename=?, original_filename=?, file_ext=?, original_format=?, converted_from_heic=?, preview_status=?, preview_error=?, thumb_path=COALESCE(?,thumb_path), preview_path=COALESCE(?,preview_path), web_path=COALESCE(?,web_path), mime_type=?, file_size=?, photo_views=COALESCE(?,photo_views), title=?, description=COALESCE(?,description), taken_at=COALESCE(?,taken_at), latitude=?, longitude=?, width=?, height=?, orientation=?, camera_make=?, camera_model=?, lens_model=?, focal_length=?, aperture=?, shutter_speed=?, iso_value=?, metadata_json=CASE WHEN ? IS NULL THEN metadata_json ELSE ? END, visibility=?, is_downloadable=?, is_missing=0, synced_at=NOW(), updated_by=? WHERE id=? AND album_id=?")
                ->execute([defined('B2_BUCKET')?(string)B2_BUCKET:null,$key,$compatibilityKey,$targetName,$dbOriginalName,pathinfo($file['base'],PATHINFO_EXTENSION),$originalFormat,$previewState['converted_from_heic'],$previewState['preview_status'],$previewState['preview_error'],$previewState['thumb_path'],$previewState['preview_path'],$previewState['web_path'],$exif['mime_type'],$b2Size,$views,$j['title'] ?? $dbOriginalName,$j['description'] ?? null,$taken,$lat?:null,$lon?:null,$exif['width'],$exif['height'],$exif['orientation'],$exif['camera_make'],$exif['camera_model'],$exif['lens_model'],$exif['focal_length'],$exif['aperture'],$exif['shutter_speed'],$exif['iso_value'],$metaJson,$metaJson,$visibility,$albumDownloadEnabled,$_SESSION['admin']['id']??null,$pid,$albumId]);
            $updated++;
        } else {
            $maxSort += 10;
            db()->prepare("INSERT INTO photos(uuid,album_id,source_type,b2_bucket,b2_key,compatibility_b2_key,original_filename,stored_filename,file_ext,original_format,converted_from_heic,preview_status,preview_error,thumb_path,preview_path,web_path,mime_type,file_size,photo_views,title,description,taken_at,latitude,longitude,width,height,orientation,camera_make,camera_model,lens_model,focal_length,aperture,shutter_speed,iso_value,metadata_json,sort_order,visibility,is_downloadable,is_missing,synced_at,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),?,?)")
                ->execute([uid(),$albumId,'takeout_upload',defined('B2_BUCKET')?(string)B2_BUCKET:null,$key,$compatibilityKey,$dbOriginalName,$targetName,pathinfo($file['base'],PATHINFO_EXTENSION),$originalFormat,$previewState['converted_from_heic'],$previewState['preview_status'],$previewState['preview_error'],$previewState['thumb_path'],$previewState['preview_path'],$previewState['web_path'],$exif['mime_type'],$b2Size,$views,$j['title'] ?? $dbOriginalName,$j['description'] ?? null,$taken,$lat?:null,$lon?:null,$exif['width'],$exif['height'],$exif['orientation'],$exif['camera_make'],$exif['camera_model'],$exif['lens_model'],$exif['focal_length'],$exif['aperture'],$exif['shutter_speed'],$exif['iso_value'],$metaJson,$maxSort,$visibility,$albumDownloadEnabled,$_SESSION['admin']['id']??null,$_SESSION['admin']['id']??null]);
            $pid = (int)db()->lastInsertId();
            $created++;
        }

        persist_photo_metadata_json($pid);
        if ($j && ($duplicateMode === 'overwrite' || !isset($b2Keys[$sidecarKey]))) {
            b2_upload_data(json_encode($j, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), $sidecarKey, 'application/json', $upload);
            $b2Keys[$sidecarKey] = ['fileName' => $sidecarKey];
            $jsonUploaded++;
        }
    }
    foreach ($jsonFiles as $jsonName => $jsonInfo) {
        $j = $jsonInfo['json'] ?? null;
        if (!takeout_photo_metadata_json($jsonName, is_array($j) ? $j : null)) continue;
        if (!is_array($j)) continue;
        $photoName = google_photo_name($jsonName, $j);
        $photo = album_photo_for_takeout_json($albumId, $photoName);
        if (!$photo && !empty($j['title'])) $photo = album_photo_for_takeout_json($albumId, basename((string)$j['title']));
        if (!$photo) continue;
        apply_takeout_json_to_photo((int)$photo['id'], $j);
        $jsonBackfilled++;
        $stored = (string)($photo['stored_filename'] ?? '');
        if ($stored === '') $stored = basename((string)($photo['b2_key'] ?? ''));
        if ($stored !== '') {
            $sidecarKey = $prefix.'/metadata/'.$stored.'.supplemental-metadata.json';
            if ($duplicateMode === 'overwrite' || !isset($b2Keys[$sidecarKey])) {
                b2_upload_data(json_encode($j, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), $sidecarKey, 'application/json', $upload);
                $b2Keys[$sidecarKey] = ['fileName' => $sidecarKey];
                $jsonUploaded++;
            }
        }
    }
    // Pasirinktas rikiavimas pritaikomas TIK dabar, kai naujos nuotraukos jau
    // duomenu bazeje - kitaip jos i eile nepatektu.
    if ($returnSortPreset !== '' && $returnSortPreset !== 'custom') {
        apply_album_sort_preset($albumId, $returnSortPreset);
    }
    append_album_takeout_internal_notes($albumId, $albumJson, $jsonFiles);
    if ($visibility === 'published' && (string)($album['visibility'] ?? '') !== 'published') {
        db()->prepare("UPDATE albums SET visibility='published', updated_by=? WHERE id=?")->execute([$_SESSION['admin']['id'] ?? null, $albumId]);
    }
    gallery_list_cache_invalidate_album($albumId);
    audit('photo',$albumId,'album_upload','Additional album photos uploaded',['mode'=>$duplicateMode,'uploaded'=>$uploaded,'overwritten'=>$overwritten,'compatibility_uploaded'=>$compatibilityUploaded,'compatibility_missing'=>$compatibilityMissing,'compatibility_invalid'=>$compatibilityInvalid,'json_uploaded'=>$jsonUploaded,'json_backfilled'=>$jsonBackfilled,'created'=>$created,'updated'=>$updated,'skipped'=>$skipped]);
    $compatibilityNote = $compatibilityMissing > 0 ? " $compatibilityMissing HEIC originalas įkeltas, bet JPG peržiūra nesukurta." : '';
    $invalidNote = $compatibilityInvalid > 0 ? " $compatibilityInvalid sugeneruota JPG peržiūros versija netinkama, įkeltas tik originalus failas." : '';
    $message = "Additional upload finished: $uploaded media uploads ($overwritten overwritten), $compatibilityUploaded JPG preview uploads, $jsonUploaded JSON files uploaded, $jsonBackfilled existing photos updated from JSON, $created DB photos created, $updated DB photos updated, $skipped media skipped/unsupported. Matomumas: $visibility.$compatibilityNote$invalidNote";
    $redirect = '?page=album_edit&id='.$albumId.($returnSortPreset !== '' ? '&sort_preset='.rawurlencode($returnSortPreset) : '');
    if ($jsonMode) {
        json_exit([
            'ok' => true,
            'message' => $message,
            'redirect' => $redirect,
            'visibility' => $visibility,
            'stats' => [
                'uploaded' => $uploaded,
                'overwritten' => $overwritten,
                'compatibilityUploaded' => $compatibilityUploaded,
                'compatibilityMissing' => $compatibilityMissing,
                'compatibilityInvalid' => $compatibilityInvalid,
                'jsonUploaded' => $jsonUploaded,
                'jsonBackfilled' => $jsonBackfilled,
                'created' => $created,
                'updated' => $updated,
                'skipped' => $skipped,
            ],
        ]);
    }
    flash($message);
    go($redirect);
}
function b2_download_key(array $auth, string $bucketName, string $key): string {
    $url = rtrim((string)$auth['downloadUrl'], '/').'/file/'.rawurlencode($bucketName).'/'.str_replace('%2F', '/', rawurlencode(trim($key, '/')));
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>120, CURLOPT_HTTPHEADER=>['Authorization: '.$auth['authToken']]]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code >= 400 || $body === false || $body === '') throw new RuntimeException('B2 download failed ('.$code.') for '.$key);
    return (string)$body;
}
function photo_heic_blob(): void {
    $id = (int)($_GET['id'] ?? 0);
    $st = db()->prepare("SELECT p.b2_key, a.created_by album_created_by FROM photos p JOIN albums a ON a.id=p.album_id WHERE p.id=? LIMIT 1");
    $st->execute([$id]); $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) { http_response_code(404); exit('not found'); }
    if (!(is_superadmin() || (int)($p['album_created_by'] ?? 0) === current_admin_id())) { http_response_code(403); exit('forbidden'); }
    b2_load_config();
    try {
        $auth = b2_auth();
        $bytes = b2_download_key($auth, defined('B2_BUCKET') ? (string)B2_BUCKET : '', (string)$p['b2_key']);
    } catch (Throwable $e) { http_response_code(502); exit('b2 error'); }
    header('Content-Type: application/octet-stream');
    header('Content-Length: '.strlen($bytes));
    header('X-Content-Type-Options: nosniff');
    echo $bytes; exit;
}
function save_recreated_jpg(): void {
    csrf(); b2_load_config();
    $id = (int)($_POST['photo_id'] ?? 0);
    $st = db()->prepare("SELECT p.b2_key, p.original_filename, a.source_path, a.created_by album_created_by FROM photos p JOIN albums a ON a.id=p.album_id WHERE p.id=? LIMIT 1");
    $st->execute([$id]); $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) json_exit(['ok'=>false,'error'=>'photo not found'], 404);
    if (!(is_superadmin() || (int)($p['album_created_by'] ?? 0) === current_admin_id())) json_exit(['ok'=>false,'error'=>'forbidden'], 403);
    $f = $_FILES['jpg'] ?? null;
    if (!$f || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) json_exit(['ok'=>false,'error'=>'no jpg'], 400);
    $info = @getimagesize((string)$f['tmp_name']);
    if (!$info || (string)($info['mime'] ?? '') !== 'image/jpeg') json_exit(['ok'=>false,'error'=>'invalid jpg'], 400);
    $key = trim((string)$p['b2_key'], '/');
    $jpgKey = preg_replace('~/originals/([^/]+)\\.[^.]+$~', '/jpg-originals/$1.jpg', $key);
    if (!is_string($jpgKey) || $jpgKey === $key) {
        $prefix = trim((string)($p['source_path'] ?? ''), '/');
        $base = preg_replace('~\\.[^.]+$~', '', basename($key)) ?: basename($key);
        $jpgKey = ($prefix !== '' ? $prefix.'/' : '').'jpg-originals/'.$base.'.jpg';
    }
    try {
        $upload = b2_upload_url();
        b2_upload_file((string)$f['tmp_name'], $jpgKey, 'image/jpeg', $upload);
        if (function_exists('purge_img_cache_for_b2_key')) purge_img_cache_for_b2_key($jpgKey);
    } catch (Throwable $e) { json_exit(['ok'=>false,'error'=>'B2 upload: '.$e->getMessage()], 502); }
    $wasHeic = is_heic_name((string)($p['original_filename'] ?: $p['b2_key'])) ? 1 : 0;
    db()->prepare("UPDATE photos SET compatibility_b2_key=?, thumb_path=?, preview_path=?, web_path=?, preview_status='ready', preview_error=NULL, converted_from_heic=?, is_missing=0, width=COALESCE(width,?), height=COALESCE(height,?), synced_at=NOW(), updated_by=? WHERE id=?")
        ->execute([$jpgKey, $jpgKey, $jpgKey, $jpgKey, $wasHeic, ((int)($info[0] ?? 0)) ?: null, ((int)($info[1] ?? 0)) ?: null, $_SESSION['admin']['id'] ?? null, $id]);
    audit('photo', $id, 'recreate_jpg', 'JPG preview recreated from HEIC (browser)', ['jpg_key'=>$jpgKey]);
    json_exit(['ok'=>true, 'jpgKey'=>$jpgKey]);
}
function save_recreate_log(): void {
    csrf();
    $payload = json_decode((string)($_POST['log'] ?? ''), true);
    if (!is_array($payload)) json_exit(['ok'=>false,'error'=>'bad log'], 400);
    $done = (int)($payload['done'] ?? 0); $fail = (int)($payload['fail'] ?? 0);
    audit('photo', ((int)($payload['album_id'] ?? 0)) ?: null, 'recreate_jpg_log', "HEIC->JPG batch: $done ok, $fail klaidu", ['log'=>array_slice((array)($payload['items'] ?? []), 0, 100)]);
    json_exit(['ok'=>true]);
}
function reload_album_photos(): void {
    csrf(); ensure_overlay_schema(); b2_load_config();
    $albumId = (int)($_POST['album_id'] ?? 0);
    if (empty($_POST['confirm_reload'])) { flash('Confirm reload before changing B2 files.', 'err'); go('?page=album_edit&id='.$albumId); }
    $album = require_album_editable_by_id($albumId);
    $prefix = trim((string)($album['source_path'] ?? ''), '/');
    if ($prefix === '') { flash('Album has no B2 source path. Set storage path first.', 'err'); go('?page=album_edit&id='.$albumId); }

    $upload = b2_upload_url();
    ensure_album_marker_file($album, $upload);
    persist_album_metadata_json($albumId);
    $albumMeta = db()->prepare("SELECT * FROM albums WHERE id=? LIMIT 1");
    $albumMeta->execute([$albumId]);
    $freshAlbum = $albumMeta->fetch(PDO::FETCH_ASSOC) ?: $album;
    b2_upload_data(json_encode(album_metadata_payload($freshAlbum, !empty($freshAlbum['metadata_json']) ? json_decode((string)$freshAlbum['metadata_json'], true) : null), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), $prefix.'/metadata/metadata.json', 'application/json', $upload);

    $deleted = 0;
    foreach ([$prefix.'/originals', $prefix.'/metadata'] as $deletePrefix) {
        foreach (b2_list_prefix($deletePrefix, 20) as $file) {
            $name = trim((string)($file['fileName'] ?? ''), '/');
            if ($name === '') continue;
            if (!str_starts_with($name, $deletePrefix.'/')) continue;
            if ($name === $prefix.'/metadata/metadata.json') continue;
            if (!str_starts_with($name, $prefix.'/originals/') && !preg_match('~^'.preg_quote($prefix, '~').'/metadata/.+\.supplemental-metadata\.json$~i', $name)) continue;
            b2_delete_file_version((string)($file['fileId'] ?? ''), $name);
            $deleted++;
        }
    }

    $st = db()->prepare("UPDATE photos SET is_missing=1, updated_by=? WHERE album_id=?");
    $st->execute([$_SESSION['admin']['id'] ?? null, $albumId]);
    audit('album',$albumId,'reload_photos','Album photos reloaded',['b2_deleted'=>$deleted,'db_missing'=>$st->rowCount(),'prefix'=>$prefix]);
    flash('Reload prepared: '.$st->rowCount().' DB photos marked missing, '.$deleted.' B2 original/sidecar files deleted. Album marker kept at '.$prefix.'/.album-netrinti.json.');
    go('?page=album_edit&id='.$albumId);
}

function save_photo_order(): void {
    csrf(); $albumId=(int)($_POST['album_id'] ?? 0); $ids=array_values(array_filter(array_map('intval', explode(',', (string)($_POST['order'] ?? '')))));
    require_album_editable_by_id($albumId);
    $jsonMode = wants_json_response();
    if(!$albumId || !$ids){ if($jsonMode) json_error('No custom order to save.', 400); flash('No custom order to save.', 'err'); go('?page=album_edit&id='.$albumId); }
    $check=db()->prepare("SELECT id FROM photos WHERE album_id=? AND id=?");
    $sort=10; foreach($ids as $id){ $check->execute([$albumId,$id]); if(!$check->fetchColumn()) continue; db()->prepare("UPDATE photos SET sort_order=?,updated_by=? WHERE id=? AND album_id=?")->execute([$sort,$_SESSION['admin']['id']??null,$id,$albumId]); $sort+=10; }
    db()->prepare("UPDATE albums SET updated_at=NOW(),updated_by=? WHERE id=?")->execute([$_SESSION['admin']['id']??null,$albumId]);
    gallery_list_cache_invalidate_album($albumId);
    audit('photo',$albumId,'reorder','Album photo order updated',['ids'=>$ids]);
    if($jsonMode) json_exit(['ok' => true, 'saved' => count($ids)]);
    flash('Photo order saved.'); go('?page=album_edit&id='.$albumId);
}
function photo_quick(): void {
    csrf(); $albumId=(int)($_POST['album_id'] ?? 0); $photoId=(int)($_POST['photo_id'] ?? 0); $op=(string)($_POST['op'] ?? '');
    if(!$albumId || !$photoId) json_error('Bad request.', 400);
    require_album_editable_by_id($albumId);
    $photoCheck = require_photo_editable_by_id($photoId);
    if ((int)($photoCheck['album_id'] ?? 0) !== $albumId) json_error('Forbidden', 403);
    if($op==='cover') db()->prepare("UPDATE albums SET cover_photo_id=?,cover_mode='manual',updated_by=? WHERE id=?")->execute([$photoId,$_SESSION['admin']['id']??null,$albumId]);
    elseif($op==='favorite') db()->prepare("UPDATE photos SET is_cover_candidate=1-is_cover_candidate,updated_by=? WHERE id=? AND album_id=?")->execute([$_SESSION['admin']['id']??null,$photoId,$albumId]);
    elseif($op==='hide') db()->prepare("UPDATE photos SET visibility='hidden',updated_by=? WHERE id=? AND album_id=?")->execute([$_SESSION['admin']['id']??null,$photoId,$albumId]);
    elseif($op==='toggle_publish') db()->prepare("UPDATE photos SET visibility=IF(visibility='published','draft','published'),updated_by=? WHERE id=? AND album_id=?")->execute([$_SESSION['admin']['id']??null,$photoId,$albumId]);
    elseif(in_array($op, ['rot_cw','rot_ccw','rot_reset'], true)) {
        if (!photo_rotation_column()) json_error('Nepavyko paruošti pasukimo stulpelio DB.', 500);
        if ($op === 'rot_reset') db()->prepare("UPDATE photos SET rotation=0,updated_by=? WHERE id=? AND album_id=?")->execute([$_SESSION['admin']['id']??null,$photoId,$albumId]);
        else db()->prepare("UPDATE photos SET rotation=MOD(rotation+?,360),updated_by=? WHERE id=? AND album_id=?")->execute([$op==='rot_cw'?90:270,$_SESSION['admin']['id']??null,$photoId,$albumId]);
    }
    else json_error('Bad operation.', 400);
    audit('photo',$photoId,'quick_'.$op,'Quick photo action');
    json_exit(['ok' => true]);
}

function photo_sidecar_key_for_row(array $photo, array $album): string {
    $b2Key = trim((string)($photo['b2_key'] ?? ''), '/');
    $stored = trim((string)($photo['stored_filename'] ?? ''), '/');
    $base = $stored !== '' ? basename($stored) : basename($b2Key);
    if ($base === '') return '';
    if ($b2Key !== '' && str_contains($b2Key, '/originals/')) {
        $albumPrefix = trim((string)dirname(dirname($b2Key)), '.\\/');
        return $albumPrefix !== '' ? $albumPrefix.'/metadata/'.$base.'.supplemental-metadata.json' : '';
    }
    $sourcePath = trim((string)($album['source_path'] ?? ''), '/');
    return $sourcePath !== '' ? $sourcePath.'/metadata/'.$base.'.supplemental-metadata.json' : '';
}

function b2_delete_exact_key(string $key): bool {
    $key = trim($key, '/');
    if ($key === '') return false;
    $prefix = trim((string)dirname($key), '.\\/');
    if ($prefix === '' || $prefix === '.') return false;
    foreach (b2_prefix_file_map($prefix, 20) as $name => $file) {
        if ((string)$name !== $key) continue;
        b2_delete_file_version((string)($file['fileId'] ?? ''), $key);
        return true;
    }
    return false;
}

function delete_album_photo(): void {
    csrf(); b2_load_config();
    $albumId = (int)($_POST['album_id'] ?? 0);
    $photoId = (int)($_POST['photo_id'] ?? 0);
    if (!$albumId || !$photoId) json_error('Bad request.', 400);
    $album = require_album_editable_by_id($albumId);
    $photo = require_photo_editable_by_id($photoId);
    if ((int)($photo['album_id'] ?? 0) !== $albumId) json_error('Forbidden', 403);

    $b2Key = trim((string)($photo['b2_key'] ?? ''), '/');
    $compatibilityKey = trim((string)($photo['compatibility_b2_key'] ?? ''), '/');
    $sidecarKey = !empty($_POST['delete_sidecar']) ? photo_sidecar_key_for_row($photo, $album) : '';
    $deletedOriginal = false;
    $deletedCompatibility = false;
    $deletedSidecar = false;
    try {
        if ($b2Key !== '') $deletedOriginal = b2_delete_exact_key($b2Key);
        if ($compatibilityKey !== '') $deletedCompatibility = b2_delete_exact_key($compatibilityKey);
        if ($sidecarKey !== '') $deletedSidecar = b2_delete_exact_key($sidecarKey);
    } catch (Throwable $e) {
        json_error('B2 delete failed: '.$e->getMessage(), 500);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE albums SET cover_photo_id=NULL, cover_mode='auto', updated_by=? WHERE id=? AND cover_photo_id=?")->execute([$_SESSION['admin']['id'] ?? null, $albumId, $photoId]);
        $pdo->prepare("DELETE FROM photo_tags WHERE photo_id=?")->execute([$photoId]);
        $pdo->prepare("DELETE FROM photos WHERE id=? AND album_id=?")->execute([$photoId, $albumId]);
        $pdo->prepare("UPDATE albums SET updated_at=NOW(), updated_by=? WHERE id=?")->execute([$_SESSION['admin']['id'] ?? null, $albumId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_error('DB delete failed: '.$e->getMessage(), 500);
    }

    if ($b2Key !== '') purge_img_cache_for_b2_key($b2Key);
    if ($compatibilityKey !== '') purge_img_cache_for_b2_key($compatibilityKey);
    persist_album_metadata_json($albumId);
    audit('photo', $photoId, 'delete', 'Album photo deleted', [
        'album_id' => $albumId,
        'b2_key' => $b2Key,
        'b2_original_deleted' => $deletedOriginal,
        'compatibility_b2_key' => $compatibilityKey,
        'compatibility_deleted' => $deletedCompatibility,
        'sidecar_key' => $sidecarKey,
        'sidecar_deleted' => $deletedSidecar,
    ]);
    json_exit(['ok'=>true,'deleted_original'=>$deletedOriginal,'deleted_sidecar'=>$deletedSidecar]);
}

// Po tiek dienų nuo turinio patalpinimo į B2 albumas iš admin nebetrinamas.
const ALBUM_DELETE_LOCK_DAYS = 60;

/**
 * Oldest B2 upload timestamp (ms since epoch) for an album's content, or null if
 * unknown. Uses B2's own uploadTimestamp — the authoritative "placed in B2" time,
 * independent of the (all-NULL) DB created_at columns. Considers real content
 * (skips /metadata/ and .json sidecars); jpg-originals are generated later so
 * they never lower the minimum.
 */
function album_b2_oldest_upload_ms(string $sourcePath): ?int {
    $sourcePath = trim($sourcePath, '/');
    if ($sourcePath === '') return null;
    try {
        b2_load_config();
        $oldest = null;
        foreach (b2_prefix_file_map($sourcePath, 50) as $name => $file) {
            if (strpos((string)$name, '/metadata/') !== false) continue;
            if (preg_match('~\.json$~i', (string)$name)) continue;
            $ts = isset($file['uploadTimestamp']) ? (int)$file['uploadTimestamp'] : 0;
            if ($ts > 0 && ($oldest === null || $ts < $oldest)) $oldest = $ts;
        }
        return $oldest;
    } catch (Throwable $e) {
        return null;
    }
}

/** Persist the album's oldest-B2-upload cache (immutable once set). */
function album_store_b2_oldest(int $albumId, ?int $oldestMs): void {
    if ($albumId <= 0 || $oldestMs === null || $oldestMs <= 0) return;
    try {
        db()->prepare("UPDATE albums SET b2_oldest_upload_at=? WHERE id=? AND b2_oldest_upload_at IS NULL")
            ->execute([date('Y-m-d H:i:s', (int)floor($oldestMs / 1000)), $albumId]);
    } catch (Throwable $e) { /* non-fatal */ }
}

/**
 * Lock state for the album-list badge. Reads the cached b2_oldest_upload_at; if
 * missing and $budget allows, computes it once from B2 and caches it. Returns
 * ['known'=>bool, 'locked'=>bool]. Only content-bearing albums can lock.
 */
function album_lock_state(array $r, int &$budget): array {
    $hasContent = (int)($r['photos_count'] ?? 0) > 0;
    if (!$hasContent) return ['known' => true, 'locked' => false];

    $cached = $r['b2_oldest_upload_at'] ?? null;
    if ($cached === null && $budget > 0) {
        $budget--;
        $ms = album_b2_oldest_upload_ms(trim((string)($r['source_path'] ?? ''), '/'));
        if ($ms !== null) {
            album_store_b2_oldest((int)$r['id'], $ms);
            $cached = date('Y-m-d H:i:s', (int)floor($ms / 1000));
        }
    }
    if ($cached === null) return ['known' => false, 'locked' => false];

    $ageDays = (time() - strtotime((string)$cached)) / 86400;
    return ['known' => true, 'locked' => $ageDays >= ALBUM_DELETE_LOCK_DAYS];
}

function delete_album(): void {
    csrf();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { flash('Bad request.', 'err'); go(consume_return_path('?page=albums')); }
    $st = db()->prepare("SELECT * FROM albums WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $album = $st->fetch();
    if (!$album) { flash('Albumas nerastas.', 'err'); go(consume_return_path('?page=albums')); }
    if (!album_editable_by_current_user($album)) { flash('Neturite teisės trinti šio albumo.', 'err'); go(consume_return_path('?page=albums')); }
    if ((string)$album['visibility'] !== 'draft') { flash('Trinti galima tik draft albumą. Pirmiausia pažymėkite jį draft.', 'err'); go(consume_return_path('?page=albums')); }
    $cnt = db()->prepare("SELECT COUNT(*) FROM photos WHERE album_id=?");
    $cnt->execute([$id]);
    $photoCount = (int)$cnt->fetchColumn();

    // 30 dienų užraktas: albumas su turiniu, kurio seniausias failas B2 saugykloje
    // yra >= 30 d. senumo, iš admin BEnebetrinamas. Amžius imamas iš B2 pačios
    // uploadTimestamp (DB created_at gyvoje lentelėje visur NULL, tad nepatikimas),
    // t. y. autoritetingas „patalpinimo į B2" laikas. Jei B2 amžiaus nepavyksta
    // nustatyti, o turinys yra — fail-closed (blokuojam), nes tai apsaugos funkcija.
    $sourcePathLock = trim((string)($album['source_path'] ?? ''), '/');
    if ($photoCount > 0) {
        $oldestMs = album_b2_oldest_upload_ms($sourcePathLock);
        album_store_b2_oldest($id, $oldestMs);
        $ageDays = $oldestMs !== null ? (time() - (int)floor($oldestMs / 1000)) / 86400 : null;
        if ($ageDays === null || $ageDays >= ALBUM_DELETE_LOCK_DAYS) {
            $addr = $sourcePathLock !== '' ? $sourcePathLock : ('albumas #'.$id);
            $ageTxt = $ageDays !== null ? ' ('.(int)floor($ageDays).' d. senumo)' : ' (amžiaus nustatyti nepavyko)';
            flash('Šio albumo iš admin trinti nebegalima: jame yra turinio, patalpinto į B2 prieš '.ALBUM_DELETE_LOCK_DAYS.'+ dienų'.$ageTxt.'. Norėdami jį pašalinti, tai atlikite rankiniu būdu B2 serveryje pagal adresą: '.$addr, 'err');
            go(consume_return_path('?page=albums'));
        }
    }

    // Dublikato atveju išvalomas ir dubliuotas B2 aplankas, kad B2 Sync albumo
    // neatkurtų. Patikra VIENPUSĖ ir tai svarbu: canonical_album_prefix() prideda
    // datą/vietą, tad saugotinas (kanoninis) albumas visada ILGESNIS, o šiukšlė —
    // trumpesnis priešdėlis. Todėl B2 valomas tik tada, kai egzistuoja ILGESNIS to
    // paties kamieno albumas (šis yra priešdėlio-stub). Ištrynus kanoninį (ilgesnį)
    // albumą, isDuplicate=false ir B2 lieka nepaliestas — DB-only, kaip ir dera.
    // Simetriška patikra čia klasifikuodavo IR kanoninį kaip dublikatą ir galėjo
    // nušluoti jo B2 originalus + JPG (žr. albumus #32/#34).
    $sourcePath = trim((string)($album['source_path'] ?? ''), '/');
    $b2Deleted = 0; $b2Failed = 0; $b2Reason = '';
    $isDuplicate = false;
    if ($sourcePath !== '') {
        $q = db()->prepare("SELECT COUNT(*) FROM albums WHERE id<>? AND source_path LIKE CONCAT(?,'-%')");
        $q->execute([$id, $sourcePath]);
        $isDuplicate = ((int)$q->fetchColumn()) > 0;
    }
    if ($isDuplicate) {
        $shared = db()->prepare("SELECT COUNT(*) FROM albums WHERE id<>? AND source_path=?");
        $shared->execute([$id, $sourcePath]);
        $foreign = db()->prepare("SELECT COUNT(*) FROM photos WHERE album_id<>? AND (b2_key LIKE CONCAT(?,'/%') OR compatibility_b2_key LIKE CONCAT(?,'/%'))");
        $foreign->execute([$id, $sourcePath, $sourcePath]);
        if ((int)$shared->fetchColumn() > 0) {
            $b2Reason = 'B2 aplankas paliktas: juo dalinasi kitas albumas.';
        } elseif ((int)$foreign->fetchColumn() > 0) {
            $b2Reason = 'B2 aplankas paliktas: jame yra kito albumo nuotraukų raktų.';
        } else {
            try {
                b2_load_config();
                foreach (b2_prefix_file_map($sourcePath, 50) as $name => $file) {
                    try {
                        b2_delete_file_version((string)($file['fileId'] ?? ''), (string)$name);
                        purge_img_cache_for_b2_key((string)$name);
                        $b2Deleted++;
                    } catch (Throwable $e) {
                        $b2Failed++;
                    }
                }
            } catch (Throwable $e) {
                $b2Reason = 'B2 valymas nepavyko: '.$e->getMessage();
            }
        }
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE pt FROM photo_tags pt JOIN photos p ON p.id=pt.photo_id WHERE p.album_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM photos WHERE album_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM album_tags WHERE album_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM albums WHERE id=?")->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('DB klaida trinant albumą: '.$e->getMessage(), 'err');
        go(consume_return_path('?page=albums'));
    }
    audit('album', $id, 'delete', 'Album deleted: '.(string)$album['title'], [
        'source_path' => $sourcePath,
        'slug' => (string)($album['slug'] ?? ''),
        'photos_deleted' => $photoCount,
        'duplicate' => $isDuplicate,
        'b2_files_deleted' => $b2Deleted,
        'b2_files_failed' => $b2Failed,
        'b2_note' => $b2Reason,
    ]);
    $msg = 'Albumas „'.(string)$album['title'].'" ir '.$photoCount.' nuotraukų įrašų ištrinti iš DB.';
    if ($b2Deleted > 0) $msg .= ' Ištrinta '.$b2Deleted.' dubliuotų B2 failų.';
    if ($b2Failed > 0) $msg .= ' Nepavyko ištrinti '.$b2Failed.' B2 failų — bandykite dar kartą per B2 puslapį.';
    if ($b2Reason !== '') $msg .= ' '.$b2Reason;
    if (!$isDuplicate) $msg .= ' B2 failai nepaliesti (albumas ne dublikatas).';
    flash($msg, $b2Failed > 0 ? 'err' : 'ok');
    go(consume_return_path('?page=albums'));
}

function bulk(string $table): void {
    csrf(); $ids=array_values(array_filter(array_map('intval', $_POST['ids'] ?? []))); if (!$ids) { flash('Nothing selected.', 'err'); go('?page='.$table); }
    $ph=implode(',', array_fill(0,count($ids),'?')); $status=$_POST['bulk_action'] ?? '';
    if ($table === 'albums' && !is_superadmin()) {
        $st = db()->prepare("SELECT id FROM albums WHERE id IN ($ph) AND created_by=?");
        $st->execute([...$ids, current_admin_id()]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) { flash('Nothing selected.', 'err'); go(consume_return_path('?page=albums')); }
        $ph=implode(',', array_fill(0,count($ids),'?'));
    }
    if ($table === 'photos' && !is_superadmin()) {
        $st = db()->prepare("SELECT p.id FROM photos p JOIN albums a ON a.id=p.album_id WHERE p.id IN ($ph) AND a.created_by=?");
        $st->execute([...$ids, current_admin_id()]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        if (!$ids) { flash('Nothing selected.', 'err'); go('?page=photos'); }
        $ph=implode(',', array_fill(0,count($ids),'?'));
    }
    $rawAction = $status;
    if ($table==='photos' && in_array($rawAction, ['rotate_cw','rotate_ccw','rotate_180','rotate_reset'], true)) {
        if (!photo_rotation_column()) { flash('Nepavyko paruošti pasukimo stulpelio DB.', 'err'); go(consume_return_path('?page=photos')); }
        if ($rawAction === 'rotate_reset') {
            db()->prepare("UPDATE photos SET rotation=0, updated_by=? WHERE id IN ($ph)")->execute([$_SESSION['admin']['id'] ?? null, ...$ids]);
        } else {
            $deg = ['rotate_cw'=>90, 'rotate_ccw'=>270, 'rotate_180'=>180][$rawAction];
            db()->prepare("UPDATE photos SET rotation=MOD(rotation+?,360), updated_by=? WHERE id IN ($ph)")->execute([$deg, $_SESSION['admin']['id'] ?? null, ...$ids]);
        }
        audit('photo', $ids[0], $rawAction, 'Pasuktos '.count($ids).' nuotr. (tik rodymas, originalai B2 nekeisti)', ['ids'=>$ids]);
        flash('Pasukta nuotraukų: '.count($ids).'. Originalai B2 nekeisti — keičiasi tik rodymas.');
        go(consume_return_path('?page=photos'));
    }
    if ($table==='photos' && $rawAction==='recheck_b2') {
        b2_load_config();
        $st = db()->prepare("SELECT p.id, p.album_id, p.b2_key, p.is_missing, a.source_path FROM photos p JOIN albums a ON a.id=p.album_id WHERE p.id IN ($ph)");
        $st->execute($ids);
        $byPrefix = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $prefix = album_storage_base_prefix((string)($r['source_path'] ?? ''));
            if ($prefix === '') $prefix = album_storage_base_prefix((string)(album_photo_prefix_guess((int)$r['album_id']) ?? ''));
            if ($prefix === '') continue;
            $byPrefix[$prefix][] = $r;
        }
        $checked = 0; $restored = 0; $marked = 0;
        $upd = db()->prepare("UPDATE photos SET is_missing=?, synced_at=NOW(), updated_by=? WHERE id=?");
        foreach ($byPrefix as $prefix => $rowsInPrefix) {
            $keys = [];
            foreach (b2_list_prefix($prefix, 50, true) as $fileRow) {
                $keys[trim((string)($fileRow['fileName'] ?? ''), '/')] = true;
            }
            foreach ($rowsInPrefix as $r) {
                $checked++;
                $exists = isset($keys[trim((string)$r['b2_key'], '/')]);
                $want = $exists ? 0 : 1;
                if ((int)($r['is_missing'] ?? 0) !== $want) {
                    $upd->execute([$want, $_SESSION['admin']['id'] ?? null, (int)$r['id']]);
                    if ($want === 0) $restored++; else $marked++;
                }
            }
        }
        $back = consume_return_path('?page=photos');
        if (!preg_match('/^\?page=photos/', $back)) $back = '?page=photos';
        audit('photo', null, 'recheck_b2', "B2 re-check: $checked checked, $restored restored, $marked marked missing", ['ids'=>$ids]);
        $extra = $marked > 0 ? " $marked failų nerasta B2 — jei įkėlimas nutrūko, naudokite „Clean missing rows“ ir įkelkite iš naujo." : '';
        flash("B2 patikra: $checked patikrinta, $restored atstatyta (failas rastas B2), $marked pažymėta kaip missing.$extra");
        go($back);
    }
    if ($table==='photos' && $rawAction==='clean_missing') {
        b2_load_config();
        $st = db()->prepare("SELECT p.id, p.album_id, p.b2_key, a.source_path FROM photos p JOIN albums a ON a.id=p.album_id WHERE p.id IN ($ph) AND p.is_missing=1");
        $st->execute($ids);
        $byPrefix = [];
        while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
            $prefix = album_storage_base_prefix((string)($r['source_path'] ?? ''));
            if ($prefix === '') $prefix = album_storage_base_prefix((string)(album_photo_prefix_guess((int)$r['album_id']) ?? ''));
            $byPrefix[$prefix][] = $r;
        }
        $checked = 0; $restored = 0; $deleted = 0; $touchedAlbums = [];
        $delTags = db()->prepare("DELETE FROM photo_tags WHERE photo_id=?");
        $delRow = db()->prepare("DELETE FROM photos WHERE id=? AND is_missing=1");
        $restore = db()->prepare("UPDATE photos SET is_missing=0, synced_at=NOW(), updated_by=? WHERE id=?");
        foreach ($byPrefix as $prefix => $rowsInPrefix) {
            $keys = [];
            if ($prefix !== '') {
                foreach (b2_list_prefix($prefix, 50, true) as $fileRow) $keys[trim((string)($fileRow['fileName'] ?? ''), '/')] = true;
            }
            foreach ($rowsInPrefix as $r) {
                $checked++;
                $touchedAlbums[(int)$r['album_id']] = true;
                if ($prefix !== '' && isset($keys[trim((string)$r['b2_key'], '/')])) {
                    $restore->execute([$_SESSION['admin']['id'] ?? null, (int)$r['id']]);
                    $restored++;
                } else {
                    $delTags->execute([(int)$r['id']]);
                    $delRow->execute([(int)$r['id']]);
                    $deleted++;
                }
            }
        }
        foreach (array_keys($touchedAlbums) as $aid) {
            db()->prepare("UPDATE albums SET cover_photo_id=NULL WHERE id=? AND cover_photo_id IS NOT NULL AND cover_photo_id NOT IN (SELECT id FROM (SELECT id FROM photos WHERE album_id=?) t)")->execute([$aid, $aid]);
        }
        $back = consume_return_path('?page=photos');
        if (!preg_match('/^\?page=photos/', $back)) $back = '?page=photos';
        audit('photo', null, 'clean_missing_rows', "Clean missing rows: $checked checked, $restored restored, $deleted deleted", ['ids'=>$ids]);
        flash("Missing DB įrašų tvarkymas: $deleted ištrinta (failų B2 nėra), $restored atstatyta (failas rastas B2). B2 failai neliesti. Ištrintas nuotraukas įkelkite iš naujo albumo formoje.");
        go($back);
    }
    if ($table==='photos' && $rawAction==='create_jpg') {
        $back = consume_return_path('?page=photos');
        if (!preg_match('/^\?page=photos/', $back)) $back = '?page=photos';
        flash('„Create JPG“ veikia naršyklėje — įjunkite JavaScript ir bandykite dar kartą.', 'err');
        go($back);
    }
    /* --- Nuotrauku priskyrimas kitam albumui ---------------------------------
     * Narys gali pasirinkti ne ta albuma, todel admin'ui reikia budo perkelti.
     * B2 invariantas leidzia TIK skaityti ir rasyti NAUJUS objektus, tad cia
     * daroma kopija i tikslinio albumo originals/, o senas objektas paliekamas
     * gulėti (jis niekam netrukdo - DB i ji neberodo). Nieko netrinam ir
     * nepervadinam.
     */
    if ($table==='photos' && $rawAction==='move_album') {
        require_superadmin();
        $back = consume_return_path('?page=photos');
        if (!preg_match('/^\?page=photos/', $back)) $back = '?page=photos';
        $targetId = (int)($_POST['target_album_id'] ?? 0);
        $target = $targetId > 0 ? member_album_by_id($targetId) : null;
        if (!$target) { flash('Pasirink albumą, į kurį priskirti pažymėtas nuotraukas.', 'err'); go($back); }
        $targetPrefix = album_storage_base_prefix((string)($target['source_path'] ?? ''));
        if ($targetPrefix === '') { flash('Albumas „'.$target['title'].'" neturi B2 kelio — pirma nustatyk Storage path.', 'err', '?page=album_edit&id='.$targetId, 'Nustatyti kelią'); go($back); }
        b2_load_config();
        $st = db()->prepare("SELECT p.id,p.album_id,p.b2_key,p.compatibility_b2_key,p.original_filename,a.source_path FROM photos p JOIN albums a ON a.id=p.album_id WHERE p.id IN ($ph)");
        $st->execute($ids);
        $photos = $st->fetchAll(PDO::FETCH_ASSOC);
        $sourceMaps = [];
        $targetMap = b2_prefix_file_map($targetPrefix, 30);
        $maxSort = (int)db()->query("SELECT COALESCE(MAX(sort_order),0) FROM photos WHERE album_id=".$targetId)->fetchColumn();
        $deadline = microtime(true) + b2_time_budget();
        $moved = 0; $already = 0; $failed = 0; $stopped = false;
        $keyTaken = db()->prepare("SELECT COUNT(*) FROM photos WHERE album_id=? AND b2_key=? AND id<>?");
        $upd = db()->prepare("UPDATE photos SET album_id=?, b2_key=?, compatibility_b2_key=?, stored_filename=?, thumb_path=?, preview_path=?, web_path=?, sort_order=?, is_missing=0, synced_at=NOW(), updated_by=? WHERE id=?");
        foreach ($photos as $r) {
            if ((int)$r['album_id'] === $targetId) { $already++; continue; }
            if (microtime(true) > $deadline) { $stopped = true; break; }
            $srcPrefix = album_storage_base_prefix((string)($r['source_path'] ?? ''));
            if ($srcPrefix === '') { $failed++; continue; }
            if (!isset($sourceMaps[$srcPrefix])) $sourceMaps[$srcPrefix] = b2_prefix_file_map($srcPrefix, 30);
            $srcKey = trim((string)$r['b2_key'], '/');
            $srcFile = $sourceMaps[$srcPrefix][$srcKey] ?? null;
            if (!$srcFile) { $failed++; continue; }
            $name = safe_b2_name(basename($srcKey));
            $newKey = $targetPrefix.'/originals/'.$name;
            $n = 0;
            while (isset($targetMap[$newKey]) || (function () use ($keyTaken, $targetId, $newKey, $r) { $keyTaken->execute([$targetId, $newKey, (int)$r['id']]); return (int)$keyTaken->fetchColumn() > 0; })()) {
                $n++;
                $newKey = $targetPrefix.'/originals/'.str_pad((string)$n, 4, '0', STR_PAD_LEFT).'_'.$name;
                if ($n > 9999) break;
            }
            try {
                b2_copy_file_version($srcFile, $newKey);
                $targetMap[$newKey] = ['fileName' => $newKey];
                $newCompat = null;
                $srcCompat = trim((string)($r['compatibility_b2_key'] ?? ''), '/');
                if ($srcCompat !== '' && isset($sourceMaps[$srcPrefix][$srcCompat])) {
                    $compatName = (preg_replace('~\.[^.]+$~', '', basename($newKey)) ?: basename($newKey)).'.jpg';
                    $newCompat = $targetPrefix.'/jpg-originals/'.$compatName;
                    b2_copy_file_version($sourceMaps[$srcPrefix][$srcCompat], $newCompat);
                    $targetMap[$newCompat] = ['fileName' => $newCompat];
                }
                $maxSort += 10;
                $upd->execute([$targetId, $newKey, $newCompat, basename($newKey), $newCompat, $newCompat, $newCompat, $maxSort, current_admin_id() ?: null, (int)$r['id']]);
                $moved++;
            } catch (Throwable $e) {
                $failed++;
            }
        }
        gallery_list_cache_invalidate_album((int)$targetId);
        audit('photo', $targetId, 'move_album', 'Nuotraukos priskirtos albumui: '.$target['title'], ['moved'=>$moved,'already'=>$already,'failed'=>$failed,'stopped'=>$stopped,'ids'=>$ids]);
        $note = $stopped ? ' Dalis liko neperkelta (laiko limitas) — pažymėk likusias ir paleisk dar kartą.' : '';
        flash('Priskirta albumui „'.$target['title'].'": '.$moved.' perkelta, '.$already.' jau buvo ten, '.$failed.' nepavyko. Seni B2 failai palikti vietoje (netrinami).'.$note, $failed > 0 ? 'err' : 'ok');
        go($back);
    }
    // Anksciau bet koks nematomumo veiksmas (pvz. download_on) per normalize_visibility()
    // tapdavo 'draft' ir nuimdavo pazymetas nuotraukas nuo viesos galerijos.
    if (!in_array($rawAction, ['', 'download_on', 'download_off'], true)) {
        $status = visibility_from_input($rawAction);
        if ($status === null) { flash('Nežinomas masinis veiksmas. '.invalid_visibility_message($rawAction), 'err'); go(consume_return_path('?page='.$table)); }
        db()->prepare("UPDATE $table SET visibility=?,updated_by=? WHERE id IN ($ph)")->execute([$status,$_SESSION['admin']['id'] ?? null,...$ids]);
    }
    if ($table==='photos' && $rawAction==='download_on') db()->prepare("UPDATE photos SET is_downloadable=1 WHERE id IN ($ph)")->execute($ids);
    if ($table==='photos' && $rawAction==='download_off') db()->prepare("UPDATE photos SET is_downloadable=0 WHERE id IN ($ph)")->execute($ids);
    if ($table==='photos' && trim((string)($_POST['author_name'] ?? ''))!=='') db()->prepare("UPDATE photos SET author_name=? WHERE id IN ($ph)")->execute([trim((string)$_POST['author_name']),...$ids]);
    if ($table==='photos' && trim((string)($_POST['copyright_text'] ?? ''))!=='') db()->prepare("UPDATE photos SET copyright_text=? WHERE id IN ($ph)")->execute([trim((string)$_POST['copyright_text']),...$ids]);
    $tagIdToAdd = (int)($_POST['tag_id_to_add'] ?? ($_POST['append_tag_id'] ?? 0));
    if (($table==='photos' || $table==='albums') && $tagIdToAdd > 0) {
        require_superadmin();
        $tid=$tagIdToAdd;
        $tagExists=(int)db()->query("SELECT COUNT(*) FROM tags WHERE id=".$tid)->fetchColumn();
        if ($tagExists) {
            $pivot = $table==='photos' ? 'photo_tags' : 'album_tags';
            $idcol = $table==='photos' ? 'photo_id' : 'album_id';
            foreach($ids as $id) db()->prepare("INSERT IGNORE INTO $pivot($idcol,tag_id) VALUES(?,?)")->execute([$id,$tid]);
        }
    }
    $back = consume_return_path('?page='.$table);
    if (!preg_match('/^\?page=' . preg_quote($table, '/') . '/', $back)) $back = '?page='.$table;
    audit(rtrim($table,'s'),null,'bulk_update',"Bulk update in $table",['ids'=>$ids]); flash('Bulk update applied.'); go($back);
}


// Zyma trinama tik is DB (tags + album_tags/photo_tags rysiai). B2 ir metadata
// JSON zymu neturi, tad B2 Sync jos nesugrazins. Audite lieka albumu/nuotrauku
// ID - prireikus rysius galima atkurti rankomis.
function delete_tag(): void {
    require_superadmin();
    csrf();
    $id = (int)($_POST['tag_id'] ?? 0);
    $st = db()->prepare("SELECT id,name FROM tags WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $tag = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$tag) { flash('Žyma nerasta.', 'err'); go('?page=tags'); }
    $a = db()->prepare("SELECT album_id FROM album_tags WHERE tag_id=?"); $a->execute([$id]); $albumIds = array_map('intval', $a->fetchAll(PDO::FETCH_COLUMN));
    $p = db()->prepare("SELECT photo_id FROM photo_tags WHERE tag_id=?"); $p->execute([$id]); $photoIds = array_map('intval', $p->fetchAll(PDO::FETCH_COLUMN));
    $db = db();
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM album_tags WHERE tag_id=?")->execute([$id]);
        $db->prepare("DELETE FROM photo_tags WHERE tag_id=?")->execute([$id]);
        $db->prepare("DELETE FROM tags WHERE id=?")->execute([$id]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        flash('Žymos ištrinti nepavyko: '.$e->getMessage(), 'err'); go('?page=tags');
    }
    audit('tag', $id, 'delete', 'Tag deleted: '.$tag['name'], ['album_ids' => $albumIds, 'photo_ids' => $photoIds]);
    flash('Žyma „'.$tag['name'].'" ištrinta (nuimta nuo '.count($albumIds).' albumų ir '.count($photoIds).' nuotraukų).');
    go('?page=tags');
}

function create_tag_inline(): void {
    require_superadmin();
    csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $back = consume_return_path('?page=tags');
    if (!preg_match('/^\?page=(photos|tags|albums)/', $back)) $back='?page=tags';
    if ($name === '') { flash('Tag name is required.', 'err'); go($back); }
    db()->prepare("INSERT IGNORE INTO tags(name,slug,type) VALUES(?,?, 'keyword')")->execute([$name,slug($name)]);
    audit('tag',null,'create','Tag created: '.$name);
    flash('Tag created: '.$name);
    go($back);
}

function export_csv(string $type): void {
    require_superadmin(); $table=$type==='photos'?'photos':'albums';
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="'.$table.'-'.date('Ymd-His').'.csv"');
    $out=fopen('php://output','w'); $st=db()->query("SELECT * FROM $table ORDER BY id"); $first=true;
    while ($r=$st->fetch()) { if ($first) { fputcsv($out,array_keys($r)); $first=false; } fputcsv($out,$r); } exit;
}

function save_settings(): void {
    require_superadmin();
    csrf();
    $b2Keys = ['b2_api_key_id','b2_api_app_key','b2_api_bucket','b2_api_bucket_id'];
    foreach ($b2Keys as $bk) if (isset($_POST[$bk])) $_POST[$bk] = trim((string)$_POST[$bk]);
    if (array_key_exists('b2_api_app_key', $_POST) && $_POST['b2_api_app_key'] === '') unset($_POST['b2_api_app_key']);
    $b2Changed = false;
    foreach ($b2Keys as $bk) if (array_key_exists($bk, $_POST) && (string)$_POST[$bk] !== setting($bk)) $b2Changed = true;
    foreach($_POST as $k=>$v){ if($k==='_token') continue; db()->prepare("INSERT INTO settings(`group`,`key`,`value`,type) VALUES('general',?,?, 'string') ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([$k,json_encode((string)$v,JSON_UNESCAPED_UNICODE)]); }
    if ($b2Changed) {
        b2_load_config();
        $cache = defined('B2_AUTH_CACHE_FILE') ? (string)B2_AUTH_CACHE_FILE : sys_get_temp_dir().'/b2_auth_cache.json';
        @unlink($cache);
        audit('settings', null, 'b2_api_update', 'B2 API settings changed, auth cache cleared');
    }
    audit('settings',null,'update','Settings updated'); flash('Settings saved.'.($b2Changed ? ' B2 raktai atnaujinti, auth cache išvalytas.' : '')); go('?page=settings');
}
function b2_test_connection(): void {
    require_superadmin();
    csrf();
    b2_load_config();
    $cache = defined('B2_AUTH_CACHE_FILE') ? (string)B2_AUTH_CACHE_FILE : sys_get_temp_dir().'/b2_auth_cache.json';
    @unlink($cache);
    try {
        $auth = b2_auth();
        $files = b2_list_prefix('', 1);
        flash('B2 ryšys veikia: autorizacija OK ('.e((string)parse_url((string)$auth['apiUrl'], PHP_URL_HOST)).'), bucket pasiekiamas — '.count($files).' failų pirmame puslapyje. Key ID: '.e(substr(defined('B2_KEY_ID') ? (string)B2_KEY_ID : '', 0, 6)).'…');
    } catch (Throwable $e) {
        flash('B2 testas nepavyko: '.$e->getMessage(), 'err');
    }
    go('?page=settings');
}
function import_preview(): void {
    csrf();
    if(empty($_FILES['csv']['tmp_name'])){ flash('CSV file missing.','err'); go('?page=import'); }
    $fh=fopen($_FILES['csv']['tmp_name'],'r'); $head=fgetcsv($fh); $rows=[];
    while(($r=fgetcsv($fh))!==false && count($rows)<500){ $rows[]=array_combine($head,$r); }
    $mode=(string)($_POST['mode'] ?? 'fill');
    if(!in_array($mode,['fill','append','overwrite'],true)) $mode='fill';
    $_SESSION['csv_preview']=['type'=>$_POST['type'] ?? 'albums','mode'=>$mode,'rows'=>$rows];
    flash('CSV peržiūra įkelta ('.count($rows).' eil., režimas: '.$mode.').'); go('?page=import');
}
/**
 * ON DUPLICATE KEY UPDATE dalis pagal pasirinkta rezima.
 *
 * fill      - rasom tik i tuscia lauka, todel rankiniai taisymai admin sasajoje islieka
 * append    - teksta pridedam prie esamo (data ir skaiciai elgiasi kaip fill)
 * overwrite - CSV laimi visada; tuscia CSV reiksme istrina esama
 *
 * Skaiciams ir datoms tikrinam tik NULL: sveikame stulpelyje 0 yra reiksme, o ne
 * tustuma, ir palyginimas su '' ji klaidingai laikytu neuzpildytu.
 */
function csv_album_update_clause(string $mode): string {
    $text    = ['title','subtitle','description','location_name','author_name','copyright_text',
                'visibility','seo_title','seo_description','dbsportas_url','klajunas_url',
                'other_url','source_path','notes_internal'];
    $numeric = ['sort_order','download_enabled'];
    $dates   = ['event_date','event_date_end'];
    $appendable = ['description','notes_internal','seo_description'];
    $parts = [];
    foreach (array_merge($text, $numeric, $dates) as $c) {
        if ($mode === 'overwrite') {
            $parts[] = "$c=VALUES($c)";
            continue;
        }
        if ($mode === 'append' && in_array($c, $appendable, true)) {
            $parts[] = "$c=IF(VALUES($c) IS NULL OR VALUES($c)='', $c, IF($c IS NULL OR $c='', VALUES($c), CONCAT($c, '\n\n', VALUES($c))))";
            continue;
        }
        $parts[] = in_array($c, $text, true)
            ? "$c=IF($c IS NULL OR $c='', VALUES($c), $c)"
            : "$c=IF($c IS NULL, VALUES($c), $c)";
    }
    $parts[] = 'updated_by=VALUES(updated_by)';
    return implode(',', $parts);
}
function import_apply(): void {
    csrf(); $p=$_SESSION['csv_preview'] ?? null; if(!$p){ flash('No preview to apply.','err'); go('?page=import'); }
    $mode=(string)($p['mode'] ?? 'fill');
    if(!in_array($mode,['fill','append','overwrite'],true)) $mode='fill';
    $count=0;
    foreach($p['rows'] as $r){
        if($p['type']==='albums'){
            $slug=$r['slug'] ?? slug($r['title'] ?? 'album'); $title=$r['title'] ?? $slug;
            // ON DUPLICATE KEY suveikia pagal slug. CSV slug'as generuojamas is
            // kelio ir su esamu DB slug'u nesutampa, todel importas kurdavo
            // NAUJA juodrasti vietoj to, kad atnaujintu esama albuma. Jei CSV
            // turi source_path, pirma ieskom albumo pagal ji ir imam jo slug'a.
            $sp = trim((string)($r['source_path'] ?? ''), '/');
            if ($sp !== '') {
                $find = db()->prepare("SELECT slug FROM albums WHERE source_path=? LIMIT 1");
                $find->execute([$sp]);
                $existing = (string)($find->fetchColumn() ?: '');
                if ($existing !== '') $slug = $existing;
            }
            // source_path ir trys nuorodu stulpeliai anksciau i importa nepatekdavo:
            // albumas atkeliaudavo nesusietas su savo B2 aplanku, o dbsportas /
            // klajunas / kita nuorodos dingdavo. Pakartotinis importas taip pat
            // neatnaujindavo pabaigos datos, SEO ir pastabu - dabar atnaujina.
            db()->prepare("INSERT INTO albums(uuid,slug,title,subtitle,description,event_date,event_date_end,location_name,country_code,author_name,copyright_text,sort_order,visibility,download_enabled,seo_title,seo_description,dbsportas_url,klajunas_url,other_url,source_path,notes_internal,source_type,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'manual',?,?) ON DUPLICATE KEY UPDATE ".csv_album_update_clause($mode))
                ->execute([uid(),$slug,$title,$r['subtitle']??null,$r['description']??null,$r['event_date']?:null,$r['event_date_end']?:null,$r['location_name']??null,$r['country_code']??null,$r['author_name']??null,$r['copyright_text']??null,(int)($r['sort_order']??0),normalize_visibility($r['visibility']??'draft'),(int)($r['download_enabled']??1),$r['seo_title']??null,$r['seo_description']??null,$r['dbsportas_url']??null,$r['klajunas_url']??null,$r['other_url']??null,(($r['source_path']??'')!=='' ? trim((string)$r['source_path'],'/') : null),$r['notes_internal']??null,$_SESSION['admin']['id']??null,$_SESSION['admin']['id']??null]);
            $aid=db()->prepare("SELECT id FROM albums WHERE slug=? LIMIT 1"); $aid->execute([$slug]); persist_album_metadata_json((int)$aid->fetchColumn());
        } else {
            $album=(int)($r['album_id'] ?? 0); if(!$album) continue; $key=$r['b2_key'] ?? ($r['original_filename'] ?? ''); if($key==='') continue;
            $albumDownloadEnabled=1; $ad=db()->prepare("SELECT download_enabled FROM albums WHERE id=? LIMIT 1"); $ad->execute([$album]); $albumDownloadEnabled=(int)$ad->fetchColumn() ?: 0;
            db()->prepare("INSERT INTO photos(uuid,album_id,b2_key,original_filename,title,caption,alt_text,description,author_name,copyright_text,credit_line,taken_at,city,region,country,country_code,sort_order,visibility,is_downloadable,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),caption=VALUES(caption),alt_text=VALUES(alt_text),description=VALUES(description),author_name=VALUES(author_name),copyright_text=VALUES(copyright_text),taken_at=VALUES(taken_at),sort_order=VALUES(sort_order),visibility=VALUES(visibility),updated_by=VALUES(updated_by)")
                ->execute([uid(),$album,$key,$r['original_filename']??basename($key),$r['title']??null,$r['caption']??null,$r['alt_text']??null,$r['description']??null,$r['author_name']??null,$r['copyright_text']??null,$r['credit_line']??null,$r['taken_at']?:null,$r['city']??null,$r['region']??null,$r['country']??null,$r['country_code']??null,(int)($r['sort_order']??0),normalize_visibility($r['visibility']??'draft'),(int)($r['is_downloadable'] ?? $albumDownloadEnabled),$_SESSION['admin']['id']??null,$_SESSION['admin']['id']??null]);
            $pid=db()->prepare("SELECT id FROM photos WHERE album_id=? AND b2_key=? LIMIT 1"); $pid->execute([$album,$key]); persist_photo_metadata_json((int)$pid->fetchColumn());
        }
        $count++;
    }
    audit('import',null,'apply',"CSV import applied: $count rows"); unset($_SESSION['csv_preview']); flash("Imported $count rows."); go('?page=import');
}
function json_time(?array $node): ?string {
    return takeout_json_time($node);
}
function exif_fraction($v): ?float {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (float)$v;
    if (is_string($v) && str_contains($v, '/')) { [$a,$b]=array_pad(explode('/',$v,2),2,0); return (float)$b ? (float)$a/(float)$b : null; }
    return null;
}
function exif_date(?string $v): ?string {
    if (!$v) return null;
    $dt=DateTime::createFromFormat('Y:m:d H:i:s', $v, new DateTimeZone('UTC'));
    return $dt ? $dt->format('Y-m-d H:i:s') : null;
}
function gps_decimal($coord, $ref): ?float {
    if (!is_array($coord) || count($coord)<3) return null;
    $d=exif_fraction($coord[0]); $m=exif_fraction($coord[1]); $s=exif_fraction($coord[2]);
    if ($d===null || $m===null || $s===null) return null;
    $val=$d+($m/60)+($s/3600);
    return in_array($ref, ['S','W'], true) ? -$val : $val;
}
function takeout_geo_value(array $j, string $key): ?float {
    $v = $j['geoData'][$key] ?? $j['geoDataExif'][$key] ?? null;
    if ($v === null || $v === '' || (float)$v === 0.0) return null;
    return (float)$v;
}
const HEIC_UNSUPPORTED_UPLOAD_MESSAGE = 'HEIC formato nuotraukų šis serveris šiuo metu negali apdoroti. Prašome įkelti JPG arba PNG.';
const HEIC_MISSING_DISPLAY_MESSAGE = 'HEIC originalas įkeltas, bet JPG peržiūra nesukurta.';
const HEIC_INVALID_DISPLAY_MESSAGE = 'Sugeneruota JPG peržiūros versija netinkama. Įkeltas tik originalus failas.';

function photo_original_format(string $name): ?string {
    $ext = strtolower(trim((string)pathinfo($name, PATHINFO_EXTENSION)));
    return $ext !== '' ? substr($ext, 0, 16) : null;
}
function photo_format_is_heic(?string $format): bool {
    return in_array(strtolower((string)$format), ['heic','heif'], true);
}
function photo_preview_state(?string $originalFormat, ?string $compatibilityKey, ?string $error = null): array {
    $format = strtolower((string)$originalFormat);
    $compatibilityKey = trim((string)$compatibilityKey, '/');
    if ($compatibilityKey !== '') {
        return [
            'converted_from_heic' => photo_format_is_heic($format) ? 1 : 0,
            'preview_status' => 'ready',
            'preview_error' => null,
            'thumb_path' => $compatibilityKey,
            'preview_path' => $compatibilityKey,
            'web_path' => $compatibilityKey,
        ];
    }
    if (photo_format_is_heic($format)) {
        return [
            'converted_from_heic' => 0,
            'preview_status' => 'failed',
            'preview_error' => trim((string)$error) !== '' ? trim((string)$error) : HEIC_MISSING_DISPLAY_MESSAGE,
            'thumb_path' => null,
            'preview_path' => null,
            'web_path' => null,
        ];
    }
    return [
        'converted_from_heic' => 0,
        'preview_status' => 'ready',
        'preview_error' => null,
        'thumb_path' => null,
        'preview_path' => null,
        'web_path' => null,
    ];
}
function photo_preview_error_from_upload(array $file): ?string {
    $value = trim((string)($file['preview_error'] ?? ''));
    return $value !== '' ? mb_substr($value, 0, 2000) : null;
}

function admin_imagick_supports_format(string $format): bool {
    if (!extension_loaded('imagick') || !class_exists('Imagick')) return false;
    try {
        $probe = new Imagick();
        $formats = $probe->queryFormats(strtoupper($format));
        return is_array($formats) && $formats !== [];
    } catch (Throwable $e) {
        return false;
    }
}
function heic_upload_supported(): bool {
    return admin_imagick_supports_format('HEIC') || admin_imagick_supports_format('HEIF');
}
function is_heic_name(string $name): bool {
    return (bool)preg_match('~\.(heic|heif)$~i', $name);
}
function valid_generated_heic_display(string $tmp, string $name): bool {
    if (!preg_match('~\.jpe?g$~i', $name)) return false;
    if (!is_uploaded_file($tmp) && !is_file($tmp)) return false;
    $info = @getimagesize($tmp);
    return is_array($info) && (string)($info['mime'] ?? '') === 'image/jpeg' && (int)($info[0] ?? 0) > 0 && (int)($info[1] ?? 0) > 0;
}
function assert_heic_upload_supported(array $files): void {
    if (heic_upload_supported()) return;
    foreach ($files as $file) {
        $name = (string)($file['base'] ?? $file['name'] ?? '');
        if ($name !== '' && is_heic_name($name)) {
            throw new RuntimeException(HEIC_UNSUPPORTED_UPLOAD_MESSAGE);
        }
    }
}
function image_mime_from_name(string $name): ?string {
    return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        default => null,
    };
}
function image_metadata_from_imagick(string $tmp, string $name = ''): array {
    $out = [];
    if (!extension_loaded('imagick') || !class_exists('Imagick')) return $out;
    if ($name !== '' && is_heic_name($name) && !heic_upload_supported()) return $out;
    try {
        $img = new Imagick();
        $img->readImage($tmp.'[0]');
        $out['width'] = $img->getImageWidth() ?: null;
        $out['height'] = $img->getImageHeight() ?: null;
        $format = strtolower((string)$img->getImageFormat());
        if ($format === 'heic') $out['mime_type'] = 'image/heic';
        elseif ($format === 'heif') $out['mime_type'] = 'image/heif';
        elseif ($format === 'jpeg' || $format === 'jpg') $out['mime_type'] = 'image/jpeg';
        elseif ($format === 'png') $out['mime_type'] = 'image/png';
        elseif ($format === 'webp') $out['mime_type'] = 'image/webp';
        elseif ($format === 'gif') $out['mime_type'] = 'image/gif';
        $orientation = $img->getImageOrientation();
        if ($orientation) $out['orientation'] = (string)$orientation;
        foreach (['exif:Make', 'tiff:Make'] as $prop) {
            $value = trim((string)$img->getImageProperty($prop));
            if ($value !== '') { $out['camera_make'] = $value; break; }
        }
        foreach (['exif:Model', 'tiff:Model'] as $prop) {
            $value = trim((string)$img->getImageProperty($prop));
            if ($value !== '') { $out['camera_model'] = $value; break; }
        }
        foreach (['exif:LensModel', 'aux:Lens'] as $prop) {
            $value = trim((string)$img->getImageProperty($prop));
            if ($value !== '') { $out['lens_model'] = $value; break; }
        }
        foreach (['exif:DateTimeOriginal', 'exif:DateTimeDigitized', 'date:create'] as $prop) {
            $value = trim((string)$img->getImageProperty($prop));
            $date = exif_date($value);
            if ($date !== null) { $out['taken_at'] = $date; break; }
        }
        $img->clear();
        $img->destroy();
    } catch (Throwable $e) {
        return [];
    }
    return $out;
}
function image_metadata(string $tmp, string $name): array {
    $out=['mime_type'=>null,'width'=>null,'height'=>null,'orientation'=>null,'camera_make'=>null,'camera_model'=>null,'lens_model'=>null,'focal_length'=>null,'aperture'=>null,'shutter_speed'=>null,'iso_value'=>null,'taken_at'=>null,'latitude'=>null,'longitude'=>null];
    if (!preg_match('~\.(jpe?g|png|webp|heic|heif|gif)$~i', $name)) return $out;
    $size=@getimagesize($tmp); if(is_array($size)){ $out['width']=$size[0]??null; $out['height']=$size[1]??null; $out['mime_type']=$size['mime']??null; }
    foreach (image_metadata_from_imagick($tmp, $name) as $key => $value) {
        if ($value !== null && $value !== '' && empty($out[$key])) $out[$key] = $value;
    }
    if (empty($out['mime_type'])) $out['mime_type'] = image_mime_from_name($name);
    if(function_exists('exif_read_data') && preg_match('~\.jpe?g$~i',$name)){
        $ex=@exif_read_data($tmp, null, true, false);
        if(is_array($ex)){
            $ifd=$ex['IFD0']??[]; $sub=$ex['EXIF']??[]; $gps=$ex['GPS']??[];
            $out['camera_make']=is_scalar($ifd['Make']??null)?(string)$ifd['Make']:null; $out['camera_model']=is_scalar($ifd['Model']??null)?(string)$ifd['Model']:null; $out['orientation']=is_scalar($ifd['Orientation']??null)?(string)$ifd['Orientation']:null;
            $focal=exif_fraction($sub['FocalLength'] ?? null); $aperture=exif_fraction($sub['FNumber'] ?? null); $iso=$sub['ISOSpeedRatings'] ?? null; if(is_array($iso)) $iso=reset($iso);
            $out['lens_model']=is_scalar($sub['LensModel']??null)?(string)$sub['LensModel']:null; $out['focal_length']=$focal!==null ? (string)round($focal,1) : null;
            $out['aperture']=$aperture!==null ? 'f/'.round($aperture,1) : null;
            $out['shutter_speed']=is_scalar($sub['ExposureTime']??null)?(string)$sub['ExposureTime']:null; $out['iso_value']=is_numeric($iso) ? (int)$iso : null;
            $out['taken_at']=exif_date($sub['DateTimeOriginal'] ?? $sub['DateTimeDigitized'] ?? $ifd['DateTime'] ?? null);
            $out['latitude']=gps_decimal($gps['GPSLatitude']??null,$gps['GPSLatitudeRef']??null); $out['longitude']=gps_decimal($gps['GPSLongitude']??null,$gps['GPSLongitudeRef']??null);
        }
    }
    return $out;
}
function takeout_album_folder(array $files): string {
    foreach($files as $f){ $dir=trim(dirname($f['name']),'.\\/'); if($dir!=='' && $dir!=='.') return basename($dir); }
    return '';
}
function uploaded_json_files(): array {
    $f = $_FILES['json_files'] ?? null; if (!$f || empty($f['tmp_name'])) return [];
    $out=[]; foreach((array)$f['tmp_name'] as $i=>$tmp){ if(!is_uploaded_file($tmp)) continue; $name=(string)($f['name'][$i] ?? ''); $j=json_decode((string)file_get_contents($tmp), true); if(is_array($j)) $out[]=['name'=>$name,'json'=>$j]; }
    return $out;
}
function takeout_sidecar_json_name(string $name): bool {
    return (bool)preg_match('/\.(supplemental|supplement|supplemen|supplem|supple|sup)(?:-metadata|-metada|-metad|-meta|-met|-me|-m)?(?:\(\d+\))?\.json$/i', $name);
}
function strip_takeout_sidecar_suffix(string $name): string {
    return preg_replace('/\.(supplemental|supplement|supplemen|supplem|supple|sup)(?:-metadata|-metada|-metad|-meta|-met|-me|-m)?(?:\(\d+\))?\.json$/i', '', $name) ?? $name;
}
function takeout_photo_metadata_json(string $name, ?array $json = null): bool {
    $base = strtolower(basename($name));
    if ($base === 'metadata.json') return false;
    if (!preg_match('/\.json$/i', $base)) return false;
    if (takeout_sidecar_json_name($base)) return true;
    if (!$json) return false;
    return !empty($json['title']) && (
        array_key_exists('imageViews', $json)
        || array_key_exists('photoViews', $json)
        || array_key_exists('photoTakenTime', $json)
        || array_key_exists('creationTime', $json)
        || array_key_exists('url', $json)
        || array_key_exists('googlePhotosOrigin', $json)
    );
}
function google_photo_name(string $jsonName, array $json): string {
    $base=basename($jsonName);
    $base=strip_takeout_sidecar_suffix($base);
    if (preg_match('/\.json$/i', $base) && !empty($json['title'])) $base = basename((string)$json['title']);
    return $base!=='' && $base!==basename($jsonName) ? $base : basename((string)($json['title'] ?? $base));
}
function takeout_name_key(string $name): string {
    $base = basename($name);
    $base = strip_takeout_sidecar_suffix($base);
    $base = preg_replace('/\.(jpe?g|png|webp|heic|heif|gif|mp4|mov|m4v|avi|webm)$/i', '', $base) ?? $base;
    $base = mb_strtolower($base, 'UTF-8');
    $base = trim((string)(preg_replace('/[^[:alnum:]]+/u', '', $base) ?? ''));
    return $base;
}
function takeout_json_add_key(array &$map, string $name, array $json): void {
    $name = basename($name);
    if ($name === '') return;
    $map[$name] = $json;
    $key = takeout_name_key($name);
    if ($key !== '') $map['normalized:'.$key] = $json;
}
function takeout_json_for_file(array $map, string $name): ?array {
    $base = basename($name);
    if (isset($map[$base]) && is_array($map[$base])) return $map[$base];
    $key = takeout_name_key($base);
    if ($key !== '' && isset($map['normalized:'.$key]) && is_array($map['normalized:'.$key])) return $map['normalized:'.$key];
    return null;
}
function find_or_create_overlay_album(?array $albumJson, int $albumId, string $sourcePath): int {
    if ($albumId > 0) return $albumId;
    $title=takeout_json_text($albumJson, ['title','name','albumTitle']) ?: trim((string)($sourcePath ?: 'Imported album overlay'));
    $sourcePath=$sourcePath ?: $title;
    $q=db()->prepare("SELECT id FROM albums WHERE source_path=? OR slug=? LIMIT 1"); $q->execute([$sourcePath,slug($title)]); $id=(int)$q->fetchColumn();
    if($id) return $id;
    db()->prepare("INSERT INTO albums(uuid,source_type,source_path,slug,title,visibility,created_by,updated_by) VALUES(?,?,?,?,?,'published',?,?)")
        ->execute([uid(),'google_takeout_overlay',$sourcePath,slug($title),$title,$_SESSION['admin']['id']??null,$_SESSION['admin']['id']??null]);
    $id = (int)db()->lastInsertId();
    persist_album_metadata_json($id);
    return $id;
}
function apply_album_overlay(int $albumId, array $j): void {
    $title=takeout_json_text($j, ['title','name','albumTitle']); $desc=takeout_json_text($j, ['description','summary','albumDescription']); $date=takeout_album_date($j);
    $visibility=takeout_album_visibility($j, '');
    db()->prepare("UPDATE albums SET title=COALESCE(NULLIF(?,''),title), description=COALESCE(NULLIF(?,''),description), event_date=COALESCE(?,event_date), visibility=COALESCE(?,visibility), seo_title=COALESCE(NULLIF(?,''),seo_title), seo_description=COALESCE(NULLIF(?,''),seo_description), metadata_json=?, updated_by=? WHERE id=?")
        ->execute([$title,$desc,$date,$visibility ?: null,$title,$desc,json_encode($j,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$_SESSION['admin']['id']??null,$albumId]);
    persist_album_metadata_json($albumId);
}
function import_google_overlay(): void {
    csrf(); ensure_overlay_schema();
    $files=uploaded_json_files(); if(!$files){ flash('No JSON files uploaded.','err'); go('?page=import'); }
    $albumJson=null; foreach($files as $f){ if(strtolower(basename($f['name']))==='metadata.json'){ $albumJson=$f['json']; break; } }
    $sourcePath=trim((string)($_POST['source_path'] ?? ''));
    $albumId=find_or_create_overlay_album($albumJson,(int)($_POST['album_id'] ?? 0),$sourcePath);
    if($albumJson) apply_album_overlay($albumId,$albumJson);
    if(!$albumJson) persist_album_metadata_json($albumId);
    if($sourcePath==='') { $q=db()->prepare("SELECT COALESCE(source_path,title) FROM albums WHERE id=?"); $q->execute([$albumId]); $sourcePath=(string)$q->fetchColumn(); }
    $created=0; $updated=0; $skipped=0;
    foreach($files as $f){
        if(!takeout_photo_metadata_json($f['name'], $f['json'] ?? null)) continue;
        $j=$f['json']; $filename=google_photo_name($f['name'],$j); if($filename===''){ $skipped++; continue; }
        $q=db()->prepare("SELECT id FROM photos WHERE album_id=? AND (original_filename=? OR b2_key=? OR b2_key LIKE ?) LIMIT 1");
        $q->execute([$albumId,$filename,trim($sourcePath,'/').'/'.$filename,'%/'.$filename]); $photoId=(int)$q->fetchColumn();
        if(!$photoId && !empty($_POST['create_missing'])){
            $key=trim($sourcePath,'/'); $key=$key!==''?$key.'/'.$filename:$filename;
            db()->prepare("INSERT INTO photos(uuid,album_id,source_type,b2_key,original_filename,file_ext,visibility,created_by,updated_by) VALUES(?,?,?,?,?,?, 'published',?,?)")
                ->execute([uid(),$albumId,'google_takeout_overlay',$key,$filename,pathinfo($filename,PATHINFO_EXTENSION),$_SESSION['admin']['id']??null,$_SESSION['admin']['id']??null]);
            $photoId=(int)db()->lastInsertId(); $created++;
        }
        if(!$photoId){ $skipped++; continue; }
        $taken=takeout_photo_taken_time($j);
        $lat=takeout_geo_value($j,'latitude'); $lon=takeout_geo_value($j,'longitude');
        $views=takeout_image_views($j);
        db()->prepare("UPDATE photos SET title=?, description=?, taken_at=COALESCE(?,taken_at), latitude=?, longitude=?, photo_views=COALESCE(?,photo_views), metadata_json=?, updated_by=? WHERE id=?")
            ->execute([(string)($j['title'] ?? $filename),(string)($j['description'] ?? ''),$taken,$lat?:null,$lon?:null,$views,json_encode($j,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$_SESSION['admin']['id']??null,$photoId]);
        persist_photo_metadata_json($photoId);
        $updated++;
    }
    append_album_takeout_internal_notes($albumId, $albumJson, $files);
    audit('import',$albumId,'google_takeout_overlay',"Google overlay imported",['created'=>$created,'updated'=>$updated,'skipped'=>$skipped]);
    flash("Google overlay imported: $updated photos updated, $created created, $skipped skipped."); go('?page=import');
}
function uploaded_takeout_files(): array {
    $f=$_FILES['takeout_files'] ?? null; if(!$f || empty($f['tmp_name'])) return [];
    $out=[]; foreach((array)$f['tmp_name'] as $i=>$tmp){ $err=(int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE); if($err!==UPLOAD_ERR_OK) continue; $name=str_replace('\\','/',(string)($f['name'][$i] ?? '')); $out[]=['tmp'=>$tmp,'name'=>$name,'base'=>basename($name),'size'=>(int)($f['size'][$i] ?? 0),'type'=>(string)($f['type'][$i] ?? 'application/octet-stream')]; }
    return $out;
}
function takeout_payload(): array {
    $raw = (string)file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        json_error('Invalid Takeout JSON payload.', 400);
    }
    if ((string)($payload['_token'] ?? '') !== (string)($_SESSION['_token'] ?? '')) {
        json_error('Session expired.', 401);
    }
    return $payload;
}
function takeout_media_file(string $name): bool { return (bool)preg_match('~\.(jpe?g|png|webp|heic|heif|gif|mp4|mov|m4v|avi|webm|pdf|csv|docx?|xlsx?)$~i',$name); }

function takeout_preview(): void {
    csrf(); ensure_overlay_schema();
    $files=uploaded_takeout_files();
    if(!$files){ flash('No Takeout files uploaded. Check PHP upload limits.', 'err'); go('?page=takeout'); }
    $total=array_sum(array_column($files,'size')); $limits=upload_limits();
    if(visibility_from_input($_POST['visibility'] ?? '')===null){ flash(invalid_visibility_message($_POST['visibility'] ?? ''), 'err'); go('?page=takeout'); }
    $opts=[
        'album_title'=>posted_text('album_title'),
        'prefix'=>posted_text('prefix'),
        'visibility'=>visibility_from_input($_POST['visibility'] ?? ''),
        'event_date'=>$_POST['event_date'] ?? '',
        'upload_json'=>!empty($_POST['upload_json']),
        'overwrite_db'=>!empty($_POST['overwrite_db']),
    ];
    try {
        $staged=takeout_stage_uploaded_files($files);
        $plan=takeout_build_plan($staged,$opts);
        if(empty($plan['media'])) {
            takeout_cleanup_plan($plan);
            flash('No supported media files found in the Takeout upload.', 'err');
            go('?page=takeout');
        }
        $_SESSION['takeout_preview']=$plan;
        render_takeout_preview($plan);
        exit;
    } catch (Throwable $e) {
        if(isset($plan) && is_array($plan)) takeout_cleanup_plan($plan);
        $message = $e->getMessage() === HEIC_UNSUPPORTED_UPLOAD_MESSAGE ? $e->getMessage() : 'Takeout preview failed: '.$e->getMessage();
        flash($message, 'err');
        go('?page=takeout');
    }
}
function takeout_preview_payload(): void {
    ensure_overlay_schema();
    $payload = takeout_payload();
    $files = (array)($payload['files'] ?? []);
    if (!$files) json_error('No Takeout files uploaded.', 400);
    $opts=[
        'album_title'=>trim((string)($payload['album_title'] ?? '')),
        'prefix'=>trim((string)($payload['prefix'] ?? '')),
        'visibility'=>visibility_from_input($payload['visibility'] ?? '') ?? json_error(invalid_visibility_message($payload['visibility'] ?? ''), 400),
        'event_date'=>$payload['event_date'] ?? '',
        'upload_json'=>!empty($payload['upload_json']),
        'overwrite_db'=>!empty($payload['overwrite_db']),
    ];
    try {
        $staged=takeout_stage_payload_files($files);
        $plan=takeout_build_plan($staged,$opts);
        if(empty($plan['media'])) {
            takeout_cleanup_plan($plan);
            json_error('No supported media files found in the Takeout upload.', 400);
        }
        $_SESSION['takeout_preview']=$plan;
        json_exit(['ok'=>true,'count'=>count($staged)]);
    } catch (Throwable $e) {
        if(isset($plan) && is_array($plan)) takeout_cleanup_plan($plan);
        json_error($e->getMessage() === HEIC_UNSUPPORTED_UPLOAD_MESSAGE ? $e->getMessage() : 'Takeout preview failed: '.$e->getMessage(), 400);
    }
}
function takeout_stage_payload(): void {
    @ini_set('memory_limit', '768M');
    @set_time_limit(300);
    ensure_overlay_schema();
    try {
        $payload = takeout_payload();
        $result = takeout_stage_payload_chunk($payload);
        json_exit(['ok'=>true] + $result);
    } catch (Throwable $e) {
        json_error($e->getMessage() === HEIC_UNSUPPORTED_UPLOAD_MESSAGE ? $e->getMessage() : 'Could not stage Takeout chunk: '.$e->getMessage(), 400);
    }
}
function takeout_build_payload(): void {
    ensure_overlay_schema();
    $payload = takeout_payload();
    $token = (string)($payload['token'] ?? '');
    if ($token === '' || !preg_match('/^[a-f0-9]{24}$/', $token)) json_error('Invalid Takeout staging token.', 400);
    $files = takeout_read_stage_manifest($token);
    if (!$files) json_error('No staged Takeout files found.', 400);
    $opts=[
        'album_title'=>trim((string)($payload['album_title'] ?? '')),
        'prefix'=>trim((string)($payload['prefix'] ?? '')),
        'visibility'=>visibility_from_input($payload['visibility'] ?? '') ?? json_error(invalid_visibility_message($payload['visibility'] ?? ''), 400),
        'event_date'=>$payload['event_date'] ?? '',
        'upload_json'=>!empty($payload['upload_json']),
        'overwrite_db'=>!empty($payload['overwrite_db']),
    ];
    try {
        $plan=takeout_build_plan($files,$opts);
        if(empty($plan['media'])) {
            takeout_cleanup_plan($plan);
            json_error('No supported media/document/video files found in the Takeout upload.', 400);
        }
        $_SESSION['takeout_preview']=$plan;
        json_exit(['ok'=>true,'count'=>count($files)]);
    } catch (Throwable $e) {
        if(isset($plan) && is_array($plan)) takeout_cleanup_plan($plan);
        json_error($e->getMessage() === HEIC_UNSUPPORTED_UPLOAD_MESSAGE ? $e->getMessage() : 'Takeout preview failed: '.$e->getMessage(), 400);
    }
}
function takeout_json_maps(array $files): array {
    $jsonByBase=[]; $albumJson=null; $jsonFiles=[];
    foreach($files as $f){
        if(!preg_match('/\.json$/i',$f['base'])) continue;
        $j=json_decode((string)file_get_contents($f['tmp']),true);
        if(!is_array($j)) continue;
        $jsonFiles[$f['base']]=['file'=>$f,'json'=>$j];
        if(strtolower($f['base'])==='metadata.json') $albumJson=$j;
        if(takeout_photo_metadata_json($f['base'], $j)){
            $photoName=google_photo_name($f['base'],$j);
            takeout_json_add_key($jsonByBase, $photoName, $j);
            if(!empty($j['title'])) takeout_json_add_key($jsonByBase, basename((string)$j['title']), $j);
        }
    }
    return [$albumJson,$jsonByBase,$jsonFiles];
}
function takeout_preview_pending(): void {
    $plan=$_SESSION['takeout_preview'] ?? null;
    if(!is_array($plan) || empty($plan['media'])) { flash('No pending Takeout preview.', 'err'); go('?page=takeout'); }
    render_takeout_preview($plan);
}function takeout_append(): void {
    csrf(); ensure_overlay_schema();
    $plan=$_SESSION['takeout_preview'] ?? null;
    if(!is_array($plan) || empty($plan['token'])) { flash('No pending Takeout preview to append to.', 'err'); go('?page=takeout'); }
    $files=uploaded_takeout_files();
    if(!$files){ flash('No additional Takeout files uploaded. Check PHP upload limits.', 'err'); go('?page=takeout_preview_pending'); }
    try {
        $staged=takeout_stage_uploaded_files($files, (string)$plan['token']);
        $merged=array_merge((array)($plan['files'] ?? []), $staged);
        $opts=(array)($plan['options'] ?? []);
        $opts['album_title']=$plan['title'] ?? '';
        $opts['event_date']=$plan['event_date'] ?? '';
        $opts['prefix']=$plan['prefix'] ?? '';
        $opts['visibility']=normalize_visibility($plan['visibility'] ?? 'draft');
        $opts['description']=$plan['description'] ?? '';
        $opts['lock_prefix']=true;
        $plan=takeout_build_plan($merged,$opts);
        $_SESSION['takeout_preview']=$plan;
        flash('Added '.count($staged).' files to the pending Takeout preview.');
        go('?page=takeout_preview_pending');
    } catch (Throwable $e) {
        $message = $e->getMessage() === HEIC_UNSUPPORTED_UPLOAD_MESSAGE ? $e->getMessage() : 'Could not append Takeout batch: '.$e->getMessage();
        flash($message, 'err');
        go('?page=takeout_preview_pending');
    }
}
function takeout_append_payload(): void {
    ensure_overlay_schema();
    $payload = takeout_payload();
    $plan=$_SESSION['takeout_preview'] ?? null;
    if(!is_array($plan) || empty($plan['token'])) json_error('No pending Takeout preview to append to.', 400);
    $files=(array)($payload['files'] ?? []);
    if(!$files) json_error('No additional Takeout files uploaded.', 400);
    try {
        $staged=takeout_stage_payload_files($files, (string)$plan['token']);
        $merged=array_merge((array)($plan['files'] ?? []), $staged);
        $opts=(array)($plan['options'] ?? []);
        $opts['album_title']=$plan['title'] ?? '';
        $opts['event_date']=$plan['event_date'] ?? '';
        $opts['prefix']=$plan['prefix'] ?? '';
        $opts['visibility']=normalize_visibility($plan['visibility'] ?? 'draft');
        $opts['description']=$plan['description'] ?? '';
        $opts['lock_prefix']=true;
        $plan=takeout_build_plan($merged,$opts);
        $_SESSION['takeout_preview']=$plan;
        json_exit(['ok'=>true,'count'=>count($staged)]);
    } catch (Throwable $e) {
        json_error($e->getMessage() === HEIC_UNSUPPORTED_UPLOAD_MESSAGE ? $e->getMessage() : 'Could not append Takeout batch: '.$e->getMessage(), 400);
    }
}
function takeout_cancel(): void {
    $plan=$_SESSION['takeout_preview'] ?? null;
    if(is_array($plan)) takeout_cleanup_plan($plan);
    unset($_SESSION['takeout_preview']);
    flash('Pending Takeout import cancelled.');
    go('?page=takeout');
}
function takeout_confirm(): void {
    csrf(); ensure_overlay_schema(); b2_load_config();
    $plan=$_SESSION['takeout_preview'] ?? null;
    if(!is_array($plan) || empty($plan['media'])) { flash('No pending Takeout preview to import.', 'err'); go('?page=takeout'); }
    if(empty($_POST['confirm_takeout_import'])) { flash('Confirm the Takeout import before uploading to B2.', 'err'); go('?page=takeout_preview_pending'); }
    $files=$plan['files'] ?? [];
    if(!$files) { flash('Staged Takeout files are missing. Start the preview again.', 'err'); go('?page=takeout'); }
    foreach($plan['media'] as $m){
        if(empty($m['tmp']) || !is_file($m['tmp'])) { flash('Staged file expired or is missing: '.($m['base'] ?? 'unknown'), 'err'); go('?page=takeout'); }
    }
    try {
        $finalOpts = (array)($plan['options'] ?? []);
        $finalOpts['album_title'] = $_POST['album_title'] ?? ($plan['title'] ?? '');
        $finalOpts['event_date'] = $_POST['event_date'] ?? ($plan['event_date'] ?? '');
        $finalOpts['prefix'] = $_POST['prefix'] ?? ($plan['prefix'] ?? '');
        $finalOpts['visibility'] = visibility_from_input($_POST['visibility'] ?? '', (string)($plan['visibility'] ?? 'draft')) ?? throw new InvalidArgumentException(invalid_visibility_message($_POST['visibility'] ?? ''));
        $finalOpts['description'] = $_POST['description'] ?? ($plan['description'] ?? '');
        $initialPrefix = (string)($_POST['initial_prefix'] ?? ($plan['prefix'] ?? ''));
        $finalTitle = trim((string)$finalOpts['album_title']);
        $finalEventDate = takeout_clean_event_date($finalOpts['event_date'] ?? '') ?? ($plan['event_date'] ?? null);
        $finalCanonicalPrefix = takeout_unified_prefix($finalTitle !== '' ? $finalTitle : (string)($plan['title'] ?? 'Takeout album'), $finalEventDate);
        $postedPrefix = trim((string)$finalOpts['prefix'], '/');
        $finalOpts['lock_prefix'] = $postedPrefix !== '' && $postedPrefix !== $initialPrefix && $postedPrefix !== $finalCanonicalPrefix;
        $plan = takeout_build_plan($files, $finalOpts);
        $_SESSION['takeout_preview'] = $plan;
    } catch (Throwable $e) {
        flash('Takeout target is not valid: '.$e->getMessage(), 'err');
        go('?page=takeout_preview_pending');
    }
    [$albumJson,$jsonByBase,$jsonFiles]=takeout_json_maps($files);
    $title=(string)($plan['title'] ?? 'Takeout album');
    $prefix=takeout_clean_prefix($plan['prefix'] ?? '');
    $visibility=normalize_visibility($plan['visibility'] ?? 'draft');
    $eventDate=$plan['event_date'] ?? null;
    $description=(string)($plan['description'] ?? '');
    $albumId=find_or_create_overlay_album($albumJson,0,$prefix);
    if($albumJson && !empty($plan['options']['overwrite_db'])) apply_album_overlay($albumId,$albumJson);
    db()->prepare("UPDATE albums SET title=?, description=?, seo_title=COALESCE(NULLIF(?,''),seo_title), seo_description=COALESCE(NULLIF(?,''),seo_description), source_type='takeout_upload', source_path=?, visibility=?, event_date=COALESCE(?,event_date), synced_at=NOW(), updated_by=? WHERE id=?")
        ->execute([$title,$description,$title,$description,$prefix,$visibility,$eventDate,$_SESSION['admin']['id']??null,$albumId]);
    place_album_by_date_if_unset($albumId);
    append_album_takeout_internal_notes($albumId, $albumJson, $jsonFiles);
    persist_album_metadata_json($albumId);
    $ad=db()->prepare("SELECT download_enabled FROM albums WHERE id=? LIMIT 1"); $ad->execute([$albumId]); $albumDownloadEnabled=(int)$ad->fetchColumn() ?: 0;
    $upload=b2_upload_url(); $uploaded=0; $jsonUploaded=0; $created=0; $updated=0;
    if(!empty($plan['options']['upload_json'])){
        $albumPayload=$albumJson ?: overlay_album_json(db()->query("SELECT * FROM albums WHERE id=".(int)$albumId)->fetch());
        b2_upload_data(json_encode($albumPayload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),$prefix.'/metadata/metadata.json','application/json',$upload);
        $jsonUploaded++;
    }
    foreach($plan['media'] as $m){
        $base=(string)$m['base']; $key=(string)$m['b2_key'];
        b2_upload_file($m['tmp'],$key,$m['type'] ?: 'application/octet-stream',$upload); $uploaded++;
        $j=takeout_json_for_file($jsonByBase, $base); $exif=image_metadata($m['tmp'],$base); $mime=$exif['mime_type'] ?: ($m['type'] ?: 'application/octet-stream');
        $originalFormat = photo_original_format($base);
        $previewState = photo_preview_state($originalFormat, null, 'Takeout importo metu JPG peržiūra nesukurta.');
        $taken=takeout_photo_taken_time($j, $exif); $taken=$taken ?: $exif['taken_at'];
        $lat=$j?takeout_geo_value($j,'latitude'):null; $lon=$j?takeout_geo_value($j,'longitude'):null; $lat=$lat ?: $exif['latitude']; $lon=$lon ?: $exif['longitude'];
        $views=$j ? takeout_image_views($j) : null;
        $q=db()->prepare("SELECT id FROM photos WHERE album_id=? AND b2_key=? LIMIT 1"); $q->execute([$albumId,$key]); $pid=(int)$q->fetchColumn();
        if($pid){
            db()->prepare("UPDATE photos SET b2_bucket=?, stored_filename=?, original_filename=?, file_ext=?, original_format=?, converted_from_heic=?, preview_status=?, preview_error=?, thumb_path=?, preview_path=?, web_path=?, mime_type=?, file_size=?, photo_views=COALESCE(?,photo_views), title=?, description=?, taken_at=COALESCE(?,taken_at), latitude=?, longitude=?, width=?, height=?, orientation=?, camera_make=?, camera_model=?, lens_model=?, focal_length=?, aperture=?, shutter_speed=?, iso_value=?, metadata_json=COALESCE(?,metadata_json), visibility=?, is_downloadable=?, is_missing=0, sort_order=?, synced_at=NOW(), updated_by=? WHERE id=?")
                ->execute([defined('B2_BUCKET')?(string)B2_BUCKET:null,$m['target_name'],$base,pathinfo($base,PATHINFO_EXTENSION),$originalFormat,$previewState['converted_from_heic'],$previewState['preview_status'],$previewState['preview_error'],$previewState['thumb_path'],$previewState['preview_path'],$previewState['web_path'],$mime,$m['size'],$views,$j['title'] ?? $base,$j['description'] ?? null,$taken,$lat?:null,$lon?:null,$exif['width'],$exif['height'],$exif['orientation'],$exif['camera_make'],$exif['camera_model'],$exif['lens_model'],$exif['focal_length'],$exif['aperture'],$exif['shutter_speed'],$exif['iso_value'],$j?json_encode($j,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$visibility,$albumDownloadEnabled,(int)$m['seq']*10,$_SESSION['admin']['id']??null,$pid]);
            $updated++;
        } else {
            db()->prepare("INSERT INTO photos(uuid,album_id,source_type,b2_bucket,b2_key,original_filename,stored_filename,file_ext,original_format,converted_from_heic,preview_status,preview_error,thumb_path,preview_path,web_path,mime_type,file_size,photo_views,title,description,taken_at,latitude,longitude,width,height,orientation,camera_make,camera_model,lens_model,focal_length,aperture,shutter_speed,iso_value,metadata_json,sort_order,visibility,is_downloadable,is_missing,synced_at,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),?,?)")
                ->execute([uid(),$albumId,'takeout_upload',defined('B2_BUCKET')?(string)B2_BUCKET:null,$key,$base,$m['target_name'],pathinfo($base,PATHINFO_EXTENSION),$originalFormat,$previewState['converted_from_heic'],$previewState['preview_status'],$previewState['preview_error'],$previewState['thumb_path'],$previewState['preview_path'],$previewState['web_path'],$mime,$m['size'],$views,$j['title'] ?? $base,$j['description'] ?? null,$taken,$lat?:null,$lon?:null,$exif['width'],$exif['height'],$exif['orientation'],$exif['camera_make'],$exif['camera_model'],$exif['lens_model'],$exif['focal_length'],$exif['aperture'],$exif['shutter_speed'],$exif['iso_value'],$j?json_encode($j,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,(int)$m['seq']*10,$visibility,$albumDownloadEnabled,$_SESSION['admin']['id']??null,$_SESSION['admin']['id']??null]);
            $pid=(int)db()->lastInsertId(); $created++;
        }
        persist_photo_metadata_json($pid);
        if(!empty($plan['options']['upload_json'])){
            $pr=db()->prepare("SELECT * FROM photos WHERE id=?"); $pr->execute([$pid]); $photo=$pr->fetch();
            $payload=$j ?: overlay_photo_json($photo);
            b2_upload_data(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),(string)$m['sidecar_key'],'application/json',$upload);
            $jsonUploaded++;
        }
    }
    audit('takeout',$albumId,'upload_confirmed','Takeout album uploaded to unified B2 layout',['prefix'=>$prefix,'uploaded'=>$uploaded,'json_uploaded'=>$jsonUploaded,'created'=>$created,'updated'=>$updated]);
    takeout_cleanup_plan($plan); unset($_SESSION['takeout_preview']);
    flash("Takeout import finished: $uploaded media files and $jsonUploaded JSON files uploaded to B2, $created photos created, $updated updated.", 'ok', '?page=album_edit&id='.$albumId, 'Atidaryti albumą');
    go(consume_return_path('?page=albums'));
}
function takeout_import(): void {
    csrf(); ensure_overlay_schema(); b2_load_config();
    $files=uploaded_takeout_files(); if(!$files){ flash('No Takeout files uploaded. Check PHP upload limits.', 'err'); go('?page=takeout'); }
    $total=array_sum(array_column($files,'size')); $limits=upload_limits();
    if($limits['recommended_album_bytes']>0 && $total>$limits['recommended_album_bytes']) { flash('Album is larger than recommended web upload size: '.human_bytes($total).' > '.human_bytes($limits['recommended_album_bytes']).'. Use CLI uploader.', 'err'); go('?page=takeout'); }
    [$albumJson,$jsonByBase,$jsonFiles] = takeout_json_maps($files);
    $sidecarsForDate = [];
    foreach ($jsonByBase as $jsonKey => $jsonValue) {
        if (str_starts_with((string)$jsonKey, 'normalized:')) continue;
        if (takeout_photo_metadata_json((string)$jsonKey, $jsonValue)) $sidecarsForDate[] = $jsonValue;
    }
    $folder=takeout_album_folder($files);
    $title=trim((string)($_POST['album_title'] ?? '')) ?: trim((string)($albumJson['title'] ?? $folder ?: 'Takeout album'));
    $eventDate = takeout_clean_event_date($_POST['event_date'] ?? '') ?? takeout_event_date($albumJson, $sidecarsForDate, $files);
    $prefix=trim((string)($_POST['prefix'] ?? ''),'/') ?: takeout_unified_prefix($title, $eventDate);
    $visibility=visibility_from_input($_POST['visibility'] ?? '');
    if($visibility===null){ flash(invalid_visibility_message($_POST['visibility'] ?? ''), 'err'); go('?page=takeout'); }
    $albumId=find_or_create_overlay_album($albumJson,0,$prefix);
    db()->prepare("UPDATE albums SET title=?, source_type='takeout_upload', source_path=?, visibility=?, event_date=COALESCE(?,event_date), synced_at=NOW(), updated_by=? WHERE id=?")->execute([$title,$prefix,$visibility,$eventDate,$_SESSION['admin']['id']??null,$albumId]);
    if($albumJson) apply_album_overlay($albumId,$albumJson);
    if(!$albumJson) persist_album_metadata_json($albumId);
    append_album_takeout_internal_notes($albumId, $albumJson, $jsonFiles);
    $albumDownloadEnabled=1; $ad=db()->prepare("SELECT download_enabled FROM albums WHERE id=? LIMIT 1"); $ad->execute([$albumId]); $albumDownloadEnabled=(int)$ad->fetchColumn() ?: 0;
    $upload=b2_upload_url(); $uploaded=0; $created=0; $updated=0; $skipped=0;
    foreach($files as $f){
        $isJson=(bool)preg_match('/\.json$/i',$f['base']); if($isJson && empty($_POST['upload_json'])) continue;
        if(!$isJson && !takeout_media_file($f['base'])) { $skipped++; continue; }
        $key=$prefix.'/'.$f['base']; b2_upload_file($f['tmp'],$key,$f['type'] ?: 'application/octet-stream',$upload); $uploaded++;
        if($isJson) continue;
        $j=takeout_json_for_file($jsonByBase, $f['base']); $exif=image_metadata($f['tmp'],$f['base']);
        $originalFormat = photo_original_format($f['base']);
        $previewState = photo_preview_state($originalFormat, null, 'Takeout importo metu JPG peržiūra nesukurta.');
        $taken=takeout_photo_taken_time($j, $exif); $taken=$taken ?: $exif['taken_at'];
        $lat=$j?takeout_geo_value($j,'latitude'):null; $lon=$j?takeout_geo_value($j,'longitude'):null; $lat=$lat ?: $exif['latitude']; $lon=$lon ?: $exif['longitude'];
        $views=$j ? takeout_image_views($j) : null;
        $q=db()->prepare("SELECT id FROM photos WHERE album_id=? AND b2_key=? LIMIT 1"); $q->execute([$albumId,$key]); $pid=(int)$q->fetchColumn();
        if($pid){
            db()->prepare("UPDATE photos SET b2_bucket=?, original_filename=?, file_ext=?, original_format=?, converted_from_heic=?, preview_status=?, preview_error=?, thumb_path=?, preview_path=?, web_path=?, mime_type=?, file_size=?, photo_views=COALESCE(?,photo_views), title=?, description=?, taken_at=COALESCE(?,taken_at), latitude=?, longitude=?, width=?, height=?, orientation=?, camera_make=?, camera_model=?, lens_model=?, focal_length=?, aperture=?, shutter_speed=?, iso_value=?, metadata_json=?, visibility=?, is_missing=0, synced_at=NOW(), updated_by=? WHERE id=?")
                ->execute([defined('B2_BUCKET')?(string)B2_BUCKET:null,$f['base'],pathinfo($f['base'],PATHINFO_EXTENSION),$originalFormat,$previewState['converted_from_heic'],$previewState['preview_status'],$previewState['preview_error'],$previewState['thumb_path'],$previewState['preview_path'],$previewState['web_path'],$exif['mime_type'],$f['size'],$views,$j['title'] ?? $f['base'],$j['description'] ?? null,$taken,$lat?:null,$lon?:null,$exif['width'],$exif['height'],$exif['orientation'],$exif['camera_make'],$exif['camera_model'],$exif['lens_model'],$exif['focal_length'],$exif['aperture'],$exif['shutter_speed'],$exif['iso_value'],$j?json_encode($j,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$visibility,$_SESSION['admin']['id']??null,$pid]);
            persist_photo_metadata_json($pid);
            $updated++;
        }
        else {
            db()->prepare("INSERT INTO photos(uuid,album_id,source_type,b2_bucket,b2_key,original_filename,file_ext,original_format,converted_from_heic,preview_status,preview_error,thumb_path,preview_path,web_path,mime_type,file_size,photo_views,title,description,taken_at,latitude,longitude,width,height,orientation,camera_make,camera_model,lens_model,focal_length,aperture,shutter_speed,iso_value,metadata_json,visibility,is_downloadable,is_missing,synced_at,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,NOW(),?,?)")
                ->execute([uid(),$albumId,'takeout_upload',defined('B2_BUCKET')?(string)B2_BUCKET:null,$key,$f['base'],pathinfo($f['base'],PATHINFO_EXTENSION),$originalFormat,$previewState['converted_from_heic'],$previewState['preview_status'],$previewState['preview_error'],$previewState['thumb_path'],$previewState['preview_path'],$previewState['web_path'],$exif['mime_type'],$f['size'],$views,$j['title'] ?? $f['base'],$j['description'] ?? null,$taken,$lat?:null,$lon?:null,$exif['width'],$exif['height'],$exif['orientation'],$exif['camera_make'],$exif['camera_model'],$exif['lens_model'],$exif['focal_length'],$exif['aperture'],$exif['shutter_speed'],$exif['iso_value'],$j?json_encode($j,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$visibility,$albumDownloadEnabled,$_SESSION['admin']['id']??null,$_SESSION['admin']['id']??null]);
            $pid=(int)db()->lastInsertId();
            persist_photo_metadata_json($pid);
            $created++;
        }
    }
    gallery_list_cache_invalidate_album($albumId);
    audit('takeout',$albumId,'upload',"Takeout album uploaded to B2",['uploaded'=>$uploaded,'created'=>$created,'updated'=>$updated,'skipped'=>$skipped,'prefix'=>$prefix]);
    flash("Takeout import finished: $uploaded files uploaded to B2, $created photos created, $updated updated, $skipped skipped."); go('?page=album_edit&id='.$albumId);
}
function export_overlay_json(int $albumId): void {
    need_login(); ensure_overlay_schema();
    $a=db()->prepare("SELECT * FROM albums WHERE id=?"); $a->execute([$albumId]); $album=$a->fetch(); if(!$album){ http_response_code(404); exit('Album not found'); }
    $ps=db()->prepare("SELECT * FROM photos WHERE album_id=? ORDER BY sort_order,taken_at,id"); $ps->execute([$albumId]);
    $out=['metadata.json'=>json_decode((string)($album['metadata_json'] ?? ''),true) ?: ['title'=>$album['title'],'description'=>$album['description'],'access'=>$album['visibility']==='published'?'public':$album['visibility'],'date'=>$album['event_date']?['timestamp'=>(string)strtotime($album['event_date'].' 00:00:00 UTC'),'formatted'=>$album['event_date']]:null], 'photos'=>[]];
    foreach($ps as $p){
        $j=json_decode((string)($p['metadata_json'] ?? ''),true) ?: [];
        $out['photos'][$p['original_filename'].'.supplemental-metadata.json']=$j ?: ['title'=>$p['original_filename'],'description'=>$p['description'] ?? '', 'photoTakenTime'=>$p['taken_at']?['timestamp'=>(string)strtotime($p['taken_at'].' UTC'),'formatted'=>$p['taken_at']]:null, 'geoData'=>['latitude'=>(float)$p['latitude'],'longitude'=>(float)$p['longitude'],'altitude'=>0]];
    }
    header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="'.slug($album['title']).'-overlay.json"');
    echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT); exit;
}
function overlay_album_json(array $album): array {
    $j=json_decode((string)($album['metadata_json'] ?? ''),true);
    if(is_array($j)) return $j;
    return [
        'title'=>$album['title'],
        'description'=>$album['description'] ?? '',
        'access'=>$album['visibility']==='published'?'public':$album['visibility'],
        'date'=>$album['event_date']?['timestamp'=>(string)strtotime($album['event_date'].' 00:00:00 UTC'),'formatted'=>$album['event_date']]:null,
    ];
}
function overlay_photo_json(array $p): array {
    $j=json_decode((string)($p['metadata_json'] ?? ''),true);
    if(is_array($j)) return $j;
    $out = [
        'title'=>$p['original_filename'],
        'description'=>$p['description'] ?? '',
        'photoTakenTime'=>$p['taken_at']?['timestamp'=>(string)strtotime($p['taken_at'].' UTC'),'formatted'=>$p['taken_at']]:null,
        'geoData'=>[
            'latitude'=>(float)($p['latitude'] ?? 0),
            'longitude'=>(float)($p['longitude'] ?? 0),
            'altitude'=>0,
            'latitudeSpan'=>0,
            'longitudeSpan'=>0,
        ],
    ];
    if (!empty($p['photo_views'])) $out['imageViews'] = (string)$p['photo_views'];
    return $out;
}
function export_overlay_zip(int $albumId): void {
    need_login(); ensure_overlay_schema();
    if(!class_exists('ZipArchive')) { http_response_code(500); exit('ZipArchive PHP extension is not available on this server.'); }
    $a=db()->prepare("SELECT * FROM albums WHERE id=?"); $a->execute([$albumId]); $album=$a->fetch(); if(!$album){ http_response_code(404); exit('Album not found'); }
    $tmp=tempnam(sys_get_temp_dir(),'overlay-'); $zip=new ZipArchive();
    if($zip->open($tmp, ZipArchive::OVERWRITE)!==true){ http_response_code(500); exit('Could not create ZIP.'); }
    $folder=slug($album['title']);
    $zip->addFromString($folder.'/metadata.json', json_encode(overlay_album_json($album), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    $ps=db()->prepare("SELECT * FROM photos WHERE album_id=? ORDER BY sort_order,taken_at,id"); $ps->execute([$albumId]);
    foreach($ps as $p){
        $zip->addFromString($folder.'/'.$p['original_filename'].'.supplemental-metadata.json', json_encode(overlay_photo_json($p), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT));
    }
    $zip->close();
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.$folder.'-overlay.zip"'); header('Content-Length: '.filesize($tmp));
    readfile($tmp); @unlink($tmp); exit;
}
function b2_load_config(): void {
    try {
        foreach (['B2_KEY_ID'=>'b2_api_key_id','B2_APP_KEY'=>'b2_api_app_key','B2_BUCKET'=>'b2_api_bucket','B2_BUCKET_ID'=>'b2_api_bucket_id'] as $const => $key) {
            $v = trim(setting($key));
            if ($v !== '' && !defined($const)) define($const, $v);
        }
    } catch (Throwable $e) { /* settings lentelė gali būti nepasiekiama bootstrap metu */ }
    $lvl = error_reporting(error_reporting() & ~E_WARNING);
    foreach ([__DIR__.'/../../b2-config.php', __DIR__.'/../../../b2-config.php'] as $cfg) {
        if (is_file($cfg)) { require_once $cfg; break; }
    }
    foreach ([__DIR__.'/../gallery-security.php', __DIR__.'/../../public_html/gallery-security.php'] as $sec) {
        if (is_file($sec)) { require_once $sec; break; }
    }
    error_reporting($lvl);
}
function b2_allowed_prefixes(?string $prefix=null): array {
    if($prefix) return [trim($prefix,'/')];
    if(defined('GALLERY_ALLOWED_PREFIXES') && is_array(GALLERY_ALLOWED_PREFIXES)) return array_values(GALLERY_ALLOWED_PREFIXES);
    return [''];
}
function b2_auth(): array {
    b2_load_config();
    foreach(['B2_KEY_ID','B2_APP_KEY','B2_BUCKET','B2_BUCKET_ID'] as $k) if(!defined($k)||constant($k)==='') throw new RuntimeException("Missing $k in b2-config.php");
    $cache=defined('B2_AUTH_CACHE_FILE')?(string)B2_AUTH_CACHE_FILE:sys_get_temp_dir().'/b2_auth_cache.json';
    $auth=is_file($cache)?json_decode((string)file_get_contents($cache),true):null;
    if(is_array($auth)&&($auth['expires']??0)>time()) return $auth;
    $ch=curl_init('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Authorization: Basic '.base64_encode(B2_KEY_ID.':'.B2_APP_KEY)]]);
    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $j=json_decode((string)$body,true); if($code>=400||!is_array($j)) throw new RuntimeException('B2 authorize failed');
    $auth=['apiUrl'=>(string)$j['apiUrl'],'downloadUrl'=>(string)$j['downloadUrl'],'authToken'=>(string)$j['authorizationToken'],'expires'=>time()+23*3600];
    @file_put_contents($cache,json_encode($auth)); return $auth;
}
function b2_upload_url(): array {
    $auth=b2_auth();
    $ch=curl_init($auth['apiUrl'].'/b2api/v2/b2_get_upload_url');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Authorization: '.$auth['authToken'],'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode(['bucketId'=>(string)B2_BUCKET_ID])]);
    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $j=json_decode((string)$body,true); if($code>=400||!is_array($j)) throw new RuntimeException('B2 upload URL failed');
    return $j;
}
function b2_upload_file(string $tmp,string $key,string $mime,array &$upload): array {
    if(!is_file($tmp)) throw new RuntimeException('Upload temp file missing: '.$key);
    $data=file_get_contents($tmp); if($data===false) throw new RuntimeException('Could not read upload temp file: '.$key);
    $sha1=sha1($data);
    $ch=curl_init($upload['uploadUrl']);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>90,CURLOPT_HTTPHEADER=>['Authorization: '.$upload['authorizationToken'],'X-Bz-File-Name: '.str_replace('%2F','/',rawurlencode($key)),'Content-Type: '.$mime,'X-Bz-Content-Sha1: '.$sha1],CURLOPT_POSTFIELDS=>$data]);
    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $j=json_decode((string)$body,true);
    if($code===401){ $upload=b2_upload_url(); return b2_upload_file($tmp,$key,$mime,$upload); }
    if($code>=400||!is_array($j)) throw new RuntimeException('B2 upload failed for '.$key.': '.$code.' '.$body);
    return $j;
}
function b2_upload_data(string $data,string $key,string $mime,array &$upload): array {
    $sha1=sha1($data);
    $ch=curl_init($upload['uploadUrl']);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>90,CURLOPT_HTTPHEADER=>['Authorization: '.$upload['authorizationToken'],'X-Bz-File-Name: '.str_replace('%2F','/',rawurlencode($key)),'Content-Type: '.$mime,'X-Bz-Content-Sha1: '.$sha1],CURLOPT_POSTFIELDS=>$data]);
    $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $j=json_decode((string)$body,true);
    if($code===401){ $upload=b2_upload_url(); return b2_upload_data($data,$key,$mime,$upload); }
    if($code>=400||!is_array($j)) throw new RuntimeException('B2 upload failed for '.$key.': '.$code.' '.$body);
    return $j;
}
function clear_missing_flags(): void {
    require_superadmin(); csrf();
    $n = (int)db()->query("SELECT COUNT(*) FROM photos WHERE is_missing=1")->fetchColumn();
    db()->prepare("UPDATE photos SET is_missing=0, updated_by=? WHERE is_missing=1")->execute([$_SESSION['admin']['id'] ?? null]);
    audit('photo', 0, 'clear_missing', 'Nuimta dingusiu zyme', ['rows' => $n]);
    flash($n.' nuotraukos grąžintos į galeriją.');
    go('?page=b2');
}

/* Kiekvienas puslapis - 1000 failu. Pasiekus $pages riba sarasas NUKIRPTAS:
 * failai uz ribos tiesiog negrazinami. 2026-09-23 B2 Sync su numatytais 8
 * puslapiais pamate tik pirmus 8000 failu (~iki 2011 m.) ir "mark missing"
 * pazymejo ~220 albumu kaip trukstamus, nors failai B2 buvo.
 *
 * Todel: b2_last_list_truncated() pasako, ar paskutinis sarasas nukirptas, o
 * $requireComplete=true nukirptu atveju meta klaida - kvietejai, kurie is
 * failo NEBUVIMO daro isvadas (zymi missing, trina DB eilutes), privalo ji
 * naudoti. Geriau nieko nepadaryti nei padaryti pagal puse saraso. */
function b2_last_list_truncated(?bool $set = null): bool {
    static $truncated = false;
    if ($set !== null) $truncated = $set;
    return $truncated;
}
function b2_list_prefix(string $prefix,int $pages=8,bool $requireComplete=false,?callable $onPage=null): array {
    $auth=b2_auth(); $out=[]; $cursor=''; $prefix=trim($prefix,'/'); $prefix=$prefix===''?'':$prefix.'/';
    for($i=0;$i<$pages;$i++){
        $post=['bucketId'=>(string)B2_BUCKET_ID,'prefix'=>$prefix,'maxFileCount'=>1000]; if($cursor!=='') $post['startFileName']=$cursor;
        $ch=curl_init($auth['apiUrl'].'/b2api/v2/b2_list_file_names');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Authorization: '.$auth['authToken'],'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($post)]);
        $body=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        $j=json_decode((string)$body,true); if($code>=400||!is_array($j)) throw new RuntimeException('B2 list failed');
        foreach(($j['files']??[]) as $f) $out[]=$f;
        if ($onPage) $onPage(count($out));
        $cursor=(string)($j['nextFileName']??''); if($cursor==='') break;
    }
    b2_last_list_truncated($cursor !== '');
    if ($cursor !== '' && $requireComplete) {
        throw new RuntimeException('B2 sąrašas nepilnas: „'.($prefix === '' ? '(šaknis)' : rtrim($prefix, '/')).'" turi daugiau nei '.($pages * 1000).' failų. Veiksmas nutrauktas — niekas nepažymėta ir neištrinta.');
    }
    return $out;
}
// Jungtis laikoma atidaryta visam uzklausu srautui. Anksciau kiekvienam failui
// buvo kuriamas naujas curl_init, tad kas kopijuojamas failas kainavo atskira
// TLS rankos paspaudima - apie sekunde. Del to 245 failu albumas per 20 s
// biudzeta pajudedavo tik ~20 failu ir migracija atrode nesibaigianti.
function b2_curl() {
    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FORBID_REUSE   => false,
            CURLOPT_FRESH_CONNECT  => false,
            CURLOPT_TCP_KEEPALIVE  => 1,
        ]);
    }
    return $ch;
}
function b2_api(string $endpoint, array $body): array {
    $auth=b2_auth();
    $ch=b2_curl();
    curl_setopt($ch, CURLOPT_URL, $auth['apiUrl'].'/b2api/v4/'.$endpoint);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: '.$auth['authToken'],'Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $json=json_decode((string)$raw,true);
    if($code>=400||!is_array($json)) throw new RuntimeException('B2 '.$endpoint.' failed: '.$code.' '.trim((string)$raw));
    return $json;
}
function b2_copy_file_version(array $file, string $newName): array {
    $body=['sourceFileId'=>(string)($file['fileId'] ?? ''),'fileName'=>$newName,'destinationBucketId'=>(string)B2_BUCKET_ID,'metadataDirective'=>'COPY'];
    if($body['sourceFileId']==='') throw new RuntimeException('B2 copy failed: missing source file id');
    return b2_api('b2_copy_file', $body);
}
function b2_delete_file_version(string $fileId, string $fileName): array {
    return b2_api('b2_delete_file_version', ['fileId'=>$fileId,'fileName'=>$fileName]);
}
function b2_prefix_file_map(string $prefix, int $pages = 20): array {
    $prefix = trim($prefix, '/');
    if ($prefix === '') return [];
    $out = [];
    foreach (b2_list_prefix($prefix, $pages) as $file) {
        $name = trim((string)($file['fileName'] ?? ''), '/');
        if ($name === '' || ($name !== $prefix && !str_starts_with($name, $prefix.'/'))) continue;
        $out[$name] = $file;
    }
    return $out;
}
function b2_prefix_image_map(string $prefix, int $pages = 20): array {
    return array_filter(b2_prefix_file_map($prefix, $pages), fn($file) => preg_match('~\.(jpe?g|png|webp|heic|heif)$~i', (string)($file['fileName'] ?? '')) && !str_contains((string)($file['fileName'] ?? ''), '/metadata/') && !str_contains((string)($file['fileName'] ?? ''), '/archive-originals/') && !str_contains((string)($file['fileName'] ?? ''), '/jpg-originals/'));
}
// Nebaigtas kopijavimas palieka dali failu naujajame kelyje. Toks aplankas nera
// svetimas dublikatas - tai ta pati migracija, kuria reikia testi. Skiriam pagal
// tai, ar VISI tikslo failai turi atitikmeni saltinyje: jei taip, tai musu pacio
// kopijavimo likutis; jei rastas bent vienas svetimas failas - tikras dublikatas.
function b2_prefix_is_partial_copy_of(string $targetPrefix, string $sourcePrefix): bool {
    $targetPrefix=trim($targetPrefix,'/');
    $sourcePrefix=trim($sourcePrefix,'/');
    if($targetPrefix==='' || $sourcePrefix==='' || $targetPrefix===$sourcePrefix) return false;
    $sourceNames=[];
    foreach(b2_prefix_file_map($sourcePrefix, 20) as $name => $_){
        $name=(string)$name;
        if($name!==$sourcePrefix && !str_starts_with($name, $sourcePrefix.'/')) continue;
        $sourceNames[$name===$sourcePrefix ? '' : substr($name, strlen($sourcePrefix)+1)]=true;
    }
    if(!$sourceNames) return false;
    $targetCount=0;
    foreach(b2_prefix_file_map($targetPrefix, 20) as $name => $_){
        $name=(string)$name;
        if($name!==$targetPrefix && !str_starts_with($name, $targetPrefix.'/')) continue;
        $suffix=$name===$targetPrefix ? '' : substr($name, strlen($targetPrefix)+1);
        if(!isset($sourceNames[$suffix])) return false;
        $targetCount++;
    }
    return $targetCount > 0;
}
// Tikrasis stabdis yra PHP vykdymo laiko riba, o ne failu skaicius. Bandom ja
// isjungti; jei serveris neleidzia - dirbam iki jos, palikdami atsarga atsakymui
// suformuoti. Anksciau cia buvo fiksuotos 20 s, todel didesni albumai keliaudavo
// po ~20 failu per paspaudima ir atrode, kad procesas nesibaigia.
// Virsutine riba yra ne PHP, o Cloudflare: pro ji einanti uzklausa nutraukiama
// apie 100 s ir naudotojas gauna 524 vietoj ataskaitos. Todel dirbam iki 85 s ir
// grazinam normalu atsakyma - jei kartais nespetume, likutis parodomas aiskiai.
function b2_time_budget(float $ceiling = 85.0): float {
    @set_time_limit(0);
    return $ceiling;
}
// Failu skaiciaus ribos nera: dirbam kol pabaigiam arba kol prisiartinam prie
// Cloudflare laiko ribos. Anksciau cia buvo 35/400/500 - skaiciai is oro, del
// kuriu didesni albumai keliaudavo dalimis be jokios priezasties.
function b2_copy_prefix_chunk(string $oldPrefix, string $newPrefix, int $maxFiles = PHP_INT_MAX, ?float $maxSeconds = null): array {
    if ($maxSeconds === null) { $maxSeconds = b2_time_budget(); }
    $oldPrefix=trim($oldPrefix,'/');
    $newPrefix=trim($newPrefix,'/');
    if($oldPrefix==='' || $oldPrefix===$newPrefix) return ['copied'=>0,'skipped'=>0,'remaining'=>0,'old_files'=>0,'target_files'=>0,'old_images'=>0,'target_images'=>0];
    $startedAt=microtime(true);
    $files=b2_prefix_file_map($oldPrefix, 20);
    $targetFiles=b2_prefix_file_map($newPrefix, 20);
    $targetNames=[];
    foreach($targetFiles as $targetName => $_) $targetNames[$targetName]=true;
    $oldImages=0; $targetImages=0; $copied=0; $skipped=0; $remaining=0;
    $expectedTargets=[];
    foreach($files as $file){
        $name=(string)($file['fileName'] ?? '');
        if($name==='' || ($name!==$oldPrefix && !str_starts_with($name, $oldPrefix.'/'))) continue;
        if(preg_match('~\.(jpe?g|png|webp|heic|heif)$~i', $name) && !str_contains($name, '/metadata/') && !str_contains($name, '/archive-originals/') && !str_contains($name, '/jpg-originals/')) $oldImages++;
        $suffix=$name=== $oldPrefix ? '' : substr($name, strlen($oldPrefix)+1);
        $target=$suffix==='' ? $newPrefix : $newPrefix.'/'.$suffix;
        $expectedTargets[$target]=true;
        if(isset($targetNames[$target])) { $skipped++; continue; }
        // Laikmatis atstatomas kas faila, kad PHP riba nenutrauktu darbo viduryje.
        @set_time_limit(30);
        if($copied < $maxFiles && (microtime(true) - $startedAt) < $maxSeconds){
            b2_copy_file_version($file, $target);
            $copied++;
            $targetNames[$target]=true;
        } else {
            $remaining++;
        }
    }
    $verifiedTargetFiles=b2_prefix_file_map($newPrefix, 20);
    $verifiedTargetNames=[];
    foreach($verifiedTargetFiles as $targetName => $_) $verifiedTargetNames[$targetName]=true;
    $missingTargets=0;
    foreach($expectedTargets as $targetName => $_) {
        if(!isset($verifiedTargetNames[$targetName])) $missingTargets++;
    }
    foreach($verifiedTargetNames as $targetName => $_) {
        if(preg_match('~\.(jpe?g|png|webp|heic|heif)$~i', $targetName) && !str_contains($targetName, '/metadata/') && !str_contains($targetName, '/archive-originals/') && !str_contains($targetName, '/jpg-originals/')) $targetImages++;
    }
    return ['copied'=>$copied,'skipped'=>$skipped,'remaining'=>$remaining,'old_files'=>count($files),'target_files'=>count($verifiedTargetNames),'missing_targets'=>$missingTargets,'old_images'=>$oldImages,'target_images'=>$targetImages];
}
function b2_delete_prefix_chunk(string $prefix, int $maxFiles = PHP_INT_MAX, ?float $maxSeconds = null): array {
    if ($maxSeconds === null) { $maxSeconds = b2_time_budget(); }
    $prefix=trim($prefix,'/');
    if($prefix==='') return ['deleted'=>0,'remaining'=>0];
    $startedAt=microtime(true);
    $files=b2_prefix_file_map($prefix, 20);
    $deleted=0; $remaining=0;
    foreach($files as $file){
        $name=(string)($file['fileName'] ?? '');
        if($name==='' || ($name!==$prefix && !str_starts_with($name, $prefix.'/'))) continue;
        @set_time_limit(30);
        if($deleted < $maxFiles && (microtime(true) - $startedAt) < $maxSeconds){
            b2_delete_file_version((string)($file['fileId'] ?? ''), $name);
            $deleted++;
        } else {
            $remaining++;
        }
    }
    return ['deleted'=>$deleted,'remaining'=>$remaining];
}
function b2_prefix_image_count(string $prefix): int {
    $prefix = trim($prefix, '/');
    if ($prefix === '') return 0;
    $files = b2_list_prefix($prefix, 20);
    $count = 0;
    foreach ($files as $file) {
        $name = trim((string)($file['fileName'] ?? ''), '/');
        if ($name === '' || !str_starts_with($name, $prefix.'/')) continue;
        if (str_contains($name, '/metadata/') || str_contains($name, '/archive-originals/') || str_contains($name, '/jpg-originals/')) continue;
        if (preg_match('~\.(jpe?g|png|webp|heic|heif)$~i', $name)) $count++;
    }
    return $count;
}
function b2_album_mapping_status_from_keys(int $albumId, array $realImageKeys): array {
    $real = [];
    foreach ($realImageKeys as $key) {
        $key = trim((string)$key, '/');
        if ($key !== '') $real[$key] = true;
    }
    $st = db()->prepare("SELECT id,b2_key,is_missing FROM photos WHERE album_id=?");
    $st->execute([$albumId]);
    $dbTotal = 0; $dbActive = 0; $found = 0; $missingRows = 0; $dbKeys = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $dbTotal++;
        $key = trim((string)($row['b2_key'] ?? ''), '/');
        if ((int)($row['is_missing'] ?? 0) === 1) { $missingRows++; continue; }
        $dbActive++;
        if ($key !== '') $dbKeys[$key] = true;
        if ($key !== '' && isset($real[$key])) $found++;
    }
    $orphans = 0;
    foreach ($real as $key => $_) if (!isset($dbKeys[$key])) $orphans++;
    $missingActive = max(0, $dbActive - $found);
    $ok = $dbActive > 0 && count($real) > 0 && $found === $dbActive && $orphans === 0;
    return [
        'ok'=>$ok,
        'db_total'=>$dbTotal,
        'db_active'=>$dbActive,
        'b2_images'=>count($real),
        'db_found'=>$found,
        'missing_db_rows'=>$missingRows,
        'missing_active'=>$missingActive,
        'orphan_b2_images'=>$orphans,
    ];
}
function b2_album_mapping_status(int $albumId, string $prefix): array {
    return b2_album_mapping_status_from_keys($albumId, array_keys(b2_prefix_image_map($prefix, 20)));
}
function album_db_photo_count(int $albumId): int {
    $st = db()->prepare("SELECT COUNT(*) FROM photos WHERE album_id=?");
    $st->execute([$albumId]);
    return (int)$st->fetchColumn();
}
function album_db_photos_found_in_b2(int $albumId, string $prefix): int {
    $prefix = trim($prefix, '/');
    if ($prefix === '') return 0;
    $files = b2_list_prefix($prefix, 20);
    $real = [];
    foreach ($files as $file) {
        $name = trim((string)($file['fileName'] ?? ''), '/');
        if ($name !== '' && !str_contains($name, '/archive-originals/') && !str_contains($name, '/jpg-originals/') && takeout_media_file($name)) $real[$name] = true;
    }
    if (!$real) return 0;
    $st = db()->prepare("SELECT b2_key FROM photos WHERE album_id=?");
    $st->execute([$albumId]);
    $found = 0;
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $key = trim((string)($row['b2_key'] ?? ''), '/');
        if (isset($real[$key])) $found++;
    }
    return $found;
}
function assert_album_b2_target_ready(int $albumId, string $targetPrefix): array {
    $targetPrefix = trim($targetPrefix, '/');
    $dbPhotos = album_db_photo_count($albumId);
    $b2Images = b2_prefix_image_count($targetPrefix);
    if ($dbPhotos > 0 && $b2Images === 0) {
        throw new RuntimeException('Refusing to switch DB source path: target B2 folder has no images.');
    }
    if ($dbPhotos > 0 && $b2Images < $dbPhotos) {
        throw new RuntimeException('Refusing to switch DB source path: target B2 folder has '.$b2Images.' images, DB has '.$dbPhotos.' photos.');
    }
    return ['db_photos'=>$dbPhotos,'b2_images'=>$b2Images];
}
function album_photo_prefix_guess(int $albumId): ?string {
    $st = db()->prepare("SELECT b2_key FROM photos WHERE album_id=? ORDER BY id LIMIT 1000");
    $st->execute([$albumId]);
    $counts = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $key = trim((string)($row['b2_key'] ?? ''), '/');
        if ($key === '') continue;
        $prefix = trim((string)dirname($key), '.\\/');
        if ($prefix === '' || $prefix === '.') continue;
        $counts[$prefix] = ($counts[$prefix] ?? 0) + 1;
    }
    if (!$counts) return null;
    arsort($counts);
    return (string)array_key_first($counts);
}
function album_storage_base_prefix(?string $prefix): string {
    $prefix = trim((string)$prefix, '/');
    foreach (['/originals','/metadata','/previews','/thumbs'] as $suffix) {
        if (str_ends_with($prefix, $suffix)) return substr($prefix, 0, -strlen($suffix));
    }
    return $prefix;
}
function reconcile_album_b2_keys(int $albumId, string $sourcePath): int {
    $sourcePath = trim($sourcePath, '/');
    if ($sourcePath === '') return 0;
    $files = b2_list_prefix($sourcePath, 20);
    $byBase = [];
    foreach ($files as $file) {
        $name = trim((string)($file['fileName'] ?? ''), '/');
        if ($name === '' || !str_starts_with($name, $sourcePath.'/')) continue;
        $base = basename($name);
        if ($base === '') continue;
        if (!isset($byBase[$base]) || str_contains($name, '/originals/')) $byBase[$base] = $name;
    }
    if (!$byBase) return 0;
    $st = db()->prepare("SELECT id,b2_key,original_filename,stored_filename FROM photos WHERE album_id=?");
    $st->execute([$albumId]);
    $upd = db()->prepare("UPDATE photos SET b2_key=?, updated_by=? WHERE id=?");
    $changed = 0;
    while ($p = $st->fetch(PDO::FETCH_ASSOC)) {
        $current = trim((string)($p['b2_key'] ?? ''), '/');
        $candidates = array_values(array_unique(array_filter([
            basename((string)($p['stored_filename'] ?? '')),
            basename((string)($p['original_filename'] ?? '')),
            basename($current),
        ], fn($v) => $v !== '')));
        $target = '';
        foreach ($candidates as $base) {
            if (isset($byBase[$base])) { $target = $byBase[$base]; break; }
        }
        if ($target !== '' && $target !== $current) {
            $upd->execute([$target, $_SESSION['admin']['id'] ?? null, (int)$p['id']]);
            $changed++;
        }
    }
    return $changed;
}
function b2_detect_album_storage_prefix(int $albumId, string $targetPrefix): array {
    $st = db()->prepare("SELECT original_filename FROM photos WHERE album_id=? ORDER BY sort_order ASC,id ASC LIMIT 300");
    $st->execute([$albumId]);
    $names = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $name = trim((string)($row['original_filename'] ?? ''));
        if ($name !== '') $names[$name] = true;
    }
    if (!$names) return ['prefix'=>'','matches'=>0,'targetMatches'=>0];

    $files = b2_list_prefix('', 100);
    $counts = [];
    foreach ($files as $file) {
        $fileName = trim((string)($file['fileName'] ?? ''), '/');
        if ($fileName === '') continue;
        $base = basename($fileName);
        if (!isset($names[$base])) continue;
        $prefix = trim((string)dirname($fileName), '.\\/');
        if ($prefix === '' || $prefix === '.') continue;
        $counts[$prefix] = ($counts[$prefix] ?? 0) + 1;
    }
    if (!$counts) return ['prefix'=>'','matches'=>0,'targetMatches'=>0];

    $target = trim($targetPrefix, '/');
    arsort($counts);
    $best = (string)array_key_first($counts);
    foreach ($counts as $prefix => $count) {
        if ((string)$prefix !== $target) {
            $best = (string)$prefix;
            break;
        }
    }
    return [
        'prefix' => $best,
        'matches' => (int)$counts[$best],
        'targetMatches' => (int)($counts[$target] ?? 0),
    ];
}
function b2_album_title(string $path): string { return basename($path) ?: $path; }
/* B2 Sync eiga: kas ~1 s irasoma i b2_sync_runs.result.progress, o B2
 * puslapis ja skaito per ?action=b2_sync_status (procentai + likes laikas).
 * Etapai ir ju svoriai visai eigai: sarasas, failai, missing suderinimas,
 * albumu uzbaigimas. Svoriai pagal realu truki: "scan only" beveik visa
 * laika skaito sarasa, "update/create" - raso kiekviena faila i DB. */
function b2_sync_progress(int $runId, string $stage, int $done, int $total = 0, bool $force = false, ?array $init = null): void {
    static $state = null, $lastWrite = 0.0, $lastStage = '';
    if ($runId <= 0) return;
    if ($init !== null) { $state = $init + ['started' => time(), 'stages' => []]; $lastWrite = 0.0; $lastStage = ''; }
    if ($state === null) return;
    $state['stage'] = $stage;
    $state['stages'][$stage] = ['done' => $done, 'total' => $total];
    $now = microtime(true);
    if (!$force && $stage === $lastStage && $now - $lastWrite < 1.0) return;
    $lastWrite = $now; $lastStage = $stage;
    try {
        db()->prepare("UPDATE b2_sync_runs SET result=? WHERE id=? AND status='running'")
            ->execute([json_encode(['progress' => $state], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $runId]);
    } catch (Throwable $e) { /* eiga - ne priezastis nutraukti sync */ }
}
function b2_sync_stage_weights(string $mode, bool $markMissing): array {
    $write = $mode !== 'scan only';
    $w = $write ? ['list' => 10, 'files' => 75, 'reconcile' => 5, 'albums' => 10] : ['list' => 80, 'files' => 10, 'reconcile' => 10, 'albums' => 0];
    if (!$markMissing) $w['reconcile'] = 0;
    return $w;
}
// Eigos santrauka JS'ui: procentai visai eigai ir apytikslis likes laikas.
function b2_sync_progress_view(array $run): array {
    $res = json_decode((string)($run['result'] ?? ''), true) ?: [];
    $p = $res['progress'] ?? null;
    $out = ['id' => (int)$run['id'], 'status' => (string)$run['status'], 'mode' => (string)$run['mode']];
    if ($run['status'] !== 'running') {
        $out['pct'] = $run['status'] === 'finished' ? 100 : null;
        $out['result'] = $res;
        return $out;
    }
    if (!is_array($p)) { $out['pct'] = 0; $out['stage'] = 'start'; return $out; }
    $weights = b2_sync_stage_weights((string)($p['mode'] ?? 'scan only'), !empty($p['mark_missing']));
    $sum = array_sum($weights) ?: 1; $acc = 0.0; $reached = false;
    foreach ($weights as $stage => $w) {
        if ($stage === $p['stage']) {
            $st = $p['stages'][$stage] ?? ['done' => 0, 'total' => 0];
            $total = (int)$st['total'];
            if ($stage === 'list' && $total <= 0) $total = (int)($p['est_files'] ?? 0);
            $frac = $total > 0 ? min(0.99, $st['done'] / $total) : 0.0;
            $acc += $w * $frac; $reached = true; break;
        }
        $acc += $w;
    }
    if (!$reached) $acc = 0.0;
    $pct = $acc / $sum;
    $elapsed = max(0, time() - (int)($p['started'] ?? time()));
    $out += [
        'pct' => round($pct * 100, 1),
        'stage' => (string)$p['stage'],
        'stages' => $p['stages'],
        'est_files' => (int)($p['est_files'] ?? 0),
        'elapsed' => $elapsed,
        'eta' => ($pct > 0.02 && $elapsed >= 2) ? (int)round($elapsed * (1 - $pct) / $pct) : null,
    ];
    return $out;
}
function b2_sync_status(): void {
    require_superadmin();
    $after = (int)($_GET['after'] ?? 0);
    $id = (int)($_GET['id'] ?? 0);
    $st = $id > 0
        ? db()->prepare("SELECT id,mode,status,result,started_at,finished_at FROM b2_sync_runs WHERE id=?")
        : db()->prepare("SELECT id,mode,status,result,started_at,finished_at FROM b2_sync_runs WHERE id>? ORDER BY id DESC LIMIT 1");
    $st->execute([$id > 0 ? $id : $after]);
    $run = $st->fetch(PDO::FETCH_ASSOC);
    json_exit($run ? ['ok' => true, 'run' => b2_sync_progress_view($run)] : ['ok' => true, 'run' => null]);
}
/* B2 Sync: skaito B2 ir atnaujina DB eilutes.
 *
 * "Mark missing" (dabar - dvipusis suderinimas) 2026-09-23 sugadino ~220
 * albumu: sarasas buvo nukirptas ties 8000 failu, o viskas uz ribos laikyta
 * dingusiu. Dabar:
 *  - sarasas skaitomas iki 200 000 failu ir zinoma, ar jis PILNAS;
 *  - eilutes, kuriu failas B2 rastas, visada atstatomos (is_missing=0);
 *  - missing zymima TIK esant pilnam sarasui, TIK jei kandidatu nedaug
 *    (saugiklis) ir TIK patikrinus imti tiesiogiai B2;
 *  - suderinimo rezultatas i audit/b2_sync_runs irasomas IS KARTO, kad ir
 *    nutrukus veliau, butu matyti, kas pakeista.
 */
function b2_sync(string $bucket,string $prefix,string $mode,array $opts): array {
    b2_load_config(); $bucket=$bucket ?: (defined('B2_BUCKET')?(string)B2_BUCKET:''); $dry=!empty($opts['dry_run']); $scanRoots=[]; $touchedAlbums=[]; $albums=0; $photos=0; $createdA=0; $createdP=0; $updatedP=0; $consolidatedRows=0;
    $skippedP = 0;   // nepakitusios eilutes (ju optimizacija: jos neperrasomos)
    $write = !$dry && $mode !== 'scan only';
    $existing = [];          // VISI B2 failai (nefiltruoti) - nebuvimo sprendimams
    $complete = true;
    $albumIdByPath = [];     // viena DB paieska albumui, ne kiekvienam failui
    $albumSeen = [];
    $findAlbum = db()->prepare("SELECT id, download_enabled FROM albums WHERE source_path=? OR slug=? LIMIT 1");
    $findPhoto = db()->prepare("SELECT id,file_size,compatibility_b2_key,is_missing FROM photos WHERE album_id=? AND b2_key=?");
    $updPhoto = db()->prepare("UPDATE photos SET b2_bucket=?,b2_key=?,original_filename=?,file_ext=?,file_size=?,is_missing=0,synced_at=NOW() WHERE id=?");
    $insPhoto = db()->prepare("INSERT INTO photos(b2_bucket,b2_key,original_filename,file_ext,file_size,is_downloadable,uuid,album_id,source_type,visibility,synced_at) VALUES(?,?,?,?,?,?,?,?, 'b2_sync','published',NOW())");
    $runId = (int)($opts['run_id'] ?? 0);
    // Sarašo dydžio iverciui - paskutinio baigto paleidimo B2 failu skaicius.
    $estFiles = 0;
    try {
        $lastRes = db()->query("SELECT result FROM b2_sync_runs WHERE status='finished' AND JSON_EXTRACT(result,'$.b2_files') IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
        $estFiles = (int)((json_decode((string)$lastRes, true) ?: [])['b2_files'] ?? 0);
    } catch (Throwable $e) {}
    b2_sync_progress($runId, 'list', 0, 0, true, ['mode' => $mode, 'mark_missing' => !empty($opts['mark_missing']), 'dry_run' => $dry, 'est_files' => $estFiles]);
    // Pirma visi sarasai, tik tada failai - kad etapai eitu is eiles ir eiga
    // butu monotoniska.
    $listed = [];
    $listedCount = 0;
    foreach(b2_allowed_prefixes($prefix) as $root){
        $scanRoots[] = trim((string)$root, '/');
        $base = $listedCount;
        $listed[] = b2_list_prefix($root, 200, false, function (int $n) use ($runId, $base) { b2_sync_progress($runId, 'list', $base + $n); });
        if (b2_last_list_truncated()) $complete = false;
        $listedCount += count(end($listed));
    }
    b2_sync_progress($runId, 'list', $listedCount, $listedCount, true);
    $fileNo = 0;
    foreach($listed as $files){
        foreach($files as $f){
            b2_sync_progress($runId, 'files', ++$fileNo, $listedCount);
            $key=(string)($f['fileName']??'');
            if ($key !== '') $existing[trim($key, '/')] = true;
            if($key===''||str_contains($key, '/archive-originals/')||str_contains($key, '/jpg-originals/')||!takeout_media_file($key)) continue;
            if(function_exists('gallery_is_allowed_prefix')&&!gallery_is_allowed_prefix($key)) continue;
            $albumPath=b2_storage_album_prefix_from_key($key); if($albumPath==='') continue;
            $photos++;
            if (!array_key_exists($albumPath, $albumIdByPath)) {
                $findAlbum->execute([$albumPath, slug($albumPath)]);
                $row = $findAlbum->fetch(PDO::FETCH_ASSOC) ?: null;
                $albumIdByPath[$albumPath] = $row ? [(int)$row['id'], (int)($row['download_enabled'] ?? 1)] : null;
            }
            if ($albumIdByPath[$albumPath] === null && $write) {
                db()->prepare("INSERT INTO albums(uuid,source_type,source_path,slug,title,visibility,created_by,updated_by) VALUES(?,?,?,?,?,'published',?,?)")->execute([uid(),'b2_sync',$albumPath,slug($albumPath),b2_album_title($albumPath),$_SESSION['admin']['id']??null,$_SESSION['admin']['id']??null]);
                $newId=(int)db()->lastInsertId(); place_album_by_date($newId); $createdA++;
                $albumIdByPath[$albumPath] = [$newId, 1];
            }
            if (!isset($albumSeen[$albumPath])) { $albumSeen[$albumPath] = true; $albums++; }
            $albumId = $albumIdByPath[$albumPath][0] ?? 0;
            if($albumId && $write){
                $touchedAlbums[$albumId] = $albumPath;
                $findPhoto->execute([$albumId,$key]); $exRow=$findPhoto->fetch(PDO::FETCH_ASSOC) ?: null; $pid=(int)($exRow['id'] ?? 0);
                if($pid){
                    // HEIC perziura: jei B2 jau guli JPG, susiejam (anksciau tai darydavo tik atskiras albumo veiksmas).
                    $compatKey=null;
                    if(preg_match('~\.(heic|heif)$~i',$key)){
                        $cand=preg_replace('~/originals/([^/]+)\.[^.]+$~','/jpg-originals/$1.jpg',$key);
                        if(is_string($cand)&&$cand!==$key&&isset($existing[trim($cand,'/')])) $compatKey=$cand;
                    }
                    // Nepakitusios eilutes nelieciam: perrasant visas 12 400 buvo ~50 000
                    // uzklausu ir >100 s (Cloudflare 524).
                    $same = $exRow !== null
                        && (int)($exRow['file_size'] ?? -1) === (int)($f['contentLength']??0)
                        && (string)($exRow['compatibility_b2_key'] ?? '') === (string)($compatKey ?? '')
                        && (int)($exRow['is_missing'] ?? 1) === 0;
                    if($same){ $skippedP++; continue; }
                    if($compatKey!==null){
                        db()->prepare("UPDATE photos SET b2_bucket=?,b2_key=?,compatibility_b2_key=?,converted_from_heic=1,preview_status='ready',preview_error=NULL,original_filename=?,file_ext=?,file_size=?,is_missing=0,synced_at=NOW() WHERE id=?")
                            ->execute([$bucket,$key,$compatKey,basename($key),pathinfo($key,PATHINFO_EXTENSION),(int)($f['contentLength']??0),$pid]);
                    } else {
                        $updPhoto->execute([$bucket,$key,basename($key),pathinfo($key,PATHINFO_EXTENSION),(int)($f['contentLength']??0),$pid]);
                    }
                    persist_photo_metadata_json($pid); $updatedP++;
                }
                else { $insPhoto->execute([$bucket,$key,basename($key),pathinfo($key,PATHINFO_EXTENSION),(int)($f['contentLength']??0),(int)($albumIdByPath[$albumPath][1] ?? 1),uid(),$albumId]); $pid=(int)db()->lastInsertId(); persist_photo_metadata_json($pid); $createdP++; }
            }
        }
    }
    $rec = ['restored'=>0,'marked'=>0,'mark_candidates'=>0,'mark_skipped'=>'','rows_checked'=>0,'listing_complete'=>$complete];
    b2_sync_progress($runId, 'files', $listedCount, $listedCount, true);
    if(!empty($opts['mark_missing'])){
        b2_sync_progress($runId, 'reconcile', 0, 1, true);
        // Dry run ir "scan only" tik suskaiciuoja (nieko neraso): "scan only" pagal
        // 2026-09-22 sprendima nieko nekeicia - rasoma tik update/create rezimais.
        $rec = b2_reconcile_missing_flags($scanRoots, $existing, $complete, $dry || $mode === 'scan only');
        // Irasom IS KARTO: jei veliau kas nors nutruks, pedsakas lieka.
        audit('b2_sync', (int)($opts['run_id'] ?? 0) ?: null, 'reconcile_missing', 'B2 missing suderinimas'.(($dry || $mode === 'scan only') ? ' (tik suskaičiuota, niekas nepakeista)' : '').': '.$rec['restored'].' atstatyta, '.$rec['marked'].' pažymėta'.($rec['mark_skipped'] !== '' ? ' — '.$rec['mark_skipped'] : ''), $rec);
        if (!empty($opts['run_id'])) db()->prepare("UPDATE b2_sync_runs SET result=? WHERE id=?")->execute([json_encode(['stage'=>'reconciled'] + $rec, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), (int)$opts['run_id']]);
    }
    if ($write) {
        $albumNo = 0; $albumTotal = count($touchedAlbums);
        foreach ($touchedAlbums as $albumId => $albumPath) {
            b2_sync_progress($runId, 'albums', ++$albumNo, $albumTotal);
            persist_album_metadata_json((int)$albumId);
            $res = consolidate_album_photo_rows_to_prefix((int)$albumId, (string)$albumPath);
            $consolidatedRows += (int)($res['deleted'] ?? 0);
        }
    }
    return ['albums'=>$albums,'photos'=>$photos,'created_albums'=>$createdA,'created_photos'=>$createdP,'updated_photos'=>$updatedP,'unchanged_photos'=>$skippedP,'missing_marked'=>$rec['marked'],'missing_restored'=>$rec['restored'],'missing_candidates'=>$rec['mark_candidates'],'missing_skipped'=>$rec['mark_skipped'],'listing_complete'=>$complete,'b2_files'=>count($existing),'consolidated_duplicates'=>$consolidatedRows,'dry_run'=>$dry];
}
// Ar failas tikrai yra B2 (vienas b2_list_file_names kreipinys).
function b2_file_exists(string $key): bool {
    $key = trim($key, '/');
    if ($key === '') return false;
    $j = b2_api('b2_list_file_names', ['bucketId'=>(string)B2_BUCKET_ID, 'prefix'=>$key, 'startFileName'=>$key, 'maxFileCount'=>1]);
    return trim((string)($j['files'][0]['fileName'] ?? ''), '/') === $key;
}
/* Dvipusis is_missing suderinimas pagal B2 sarasa ($existing = visi failu
 * vardai po $roots). Atstatymas saugus visada - failas matytas. Zymejimas -
 * tik pilnam sarasui, tik su saugikliu ir imties patikra B2. */
function b2_reconcile_missing_flags(array $roots, array $existing, bool $complete, bool $dry = false): array {
    $roots = array_values(array_unique(array_filter(array_map(fn($r) => trim((string)$r, '/'), $roots), fn($v) => $v !== '')));
    $where = "b2_key IS NOT NULL AND b2_key <> ''"; $params = [];
    if ($roots) {
        $where .= ' AND ('.implode(' OR ', array_fill(0, count($roots), 'b2_key LIKE ?')).')';
        foreach ($roots as $r) $params[] = $r.'/%';
    }
    $st = db()->prepare("SELECT id, b2_key, is_missing FROM photos WHERE $where");
    $st->execute($params);
    $toRestore = []; $toMark = []; $rows = 0;
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $rows++;
        $k = trim((string)$r['b2_key'], '/');
        $has = isset($existing[$k]);
        if ($has && (int)$r['is_missing'] === 1) $toRestore[] = (int)$r['id'];
        elseif (!$has && (int)$r['is_missing'] !== 1) $toMark[$k] = (int)$r['id'];
    }
    $admin = $_SESSION['admin']['id'] ?? null;
    foreach (($dry ? [] : array_chunk($toRestore, 500)) as $chunk) {
        db()->prepare("UPDATE photos SET is_missing=0, synced_at=NOW(), updated_by=? WHERE id IN (".implode(',', array_fill(0, count($chunk), '?')).")")->execute([$admin, ...$chunk]);
    }
    $marked = 0; $skipped = '';
    $limit = max(50, (int)ceil($rows * 0.02));
    if ($toMark) {
        if (!$complete) {
            $skipped = 'B2 sąrašas nepilnas — missing nežymėta ('.count($toMark).' kandidatų)';
        } elseif (count($toMark) > $limit) {
            $skipped = 'per daug kandidatų ('.count($toMark).' iš '.$rows.', riba '.$limit.') — tikėtina B2 sąrašo klaida, missing nežymėta. Tikrinkite albumus per „Re-check in B2"';
        } else {
            // Imtis: iki 20 kandidatu tikrinam tiesiogiai. Jei bent vienas
            // yra B2 - sarasu pasitiketi negalima, nieko nezymim.
            $sample = array_slice(array_keys($toMark), 0, 20);
            try {
                $found = array_values(array_filter($sample, 'b2_file_exists'));
            } catch (Throwable $e) {
                $found = null;
                $skipped = 'nepavyko patikrinti imties B2 ('.$e->getMessage().') — missing nežymėta';
            }
            if ($found === null) {
                // $skipped jau nustatytas
            } elseif ($found) {
                $skipped = 'patikrinus B2 rasti '.count($found).' iš '.count($sample).' „trūkstamų" failų (pvz. '.$found[0].') — sąrašas nepatikimas, missing nežymėta';
            } else {
                foreach (($dry ? [] : array_chunk(array_values($toMark), 500)) as $chunk) {
                    db()->prepare("UPDATE photos SET is_missing=1, updated_by=? WHERE id IN (".implode(',', array_fill(0, count($chunk), '?')).")")->execute([$admin, ...$chunk]);
                }
                $marked = count($toMark);
            }
        }
    }
    return ['dry_run'=>$dry, 'restored'=>count($toRestore), 'marked'=>$marked, 'mark_candidates'=>count($toMark), 'mark_skipped'=>$skipped, 'rows_checked'=>$rows, 'listing_complete'=>$complete];
}
function b2_log(): void {
    csrf();
    $bucket=(string)($_POST['bucket']??''); $prefix=trim((string)($_POST['prefix']??''),'/'); $mode=(string)($_POST['mode']??'scan only');
    $opts=['dry_run'=>isset($_POST['dry_run']),'mark_missing'=>isset($_POST['mark_missing'])];
    db()->prepare("INSERT INTO b2_sync_runs(bucket,prefix,mode,options,status,started_at,triggered_by) VALUES(?,?,?,?, 'running',NOW(),?)")->execute([$bucket,$prefix,$mode,json_encode($opts),$_SESSION['admin']['id']??null]);
    $runId=(int)db()->lastInsertId();
    $opts['run_id'] = $runId;
    // Pilnas sarasas + DB darbas didesniam archyvui trunka ilgiau nei
    // numatytas PHP limitas; nutrukus pusiaukeleje b2_sync_runs liktu 'running'.
    @set_time_limit(600);
    ignore_user_abort(true);
    // Fonu (JS) paleistas sync: sesija atlaisvinama, kad eigos uzklausos
    // (?action=b2_sync_status) nelauktu sesijos uzrakto iki sync pabaigos.
    // $_SESSION lieka skaitomas (audit() ima admin id), flash() nebenaudojam.
    $json = wants_json_response();
    if ($json) session_write_close();
    audit('b2_sync',$runId,'start','B2 sync started: '.$mode.($opts['mark_missing'] ? ' + missing suderinimas' : '').($opts['dry_run'] ? ' (dry run)' : ''),['prefix'=>$prefix,'mode'=>$mode,'opts'=>$opts]);
    try{
        $result=b2_sync($bucket,$prefix,$mode,$opts);
        db()->prepare("UPDATE b2_sync_runs SET status='finished', result=?, finished_at=NOW() WHERE id=?")->execute([json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$runId]);
        audit('b2_sync',$runId,'run','B2 sync finished',$result);
        $msg = 'B2 sync finished'.($result['dry_run'] ? ' (DRY RUN — niekas nepakeista)' : '').': '.$result['albums'].' albums, '.$result['photos'].' photos ('.$result['unchanged_photos'].' nepakitę), B2 failų: '.$result['b2_files'].($result['listing_complete'] ? '' : ' (SĄRAŠAS NEPILNAS)').'.';
        if ($opts['mark_missing']) $msg .= ' Missing: '.$result['missing_restored'].' atstatyta, '.$result['missing_marked'].' pažymėta'.($result['missing_skipped'] !== '' ? ' — '.$result['missing_skipped'] : '').'.';
        $msg .= ' '.$result['consolidated_duplicates'].' duplicate rows consolidated.';
        $warn = ($result['missing_skipped'] !== '' || !$result['listing_complete']);
        if ($json) json_exit(['ok'=>true,'run_id'=>$runId,'message'=>$msg,'warn'=>$warn]);
        flash($msg, $warn ? 'err' : 'ok');
    }catch(Throwable $e){
        db()->prepare("UPDATE b2_sync_runs SET status='failed', result=?, finished_at=NOW() WHERE id=?")->execute([json_encode(['error'=>$e->getMessage()]),$runId]);
        audit('b2_sync',$runId,'failed','B2 sync failed: '.$e->getMessage(),['error'=>$e->getMessage()]);
        if ($json) json_exit(['ok'=>false,'run_id'=>$runId,'error'=>'B2 sync failed: '.$e->getMessage()], 500);
        flash('B2 sync failed: '.$e->getMessage(),'err');
    }
    go('?page=b2');
}
function zip_request(): void {
    csrf();
    $album=(int)($_POST['album_id']??0);
    $a=db()->prepare("SELECT id,title,slug,visibility,download_enabled FROM albums WHERE id=? LIMIT 1");
    $a->execute([$album]);
    $albumRow=$a->fetch();
    if(!$albumRow){ flash('Album not found.', 'err'); go('?page=zip'); }
    if((int)($albumRow['download_enabled'] ?? 0) !== 1){
        flash('This album has downloads disabled. Enable downloads before creating ZIP requests.', 'err');
        go('?page=zip');
    }
    $files=(int)db()->query("SELECT COUNT(*) FROM photos WHERE album_id=".$album.(isset($_POST['only_published'])?" AND visibility='published'":''))->fetchColumn();
    $albumSlug = (string)($albumRow['slug'] ?: slug((string)$albumRow['title']));
    $output = safe_b2_name($albumSlug, 220).'.zip';
    db()->prepare("INSERT INTO zip_downloads(type,album_id,variant,only_published,output_filename,files_count,status,created_by) VALUES('album',?,?,?,?,?,'requested',?)")->execute([$album,$_POST['variant']??'originals',isset($_POST['only_published'])?1:0,$output,$files,$_SESSION['admin']['id']??null]); audit('zip',null,'request','ZIP request recorded'); flash('ZIP request recorded.'); go('?page=zip');
}

// /upload/index.php (nariu ikelimo irankis) ikrauna si faila kaip BIBLIOTEKA:
// B2 klientas, EXIF skaitymas, raktu skyrimas ir photos irasai turi gyventi
// vienoje vietoje. Kopijuoti juos i antra faila reisktu kartoti
// simple-foto-admin klaida - dvi atsakos, kurios tyliai issiskiria.
// Bibliotekos rezimu maršrutizatorius neturi veikti: kviecianti puse pati
// nusprendzia, ka rodyti.
if (defined('KLAJUNAS_ADMIN_LIB')) return;

try {
    ensure_schema();
    ensure_overlay_schema();
    if (metadata_sidecar_backfill_needed()) {
        backfill_metadata_sidecars();
    }
    $action=$_GET['action'] ?? '';
    if ($action==='google_login') do_login();
    if ($action==='logout') { session_destroy(); go('?page=login'); }
    if ($action==='export') export_csv((string)($_GET['type'] ?? 'albums'));
    if ($action==='export_overlay') {
        $albumId=(int)($_GET['album_id'] ?? 0);
        if(!empty($_GET['zip'])) export_overlay_zip($albumId);
        export_overlay_json($albumId);
    }
    if ($action==='clear_missing_flags') { need_login(); clear_missing_flags(); }
    if ($action==='save_album') { need_login(); save_album(); }
    if ($action==='move_album_sort') { need_login(); move_album_sort(); }
    if ($action==='move_photo_sort') { need_login(); move_photo_sort(); }
    if ($action==='save_storage_path') { need_login(); save_storage_path(); }
    if ($action==='save_photo') { need_login(); save_photo(); }
    if ($action==='save_photo_order') { need_login(); save_photo_order(); }
    if ($action==='backfill_album_metadata_json') { need_login(); backfill_album_metadata_json(); }
    if ($action==='upload_album_photos') { need_login(); upload_album_photos(); }
    if ($action==='reload_album_photos') { need_login(); reload_album_photos(); }
    if ($action==='photo_heic_blob') { need_login(); photo_heic_blob(); }
    if ($action==='save_recreated_jpg') { need_login(); save_recreated_jpg(); }
    if ($action==='save_recreate_log') { need_login(); save_recreate_log(); }
    if ($action==='cleanup_missing_photo_rows') { need_login(); cleanup_missing_photo_rows(); }
    if ($action==='photo_quick') { need_login(); photo_quick(); }
    if ($action==='delete_album_photo') { need_login(); delete_album_photo(); }
    if ($action==='delete_album') { need_login(); delete_album(); }
    if ($action==='access_export') { need_login(); access_export_csv(); }
    if ($action==='bulk_albums') { need_login(); bulk('albums'); }
    if ($action==='bulk_photos') { need_login(); bulk('photos'); }
    if ($action==='create_tag_inline') { need_login(); create_tag_inline(); }
    if ($action==='delete_tag') { need_login(); delete_tag(); }
    if ($action==='save_admin') { need_login(); save_admin(); }
    if ($action==='save_member') { need_login(); save_member(); }
    if ($action==='toggle_member_active') { need_login(); toggle_member_active(); }
    if ($action==='toggle_member_album') { need_login(); toggle_member_album(); }
    if ($action==='create_member_invite') { need_login(); create_member_invite(); }
    if ($action==='revoke_member_invite') { need_login(); revoke_member_invite(); }
    if ($action==='save_settings') { need_login(); save_settings(); }
    if ($action==='b2_test') { need_login(); b2_test_connection(); }
    if ($action==='save_takeout_stage_profile') { need_login(); save_takeout_stage_profile(); }
    if ($action==='import_preview') { need_login(); import_preview(); }
    if ($action==='import_apply') { need_login(); import_apply(); }
    if ($action==='import_google_overlay') { need_login(); import_google_overlay(); }
    if ($action==='takeout_preview' || $action==='ti_preview') { need_login(); takeout_preview(); }
    if ($action==='ti_payload_preview') { need_login(); takeout_preview_payload(); }
    if ($action==='ti_payload_stage') { need_login(); takeout_stage_payload(); }
    if ($action==='ti_payload_build') { need_login(); takeout_build_payload(); }
    if ($action==='takeout_confirm') { need_login(); takeout_confirm(); }
    if ($action==='takeout_append' || $action==='ti_append') { need_login(); takeout_append(); }
    if ($action==='ti_payload_append') { need_login(); takeout_append_payload(); }
    if ($action==='takeout_cancel') { need_login(); takeout_cancel(); }
    if ($action==='takeout_import') { need_login(); takeout_import(); }
    if ($action==='b2_log') { need_login(); b2_log(); }
    if ($action==='b2_sync_status') { need_login(); b2_sync_status(); }
    if ($action==='db_backup_now') { need_login(); db_backup_now(); }
    if ($action==='create_db_album_from_b2_prefix') { need_login(); create_db_album_from_b2_prefix(); }
    if ($action==='link_b2_prefix_to_album') { need_login(); link_b2_prefix_to_album(); }
    if ($action==='merge_b2_prefix_into_album') { need_login(); merge_b2_prefix_into_album(); }
    if ($action==='inbox_settings') { need_login(); inbox_settings(); }
    if ($action==='inbox_import') { need_login(); inbox_import(); }
    if ($action==='inbox_delete') { need_login(); inbox_delete(); }
    if ($action==='inbox_delete_file') { need_login(); inbox_delete_file(); }
    if ($action==='inbox_thumb') { need_login(); inbox_thumb(); }
    if ($action==='zip_request') { need_login(); zip_request(); }
    if ($action==='save_tag') { need_login(); csrf(); $name=trim((string)($_POST['name'] ?? '')); if ($name!=='') { db()->prepare("INSERT IGNORE INTO tags(name,slug,type) VALUES(?,?,?)")->execute([$name,slug($name),$_POST['type'] ?? 'keyword']); audit('tag',null,'create','Tag created: '.$name); } go('?page=tags'); }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $page=$_GET['page'] ?? (str_ends_with($path, '/login') ? 'login' : 'dashboard');
    if ($page==='login') { login_page(); exit; }
    need_login();
    match($page) {
        'dashboard' => dashboard(),
        'albums' => albums(),
        'album_edit' => album_edit(),
        'photos' => photos(),
        'photo_edit' => photo_edit(),
        'tags' => tags(),
        'members' => members_page(),
        'admins' => admins(),
        'audit' => audit_page(),
        'access' => access_page(),
        'settings' => settings_page(),
        'b2' => b2_page(),
        'inbox' => inbox_page(),
        'takeout' => takeout_page(),
        'takeout_preview_pending' => takeout_preview_pending(),
        'import' => import_page(),
        'zip' => zip_page(),
        default => dashboard(),
    };
} catch (Throwable $e) {
    if (wants_json_response()) {
        json_exit(['ok' => false, 'error' => 'Admin error: '.$e->getMessage()], 500);
    }
    http_response_code(500);
    echo '<h1 class="keep">Admin error</h1><p>'.e($e->getMessage()).'</p>';
}
