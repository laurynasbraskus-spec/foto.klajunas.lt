<#
    Nustato kiekvienos "nepriskirtos" nuotraukos data ir pasiulo, i kuri esama
    albuma ji tinka.

    Data ieskoma tokia tvarka (nuo patikimiausio):
      1) EXIF DateTimeOriginal vietiniame faile;
      2) laiko zyme failo varde ("20160918_160516_resized.jpg");
      3) Google photoTakenTime, BET tik jei jis skiriasi nuo creationTime -
         kitaip tai tik ikelimo i Google akimirka, ne fotografavimas.

    Pasiulymas: albumas, kurio laikotarpis apima ta data. Jei tokio nera -
    rodomas artimiausias, kad butu matyti, ko trukstma.

    Nieko nekeicia - raso CSV.
#>
param(
    [int[]]$AlbumIds = @(62, 468, 477, 574, 73),
    [string]$Base = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas',
    [string]$Out = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\nepriskirtos_nuotraukos.csv'
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')
Add-Type -AssemblyName System.Drawing

# vietiniai failai pagal varda (ju gali buti keliose vietose - imam pirma)
$localByName = @{}
foreach ($root in @((Join-Path $Base 'sutvarkyta\albums'), (Join-Path $Base 'perkelta i web'))) {
    if (-not (Test-Path $root)) { continue }
    Get-ChildItem $root -Recurse -File -EA SilentlyContinue | ForEach-Object {
        $k = $_.Name.ToLower()
        if (-not $localByName.ContainsKey($k)) { $localByName[$k] = $_.FullName }
    }
}
Write-Output ("vietiniu failu zemelapyje: {0}" -f $localByName.Count)

function Exif-Date([string]$path) {
    try {
        $img = [Drawing.Image]::FromFile($path)
        try {
            $p = $img.GetPropertyItem(36867)
            $s = ([Text.Encoding]::ASCII.GetString($p.Value)).Trim([char]0)
            if ($s -match '^(\d{4}):(\d{2}):(\d{2})') { return ("{0}-{1}-{2}" -f $Matches[1], $Matches[2], $Matches[3]) }
        } finally { $img.Dispose() }
    } catch {}
    return ''
}
function Google-Date([string]$path) {
    $side = $path -replace '\\originals\\', '\metadata\'
    foreach ($cand in @($side + '.supplemental-metadata.json', $side + '.json')) {
        if (-not (Test-Path $cand)) { continue }
        try {
            $j = Get-Content $cand -Raw | ConvertFrom-Json
            $t = [int64]$j.photoTakenTime.timestamp
            $c = [int64]$j.creationTime.timestamp
            if ($t -gt 0 -and [Math]::Abs($t - $c) -gt 60) {
                return ([datetimeoffset]::FromUnixTimeSeconds($t)).ToString('yyyy-MM-dd')
            }
        } catch {}
    }
    return ''
}

$albums = Db-Read "SELECT id,title,event_date,event_date_end FROM albums WHERE event_date IS NOT NULL AND event_date<>'' AND visibility='published'"
$ph = ($AlbumIds | ForEach-Object { '?' }) -join ','
$photos = Db-Read ("SELECT p.id,p.album_id,p.original_filename,a.title FROM photos p JOIN albums a ON a.id=p.album_id WHERE p.album_id IN ($ph) ORDER BY p.album_id,p.original_filename") $AlbumIds
Write-Output ("tikrinama nuotrauku: {0}" -f $photos.Count)

$res = @()
foreach ($p in $photos) {
    $name = [string]$p.original_filename
    $local = ''
    if ($localByName.ContainsKey($name.ToLower())) { $local = $localByName[$name.ToLower()] }
    $date = ''; $src = ''
    if ($local -ne '') { $date = Exif-Date $local; if ($date) { $src = 'EXIF' } }
    if ($date -eq '' -and $name -match '(20\d{2})(\d{2})(\d{2})[_-]?\d{6}') {
        $mo = [int]$Matches[2]; $d = [int]$Matches[3]
        if ($mo -ge 1 -and $mo -le 12 -and $d -ge 1 -and $d -le 31) { $date = ("{0}-{1:d2}-{2:d2}" -f $Matches[1], $mo, $d); $src = 'failo vardas' }
    }
    if ($date -eq '' -and $local -ne '') { $date = Google-Date $local; if ($date) { $src = 'Google (fotografavimas)' } }

    $match = ''; $near = ''
    if ($date -ne '') {
        foreach ($a in $albums) {
            $s = [string]$a.event_date
            $e = [string]$a.event_date_end; if ($e -eq '') { $e = $s }
            if ($date -ge $s -and $date -le $e) { $match = ("#{0} {1}" -f $a.id, $a.title); break }
        }
        if ($match -eq '') {
            $best = $null; $bestD = 99999
            foreach ($a in $albums) {
                $d2 = [Math]::Abs(([datetime]$date - [datetime]$a.event_date).Days)
                if ($d2 -lt $bestD) { $bestD = $d2; $best = $a }
            }
            if ($best) { $near = ("#{0} {1} ({2}, skirtumas {3} d.)" -f $best.id, $best.title, $best.event_date, $bestD) }
        }
    }
    $res += [pscustomobject]@{
        IsAlbumo = [string]$p.title
        Failas = $name
        Data = $date
        DatosSaltinis = $src
        TinkaAlbumui = $match
        Artimiausias = $near
    }
}
$res | Export-Csv $Out -NoTypeInformation -Encoding UTF8
$withDate = @($res | Where-Object { $_.Data -ne '' })
$placed = @($res | Where-Object { $_.TinkaAlbumui -ne '' })
Write-Output ("`ndata nustatyta   : {0} is {1}" -f $withDate.Count, $res.Count)
Write-Output ("rastas albumas   : {0}" -f $placed.Count)
Write-Output ("`nCSV: {0}" -f $Out)
