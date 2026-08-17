<#
    Pazymi albumus, kuriu data patvirtino nepriklausomas klubo saltinis.

    Iki tol ju data buvo tik Google zyme, todel keliamasis skriptas ju neimdavo
    (jis ima tik 'exif' ir 'tyrimas'). Cia i album.json irasoma, KAS patvirtino,
    ir patikimumas pakeliamas i 'tyrimas' - taip lieka matoma, kuo remtasi.

    Imami tik tie, kuriuos propose_dates.ps1 ivertino kaip "patvirtina".
    Datos NEkeiciamos - keiciamas tik saltinis ir patikimumas.
#>
param(
    [string]$Pasiulymai = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\datu_pasiulymai.csv',
    [string]$Base = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas',
    [switch]$Execute
)
$ErrorActionPreference = 'Stop'

$rows = @(Import-Csv $Pasiulymai | Where-Object { $_.Verdiktas -eq 'patvirtina' })
Write-Output ("patvirtintu albumu: {0}" -f $rows.Count)

# vietinis archyvas: pavadinimas -> aplankas
$byTitle = @{}
foreach ($root in @((Join-Path $Base 'sutvarkyta\albums'), (Join-Path $Base 'perkelta i web'))) {
    if (-not (Test-Path $root)) { continue }
    Get-ChildItem $root -Directory -Recurse -Depth 1 -EA SilentlyContinue |
        Where-Object { Test-Path (Join-Path $_.FullName 'album.json') } | ForEach-Object {
            $meta = Get-Content (Join-Path $_.FullName 'album.json') -Raw | ConvertFrom-Json
            $t = [string]$meta.displayName
            if ($t -ne '' -and -not $byTitle.ContainsKey($t)) { $byTitle[$t] = $_.FullName }
        }
}
Write-Output ("vietiniu albumu zemelapyje: {0}" -f $byTitle.Count)

# CSV pavadinimai jau sutvarkyti (metai gale, be skliaustu), o album.json liko
# originalus - todel tiesioginis sutapimas kartais nepavyksta. Atsarginis
# variantas: lyginam be metu, skyrybos ir raidziu registro.
function Key([string]$s) {
    # Pirma nuimam pilnas datas ir ju nuolauzas ("(2025-03-29)", "-03-29"),
    # tik po to metus. Visu skaitmenu salinti negalima: "31 begimas" ir
    # "35 begimas" yra skirtingi albumai, o be skaitmenu jie sutaptu.
    $s = $s -replace '\(?\b(19|20)\d{2}-\d{2}-\d{2}\b\)?', ''
    $s = $s -replace '(?<=[-\s])\d{2}-\d{2}\b', ''
    $s = ($s -replace '\b(19|20)\d{2}\b', '')
    $s = $s.ToLower()
    $map = @{ 'ą'='a';'č'='c';'ę'='e';'ė'='e';'į'='i';'š'='s';'ų'='u';'ū'='u';'ž'='z' }
    foreach ($k in $map.Keys) { $s = $s -replace $k, $map[$k] }
    return ($s -replace '[^a-z0-9]', '')
}
$byKey = @{}
foreach ($t in $byTitle.Keys) {
    $k = Key $t
    if ($k -ne '' -and -not $byKey.ContainsKey($k)) { $byKey[$k] = $byTitle[$t] }
}

$plan = @(); $missing = @()
foreach ($r in $rows) {
    $t = [string]$r.Pavadinimas
    $path = ''
    if ($byTitle.ContainsKey($t)) { $path = $byTitle[$t] }
    else {
        $k = Key $t
        if ($byKey.ContainsKey($k)) { $path = $byKey[$k] }
    }
    if ($path -eq '') { $missing += $t; continue }
    $plan += [pscustomobject]@{
        Title = $t
        Path  = $path
        Src   = ("{0} {1} ({2})" -f $r.Saltinis, $r.SaltinioData, $r.SaltinioPav)
        Link  = [string]$r.Nuoroda
    }
}
Write-Output ("pazymesim: {0}   vietinio nerasta: {1}" -f $plan.Count, $missing.Count)
$missing | ForEach-Object { Write-Output ('   nerasta: ' + $_) }
foreach ($p in $plan) { Write-Output ("   {0,-42} <- {1}" -f $p.Title, $p.Src) }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$enc = New-Object Text.UTF8Encoding($false)
$done = 0
foreach ($p in $plan) {
    $f = Join-Path $p.Path 'album.json'
    $a = Get-Content $f -Raw | ConvertFrom-Json
    $a | Add-Member -NotePropertyName dateSource -NotePropertyValue $p.Src -Force
    $a | Add-Member -NotePropertyName dateConfidence -NotePropertyValue 'tyrimas' -Force
    if ($p.Link -ne '') { $a | Add-Member -NotePropertyName dateSourceUrl -NotePropertyValue $p.Link -Force }
    [IO.File]::WriteAllText($f, ($a | ConvertTo-Json -Depth 8), $enc)
    $done++
}
Write-Output ("`npazymeta: {0}" -f $done)
