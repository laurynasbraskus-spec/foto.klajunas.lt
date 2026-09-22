<?php
declare(strict_types=1);
/**
 * Laikina nuotrauku talpykla klubo nariams: https://upload.klajunas.lt/
 *
 * Kam to reikia: po renginio nuotraukos guli nariu telefonuose ir kompiuteriu
 * aplankuose. I admin panele jie priejimo neturi ir neturetu tureti, o el.
 * pastas su Messenger nuotraukas suspaudzia. Sis puslapis prima originalus ir
 * padeda juos i sali, kol adminas nusprendzia, i kuri albuma jie keliauja.
 *
 * Kiekviena siunta ("partija") turi laikina pavadinima ir keliancio varda -
 * be ju admin puseje liktu bevardis failu srautas be jokios versijos, ka su
 * juo daryti.
 *
 * Kur guli failai: B2, prefikse "inbox/" - UZ "albums/" ribu. Tai ne detale:
 * gallery-security.php baltasis sarasas leidzia viesai atiduoti tik "albums/",
 * todel img.php ir b2-gallery.php siu failu nerodys niekam, net zinanciam
 * tiksli rakta. Perkelimas i galerija daromas admin > Uploads.
 *
 * Vartai: bendras klubo kodas (admin > Uploads arba Settings). Tai ne tapatybes
 * patikra, o filtras nuo praeiviu ir botu - puslapis guli viesame domene.
 * Kol kodas nenustatytas, puslapis nieko neprima (fail closed).
 *
 * Failai keliami po viena (XHR is JS), todel mobilaus rysio truktelejimas
 * sugadina viena faila, o ne visa 200 nuotrauku siunta, ir nereikia i
 * post_max_size sutalpinti viso aplanko.
 *
 * DB lenteles (inbox_batches, inbox_files) kuriamos ir cia, ir admin/index.php
 * ensure_inbox_schema() - keiciant viena vieta, keisti abi.
 */

// Sesijos grudinimas privalo ivykti PRIES session_start() - tas pats dalykas
// kaip admin/index.php ir del tos pacios priezasties: shared host numatytieji
// nustatymai palieka cookie be HttpOnly/Secure/SameSite.
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

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Robots-Tag: noindex, nofollow');

const INBOX_ROOT_PREFIX     = 'inbox';
// 95 MB vienam failui. Riba ne is dangaus: domenas eina per Cloudflare, o jo
// free planas nupjauna didesni nei ~100 MB uzklausos kuna - narys tokiu atveju
// matytu ne mano zinute, o svetima 413 puslapi. Antra riba is tos pacios puses:
// B2 ikelimo galas reikalauja POST su Content-Length, tad failas keliauja per
// atminti (duomenu blokas + cURL kopija) prie 512 MB memory_limit. Nuotraukos
// sveria 3-8 MB, trumpi telefono video - iki keliasdesimties.
const INBOX_MAX_FILE_BYTES  = 99614720;
const INBOX_MAX_BATCH_FILES = 600;
const INBOX_GATE_MAX_TRIES  = 10;
const INBOX_GATE_LOCK_SEC   = 300;
const INBOX_ALLOWED_EXT = ['jpg','jpeg','png','webp','gif','heic','heif','mp4','mov','m4v','webm','avi'];

// Konfigai guli UZ webroot ribu (~/domains/), kaip ir visame likusiame
// projekte. Du keliai todel, kad skiriasi medziu gylis: sis puslapis gyvena
// ~/domains/klajunas.lt/public_html/upload (subdomenas upload.klajunas.lt), o
// admin - ~/domains/foto.klajunas.lt/public_html/admin. Abiem atvejais i
// ~/domains/ atveda "../../../".
foreach ([__DIR__.'/../../foto-db-config.php', __DIR__.'/../../../foto-db-config.php'] as $cfg) {
    if (is_file($cfg)) { require_once $cfg; break; }
}
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'klajunas_foto');
if (!defined('DB_USER')) define('DB_USER', 'klajunas_adm');
if (!defined('DB_PASS')) define('DB_PASS', '');

function inbox_db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    return $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
function inbox_setting(string $key, string $default=''): string {
    try {
        $s = inbox_db()->prepare("SELECT value FROM settings WHERE `key`=?");
        $s->execute([$key]);
        $v = $s->fetchColumn();
    } catch (Throwable $e) { return $default; }
    if (!$v) return $default;
    $j = json_decode((string)$v, true);
    return is_scalar($j) ? (string)$j : $default;
}
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function inbox_token(): string { return $_SESSION['_token'] ??= bin2hex(random_bytes(32)); }
function inbox_json(array $payload, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function inbox_fail(string $msg, int $code = 400): never { inbox_json(['ok'=>false, 'error'=>$msg], $code); }
function inbox_csrf(): void {
    if (!hash_equals((string)($_SESSION['_token'] ?? ''), (string)($_POST['_token'] ?? ''))) {
        inbox_fail('Sesija pasibaigė - perkraukite puslapį.', 401);
    }
}

/** Kaip admin b2_folder_slug(): didziosios raides islieka, '/' virsta '_'. */
function inbox_folder_slug(string $s): string {
    $s = str_replace('/', '_', $s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = trim(preg_replace('/[^a-zA-Z0-9_]+/', '-', $s) ?: '', '-_');
    return substr($s !== '' ? $s : 'siunta', 0, 60);
}
/** Kaip admin safe_b2_name(): B2 raktas turi likti ASCII ir be tarpu. */
function inbox_safe_name(string $name, int $max = 180): string {
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $stem = $ext !== '' ? substr($name, 0, -(strlen($ext)+1)) : $name;
    $stem = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $stem) ?: $stem;
    $stem = trim((string)(preg_replace('/[^a-zA-Z0-9._-]+/', '-', $stem) ?: ''), '-._');
    $stem = $stem !== '' ? $stem : 'file';
    $ext  = $ext !== '' ? strtolower((string)(preg_replace('/[^a-zA-Z0-9]+/', '', $ext) ?: $ext)) : '';
    return substr($ext !== '' ? $stem.'.'.$ext : $stem, 0, $max);
}
function inbox_ext(string $name): string { return strtolower((string)pathinfo($name, PATHINFO_EXTENSION)); }
function inbox_is_image(string $name): bool { return (bool)preg_match('~\.(jpe?g|png|webp|gif|heic|heif)$~i', $name); }
function inbox_mime(string $name): string {
    return match (inbox_ext($name)) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
        'mp4', 'm4v' => 'video/mp4',
        'mov'  => 'video/quicktime',
        'webm' => 'video/webm',
        'avi'  => 'video/x-msvideo',
        default => 'application/octet-stream',
    };
}
function inbox_human_bytes(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0; $v = (float)$bytes;
    while ($v >= 1024 && $i < count($units) - 1) { $v /= 1024; $i++; }
    return ($i === 0 ? (string)(int)$v : number_format($v, $v < 10 ? 1 : 0, ',', ' ')).' '.$units[$i];
}

/**
 * Lenteles. Tokios pacios kaip admin/index.php ensure_inbox_schema() - jei
 * keiciasi viena, turi keistis ir kita.
 */
function inbox_ensure_schema(): void {
    inbox_db()->exec("CREATE TABLE IF NOT EXISTS inbox_batches (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, token CHAR(16) NOT NULL UNIQUE, title VARCHAR(191) NOT NULL, uploader_name VARCHAR(191) NOT NULL, note VARCHAR(500) NULL, event_date DATE NULL, b2_prefix VARCHAR(500) NOT NULL, files_count INT UNSIGNED NOT NULL DEFAULT 0, bytes_total BIGINT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(32) NOT NULL DEFAULT 'open', album_id BIGINT UNSIGNED NULL, imported_at TIMESTAMP NULL, ip_address VARCHAR(45) NULL, user_agent VARCHAR(255) NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, INDEX inbox_batches_status_idx(status,created_at)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    inbox_db()->exec("CREATE TABLE IF NOT EXISTS inbox_files (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, batch_id BIGINT UNSIGNED NOT NULL, b2_key VARCHAR(500) NOT NULL, thumb_b2_key VARCHAR(500) NULL, original_filename VARCHAR(255) NOT NULL, mime_type VARCHAR(191) NULL, file_size BIGINT UNSIGNED NOT NULL DEFAULT 0, checksum_sha1 CHAR(40) NULL, width INT UNSIGNED NULL, height INT UNSIGNED NULL, taken_at TIMESTAMP NULL, status VARCHAR(32) NOT NULL DEFAULT 'stored', photo_id BIGINT UNSIGNED NULL, created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP, INDEX inbox_files_batch_idx(batch_id), INDEX inbox_files_name_idx(batch_id,original_filename)) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
}

// ----------------------------------------------------------------- B2 ------
// Atskira, minimali B2 dalis: admin/index.php yra vienas 7000 eiluciu failas,
// is jo nieko neiimsi, o cia reikia tik dvieju veiksmu - autorizacijos ir
// ikelimo. Auth cache failas tas pats, todel abi puses dalinasi zetonu.
function inbox_b2_config(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    foreach (['B2_KEY_ID'=>'b2_api_key_id', 'B2_APP_KEY'=>'b2_api_app_key', 'B2_BUCKET'=>'b2_api_bucket', 'B2_BUCKET_ID'=>'b2_api_bucket_id'] as $const => $key) {
        $v = trim(inbox_setting($key));
        if ($v !== '' && !defined($const)) define($const, $v);
    }
    $lvl = error_reporting(error_reporting() & ~E_WARNING);
    foreach ([__DIR__.'/../../b2-config.php', __DIR__.'/../../../b2-config.php'] as $cfg) {
        if (is_file($cfg)) { require_once $cfg; break; }
    }
    error_reporting($lvl);
    foreach (['B2_KEY_ID', 'B2_APP_KEY', 'B2_BUCKET', 'B2_BUCKET_ID'] as $k) {
        if (!defined($k) || constant($k) === '') throw new RuntimeException('B2 konfigūracija nepilna: '.$k);
    }
}
function inbox_b2_auth(): array {
    inbox_b2_config();
    $cache = defined('B2_AUTH_CACHE_FILE') ? (string)B2_AUTH_CACHE_FILE : sys_get_temp_dir().'/b2_auth_cache.json';
    $auth = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : null;
    if (is_array($auth) && ($auth['expires'] ?? 0) > time()) return $auth;
    $ch = curl_init('https://api.backblazeb2.com/b2api/v2/b2_authorize_account');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>20, CURLOPT_HTTPHEADER=>['Authorization: Basic '.base64_encode(B2_KEY_ID.':'.B2_APP_KEY)]]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$body, true);
    if ($code >= 400 || !is_array($j)) throw new RuntimeException('B2 autorizacija nepavyko');
    $auth = ['apiUrl'=>(string)$j['apiUrl'], 'downloadUrl'=>(string)$j['downloadUrl'], 'authToken'=>(string)$j['authorizationToken'], 'expires'=>time()+23*3600];
    @file_put_contents($cache, json_encode($auth));
    return $auth;
}
function inbox_b2_upload_url(): array {
    $auth = inbox_b2_auth();
    $ch = curl_init($auth['apiUrl'].'/b2api/v2/b2_get_upload_url');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_TIMEOUT=>20, CURLOPT_HTTPHEADER=>['Authorization: '.$auth['authToken'], 'Content-Type: application/json'], CURLOPT_POSTFIELDS=>json_encode(['bucketId'=>(string)B2_BUCKET_ID])]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$body, true);
    if ($code >= 400 || !is_array($j)) throw new RuntimeException('B2 nedavė įkėlimo adreso');
    return $j;
}
/**
 * Ikelimo adresas podelyje. b2_get_upload_url yra atskiras kreipinys i B2, o
 * gautas adresas galioja para - kviesti ji kiekvienam failui reiskia prikabinti
 * po papildoma kelione per Atlanta prie kiekvienos nuotraukos.
 *
 * Podelis - faile, ne sesijoje: sesija ikelimo metu jau uzdaryta (zr.
 * session_write_close komentara), o ir rakinama ji butu bendra visiems
 * lygiagretiems srautams. Raktas - siuntos zetonas + srauto numeris, nes B2
 * NELEIDZIA to paties adreso naudoti dviems ikelimams vienu metu.
 */
function inbox_b2_upload_cache_file(string $batchToken, int $slot): string {
    $dir = defined('B2_AUTH_CACHE_FILE') ? dirname((string)B2_AUTH_CACHE_FILE) : sys_get_temp_dir();
    return rtrim($dir, '/\\').'/b2_up_'.substr(sha1($batchToken.'|'.$slot), 0, 24).'.json';
}
function inbox_b2_upload_slot(string $batchToken, int $slot): array {
    $file = inbox_b2_upload_cache_file($batchToken, $slot);
    if (is_file($file)) {
        $c = json_decode((string)@file_get_contents($file), true);
        if (is_array($c) && ($c['got'] ?? 0) > time() - 12 * 3600 && !empty($c['url']['uploadUrl'])) {
            return $c['url'];
        }
    }
    $url = inbox_b2_upload_url();
    @file_put_contents($file, json_encode(['got' => time(), 'url' => $url]));
    return $url;
}
function inbox_b2_upload_slot_store(string $batchToken, int $slot, array $url): void {
    @file_put_contents(inbox_b2_upload_cache_file($batchToken, $slot), json_encode(['got' => time(), 'url' => $url]));
}

/** 401/503 reiskia pasenusi ar uzimta upload URL - imam nauja ir bandom dar karta. */
function inbox_b2_put(string $data, string $key, string $mime, array &$upload, bool $retry = true): array {
    $ch = curl_init($upload['uploadUrl']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            'Authorization: '.$upload['authorizationToken'],
            'X-Bz-File-Name: '.str_replace('%2F', '/', rawurlencode($key)),
            'Content-Type: '.$mime,
            'X-Bz-Content-Sha1: '.sha1($data),
        ],
        CURLOPT_POSTFIELDS => $data,
    ]);
    $body = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = json_decode((string)$body, true);
    if (($code === 401 || $code === 503 || $code === 408 || $code === 429) && $retry) {
        $upload = inbox_b2_upload_url();
        return inbox_b2_put($data, $key, $mime, $upload, false);
    }
    if ($code >= 400 || !is_array($j)) throw new RuntimeException('B2 atmetė failą ('.$code.')');
    return $j;
}

/**
 * Maza JPEG perziura admin sarasui. Originalo img.php neatiduos (jis uz
 * "albums/" ribu), tad be sios miniatiuros adminas matytu tik failu vardus.
 * Nepavykus (HEIC be Imagick delegato, video, sugades failas) - null, ir
 * partija tiesiog rodoma be paveiksleliu.
 */
function inbox_thumb_jpeg(string $tmp, string $name): ?string {
    if (!inbox_is_image($name)) return null;
    if (extension_loaded('imagick') && class_exists('Imagick')) {
        try {
            $im = new Imagick();
            // jpeg:size leidzia dekoderiui skaityti jau sumazinta - be sito
            // 12 Mpx telefono kadras suvalgo sekunde ir 100+ MB atminties.
            $im->setOption('jpeg:size', '1200x1200');
            $im->readImage($tmp);
            if (method_exists($im, 'autoOrient')) $im->autoOrient();
            $im->thumbnailImage(900, 900, true);
            $im->setImageFormat('jpeg');
            $im->setImageCompressionQuality(78);
            $im->stripImage();
            $blob = $im->getImageBlob();
            $im->clear(); $im->destroy();
            return $blob !== '' ? $blob : null;
        } catch (Throwable $e) { /* krentam i GD */ }
    }
    if (!function_exists('imagecreatefromjpeg')) return null;
    $size = @getimagesize($tmp);
    if (!is_array($size)) return null;
    $src = match ((string)($size['mime'] ?? '')) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png'  => @imagecreatefrompng($tmp),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        'image/gif'  => @imagecreatefromgif($tmp),
        default => false,
    };
    if (!$src) return null;
    $scaled = @imagescale($src, min(900, imagesx($src)));
    if ($scaled) { imagedestroy($src); $src = $scaled; }
    ob_start(); imagejpeg($src, null, 78); $blob = (string)ob_get_clean();
    imagedestroy($src);
    return $blob !== '' ? $blob : null;
}
function inbox_image_facts(string $tmp, string $name): array {
    $out = ['width'=>null, 'height'=>null, 'taken_at'=>null];
    if (!inbox_is_image($name)) return $out;
    $size = @getimagesize($tmp);
    if (is_array($size)) { $out['width'] = $size[0] ?? null; $out['height'] = $size[1] ?? null; }
    if (function_exists('exif_read_data') && preg_match('~\.jpe?g$~i', $name)) {
        $ex = @exif_read_data($tmp, null, true, false);
        if (is_array($ex)) {
            $raw = $ex['EXIF']['DateTimeOriginal'] ?? $ex['EXIF']['DateTimeDigitized'] ?? $ex['IFD0']['DateTime'] ?? null;
            if (is_string($raw) && $raw !== '' && !str_starts_with($raw, '0000')) {
                $t = strtotime((string)(preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', $raw) ?: $raw));
                if ($t !== false) $out['taken_at'] = date('Y-m-d H:i:s', $t);
            }
        }
    }
    return $out;
}

// --------------------------------------------------------------- Vartai ----
function inbox_enabled(): bool { return inbox_setting('inbox_enabled', '1') !== '0'; }
function inbox_code(): string { return trim(inbox_setting('inbox_access_code')); }
function inbox_gate_ok(): bool { return !empty($_SESSION['inbox_ok']) && inbox_code() !== '' && inbox_enabled(); }
function inbox_gate_locked_for(): int {
    $until = (int)($_SESSION['inbox_gate_lock'] ?? 0);
    return $until > time() ? $until - time() : 0;
}
function inbox_require_gate_json(): void {
    if (!inbox_gate_ok()) inbox_fail('Reikia klubo kodo - perkraukite puslapį.', 403);
}

// ------------------------------------------------------------ Marsrutai ----
$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

try {
    if ($action === 'gate') {
        // Paprasta POST forma be JS: kodas -> sesija -> redirect (PRG).
        if (!hash_equals((string)($_SESSION['_token'] ?? ''), (string)($_POST['_token'] ?? ''))) {
            $_SESSION['inbox_gate_msg'] = 'Sesija pasibaigė - bandykite dar kartą.';
            header('Location: ./'); exit;
        }
        if (inbox_gate_locked_for() > 0) {
            $_SESSION['inbox_gate_msg'] = 'Per daug bandymų. Palaukite kelias minutes.';
            header('Location: ./'); exit;
        }
        $given = trim((string)($_POST['code'] ?? ''));
        $code  = inbox_code();
        // Atsakymo laikas neturi isduoti, kiek simboliu sutapo; atsitiktine
        // pauze dar ir letina automatini spejima.
        usleep(random_int(120000, 320000));
        if ($code !== '' && inbox_enabled() && hash_equals($code, $given)) {
            session_regenerate_id(true);
            $_SESSION['inbox_ok'] = true;
            $_SESSION['inbox_tries'] = 0;
            unset($_SESSION['inbox_gate_msg']);
        } else {
            $_SESSION['inbox_tries'] = (int)($_SESSION['inbox_tries'] ?? 0) + 1;
            if ((int)$_SESSION['inbox_tries'] >= INBOX_GATE_MAX_TRIES) {
                $_SESSION['inbox_gate_lock'] = time() + INBOX_GATE_LOCK_SEC;
                $_SESSION['inbox_tries'] = 0;
            }
            $_SESSION['inbox_gate_msg'] = 'Kodas neteisingas.';
        }
        header('Location: ./'); exit;
    }

    if ($action === 'start') {
        inbox_csrf(); inbox_require_gate_json(); inbox_ensure_schema();
        $title    = trim((string)($_POST['title'] ?? ''));
        $uploader = trim((string)($_POST['uploader'] ?? ''));
        $note     = trim((string)($_POST['note'] ?? ''));
        $date     = trim((string)($_POST['event_date'] ?? ''));
        if (mb_strlen($title) < 2) inbox_fail('Pavadinimas per trumpas - bent 2 simboliai.');
        // Vardas nebutinas: dalis nariu ji vis tiek praleisdavo, o siunta atpazysti
        // galima ir is pavadinimo su data. Data atvirksciai - be jos albumo kelio
        // nesudarysi, tad ji privaloma.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) inbox_fail('Renginio data privaloma (YYYY-MM-DD).');
        $title    = mb_substr($title, 0, 150);
        $uploader = mb_substr($uploader, 0, 80);
        $note     = mb_substr($note, 0, 400);

        $token  = bin2hex(random_bytes(6));
        $folder = $date.'-'.inbox_folder_slug($title).'-'.$token;
        $prefix = INBOX_ROOT_PREFIX.'/'.$folder;

        inbox_db()->prepare("INSERT INTO inbox_batches(token,title,uploader_name,note,event_date,b2_prefix,status,ip_address,user_agent) VALUES(?,?,?,?,?,?,'open',?,?)")
            ->execute([
                $token, $title, $uploader, $note !== '' ? $note : null, $date, $prefix,
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
            ]);
        // Partija pririsama prie sesijos: svetimo zetono spejimas neleis
        // prikabinti failu prie kito zmogaus siuntos.
        $_SESSION['inbox_batches'][$token] = (int)inbox_db()->lastInsertId();

        // Zymeklis B2 folderyje: jei DB kada nors dingtu, aplankas pats pasako,
        // kieno jis ir kada atsirado (tas pats principas kaip albumu marker).
        try {
            $upload = inbox_b2_upload_url();
            inbox_b2_put(json_encode([
                'type' => 'foto.klajunas.lt inbox batch',
                'token' => $token,
                'title' => $title,
                'uploader' => $uploader,
                'note' => $note,
                'event_date' => $date,
                'created_at' => date('c'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), $prefix.'/batch.json', 'application/json', $upload);
        } catch (Throwable $e) { /* zymeklis - patogumas, ne salyga */ }

        inbox_json(['ok'=>true, 'token'=>$token, 'prefix'=>$prefix]);
    }

    if ($action === 'file') {
        inbox_csrf(); inbox_require_gate_json();
        $token = (string)($_POST['batch'] ?? '');
        $batchId = (int)($_SESSION['inbox_batches'][$token] ?? 0);
        $slot = max(0, min(7, (int)($_POST['slot'] ?? 0)));
        // PHP sesija guli faile ir uzrakinama visam uzklausimui. Kol vienas
        // failas keliauja i B2, kiti to paties lango uzklausimai stovi eileje -
        // t.y. lygiagretumo nebuvo nei su dviem, nei su keturiais srautais.
        // Viskas, ko is sesijos reikia, jau nuskaityta, tad paleidziam ja.
        // Lenteles kuriamos "start" metu, todel cia DDL irgi nebereikia.
        session_write_close();
        if ($batchId <= 0) inbox_fail('Siunta nerasta - pradėkite iš naujo.', 403);

        $st = inbox_db()->prepare("SELECT * FROM inbox_batches WHERE id=? LIMIT 1");
        $st->execute([$batchId]);
        $batch = $st->fetch();
        if (!$batch) inbox_fail('Siunta nerasta - pradėkite iš naujo.', 404);
        if ((int)$batch['files_count'] >= INBOX_MAX_BATCH_FILES) inbox_fail('Vienoje siuntoje telpa iki '.INBOX_MAX_BATCH_FILES.' failų. Pradėkite naują.');

        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
            $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
            inbox_fail(in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'Failas per didelis serveriui.' : 'Failas neatkeliavo (klaida '.$err.').');
        }
        $original = basename(str_replace('\\', '/', (string)$f['name']));
        $size     = (int)$f['size'];
        $ext      = inbox_ext($original);
        if (!in_array($ext, INBOX_ALLOWED_EXT, true)) inbox_fail('Netinkamas failo tipas (.'.$ext.').');
        if ($size <= 0) inbox_fail('Tuščias failas.');
        if ($size > INBOX_MAX_FILE_BYTES) inbox_fail('Failas per didelis ('.inbox_human_bytes($size).').');

        // Ta pati nuotrauka po pakartotinio bandymo neturi virsti dublikatu.
        $dup = inbox_db()->prepare("SELECT id FROM inbox_files WHERE batch_id=? AND original_filename=? AND file_size=? LIMIT 1");
        $dup->execute([$batchId, $original, $size]);
        if ($dup->fetchColumn()) inbox_json(['ok'=>true, 'duplicate'=>true]);

        $prefix = trim((string)$batch['b2_prefix'], '/');
        $safe   = inbox_safe_name($original);
        $key    = $prefix.'/originals/'.$safe;
        $taken  = inbox_db()->prepare("SELECT id FROM inbox_files WHERE batch_id=? AND b2_key=? LIMIT 1");
        $n = 1;
        while (true) {
            $taken->execute([$batchId, $key]);
            if (!$taken->fetchColumn()) break;
            $safe = str_pad((string)(++$n), 4, '0', STR_PAD_LEFT).'_'.inbox_safe_name($original);
            $key  = $prefix.'/originals/'.$safe;
        }

        $data = file_get_contents((string)$f['tmp_name']);
        if ($data === false) inbox_fail('Nepavyko perskaityti failo serveryje.', 500);
        $mime = inbox_mime($original);
        $upload = inbox_b2_upload_slot($token, $slot);
        inbox_b2_put($data, $key, $mime, $upload);
        $sha1 = sha1($data);
        unset($data);

        $facts = inbox_image_facts((string)$f['tmp_name'], $original);

        // Miniatiura pirmiausia imama is narsykles: ji ta pati kadra sumazina
        // per apie 0,1 s, o serveryje Imagick uz ta pati sumoka apie sekunde
        // procesoriaus. Serverio kelias lieka atsarginis - HEIC ir video, kuriu
        // narsykle nemoka perpiesti.
        $thumb = null;
        $tf = $_FILES['thumb'] ?? null;
        if (is_array($tf) && (int)($tf['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            && is_uploaded_file((string)$tf['tmp_name'])
            && (int)$tf['size'] > 0 && (int)$tf['size'] <= 2097152) {
            $probe = @getimagesize((string)$tf['tmp_name']);
            // Tikrinam pavidala: tai, ka atsiuntė narsykle, keliauja i musu bucket.
            if (is_array($probe) && (string)($probe['mime'] ?? '') === 'image/jpeg') {
                $thumb = @file_get_contents((string)$tf['tmp_name']) ?: null;
            }
        }
        if ($thumb === null) $thumb = inbox_thumb_jpeg((string)$f['tmp_name'], $original);
        $thumbKey = null;
        if ($thumb !== null) {
            $thumbKey = $prefix.'/thumbs/'.$safe.'.jpg';
            try { inbox_b2_put($thumb, $thumbKey, 'image/jpeg', $upload); }
            catch (Throwable $e) { $thumbKey = null; }
        }
        unset($thumb);
        // Takeout pavidalo sidecar failo cia nebera: fotografavimo laikas guli
        // inbox_files.taken_at, ir perkeldamas i albuma ji paima admin. Vienas
        // POST i B2 maziau kiekvienai nuotraukai.
        inbox_b2_upload_slot_store($token, $slot, $upload);

        inbox_db()->prepare("INSERT INTO inbox_files(batch_id,b2_key,thumb_b2_key,original_filename,mime_type,file_size,checksum_sha1,width,height,taken_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
            ->execute([$batchId, $key, $thumbKey, $original, $mime, $size, $sha1, $facts['width'], $facts['height'], $facts['taken_at']]);
        inbox_db()->prepare("UPDATE inbox_batches SET files_count=files_count+1, bytes_total=bytes_total+? WHERE id=?")->execute([$size, $batchId]);

        inbox_json(['ok'=>true, 'key'=>$key, 'thumb'=>(bool)$thumbKey]);
    }

    if ($action === 'done') {
        inbox_csrf(); inbox_require_gate_json(); inbox_ensure_schema();
        $token = (string)($_POST['batch'] ?? '');
        $batchId = (int)($_SESSION['inbox_batches'][$token] ?? 0);
        if ($batchId <= 0) inbox_fail('Siunta nerasta.', 404);
        inbox_db()->prepare("UPDATE inbox_batches SET status='closed' WHERE id=? AND status='open'")->execute([$batchId]);
        $st = inbox_db()->prepare("SELECT title,files_count,bytes_total FROM inbox_batches WHERE id=? LIMIT 1");
        $st->execute([$batchId]);
        $b = $st->fetch() ?: [];
        inbox_json([
            'ok' => true,
            'files' => (int)($b['files_count'] ?? 0),
            'bytes' => inbox_human_bytes((int)($b['bytes_total'] ?? 0)),
            'title' => (string)($b['title'] ?? ''),
        ]);
    }
} catch (Throwable $ex) {
    error_log('[foto.klajunas.lt inbox] '.$ex->getMessage());
    inbox_json(['ok'=>false, 'error'=>'Serverio klaida: '.$ex->getMessage()], 500);
}

// ---------------------------------------------------------------- HTML -----
$gateOk  = inbox_gate_ok();
$codeSet = inbox_code() !== '';
$enabled = inbox_enabled();
$gateMsg = (string)($_SESSION['inbox_gate_msg'] ?? '');
$locked  = inbox_gate_locked_for();
unset($_SESSION['inbox_gate_msg']);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="lt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Įkelti nuotraukas · foto.klajunas.lt</title>
<link rel="icon" href="https://foto.klajunas.lt/favicon.ico">
<style>
:root{color-scheme:dark;--bg:#0b0e12;--panel:#151a20;--panel2:#10151b;--input:#0d1116;--line:rgba(255,255,255,.09);--line-strong:rgba(255,255,255,.22);--text:#e8eef6;--muted:#a7b3c2;--accent:#ffb74a;--accent-soft:rgba(255,183,74,.14);--accent-line:rgba(255,183,74,.4);--accent-ink:#ffc46b;--ok:#4cc38a;--err:#e05b6a;--radius:14px}
*{box-sizing:border-box}
body{margin:0;background:radial-gradient(1200px 800px at 50% -220px,var(--accent-soft),transparent 60%),var(--bg);color:var(--text);font:15px/1.45 system-ui,-apple-system,Segoe UI,sans-serif;padding-bottom:env(safe-area-inset-bottom)}
.wrap{max-width:760px;margin:0 auto;padding:22px 16px 60px}
header.top{display:flex;align-items:center;gap:14px;margin-bottom:18px}
header.top img{height:52px;width:auto;display:block}
h1{font-size:22px;margin:0;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:13px;margin-top:2px}
.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:18px;margin-bottom:14px}
label{display:block;font-size:12px;color:var(--muted);margin:0 0 6px}
input,textarea{width:100%;background:var(--input);border:1px solid var(--line);color:var(--text);border-radius:10px;padding:12px;font:inherit}
input:focus,textarea:focus{outline:none;border-color:var(--accent-line)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.btn,button{border:1px solid var(--line);background:#1c222b;color:var(--text);border-radius:99px;padding:12px 18px;font:inherit;font-weight:650;cursor:pointer}
.btn:hover,button:hover{border-color:var(--line-strong)}
.primary{border-color:var(--accent-line);background:var(--accent-soft);color:var(--accent-ink)}
button[disabled]{opacity:.5;cursor:not-allowed}
.bigtitle{font-size:26px;font-weight:750;background:transparent;border:0;border-bottom:1px solid var(--line-strong);border-radius:0;padding:10px 2px;letter-spacing:-.02em}
.bigtitle::placeholder{color:var(--muted);font-weight:400}
.bigtitle:focus{border-bottom-color:var(--accent)}
.meta{margin-top:16px}
.datefield{position:relative}
.datefield #edate{padding-right:46px}
/* Kalendoriaus mygtukas is tikruju yra gimtasis type=date laukas, uzdetas ant
   ikonos ir padarytas permatomu. Taip kalendoriu atidaro pats naudotojo
   paspaudimas - nereikia showPicker(), kuris be tikro gesto meta klaida. O
   matomas lieka tekstinis YYYY-MM-DD, nes type=date formata pasirenka pati
   narsykle pagal lokale (rode mm/dd/yyyy). */
.calwrap{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:34px;height:34px;display:grid;place-items:center;color:var(--muted)}
.calwrap:hover{color:var(--text)}
.calicon{font-size:17px;line-height:1;pointer-events:none}
.calinput{position:absolute;inset:0;width:100%;height:100%;padding:0;border:0;background:none;opacity:0;cursor:pointer}
.empty{padding:34px 16px;text-align:center}
.empty-title{margin:0 0 16px;color:var(--muted)}
.big{padding:14px 28px;font-size:15.5px}
.linkbtn{background:none;border:0;padding:0;color:var(--accent-ink);font:inherit;font-weight:650;text-decoration:underline;text-underline-offset:3px;cursor:pointer;width:auto}
#drop{margin-top:16px;border:2px dashed var(--line-strong);border-radius:var(--radius);padding:8px;color:var(--muted)}
#drop.over{border-color:var(--accent);background:var(--accent-soft);color:var(--accent-ink)}
#drop b{color:var(--text)}
.hide{display:none!important}
.filelist{list-style:none;margin:14px 0 0;padding:0;max-height:46vh;overflow:auto;border:1px solid var(--line);border-radius:10px}
.filelist li{display:flex;align-items:center;gap:10px;padding:9px 12px;border-bottom:1px solid rgba(255,255,255,.05);font-size:13.5px}
.filelist li:last-child{border-bottom:0}
.fname{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fsize{color:var(--muted);font-size:12px;flex-shrink:0}
.fstate{flex-shrink:0;width:22px;text-align:center}
.fstate.ok{color:var(--ok)}.fstate.err{color:var(--err)}.fstate.run{color:var(--accent)}
.ferr{display:block;color:var(--err);font-size:11.5px;white-space:normal}
.bar{height:8px;border-radius:99px;background:var(--panel2);border:1px solid var(--line);overflow:hidden;margin-top:14px}
.bar>i{display:block;height:100%;width:0;background:var(--accent);transition:width .2s}
.status{margin-top:10px;font-size:13.5px;color:var(--muted)}
.msg{padding:12px 14px;border-radius:10px;border:1px solid var(--line);background:rgba(255,183,74,.08);margin-bottom:14px}
.msg.err{border-color:rgba(224,91,106,.5);background:rgba(224,91,106,.1)}
.muted{color:var(--muted)}
.small{font-size:12.5px}
.done h2{margin:0 0 8px;font-size:19px}
@media(max-width:560px){.wrap{padding:16px 12px 52px}header.top img{height:42px}h1{font-size:19px}.bigtitle{font-size:22px}.btn,.primary{width:100%}}
</style>
</head>
<body>
<div class="wrap">
<header class="top">
  <!-- Logotipas ir favicon guli foto.klajunas.lt - cia, subdomeno saknyje, ju nera. -->
  <img src="https://foto.klajunas.lt/foto-klajunas-logo.png" alt="foto.klajunas.lt">
  <div><h1>Įkelti nuotraukas</h1><div class="sub">Laikina talpykla klubo nariams</div></div>
</header>

<?php if (!$enabled || !$codeSet): ?>
  <div class="msg err">Įkėlimas šiuo metu išjungtas.
    <span class="muted small">Administratorius turi jį įjungti: admin &rarr; Settings &rarr; Inbox.</span></div>
<?php elseif (!$gateOk): ?>
  <?php if ($gateMsg !== ''): ?><div class="msg err"><?= e($gateMsg) ?></div><?php endif; ?>
  <form class="card" method="post" action="./">
    <input type="hidden" name="action" value="gate">
    <input type="hidden" name="_token" value="<?= e(inbox_token()) ?>">
    <label for="code">Klubo kodas</label>
    <input id="code" name="code" type="password" autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" required<?= $locked > 0 ? ' disabled' : '' ?>>
    <p class="muted small" style="margin:10px 0 14px">Kodą rasite klubo grupėje. Jis reikalingas tik tam, kad puslapio nerastų atsitiktiniai praeiviai.</p>
    <button class="primary"<?= $locked > 0 ? ' disabled' : '' ?>><?= $locked > 0 ? 'Palaukite '.(int)ceil($locked / 60).' min.' : 'Toliau' ?></button>
  </form>
<?php else: ?>
  <div class="card" id="formCard">
    <input id="title" class="bigtitle" maxlength="150" placeholder="Pridėkite pavadinimą" required>
    <div class="grid meta">
      <div><label for="edate">Renginio data *</label>
        <div class="datefield">
          <input id="edate" placeholder="YYYY-MM-DD" inputmode="numeric" pattern="\d{4}-\d{2}-\d{2}" maxlength="10" title="Metai-mėnuo-diena, pvz. 2026-09-21" required>
          <span class="calwrap" title="Rinktis iš kalendoriaus"><span class="calicon" aria-hidden="true">&#128197;</span>
            <input id="edatePicker" type="date" class="calinput" aria-label="Rinktis iš kalendoriaus"></span>
        </div>
      </div>
      <div><label for="uploader">Kas įkelia</label><input id="uploader" maxlength="80" placeholder="Vardas Pavardė"></div>
      <div><label for="note">Pastaba adminui</label><input id="note" maxlength="400" placeholder="Nebūtina"></div>
    </div>

    <input id="inFiles" class="hide" type="file" multiple accept="image/*,video/*">
    <input id="inFolder" class="hide" type="file" multiple webkitdirectory directory>

    <div id="drop">
      <div class="empty" id="empty">
        <p class="empty-title">Nuotraukų dar nepasirinkta</p>
        <button type="button" class="primary big" id="btnFiles">Pridėti nuotraukas</button>
        <p class="small" style="margin:16px 0 0">arba <button type="button" class="linkbtn" id="btnFolder">pasirinkti aplanką</button><br>failus galima ir nutempti čia</p>
      </div>
      <ul class="filelist hide" id="list"></ul>
    </div>
    <div class="status" id="picked"></div>

    <div class="bar hide" id="bar"><i></i></div>
    <div class="status hide" id="progressText"></div>

    <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap">
      <button class="primary" id="send" disabled>Įkelti</button>
      <button class="btn hide" id="more" type="button">Pridėti dar</button>
      <button class="btn hide" id="clear" type="button">Išvalyti sąrašą</button>
    </div>
    <p class="muted small" style="margin:12px 0 0">Keliami originalūs failai, po vieną; vienas failas – iki <?= e(inbox_human_bytes(INBOX_MAX_FILE_BYTES)) ?>. Didelė siunta gali užtrukti – palikite puslapį atvirą. Į galeriją nuotraukos patenka tik tada, kai jas peržiūri administratorius.</p>
  </div>

  <div class="card done hide" id="doneCard">
    <h2>Ačiū! Nuotraukos gautos.</h2>
    <p id="doneText" class="muted"></p>
    <button class="primary" id="again" type="button">Įkelti dar vieną siuntą</button>
  </div>

<script>
(function(){
  var TOKEN = <?= json_encode(inbox_token()) ?>;
  var MAX_BYTES = <?= INBOX_MAX_FILE_BYTES ?>;
  var OK_EXT = <?= json_encode(INBOX_ALLOWED_EXT) ?>;
  var S = { files: [], batch: null, running: false, seq: 0 };

  function $(id){ return document.getElementById(id); }
  var list = $('list'), picked = $('picked'), bar = $('bar'), barFill = bar.querySelector('i'), ptext = $('progressText');

  function human(b){
    var u = ['B','KB','MB','GB'], i = 0, v = b;
    while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
    return (i ? v.toFixed(v < 10 ? 1 : 0) : v) + ' ' + u[i];
  }
  function extOf(n){ var m = /\.([a-z0-9]+)$/i.exec(n || ''); return m ? m[1].toLowerCase() : ''; }
  // "1 failų" atrodo kaip neuzbaigta programa, o ne kaip tekstas zmogui.
  function plural(n, one, few, many){
    var n100 = n % 100, n10 = n % 10;
    if (n10 === 1 && n100 !== 11) return one;
    if (n10 === 0 || (n100 >= 11 && n100 <= 19)) return many;
    return few;
  }
  function files(n){ return n + ' ' + plural(n, 'failas', 'failai', 'failų'); }
  function rightType(f){ return OK_EXT.indexOf(extOf(f.name)) >= 0 && f.size > 0; }

  function addFiles(fileList){
    // Praleidimo priezastys skaiciuojamos atskirai: "praleista: 3" nieko
    // nepasako, o del ribos atmestas video atrodo tiesiog dinges.
    var skip = { type: 0, big: 0, dup: 0 };
    for (var i = 0; i < fileList.length; i++) {
      var f = fileList[i];
      if (!rightType(f)) { skip.type++; continue; }
      if (f.size > MAX_BYTES) { skip.big++; continue; }
      // Tas pats vardas ir dydis jau eileje - tikrai tas pats failas.
      var dup = S.files.some(function(x){ return x.file.name === f.name && x.file.size === f.size; });
      if (dup) { skip.dup++; continue; }
      S.files.push({ id: ++S.seq, file: f, state: 'pending', error: '' });
    }
    render(skip);
  }

  function render(skip){
    list.innerHTML = '';
    S.files.forEach(function(item){
      var li = document.createElement('li');
      var icon = { pending: '•', running: '⏳', done: '✓', dup: '✓', error: '✗' }[item.state] || '•';
      var cls  = { done: 'ok', dup: 'ok', error: 'err', running: 'run' }[item.state] || '';
      li.innerHTML = '<span class="fstate ' + cls + '">' + icon + '</span><span class="fname"></span><span class="fsize">' + human(item.file.size) + '</span>';
      var nameCell = li.querySelector('.fname');
      nameCell.textContent = item.file.name + (item.state === 'dup' ? ' (jau buvo)' : '');
      if (item.error) {
        var er = document.createElement('span');
        er.className = 'ferr';
        er.textContent = item.error;
        nameCell.appendChild(er);
      }
      list.appendChild(li);
    });
    // Tuscia busena ir sarasas keiciasi vietomis: kol nieko nepasirinkta,
    // matomas tik vienas mygtukas, o ne tuscias remelis su antrastemis.
    var has = S.files.length > 0;
    list.classList.toggle('hide', !has);
    $('empty').classList.toggle('hide', has);
    $('more').classList.toggle('hide', !has);
    $('clear').classList.toggle('hide', !has);
    var total = S.files.reduce(function(s, x){ return s + x.file.size; }, 0);
    var bits = [];
    if (skip && skip.type) bits.push(skip.type + ' netinkamo tipo');
    if (skip && skip.big) bits.push(skip.big + ' per dideli (riba ' + human(MAX_BYTES) + ')');
    if (skip && skip.dup) bits.push(skip.dup + ' jau sąraše');
    picked.textContent = S.files.length
      ? files(S.files.length) + ', ' + human(total) + (bits.length ? ' · praleista: ' + bits.join(', ') : '')
      : (bits.length ? 'Praleista: ' + bits.join(', ') + '.' : '');
    $('send').disabled = S.running || S.files.length === 0;
  }

  // --- failu pasirinkimas -------------------------------------------------
  // Bruksnelius dedam patys: telefono skaiciu klaviatura ju neturi, o formatas
  // turi likti YYYY-MM-DD - toks pat, kokio lauks serveris ir koks guli DB.
  // Kalendorius: permatomas type=date laukas ant ikonos. Pries atidarant i ji
  // perkeliam jau irasyta reiksme, kad kalendorius atsivertu ties ta diena.
  var cal = $('edatePicker');
  cal.addEventListener('pointerdown', function(){
    cal.value = /^\d{4}-\d{2}-\d{2}$/.test($('edate').value) ? $('edate').value : '';
  });
  cal.addEventListener('change', function(){ if (cal.value) $('edate').value = cal.value; });

  $('edate').addEventListener('input', function(){
    var d = this.value.replace(/[^0-9]/g, '').slice(0, 8);
    var out = d.slice(0, 4);
    if (d.length > 4) out += '-' + d.slice(4, 6);
    if (d.length > 6) out += '-' + d.slice(6, 8);
    this.value = out;
  });

  $('btnFiles').onclick  = function(){ $('inFiles').click(); };
  $('more').onclick      = function(){ $('inFiles').click(); };
  $('btnFolder').onclick = function(){ $('inFolder').click(); };
  ['inFiles','inFolder'].forEach(function(id){
    $(id).addEventListener('change', function(ev){ addFiles(ev.target.files); ev.target.value = ''; });
  });

  var drop = $('drop');
  ['dragenter','dragover'].forEach(function(t){
    drop.addEventListener(t, function(ev){ ev.preventDefault(); drop.classList.add('over'); });
  });
  ['dragleave','drop'].forEach(function(t){
    drop.addEventListener(t, function(ev){ ev.preventDefault(); drop.classList.remove('over'); });
  });
  drop.addEventListener('drop', function(ev){
    var dt = ev.dataTransfer;
    if (!dt) return;
    // Atitempus aplanka, dt.files buna tuscias - reikia eiti per katalogu medi.
    var entries = [];
    if (dt.items && dt.items.length && dt.items[0].webkitGetAsEntry) {
      for (var i = 0; i < dt.items.length; i++) {
        var en = dt.items[i].webkitGetAsEntry();
        if (en) entries.push(en);
      }
    }
    if (entries.length) { walkEntries(entries).then(addFiles); }
    else if (dt.files && dt.files.length) { addFiles(dt.files); }
  });

  function walkEntries(entries){
    var out = [];
    function one(entry){
      return new Promise(function(res){
        if (entry.isFile) {
          entry.file(function(f){ out.push(f); res(); }, function(){ res(); });
          return;
        }
        var reader = entry.createReader(), kids = [];
        // readEntries atiduoda po ~100 irasu, kol grazina tuscia masyva.
        (function readMore(){
          reader.readEntries(function(batch){
            if (!batch.length) { Promise.all(kids.map(one)).then(function(){ res(); }); return; }
            kids = kids.concat(Array.prototype.slice.call(batch));
            readMore();
          }, function(){ res(); });
        })();
      });
    }
    return Promise.all(entries.map(one)).then(function(){ return out; });
  }

  $('clear').onclick = function(){
    if (S.running) return;
    S.files = [];
    render();
  };

  // --- siuntimas ----------------------------------------------------------
  function post(fields, parts, onProgress){
    return new Promise(function(resolve, reject){
      var fd = new FormData();
      Object.keys(fields).forEach(function(k){ fd.append(k, fields[k]); });
      (parts || []).forEach(function(part){ fd.append(part[0], part[1], part[2]); });
      var xhr = new XMLHttpRequest();
      xhr.open('POST', location.pathname, true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.timeout = 300000;
      if (onProgress && xhr.upload) {
        xhr.upload.onprogress = function(ev){ if (ev.lengthComputable) onProgress(ev.loaded / ev.total); };
      }
      xhr.onload = function(){
        var j = null;
        try { j = JSON.parse(xhr.responseText); } catch (e) {}
        if (!j) { reject(new Error('Serveris atsakė netinkamai (' + xhr.status + ')')); return; }
        if (!j.ok) { reject(new Error(j.error || ('Klaida ' + xhr.status))); return; }
        resolve(j);
      };
      xhr.onerror = function(){ reject(new Error('Ryšys nutrūko')); };
      xhr.ontimeout = function(){ reject(new Error('Baigėsi laukimo laikas')); };
      xhr.send(fd);
    });
  }

  // Miniatiura gaminama cia ir keliauja kartu su originalu tame paciame POST'e.
  // Naršykle 12 Mpx kadra sumazina per apie 0,1 s; serveriui tas pats darbas
  // kainuoja apie sekunde procesoriaus ir dar viena kelione i B2.
  function makeThumb(file){
    if (!/\.(jpe?g|png|webp|gif)$/i.test(file.name || '') || typeof createImageBitmap !== 'function') {
      return Promise.resolve(null);
    }
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function(bmp){
      var max = 900, scale = Math.min(1, max / Math.max(bmp.width, bmp.height));
      var w = Math.max(1, Math.round(bmp.width * scale));
      var h = Math.max(1, Math.round(bmp.height * scale));
      var c = document.createElement('canvas');
      c.width = w; c.height = h;
      c.getContext('2d').drawImage(bmp, 0, 0, w, h);
      if (bmp.close) bmp.close();
      return new Promise(function(res){ c.toBlob(res, 'image/jpeg', 0.78); });
    }).catch(function(){ return null; });   // HEIC ir kitos nemokamos - piesia serveris
  }

  function meta(){
    return {
      title: $('title').value.trim(),
      uploader: $('uploader').value.trim(),
      note: $('note').value.trim(),
      event_date: $('edate').value
    };
  }

  $('send').onclick = function(){
    if (S.running) return;
    var m = meta();
    // Klaida turi pasakyti, kas negerai: anksciau dvieju raidziu pavadinimas
    // ("HA") gaudavo "Įrašykite laikiną pavadinimą", nors jis buvo irasytas.
    if (m.title.length < 2) { $('title').focus(); alert('Pavadinimas per trumpas – bent 2 simboliai.'); return; }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(m.event_date)) { $('edate').focus(); alert('Įrašykite renginio datą (YYYY-MM-DD) arba pasirinkite ją kalendoriuje.'); return; }
    try { localStorage.setItem('inbox_uploader', m.uploader); } catch (e) {}
    run(m);
  };

  function run(m){
    S.running = true;
    $('send').disabled = true;
    bar.classList.remove('hide');
    ptext.classList.remove('hide');
    var queue = S.files.filter(function(x){ return x.state === 'pending' || x.state === 'error'; });
    var totalBytes = queue.reduce(function(s, x){ return s + x.file.size; }, 0);
    var sentBytes = 0, doneCount = 0, failCount = 0, idx = 0;
    // Kiek jau isejo is siuo metu siunciamu failu. Be sito juostele su dviem
    // srautais soktu atgal, nes matytu tik paskutinio failo progresa.
    var inflight = {};

    function tick(){
      var extra = 0;
      Object.keys(inflight).forEach(function(k){ extra += inflight[k]; });
      var frac = totalBytes ? Math.min(1, (sentBytes + extra) / totalBytes) : 1;
      barFill.style.width = (frac * 100).toFixed(1) + '%';
      ptext.textContent = 'Įkelta ' + doneCount + ' iš ' + queue.length
        + (failCount ? ' · nepavyko: ' + failCount : '') + ' · ' + Math.round(frac * 100) + '%';
    }

    var start = S.batch
      ? Promise.resolve(S.batch)
      : post(Object.assign({ action: 'start', _token: TOKEN }, m), null).then(function(j){ S.batch = j; return j; });

    start.then(function(){
      function sendOne(item, slot){
        item.state = 'running';
        render();
        var attempt = 0;
        function attemptOnce(){
          attempt++;
          return makeThumb(item.file).then(function(thumb){
            var parts = [['file', item.file, item.file.name]];
            if (thumb) parts.push(['thumb', thumb, 'thumb.jpg']);
            return parts;
          }).then(function(parts){
          return post({ action: 'file', _token: TOKEN, batch: S.batch.token, slot: slot }, parts, function(p){
            inflight[item.id] = p * item.file.size;
            tick();
          }).then(function(j){
            delete inflight[item.id];
            item.state = j.duplicate ? 'dup' : 'done';
            item.error = '';
            sentBytes += item.file.size;
            doneCount++;
          }).catch(function(err){
            delete inflight[item.id];
            // Vienas pakartojimas: mobilus rysys kartais nutrūksta be
            // priezasties, ir antras bandymas paprastai praeina.
            if (attempt < 2) return new Promise(function(r){ setTimeout(r, 1500); }).then(attemptOnce);
            item.state = 'error';
            item.error = err.message || 'Nepavyko';
            sentBytes += item.file.size;
            failCount++;
          });
          });
        }
        return attemptOnce().then(function(){ render(); tick(); });
      }
      function worker(slot){
        if (idx >= queue.length) return Promise.resolve();
        return sendOne(queue[idx++], slot).then(function(){ return worker(slot); });
      }
      // Keturi srautai. Kiekvienas turi savo B2 ikelimo adresa (slot), nes to
      // paties adreso dviem ikelimams vienu metu B2 neleidzia.
      return Promise.all([worker(0), worker(1), worker(2), worker(3)]);
    }).then(function(){
      return post({ action: 'done', _token: TOKEN, batch: S.batch.token }, null);
    }).then(function(j){
      S.running = false;
      if (failCount === 0) {
        $('formCard').classList.add('hide');
        $('doneCard').classList.remove('hide');
        $('doneText').textContent = 'Siunta „' + j.title + '“: ' + files(j.files) + ', ' + j.bytes
          + '. Administratorius juos peržiūrės ir perkels į galeriją.';
      } else {
        ptext.textContent = 'Baigta: ' + doneCount + ' įkelta, ' + failCount
          + ' nepavyko. Spauskite „Įkelti“ dar kartą – bus bandoma tik tai, kas nepavyko.';
        $('send').disabled = false;
      }
    }).catch(function(err){
      S.running = false;
      $('send').disabled = false;
      ptext.textContent = 'Klaida: ' + (err.message || err);
    });
  }

  $('again').onclick = function(){
    S.files = [];
    S.batch = null;
    $('doneCard').classList.add('hide');
    $('formCard').classList.remove('hide');
    bar.classList.add('hide');
    ptext.classList.add('hide');
    barFill.style.width = '0';
    $('title').value = '';
    render();
  };

  window.addEventListener('beforeunload', function(ev){
    if (!S.running) return;
    ev.preventDefault();
    ev.returnValue = '';
  });

  try {
    var saved = localStorage.getItem('inbox_uploader');
    if (saved) $('uploader').value = saved;
  } catch (e) {}
  render();
})();
</script>
<?php endif; ?>

<p class="muted small" style="text-align:center;margin-top:26px"><a href="https://foto.klajunas.lt/" style="color:inherit">← foto.klajunas.lt galerija</a></p>
</div>
</body>
</html>
