<#
    Suvienodina viesus albumu pavadinimus i viena is dvieju pavidalu:

        Pavadinimas (Metai)
        Pavadinimas (Vieta, Metai)

    Vieta imama is albumo lauko location_name. Jei jos nera - lieka tik metai.

    Ka daro atsargiai:
      - jei pavadinime jau yra skliaustai su vieta ir metais, nieko nekeicia;
      - vietos, kurioje pačioje yra kablelis ("Kaukinės miškas, Nemaitonių
        sen."), i pavadinima deda tik pirma dali, kad neatsirastu trys kableliai
        is eiles - pilna vieta lieka atskirame lauke ir galerijoje rodoma po
        pavadinimu;
      - jei pavadinime jau minima ta pati vieta, jos nekartoja.

    Be -Execute nieko nekeicia.
#>
param(
    [switch]$WithPlace,
    # Albumai, kuriu pavadinimas yra paaiskinantis sakinys, o ne tipinis
    # renginio vardas - vietos i skliaustus jiems kaisti nereikia.
    # #534 "Utenos „Saulės gimnazija" Kulionių miške" - vieta jau pasakyta
    # pacioje frazeje.
    [int[]]$Skip = @(534),
    [switch]$Execute
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

function Fold([string]$s) {
    $s = $s.ToLower()
    $map = @{ 'ą' = 'a'; 'č' = 'c'; 'ę' = 'e'; 'ė' = 'e'; 'į' = 'i'; 'š' = 's'; 'ų' = 'u'; 'ū' = 'u'; 'ž' = 'z' }
    foreach ($k in $map.Keys) { $s = $s -replace $k, $map[$k] }
    return ($s -replace '[^a-z0-9]', '')
}

$albums = Db-Read 'SELECT id,title,event_date,location_name FROM albums ORDER BY event_date,id'
Write-Output ("albumu: {0}" -f $albums.Count)

$plan = @(); $noYear = @()
foreach ($a in $albums) {
    if ($Skip -contains [int]$a.id) { continue }
    $t = ([string]$a.title).Trim()
    $year = ''
    if ([string]$a.event_date -match '^(\d{4})-') { $year = $Matches[1] }
    if ($year -eq '') { $noYear += ("#{0} {1}" -f $a.id, $t); continue }

    # Metai, esantys renginio pavadinime KABUTESE ("OS „Žiema 2022""), yra
    # paties pavadinimo dalis - tokiu atveju skliaustu su metais nereikia.
    if ($t -match ('[„"''][^„"'']*\b' + $year + '\b[^„"'']*["“'']')) { continue }

    # Nuimam esamus skliaustus su metais. DEMESIO: $Matches perrasomas kiekvieno
    # -match, todel pirmo palyginimo grupes issisaugom IS KARTO - kitaip
    # pavadinimo pradzia dingsta (taip "...dienos bėgimas (Prienai, 2017)"
    # buvo virtes tiesiog kableliu).
    $base = $t
    $suffix = ''
    $m = [regex]::Match($t, '^(.*?)\s*\(([^()]*)\)\s*(.*)$')
    if ($m.Success) {
        $head = $m.Groups[1].Value.Trim()
        $inner = $m.Groups[2].Value
        $tail = $m.Groups[3].Value.Trim()
        if ($inner -match ('(^|,\s*)' + $year + '\s*$') -and $head -ne '') {
            $base = $head
            $suffix = $tail
        }
    }
    $base = ($base -replace '\s{2,}', ' ').Trim()
    if ($base -eq '') { continue }

    $place = ''
    if ($WithPlace) {
        $p = ([string]$a.location_name).Trim()
        if ($p -ne '') {
            # vieta su kableliu - imam tik pirma dali; skliaustus vietoje
            # ("Bebrusai (F1)") nuimam, kad neatsirastu skliaustai skliaustuose
            $short = ($p -split ',')[0]
            $short = ($short -replace '\([^()]*\)', '').Trim()
            if ($short -ne '' -and (Fold $base) -notmatch (Fold $short)) { $place = $short }
        }
    }
    $new = if ($place -ne '') { '{0} ({1}, {2})' -f $base, $place, $year } else { '{0} ({1})' -f $base, $year }
    # Priesaga po skliaustu ("- E. Songailos nuotraukos") lieka gale.
    if ($suffix -ne '') { $new = $new + ' ' + $suffix }
    $new = ($new -replace '\s{2,}', ' ').Trim()
    if ($new -ne $t) { $plan += [pscustomobject]@{ Id = [int]$a.id; Buvo = $t; Tapo = $new } }
}

Write-Output ("keisim: {0}" -f $plan.Count)
foreach ($p in $plan) { Write-Output ("   {0,-52} -> {1}" -f $p.Buvo, $p.Tapo) }
if ($noYear.Count) {
    Write-Output ""
    Write-Output ("be datos - metu nera is kur imti ({0}):" -f $noYear.Count)
    $noYear | ForEach-Object { Write-Output ('   ' + $_) }
}
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$done = 0
foreach ($p in $plan) { $done += Db-Write 'UPDATE albums SET title=?, updated_at=NOW() WHERE id=?' @($p.Tapo, $p.Id) }
Write-Output ("`npakeista: {0}" -f $done)
