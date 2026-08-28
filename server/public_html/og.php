<?php
/**
 * Dalinamų nuorodų endpoint'as: /a/<albumo-slug>[?f=N]  (rewrite -> og.php?a=...)
 *
 * Bot'ams (WhatsApp/FB/Google) atiduoda statinį HTML su og:title / og:image /
 * description — nuorodos peržiūra rodo albumo viršelį. Žmones iškart peradresuoja
 * į SPA gilų adresą /?a=<slug>[&f=N]. Jokio JS rendering — pigu ir greita.
 */
declare(strict_types=1);

$dbConfig = __DIR__ . '/../../foto-db-config.php';
if (is_file($dbConfig)) require_once $dbConfig;
if (!defined('GALLERY_DB_HOST')) define('GALLERY_DB_HOST', 'localhost');
if (!defined('GALLERY_DB_NAME')) define('GALLERY_DB_NAME', 'klajunas_foto');
if (!defined('GALLERY_DB_USER')) define('GALLERY_DB_USER', 'klajunas_adm');
if (!defined('GALLERY_DB_PASS')) define('GALLERY_DB_PASS', '');

function og_e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$slug = trim((string)($_GET['a'] ?? ''), "/ \t\n\r\0\x0B");
$f = max(0, (int)($_GET['f'] ?? 0));
$base = 'https://foto.klajunas.lt';

/**
 * Pastovi nuoroda pagal albumo ID: /a/id580 (arba /a/580, ?id=580).
 *
 * Albumų pavadinimai ir slug'ai laikui bėgant tikslinami — kanoninamas kelias,
 * taisoma rašyba, albumas skaidomas. Slug'u paremta nuoroda po tokio pakeitimo
 * miršta, o ID nesikeičia niekada. Todėl dalinimuisi naudojam ID, o dabartinį
 * slug'ą pasiimam iš DB čia pat ir toliau viskas veikia kaip anksčiau.
 */
$albumId = 0;
if (preg_match('~^id[:\-]?(\d+)$~i', $slug, $m)) $albumId = (int)$m[1];
elseif ($slug !== '' && ctype_digit($slug)) $albumId = (int)$slug;
elseif (isset($_GET['id']) && ctype_digit((string)$_GET['id'])) $albumId = (int)$_GET['id'];

$title = 'foto.klajunas.lt — albumai';
$desc = 'OK Klajūnas nuotraukų archyvas — orientavimosi sporto, bėgimo ir žygių renginių albumai.';
$image = '';
$found = false;

if ($slug !== '' || $albumId > 0) {
    try {
        $pdo = new PDO(
            'mysql:host=' . GALLERY_DB_HOST . ';dbname=' . GALLERY_DB_NAME . ';charset=utf8mb4',
            GALLERY_DB_USER, GALLERY_DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        if ($albumId > 0) {
            $q = $pdo->prepare("SELECT id,slug,title,subtitle,description,event_date,location_name,cover_photo_id
                                  FROM albums WHERE visibility='published' AND id=? LIMIT 1");
            $q->execute([$albumId]);
        } else {
            $q = $pdo->prepare("SELECT id,slug,title,subtitle,description,event_date,location_name,cover_photo_id
                                  FROM albums WHERE visibility='published' AND (slug=? OR source_path=?) LIMIT 1");
            $q->execute([$slug, $slug]);
        }
        $al = $q->fetch();
        if ($al) {
            $found = true;
            // Nuo čia dirbam su DABARTINIU slug'u — jis keliauja ir į SPA
            // nuorodą, ir į canonical, tad ID nuoroda visada atveda į teisingą
            // albumą, kad ir kiek kartų jis būtų pervadintas.
            $slug = trim((string)$al['slug'], "/ \t\n\r\0\x0B");
            $title = (string)$al['title'];
            $bits = array_filter([
                (string)($al['event_date'] ?? ''),
                (string)($al['location_name'] ?? ''),
                (string)($al['subtitle'] ?? ''),
            ]);
            $desc = trim((string)($al['description'] ?? '')) ?: (implode(' · ', $bits) ?: $desc);

            // Viršelis: pasirinktas cover, kitaip pirma rodoma nuotrauka. JPG
            // suderinamumo raktas pirmenybėje; OG scraper'iams duodam jpeg, ne webp.
            $coverSql = "SELECT COALESCE(NULLIF(compatibility_b2_key,''), b2_key) k
                           FROM photos
                          WHERE album_id=? AND visibility='published' AND is_missing=0 %s
                          ORDER BY CASE WHEN is_cover_candidate=1 THEN 0 ELSE 1 END,
                                   sort_order ASC, id ASC LIMIT 1";
            $cover = null;
            if ((int)$al['cover_photo_id'] > 0) {
                $cq = $pdo->prepare(sprintf($coverSql, 'AND id=' . (int)$al['cover_photo_id']));
                $cq->execute([(int)$al['id']]);
                $cover = $cq->fetchColumn() ?: null;
            }
            if (!$cover) {
                $cq = $pdo->prepare(sprintf($coverSql, ''));
                $cq->execute([(int)$al['id']]);
                $cover = $cq->fetchColumn() ?: null;
            }
            if ($cover && preg_match('~\.(jpe?g|png|webp)$~i', (string)$cover)) {
                $image = $base . '/img.php?file=' . rawurlencode((string)$cover) . '&w=1400&q=83&fmt=jpeg&v=7';
            }
        }
    } catch (Throwable $e) {
        // DB nepasiekiama — atiduodam bendrą puslapį su redirect'u, nieko nelaužom.
    }
}

// ID nerastas (albumas ištrintas ar paslėptas) — vedam į albumų sąrašą, o ne
// į "?a=id580", kur SPA parodytų tuščią langą.
if ($albumId > 0 && !$found) $slug = '';

// Skaičiuojam TIK dabar: iki šios vietos $slug galėjo būti ID pavidalo ("id580"),
// o po paieškos jis jau yra tikrasis albumo slug'as.
$spaUrl = $slug !== '' ? $base . '/?a=' . rawurlencode($slug) . ($f >= 1 ? '&f=' . $f : '') : $base . '/';
$canonical = $slug !== '' ? $base . '/a/' . rawurlencode($slug) : $base . '/';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=600, s-maxage=3600');
?><!DOCTYPE html>
<html lang="lt">
<head>
<meta charset="UTF-8">
<title><?= og_e($title) ?></title>
<meta name="description" content="<?= og_e($desc) ?>">
<link rel="canonical" href="<?= og_e($canonical) ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="foto.klajunas.lt">
<meta property="og:title" content="<?= og_e($title) ?>">
<meta property="og:description" content="<?= og_e($desc) ?>">
<meta property="og:url" content="<?= og_e($canonical) ?>">
<?php if ($image !== ''): ?>
<meta property="og:image" content="<?= og_e($image) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="<?= og_e($image) ?>">
<?php endif; ?>
<meta http-equiv="refresh" content="0;url=<?= og_e($spaUrl) ?>">
<script>location.replace(<?= json_encode($spaUrl, JSON_UNESCAPED_SLASHES) ?>);</script>
</head>
<body>
<p><a href="<?= og_e($spaUrl) ?>"><?= og_e($title) ?> — atidaryti galeriją</a></p>
</body>
</html>
