<#
    Patikrina, ar CSV eilute tikrai apraso ta pati albuma, i kuri rodo jos
    source_path.

    Kodel to reikia: pavadinimai ir datos gali sutapti (2007-09-12 vyko trys
    renginiai), todel susiejimas pagal juos klysta. Nuotrauku failu vardai
    nemeluoja - jie ateina is fotoaparato.

    Kiekvienam DB albumui paimam jo nuotrauku vardus (be numeracijos priesagos)
    ir ieskom vietinio archyvo aplanko, kurio failai sutampa. Jei rastas
    aplankas turi kita pavadinima nei CSV eilute - tai klaidingas susiejimas.
#>
param(
    [string]$Csv  = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\albums_import.csv',
    [string]$Base = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas'
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

# --- vietinis archyvas: aplankas -> failu vardai ---
$locals = @()
foreach ($root in @((Join-Path $Base 'sutvarkyta\albums'), (Join-Path $Base 'perkelta i web'))) {
    if (-not (Test-Path $root)) { continue }
    Get-ChildItem $root -Directory -Recurse -Depth 1 -EA SilentlyContinue |
        Where-Object { Test-Path (Join-Path $_.FullName 'album.json') } | ForEach-Object {
            $meta = Get-Content (Join-Path $_.FullName 'album.json') -Raw | ConvertFrom-Json
            $orig = Join-Path $_.FullName 'originals'
            $names = @()
            if (Test-Path $orig) { $names = @(Get-ChildItem $orig -File -EA SilentlyContinue | ForEach-Object { $_.Name.ToLower() }) }
            if ($names.Count) {
                $locals += [pscustomobject]@{
                    Folder = $_.Name
                    Title  = [string]$meta.displayName
                    Date   = [string]$meta.date
                    Names  = @{}
                    List   = $names
                }
                foreach ($n in $names) { $locals[-1].Names[$n] = $true }
            }
        }
}
Write-Output ("vietiniu albumu su nuotraukomis: {0}" -f $locals.Count)

# DEMESIO: kintamasis NEGALI vadintis $csv - PowerShellyje jis butu tas pats
# kintamasis kaip parametras [string]$Csv, o tipo apribojimas masyva paverstu
# tekstu. Del to lentele likdavo tuscia ir visi susiejimai atrode nerasti.
$rows = @(Import-Csv $Csv)
# Susiejimas turi buti TOKS PAT kaip apply_albums_to_db.ps1: pirma pagal
# source_path, po to pagal slug'a. Kitaip tikrintume ne ta, kas bus rasoma.
$byPath = @{}; $bySlug = @{}
foreach ($r in $rows) {
    if ($r.source_path) { $byPath[[string]$r.source_path] = $r }
    if ($r.slug) { $bySlug[[string]$r.slug] = $r }
}

$albums = Db-Read 'SELECT id, title, source_path, slug FROM albums ORDER BY id'
$photos = Db-Read 'SELECT album_id, b2_key FROM photos'
$dbNames = @{}
foreach ($p in $photos) {
    $id = [int]$p.album_id
    $n = (([string]$p.b2_key -split '/')[-1] -replace '^\d{4}_', '').ToLower()
    if (-not $dbNames.ContainsKey($id)) { $dbNames[$id] = @() }
    $dbNames[$id] += $n
}

$bad = @(); $okc = 0; $noLocal = 0; $matched = 0; $noRow = 0
foreach ($a in $albums) {
    $id = [int]$a.id
    if (-not $dbNames.ContainsKey($id)) { continue }
    $mine = @($dbNames[$id])
    $best = $null; $bestHit = 0
    foreach ($l in $locals) {
        $hit = 0
        foreach ($n in $mine) { if ($l.Names.ContainsKey($n)) { $hit++ } }
        if ($hit -gt $bestHit) { $bestHit = $hit; $best = $l }
    }
    if (-not $best -or $bestHit -lt [Math]::Max(2, [int]($mine.Count * 0.6))) {
        $noLocal++
        if ($noLocal -le 5) { Write-Output ("   (nerastas vietinis) #{0} {1}  geriausiai sutapo {2}/{3}" -f $id, $a.title, $bestHit, $mine.Count) }
        continue
    }
    $matched++

    $row = $null
    if ($byPath.ContainsKey([string]$a.source_path)) { $row = $byPath[[string]$a.source_path] }
    if (-not $row) { $noRow++; continue }
    # Lyginam su vietiniu pavadinimu: CSV pavadinimai jau normalizuoti (metai
    # gale), todel lyginam tik raidine dali.
    $stripYear = { param($s) ($s -replace '\b(19|20)\d{2}\b', '') -replace '[^\p{L}\p{N}]', '' }
    $t1 = (& $stripYear ([string]$row.title)).ToLower()
    $t2 = (& $stripYear ([string]$best.Title)).ToLower()
    if ($t1 -eq $t2 -or $t1 -like "*$t2*" -or $t2 -like "*$t1*") { $okc++; continue }
    $bad += [pscustomobject]@{
        Id = $id; DbTitle = [string]$a.title; CsvTitle = [string]$row.title
        Tikra = [string]$best.Title; Sutapo = ("{0}/{1}" -f $bestHit, $mine.Count)
        Kelias = [string]$a.source_path
    }
}
Write-Output ("vietinis rastas      : {0}" -f $matched)
Write-Output ("CSV eilutes nerasta  : {0}" -f $noRow)
Write-Output ("susiejimas teisingas : {0}" -f $okc)
Write-Output ("vietinio nerasta     : {0}" -f $noLocal)
Write-Output ("KLAIDINGAS susiejimas: {0}" -f $bad.Count)
Write-Output ''
$bad | ForEach-Object {
    Write-Output ("  #{0}  {1}" -f $_.Id, $_.Kelias)
    Write-Output ("      DB dabar : {0}" -f $_.DbTitle)
    Write-Output ("      CSV siulo: {0}" -f $_.CsvTitle)
    Write-Output ("      is tikro : {0}   (failu sutapo {1})" -f $_.Tikra, $_.Sutapo)
}
