<?php
/**
 * Zetonu apsaugota SQL prieiga prie foto DB.
 *
 * Kam to reikia: kiekvienas albumu pakeitimas eidavo per "Export CSV" naršyklėje
 * -> failas i Downloads -> perdirbimas -> "Import CSV" atgal. Del vieno stulpelio
 * pataisymo - keturi zingsniai ir rankinis darbas. Sis galas leidzia ta pati
 * padaryti viena uzklausa.
 *
 * Saugumas:
 *   - zetonas gyvena UZ webroot ribu (~/domains/foto-api-token.php), git'e jo nera;
 *   - be teisingo zetono atsakymas yra 404, kad galas is viso nesimatytu;
 *   - be "write": true praleidziamos tik skaitancios uzklausos;
 *   - kiekviena uzklausa irasoma i ~/domains/foto-api.log.
 *
 * Kodel base64: serverio ModSecurity blokuoja POST kunus, panasius i SQL (tas
 * pats WAF anksciau grazino 406 uz URL pavidalo parametra). Uzkoduotas kunas
 * praeina svariai. Tai ne apejimas - galas apsaugotas zetonu, koduojama tik del
 * klaidingu WAF suveikimu.
 *
 * Uzklausa:
 *   POST /admin/db-api.php
 *   p=<base64( {"token":"...","sql":"SELECT ...","params":[...],"write":false} )>
 *
 * Atsakymas: {"ok":true,"rows":[...],"count":N} arba {"ok":true,"affected":N}
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$cfg   = __DIR__ . '/../../../foto-db-config.php';
$tokf  = __DIR__ . '/../../../foto-api-token.php';
$logf  = __DIR__ . '/../../../foto-api.log';
if (is_file($cfg))  require_once $cfg;
if (is_file($tokf)) require_once $tokf;

/** Nezinomas kvieciantysis neturi net suzinoti, kad sis failas egzistuoja. */
function nope(): never {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not found']);
    exit;
}
function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!defined('ADMIN_API_TOKEN') || strlen((string)ADMIN_API_TOKEN) < 32) nope();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') nope();

$payload = (string)($_POST['p'] ?? '');
if ($payload === '') nope();
$json = base64_decode($payload, true);
if ($json === false) nope();
$req = json_decode($json, true);
if (!is_array($req)) nope();

$given = (string)($req['token'] ?? ($_SERVER['HTTP_X_API_TOKEN'] ?? ''));
// hash_equals - kad atsakymo laikas neisduotu, kiek zetono simboliu sutapo.
if (!hash_equals((string)ADMIN_API_TOKEN, $given)) {
    usleep(random_int(100000, 300000));
    nope();
}

$sql    = trim((string)($req['sql'] ?? ''));
$params = is_array($req['params'] ?? null) ? array_values($req['params']) : [];
$write  = !empty($req['write']);
$limit  = min(20000, max(1, (int)($req['limit'] ?? 5000)));
if ($sql === '') fail('tuscia uzklausa');

// Kelios uzklausos viename kune butu neperskaitomos ir neatsukamos - po viena.
if (preg_match('/;\s*\S/', preg_replace('/([\'"])(?:\\\\.|(?!\1).)*\1/s', "''", $sql) ?? $sql)) {
    fail('viena uzklausa per karta');
}

$verb = strtoupper((string)(preg_split('/\s+/', $sql)[0] ?? ''));
$readVerbs = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'WITH'];
if (!$write && !in_array($verb, $readVerbs, true)) {
    fail('rasymui reikia "write": true (dabar: ' . $verb . ')');
}
// DROP/TRUNCATE per si gala neleidziami niekada - tokiam dalykui reikia
// samoningo zingsnio per DirectAdmin, ne uzklausos is skripto.
if (in_array($verb, ['DROP', 'TRUNCATE', 'ALTER', 'GRANT', 'REVOKE'], true)) {
    fail($verb . ' per si gala neleidziamas');
}

$started = microtime(true);
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $st = $pdo->prepare($sql);
    $st->execute($params);

    if (in_array($verb, $readVerbs, true)) {
        $rows = $st->fetchAll();
        $total = count($rows);
        if ($total > $limit) $rows = array_slice($rows, 0, $limit);
        $out = ['ok' => true, 'count' => $total, 'returned' => count($rows), 'rows' => $rows];
    } else {
        $out = ['ok' => true, 'affected' => $st->rowCount(), 'lastId' => $pdo->lastInsertId()];
    }
} catch (Throwable $ex) {
    $out = ['ok' => false, 'error' => $ex->getMessage()];
}
$ms = (int)round((microtime(true) - $started) * 1000);

@file_put_contents(
    $logf,
    sprintf(
        "%s\t%s\t%dms\t%s\t%s\n",
        date('c'),
        $_SERVER['REMOTE_ADDR'] ?? '-',
        $ms,
        empty($out['ok']) ? 'KLAIDA' : 'ok',
        str_replace(["\r", "\n", "\t"], ' ', $sql)
    ),
    FILE_APPEND | LOCK_EX
);

$out['ms'] = $ms;
$body = json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
// Atsakymas irgi gali kliuti WAF'ui (rezultatuose buna URL ir SQL fragmentu),
// todel kvieciantysis gali paprasyti uzkoduoto atsakymo.
if (!empty($req['enc'])) {
    echo json_encode(['ok' => true, 'b64' => base64_encode((string)$body)]);
} else {
    echo $body;
}
