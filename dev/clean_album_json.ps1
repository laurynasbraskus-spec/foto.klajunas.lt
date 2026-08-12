<#
    Isvalo album.json failus.

    SALINAMA:
      exifDateFiles, metadataDateFiles - jie rode 0 ten, kur EXIF yra, ir butent
        del ju albumu datos nukrito i Google ikelimo laika. Ta pati informacija
        dabar patikimiau laikoma google_metadata_trust.csv.
      namingStandard - vienodas visuose 299 failuose, gryna tara.
      folderDateKey  - isvedamas is aplanko vardo.

    PRIDEDAMA:
      dateConfidence - is kur data ir kiek ja galima tiketi. Butina, kad kitas
        etapas nelaikytu ikelimo datos faktu.

    Datos NEKEICIAMOS - tai atskiras darbas su patvirtinimu.

    Be -Execute tik parodo, ka darytu.
#>
param(
    [string]$Base = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta',
    [switch]$Execute
)
$ErrorActionPreference = 'Stop'
$root = Join-Path $Base 'albums'

$drop = @('exifDateFiles', 'metadataDateFiles', 'namingStandard', 'folderDateKey')

# --- pagalbines lenteles ---
$trust = @{}
$p = Join-Path $Base 'google_metadata_quick.csv'
if (Test-Path $p) { Import-Csv $p | ForEach-Object { $trust[$_.Aplankas] = $_.Verdiktas } }
$exif = @{}
$p = Join-Path $Base 'exif_date_audit.csv'
if (Test-Path $p) { Import-Csv $p | ForEach-Object { $exif[$_.Aplankas] = $_ } }

# Saltiniu hierarchija: tyrimas > EXIF > Google fotografavimo laikas > Google ikelimas
function Get-Confidence([string]$folder, [string]$dateSource) {
    if ($dateSource -match 'dbsportas|klajunas\.lt|archyvas') { return 'tyrimas' }
    $e = $exif[$folder]
    if ($e -and [int]$e.ExifKiek -gt 0 -and $e.Verdiktas -eq 'OK') { return 'exif' }
    $t = $trust[$folder]
    if ($t -like 'IKELIMO*') { return 'google-ikelimas' }
    if ($t -eq 'FOTOGRAFAVIMO LAIKAS') { return 'google-fotografavimas' }
    if ($dateSource -match 'exif') { return 'exif' }
    return 'nezinoma'
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$backup = Join-Path $Base ('_album_json_backup_' + $stamp)

$albums = @(Get-ChildItem $root -Directory -Recurse -Depth 1 -EA SilentlyContinue |
            Where-Object { Test-Path (Join-Path $_.FullName 'album.json') })
Write-Output ("album.json failu: {0}" -f $albums.Count)
Write-Output ("atsargine kopija: {0}" -f $backup)
Write-Output ''

$stats = @{}; $dropped = @{}; $n = 0
foreach ($d in $albums) {
    $src = Join-Path $d.FullName 'album.json'
    $a = Get-Content $src -Raw | ConvertFrom-Json

    $conf = Get-Confidence $d.Name ([string]$a.dateSource)
    $stats[$conf] = 1 + [int]$stats[$conf]

    # naujas objektas: laukai ta pacia tvarka, be salinamu, su dateConfidence po dateSource
    $new = [ordered]@{}
    foreach ($pr in $a.PSObject.Properties) {
        if ($drop -contains $pr.Name) { $dropped[$pr.Name] = 1 + [int]$dropped[$pr.Name]; continue }
        $new[$pr.Name] = $pr.Value
        if ($pr.Name -eq 'dateSource') { $new['dateConfidence'] = $conf }
    }
    if (-not $new.Contains('dateConfidence')) { $new['dateConfidence'] = $conf }

    if ($Execute) {
        $rel = $d.FullName.Substring($root.Length).TrimStart('\')
        $bdir = Join-Path $backup $rel
        New-Item -ItemType Directory -Force -Path $bdir | Out-Null
        Copy-Item $src (Join-Path $bdir 'album.json') -Force
        ([pscustomobject]$new | ConvertTo-Json -Depth 6) | Set-Content $src -Encoding utf8
    }
    $n++
}

Write-Output "--- pasalinti laukai ---"
$dropped.GetEnumerator() | Sort-Object Name | ForEach-Object { Write-Output ("  {0,-22} {1} failuose" -f $_.Key, $_.Value) }
Write-Output ''
Write-Output "--- dateConfidence pasiskirstymas ---"
$stats.GetEnumerator() | Sort-Object { -$_.Value } | ForEach-Object { Write-Output ("  {0,-24} {1}" -f $_.Key, $_.Value) }
Write-Output ''
if ($Execute) { Write-Output ("Atnaujinta failu: {0}" -f $n) }
else { Write-Output "BANDOMASIS REZIMAS - niekas nekeista. Realiam darbui pridek -Execute" }
