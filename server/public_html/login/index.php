<?php
declare(strict_types=1);

/**
 * foto.klajunas.lt/login/ — prisijungimas privatiems albumams žiūrėti.
 *
 * Kas mato privataus albumo (visibility='private') nuotraukas, nusprendžia
 * admin > Settings > „Privatūs albumai":
 *   private_album_viewers = 'admins'  - tik adminai (numatyta)
 *                           'members' - adminai + el. paštai iš private_viewer_emails
 *
 * Prisijungus dedamas $_SESSION['viewer'] (el. paštas, vardas, ar adminas).
 * Jis NEDUODA jokios admin'o prieigos - admin'as remiasi tik $_SESSION['admin'].
 * Nario teisė tikrinama kiekvienoje užklausoje (gallery_viewer_can_see_private),
 * todėl pašalintas iš sąrašo netenka prieigos iškart.
 *
 * Google patikra ir sesija - admin biblioteka (KLAJUNAS_ADMIN_LIB), kaip
 * buvusiame /upload: tokenų tikrinimo kodas lieka vienoje vietoje.
 */

define('KLAJUNAS_ADMIN_LIB', 1);
require_once __DIR__ . '/../admin/index.php';
require_once __DIR__ . '/../gallery-security.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

/** Grįžti leidžiama tik į savo galerijos adresus: /, /a/id<N>, /a/<slug>. */
function login_return_path(string $raw): string {
    $raw = trim($raw);
    return preg_match('~^/(a/[A-Za-z0-9_\-]{1,160})?$~', $raw) ? $raw : '/';
}

function login_email_is_admin(string $email): bool {
    if (in_array($email, ALLOWED_EMAILS, true)) return true;
    $q = db()->prepare("SELECT 1 FROM admins WHERE email=? AND is_active=1 LIMIT 1");
    $q->execute([$email]);
    return (bool)$q->fetchColumn();
}

$return = login_return_path((string)($_POST['return'] ?? $_GET['return'] ?? '/'));
$mode = gallery_private_viewers_mode();
$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    csrf();
    try {
        $p = google_payload(trim((string)($_POST['credential'] ?? '')));
        $email = strtolower((string)($p['email'] ?? ''));
        $isAdmin = login_email_is_admin($email);
        $isMember = $mode === 'members' && in_array($email, gallery_private_viewer_emails(), true);
        if (!$isAdmin && !$isMember) {
            audit('viewer', null, 'viewer_login_denied', 'Privačių albumų prisijungimas atmestas: '.$email);
            $_SESSION['login_msg'] = 'Paskyra '.$email.' neturi prieigos prie privačių albumų.';
            go('./?return='.rawurlencode($return));
        }
        // Naujas sesijos ID po prisijungimo - kad iš anksto pakištas ID nieko neduotų.
        session_regenerate_id(true);
        $_SESSION['viewer'] = [
            'email' => $email,
            'name' => trim((string)($p['name'] ?? '')) !== '' ? (string)$p['name'] : $email,
            'is_admin' => $isAdmin,
            'at' => time(),
        ];
        audit('viewer', null, 'viewer_login', 'Prisijungė privatiems albumams: '.$email.($isAdmin ? ' (adminas)' : ''));
        go($return);
    } catch (Throwable $e) {
        $_SESSION['login_msg'] = 'Prisijungti nepavyko: '.$e->getMessage();
        go('./?return='.rawurlencode($return));
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') {
    csrf();
    unset($_SESSION['viewer']);
    go($return);
}

$viewer = is_array($_SESSION['viewer'] ?? null) ? $_SESSION['viewer'] : null;
$msg = (string)($_SESSION['login_msg'] ?? '');
unset($_SESSION['login_msg']);
$who = $mode === 'members' ? 'administratoriai ir klubo nariai, kurių el. paštas įrašytas sąraše' : 'tik administratoriai';
?>
<!doctype html>
<html lang="lt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Prisijungimas · foto.klajunas.lt</title>
<link rel="icon" href="/favicon.ico">
<style>
:root{color-scheme:dark;--bg:#0b0e12;--panel:#151a20;--line:rgba(255,255,255,.1);--text:#e8eef6;--muted:#a7b3c2;--accent:#ffb74a;--err:#ff8a8a}
@media (prefers-color-scheme: light){:root{color-scheme:light;--bg:#f6f5f2;--panel:#fff;--line:rgba(0,0,0,.1);--text:#1b1f24;--muted:#5d6670;--accent:#b8640b;--err:#b3261e}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:16px;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
main{width:100%;max-width:420px;background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:24px}
h1{font-size:20px;margin:0 0 6px}
p{margin:0 0 14px;color:var(--muted)}
.err{color:var(--err)}
.g{display:flex;justify-content:center;margin:18px 0 6px;min-height:44px}
a{color:var(--accent)}
button{font:inherit;padding:8px 16px;border-radius:999px;border:1px solid var(--line);background:transparent;color:var(--text);cursor:pointer}
.row{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
</style>
</head>
<body>
<main>
<h1>&#128274; Privatūs albumai</h1>
<p>Privačių albumų nuotraukas mato <?= e($who) ?>.</p>
<?php if ($msg !== ''): ?><p class="err"><?= e($msg) ?></p><?php endif; ?>
<?php if ($viewer): ?>
  <p>Prisijungęs: <strong><?= e((string)$viewer['email']) ?></strong></p>
  <div class="row">
    <a href="<?= e($return) ?>">&larr; Grįžti į galeriją</a>
    <form method="post" style="margin:0">
      <input type="hidden" name="_token" value="<?= e(token()) ?>">
      <input type="hidden" name="action" value="logout">
      <input type="hidden" name="return" value="<?= e($return) ?>">
      <button>Atsijungti</button>
    </form>
  </div>
<?php else: ?>
  <form method="post" id="loginForm">
    <input type="hidden" name="_token" value="<?= e(token()) ?>">
    <input type="hidden" name="action" value="login">
    <input type="hidden" name="return" value="<?= e($return) ?>">
    <input type="hidden" name="credential" id="credential">
  </form>
  <div class="g"><div id="g_id_onload" data-client_id="<?= e(GOOGLE_CLIENT_ID) ?>" data-callback="onGoogle" data-auto_prompt="false"></div><div class="g_id_signin" data-type="standard" data-shape="pill" data-text="signin_with" data-locale="lt"></div></div>
  <p style="margin:12px 0 0"><a href="<?= e($return) ?>">&larr; Grįžti į galeriją</a></p>
  <script>function onGoogle(r){document.getElementById("credential").value=r.credential;document.getElementById("loginForm").submit();}</script>
  <script src="https://accounts.google.com/gsi/client" async defer></script>
<?php endif; ?>
</main>
</body>
</html>
