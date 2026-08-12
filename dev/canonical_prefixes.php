<?php
/**
 * Apskaiciuoja kanoninius B2 kelius TUO PACIU kodu, kuri naudoja serveris.
 *
 * Perrasyti sia logika PowerShellyje butu rizikinga: vardas transliteruojamas
 * per iconv ASCII//TRANSLIT, ir menkiausias skirtumas reikstu, kad admin
 * laikytu ka tik ikelta aplanka "ne kanoniniu".
 *
 * Naudojimas:
 *   php canonical_prefixes.php <ivestis.json> <isvestis.json>
 *
 * Ivestis: [{"name":..., "title":..., "date":..., "dateEnd":...}, ...]
 * Isvestis: tas pats + "prefix".
 */
declare(strict_types=1);

// --- kopija is admin/index.php (b2_folder_slug + canonical_album_prefix) ---
function b2_folder_slug(string $s): string {
    $s = str_replace('/', '_', $s);
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = trim(preg_replace('/[^a-zA-Z0-9_]+/', '-', $s) ?: '', '-_');
    return $s ?: 'item-'.date('Ymd-His');
}
function canonical_album_prefix(string $title, ?string $eventDate = null, ?string $eventDateEnd = null): string {
    $year = $eventDate ? substr($eventDate, 0, 4) : date('Y');
    $name = b2_folder_slug($title);

    if (!$eventDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
        return 'albums/'.$year.'/'.$name;
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

    return 'albums/'.$year.'/'.$dateKey.'__'.$name;
}
// --- kopijos pabaiga ---

if ($argc < 3) { fwrite(STDERR, "Naudojimas: php canonical_prefixes.php <ivestis.json> <isvestis.json>\n"); exit(1); }
$raw = file_get_contents($argv[1]);
if ($raw === false) { fwrite(STDERR, "Nepavyko perskaityti: {$argv[1]}\n"); exit(1); }
$items = json_decode($raw, true);
if (!is_array($items)) { fwrite(STDERR, "Bloga JSON ivestis\n"); exit(1); }

$out = [];
foreach ($items as $it) {
    $title = (string)($it['title'] ?? '');
    $date  = ($it['date'] ?? '') !== '' ? (string)$it['date'] : null;
    $end   = ($it['dateEnd'] ?? '') !== '' ? (string)$it['dateEnd'] : null;
    $it['prefix'] = canonical_album_prefix($title, $date, $end);
    $out[] = $it;
}
file_put_contents($argv[2], json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
fwrite(STDOUT, "Apskaiciuota kelio: ".count($out)."\n");
