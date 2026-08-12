<?php
/**
 * /sitemap.xml (rewrite -> sitemap.php): pradžia + visų publikuotų albumų
 * /a/<slug> adresai (juos aptarnauja og.php su tikru turiniu bot'ams).
 */
declare(strict_types=1);

$dbConfig = __DIR__ . '/../../foto-db-config.php';
if (is_file($dbConfig)) require_once $dbConfig;
if (!defined('GALLERY_DB_HOST')) define('GALLERY_DB_HOST', 'localhost');
if (!defined('GALLERY_DB_NAME')) define('GALLERY_DB_NAME', 'klajunas_foto');
if (!defined('GALLERY_DB_USER')) define('GALLERY_DB_USER', 'klajunas_adm');
if (!defined('GALLERY_DB_PASS')) define('GALLERY_DB_PASS', '');

$base = 'https://foto.klajunas.lt';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600, s-maxage=21600');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
echo "  <url><loc>{$base}/</loc><changefreq>weekly</changefreq></url>\n";

try {
    $pdo = new PDO(
        'mysql:host=' . GALLERY_DB_HOST . ';dbname=' . GALLERY_DB_NAME . ';charset=utf8mb4',
        GALLERY_DB_USER, GALLERY_DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $rows = $pdo->query("SELECT COALESCE(NULLIF(slug,''), source_path) p, updated_at, event_date
                           FROM albums WHERE visibility='published'
                          ORDER BY COALESCE(event_date,'0000-00-00') DESC")->fetchAll();
    foreach ($rows as $r) {
        $p = trim((string)$r['p'], '/');
        if ($p === '') continue;
        $lastmod = $r['updated_at'] ?: $r['event_date'];
        echo '  <url><loc>' . $base . '/a/' . rawurlencode($p) . '</loc>'
           . ($lastmod ? '<lastmod>' . substr((string)$lastmod, 0, 10) . '</lastmod>' : '')
           . "</url>\n";
    }
} catch (Throwable $e) {
    // DB klaida — sitemap lieka tik su pradžia, geriau nei 500.
}
echo '</urlset>' . "\n";
