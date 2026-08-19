<#
    Sutvarko albumu pavadinimus i pavidala:

        Pavadinimas (Metai)
        Pavadinimas (Vieta, Metai)

    Nuo pirmos versijos skiriasi tuo, kad:
      - nuima VISAS gale prilipusias skliaustu grupes, kuriose tik metai ir/ar
        vieta (pirma versija zvelge tik i pirma grupe ir prilipdydavo antra:
        "... (Spring cup WRE, ilga) (2026) (Trako-Mikyčių miškas, 2026)");
      - suprastas ir tavo pavyzdys "LTeam Rogaining 2022 (Vilnius)" ->
        "LTeam Rogaining (Vilnius, 2022)";
      - abejotinus atideda i sali, o ne spėja.

    ABEJOTINI (nekeiciami, tik isvardijami):
      - pavadinime metai yra kabutese - jie renginio vardo dalis;
      - gale likusiuose skliaustuose minima vieta, kuri nesutampa su
        location_name (pvz. "(Prienai, Jonava)" prie vietos "Prienai") -
        nezinia, ar tai vieta, ar pavadinimo dalis;
      - albumas be datos.
#>
param(
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

$plan = @(); $unclear = @()
foreach ($a in $albums) {
    $id = [int]$a.id
    $t = ([string]$a.title).Trim()
    if ($Skip -contains $id) { continue }

    $year = ''
    if ([string]$a.event_date -match '^(\d{4})-') { $year = $Matches[1] }
    if ($year -eq '') { $unclear += [pscustomobject]@{ Id = $id; Title = $t; Kodel = 'nera datos - metu nera is kur imti' }; continue }

    if ($t -match ('[„"''][^„"'']*\b' + $year + '\b[^„"'']*["“'']')) {
        $unclear += [pscustomobject]@{ Id = $id; Title = $t; Kodel = 'metai kabutese - renginio pavadinimo dalis' }
        continue
    }

    $place = ''
    $p = ([string]$a.location_name).Trim()
    if ($p -ne '') {
        $short = ($p -split ',')[0]
        $short = ($short -replace '\([^()]*\)', '').Trim()
        if ($short -ne '') { $place = $short }
    }

    # 1) nuimam visas gale prilipusias "triuksmo" grupes ir pliką metų uodegą
    $base = $t
    $changed = $true
    while ($changed) {
        $changed = $false
        $m = [regex]::Match($base, '^(.*?)\s*\(([^()]*)\)\s*$')
        if ($m.Success) {
            $head = $m.Groups[1].Value.Trim()
            $inner = $m.Groups[2].Value
            $rest = ($inner -replace ('\b' + $year + '\b'), '')
            if ($place -ne '') { $rest = $rest -replace [regex]::Escape($place), '' }
            $rest = ($rest -replace '[,\s]', '')
            if ($rest -eq '' -and $head -ne '') { $base = $head; $changed = $true; continue }
        }
        if ($base -match ('^(.*?)[\s,–-]*\b' + $year + '\b\s*$')) {
            $h = $Matches[1].Trim(" ,–-")
            if ($h -ne '') { $base = $h; $changed = $true }
        }
    }
    $base = ($base -replace '\s{2,}', ' ').Trim()
    if ($base -eq '') { $unclear += [pscustomobject]@{ Id = $id; Title = $t; Kodel = 'nuemus metus ir vieta nieko nelieka' }; continue }

    # 2) jei gale likusiuose skliaustuose minima vieta - nezinia, ar tai vieta,
    #    ar pavadinimo dalis. Neliesim.
    $mm = [regex]::Match($base, '\(([^()]*)\)\s*$')
    if ($mm.Success -and $place -ne '' -and (Fold $mm.Groups[1].Value) -match (Fold $place)) {
        $unclear += [pscustomobject]@{ Id = $id; Title = $t; Kodel = ('gale skliaustuose minima vieta: (' + $mm.Groups[1].Value + '), o laukas: ' + $p) }
        continue
    }

    # Jei metai jau yra pavadinimo viduryje skliaustuose (o gale eina priesaga,
    # pvz. "- E. Songailos nuotraukos"), antru kartu ju kabinti nereikia.
    if ($base -match ('\([^()]*\b' + $year + '\b[^()]*\)')) { continue }

    # Pavadinime nurodyta viena vieta, o lauke - kita. Kuri teisinga, sprendzia
    # zmogus: "LTeam Rogaining (Vilnius)" prie lauko "Baltasis tiltas".
    $mp = [regex]::Match($base, '\(([^()]*)\)\s*$')
    if ($mp.Success -and $place -ne '' -and (Fold $mp.Groups[1].Value) -ne (Fold $place) -and
        $mp.Groups[1].Value -cmatch '^\s*\p{Lu}' -and $mp.Groups[1].Value -notmatch '[,]') {
        $unclear += [pscustomobject]@{ Id = $id; Title = $t; Kodel = ('pavadinime skliaustuose "' + $mp.Groups[1].Value + '", o vietos laukas: ' + $p) }
        continue
    }

    if ($place -ne '' -and (Fold $base) -match (Fold $place)) { $place = '' }   # vieta jau pavadinime
    $new = if ($place -ne '') { '{0} ({1}, {2})' -f $base, $place, $year } else { '{0} ({1})' -f $base, $year }
    $new = ($new -replace '\s{2,}', ' ').Trim()
    if ($new -ne $t) { $plan += [pscustomobject]@{ Id = $id; Buvo = $t; Tapo = $new } }
}

Write-Output ("`nKEISIM ({0}):" -f $plan.Count)
foreach ($x in $plan) { Write-Output ("   {0,-56} -> {1}" -f $x.Buvo, $x.Tapo) }
Write-Output ("`nABEJOTINI - nekeiciami ({0}):" -f $unclear.Count)
foreach ($x in $unclear) { Write-Output ("   #{0,-4} {1}`n        {2}" -f $x.Id, $x.Title, $x.Kodel) }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$done = 0
foreach ($x in $plan) { $done += Db-Write 'UPDATE albums SET title=?, updated_at=NOW() WHERE id=?' @($x.Tapo, $x.Id) }
Write-Output ("`npakeista: {0}" -f $done)
