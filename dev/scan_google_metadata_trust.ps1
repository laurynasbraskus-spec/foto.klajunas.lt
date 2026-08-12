<#
    Ivertina, kiek Google Takeout metadata galima pasitiketi kaip fotografavimo data.

    Google i sidecar raso du laukus:
      photoTakenTime - fotografavimo laikas, jei Google ji rado nuotraukoje
      creationTime   - ikelimo i Google Photos laikas

    Kai Google nuotraukoje datos NERANDA, i abu laukus irasoma ikelimo akimirka.
    Tada photoTakenTime nera fotografavimo data - tai tik virsutine riba.

    Pozymiai, kad albumo Google datos yra ikelimo zyma:
      1) photoTakenTime ir creationTime sutampa (skirtumas kelios sekundes)
      2) visos albumo nuotraukos turi ta pati photoTakenTime

    Nieko nekeicia - tik raso ataskaita.
#>
param(
    [string]$Root   = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\albums',
    [string]$OutCsv = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\google_metadata_trust.csv'
)
$ErrorActionPreference = 'Continue'
Add-Type -AssemblyName System.Drawing

function Get-ExifDay([string]$path) {
    try {
        $img = [System.Drawing.Image]::FromFile($path)
        try {
            $p = $img.GetPropertyItem(36867)
            $v = [Text.Encoding]::ASCII.GetString($p.Value).Trim([char]0).Trim()
            if ($v -match '^(\d{4}):(\d{2}):(\d{2})') { return ($Matches[1] + '-' + $Matches[2] + '-' + $Matches[3]) }
            return $null
        } catch { return $null } finally { $img.Dispose() }
    } catch { return $null }
}

$rows = @()
$albums = @(Get-ChildItem $Root -Directory -Recurse -Depth 1 -EA SilentlyContinue |
            Where-Object { Test-Path (Join-Path $_.FullName 'metadata') })
$i = 0
foreach ($d in $albums) {
    $i++
    Write-Progress -Activity 'Google metadata' -Status $d.Name -PercentComplete ([int](100 * $i / $albums.Count))

    $taken = @(); $sameAsCreated = 0; $total = 0
    foreach ($m in (Get-ChildItem (Join-Path $d.FullName 'metadata') -File -Filter '*.supplemental-metadata.json' -EA SilentlyContinue)) {
        $j = $null
        try { $j = Get-Content $m.FullName -Raw | ConvertFrom-Json } catch { continue }
        if (-not $j.photoTakenTime.timestamp) { continue }
        $total++
        $pt = [int64]$j.photoTakenTime.timestamp
        $taken += [DateTimeOffset]::FromUnixTimeSeconds($pt).UtcDateTime
        if ($j.creationTime.timestamp) {
            if ([Math]::Abs([int64]$j.creationTime.timestamp - $pt) -le 60) { $sameAsCreated++ }
        }
    }
    if ($total -eq 0) { continue }

    $distinctDays    = @($taken | ForEach-Object { $_.ToString('yyyy-MM-dd') } | Sort-Object -Unique)
    $distinctStamps  = @($taken | ForEach-Object { $_.ToString('yyyy-MM-dd HH:mm:ss') } | Sort-Object -Unique)
    $allSameStamp    = ($distinctStamps.Count -eq 1 -and $total -gt 1)
    $mostlyUpload    = ($sameAsCreated / $total) -ge 0.9

    # EXIF palyginimui - imam is nuotrauku
    $exifDays = @{}
    foreach ($f in (Get-ChildItem (Join-Path $d.FullName 'originals') -File -EA SilentlyContinue)) {
        if ($f.Extension -notmatch '(?i)\.(jpe?g)$') { continue }
        $e = Get-ExifDay $f.FullName
        if ($e) { $exifDays[$e] = 1 + [int]$exifDays[$e] }
    }
    $exifList = @($exifDays.Keys | Sort-Object)

    $verdict =
        if ($allSameStamp -and $mostlyUpload) { 'IKELIMO ZYMA (visos vienoda)' }
        elseif ($mostlyUpload)                { 'IKELIMO ZYMA (taken=created)' }
        elseif ($distinctStamps.Count -gt 1)  { 'FOTOGRAFAVIMO LAIKAS' }
        else                                  { 'neaisku' }

    $conflict = ''
    if ($exifList.Count -and $distinctDays.Count) {
        $overlap = @($exifList | Where-Object { $distinctDays -contains $_ })
        if (-not $overlap.Count) { $conflict = 'EXIF NESUTAMPA' }
    }

    $rows += [pscustomobject]@{
        Aplankas          = $d.Name
        Nuotrauku         = $total
        GoogleDienos      = ($distinctDays -join ' ')
        SkirtingosZymos   = $distinctStamps.Count
        TakenLygusCreated = "$sameAsCreated/$total"
        Verdiktas         = $verdict
        ExifDienos        = (($exifList | Select-Object -First 4) -join ' ')
        Konfliktas        = $conflict
    }
}
Write-Progress -Activity 'Google metadata' -Completed

if (-not $rows.Count) { Write-Output 'KLAIDA: neistirtas ne vienas albumas.'; exit 1 }
$rows | Sort-Object Aplankas | Export-Csv $OutCsv -NoTypeInformation -Encoding UTF8

Write-Output ("Istirta albumu: {0}" -f $rows.Count)
Write-Output ''
$rows | Group-Object Verdiktas | Sort-Object Count -Descending | ForEach-Object {
    Write-Output ("  {0,-32} {1}" -f $_.Name, $_.Count)
}
Write-Output ''
$bad = @($rows | Where-Object { $_.Konfliktas -eq 'EXIF NESUTAMPA' })
Write-Output ("Albumu, kur EXIF ir Google datos visai nesutampa: {0}" -f $bad.Count)
$bad | Sort-Object Aplankas | ForEach-Object {
    Write-Output ("  Google {0,-22} EXIF {1,-22} {2}" -f $_.GoogleDienos, $_.ExifDienos, $_.Aplankas)
}
Write-Output ''
Write-Output ("Ataskaita: " + $OutCsv)
