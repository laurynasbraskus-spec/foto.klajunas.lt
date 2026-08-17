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
    // Metai grazinami i pavadinimo gala. Auksciau jie nuimami tam, kad butu
    // nesvarbu, kokiu pavidalu buvo ivesti ("Telse 2017", "2017 m. Telse",
    // "Telse (2017-08-12)") - po nuemimo visi virsta vienodu vardu, o cia
    // pridedami vienodai. Taip aplanko varde metai visada matomi ir visada
    // gale, o datos raktas lieka atskirai.
    $name = roman_head_to_arabic($name);
    $name .= '-'.$year;

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
