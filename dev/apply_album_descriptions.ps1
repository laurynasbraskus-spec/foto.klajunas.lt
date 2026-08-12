<#
    Iraso i album.json renginio aprasyma ir nuorodas.

    Aprasymai ruosiami rankiniu budu is klajunas.lt irasu (.psd1 failas:
    aplanko vardas -> tekstas), nes prizinių vietų istraukimas is laisvo teksto
    yra kalbos, o ne sablonu darbas.

    Nuorodos deliojamos pagal prioriteta:
      klajunasUrl  - pranesimas klajunas.lt (pagrindinis)
      protokolasUrl- old.klajunas.lt/archyvas protokolas (jei irašo nera arba papildomai)
      dbsportasUrl - dbsportas.lt varzybos
      otherUrl     - kiti saltiniai

    Esamos reiksmes NEPERRASOMOS, nebent nurodyta -Overwrite.
    Be -Execute tik parodo, ka darytu.
#>
param(
    [Parameter(Mandatory = $true)][string]$Descriptions,
    [string]$Base = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta',
    [switch]$Execute,
    [switch]$Overwrite
)
$ErrorActionPreference = 'Stop'
$root = Join-Path $Base 'albums'

if (-not (Test-Path $Descriptions)) { Write-Output "KLAIDA: nerastas $Descriptions"; exit 1 }
$desc = Import-PowerShellDataFile -Path $Descriptions
Write-Output ("aprasymu faile: {0}" -f $desc.Keys.Count)

# nuorodos is ankstesniu etapu
$matches = @{}
$p = Join-Path $Base 'album_post_matches.csv'
if (Test-Path $p) { Import-Csv $p | Where-Object { $_.Nuoroda } | ForEach-Object { $matches[$_.Albumas] = $_.Nuoroda } }
$protocols = @()
$p = Join-Path $Base 'klajunas_archive_index.csv'
if (Test-Path $p) { $protocols = @(Import-Csv $p | Where-Object { $_.Data }) }

$applied = 0; $skipped = @(); $missing = @()
foreach ($name in $desc.Keys) {
    $dir = @(Get-ChildItem $root -Directory -Recurse -Depth 1 -EA SilentlyContinue | Where-Object { $_.Name -eq $name })
    if ($dir.Count -ne 1) { $missing += $name; continue }
    $json = Join-Path $dir[0].FullName 'album.json'
    $a = Get-Content $json -Raw | ConvertFrom-Json

    $changes = @()
    $text = ([string]$desc[$name]).Trim()
    if ($text -and ($Overwrite -or -not [string]$a.albumDescription)) {
        $a | Add-Member -NotePropertyName 'albumDescription' -NotePropertyValue $text -Force
        $changes += 'aprasymas'
    }
    if ($matches.ContainsKey($name) -and ($Overwrite -or -not [string]$a.klajunasUrl)) {
        $a | Add-Member -NotePropertyName 'klajunasUrl' -NotePropertyValue $matches[$name] -Force
        $changes += 'klajunasUrl'
    }
    # protokolas tik tada, kai tos pacios dienos irasas archyve yra vienintelis
    $sameDay = @($protocols | Where-Object { $_.Data -eq [string]$a.date })
    if ($sameDay.Count -eq 1 -and ($Overwrite -or -not [string]$a.protokolasUrl)) {
        $a | Add-Member -NotePropertyName 'protokolasUrl' -NotePropertyValue $sameDay[0].Nuoroda -Force
        $changes += 'protokolasUrl'
    }

    if (-not $changes.Count) { $skipped += $name; continue }
    if ($Execute) { $a | ConvertTo-Json -Depth 6 | Set-Content $json -Encoding utf8 }
    $applied++
    Write-Output ("  {0,-52} {1}" -f $name, ($changes -join ', '))
}

Write-Output ''
Write-Output ("Pakeista albumu   : {0}" -f $applied)
if ($skipped.Count) { Write-Output ("Praleista (jau uzpildyta): {0}" -f $skipped.Count) }
if ($missing.Count) {
    Write-Output ("NERASTA aplanku   : {0}" -f $missing.Count)
    $missing | ForEach-Object { Write-Output ("   " + $_) }
}
if (-not $Execute) { Write-Output ''; Write-Output 'BANDOMASIS REZIMAS - niekas nekeista. Pridek -Execute' }
