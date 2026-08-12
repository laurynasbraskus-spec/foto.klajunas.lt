<#
    Sutvarko pavadinimus tu albumu, kuriems nera atitikmens paruostame CSV -
    tai senesni, tiesiai per admin ikelti albumai.

    Taisykle ta pati kaip archyve: data pavadinime nereikalinga, nes ji laikoma
    atskirame lauke. Paliekam metus gale.

    Nuimamas TIK paskutinis skliaustas su data, kad "Žygis (ne slidėmis)"
    isliktu nepaliestas.

    Jei metai skliaustuose nesutampa su renginio data, pavadinimas NEliecamas -
    tai duomenu prestaravimas, kuri turi issprensti zmogus, o ne skriptas.
#>
param([switch]$Execute)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

$albums = Db-Read "SELECT id, title, event_date, location_name FROM albums WHERE title LIKE ? OR title LIKE ? ORDER BY event_date" @('%(20%', '%(19%')
Write-Output ("tikrinama: {0}" -f $albums.Count)

$plan = @(); $conflict = @()
foreach ($a in $albums) {
    $t = [string]$a.title
    # tik PASKUTINIS skliaustas ir tik jei jame yra data
    if ($t -notmatch '^(.*)\(([^()]*(?:19|20)\d{2}[^()]*)\)\s*$') { continue }
    $head = $Matches[1].Trim()
    $inner = $Matches[2]
    if ($inner -notmatch '((?:19|20)\d{2})') { continue }
    $year = $Matches[1]

    $dy = ''
    if ([string]$a.event_date -match '^(\d{4})-') { $dy = $Matches[1] }
    if ($dy -ne '' -and $dy -ne $year) {
        $conflict += ("#{0}  {1}   (renginio data {2})" -f $a.id, $t, $a.event_date)
        continue
    }

    $rest = ($inner -replace '(?:19|20)\d{2}[-/]?\d{0,2}[-/]?\d{0,2}(?:\s*[/-]\s*\d{1,2})?', '').Trim(" ,`t")
    $newLoc = [string]$a.location_name
    if ($rest -ne '' -and $rest -notmatch '(?i)tras|sprint|estafet|distanc' -and $newLoc -eq '') { $newLoc = $rest }
    elseif ($rest -ne '' -and $rest -match '(?i)tras|sprint|estafet|distanc') { $head = ($head + ' ' + $rest).Trim() }

    $new = if ($head -match ('\b' + $year + '\b')) { $head } else { $head + ' ' + $year }
    $new = ($new -replace '\s{2,}', ' ').Trim()
    if ($new -eq $t) { continue }
    $plan += [pscustomobject]@{ Id = [int]$a.id; Buvo = $t; Tapo = $new; Vieta = $newLoc; SenaVieta = [string]$a.location_name }
}

Write-Output ("keisim: {0}" -f $plan.Count)
foreach ($p in $plan) { Write-Output ("   #{0,-4} {1,-56} -> {2}" -f $p.Id, $p.Buvo, $p.Tapo) }
if ($conflict.Count) {
    Write-Output ""
    Write-Output ("NEliesta - metai nesutampa su data ({0}):" -f $conflict.Count)
    $conflict | ForEach-Object { Write-Output ('   ' + $_) }
}
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$done = 0
foreach ($p in $plan) {
    if ($p.Vieta -ne $p.SenaVieta) {
        $done += Db-Write 'UPDATE albums SET title = ?, location_name = ?, updated_at = NOW() WHERE id = ?' @($p.Tapo, $p.Vieta, $p.Id)
    } else {
        $done += Db-Write 'UPDATE albums SET title = ?, updated_at = NOW() WHERE id = ?' @($p.Tapo, $p.Id)
    }
}
Write-Output ("`npakeista: {0}" -f $done)
