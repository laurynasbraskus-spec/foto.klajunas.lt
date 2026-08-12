<#
    Perskaito EXIF DateTimeOriginal is VISU albumo nuotrauku ir palygina su
    album.json periodu [date .. dateEnd].

    Reikalinga todel, kad ankstesnis datu skriptas dalyje albumu EXIF nerado
    (album.json rodo exifDateFiles=0) ir data pasieme is Google sidecar
    photoTakenTime - o tai yra IKELIMO, ne fotografavimo data.

    Nieko nekeicia - tik raso ataskaita.
#>
param(
    [string]$Root   = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\albums',
    [string]$OutCsv = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\exif_date_audit.csv'
)

$ErrorActionPreference = 'Continue'
Add-Type -AssemblyName System.Drawing

# Fotoaparato laikrodis po baterijos iskrovimo atsistato i gamyklinius metus.
# Tokia data nera melas apie renginy - ji tiesiog nieko nepasako.
function Parse-Ymd([string]$s) {
    if (-not $s) { return $null }
    if ($s -notmatch '^(\d{4})-(\d{2})-(\d{2})') { return $null }
    try { return [datetime]::ParseExact(($Matches[1] + '-' + $Matches[2] + '-' + $Matches[3]), 'yyyy-MM-dd', $null) }
    catch { return $null }
}

function Is-ResetClock([datetime]$d) {
    return ($d.Year -le 2001) -or ($d.Year -eq 2007 -and $d.Month -eq 1 -and $d.Day -le 15) -or ($d.Year -gt 2030)
}

$rows = @()
$albums = @(Get-ChildItem $Root -Directory -Recurse -Depth 1 -EA SilentlyContinue |
            Where-Object { Test-Path (Join-Path $_.FullName 'album.json') })
$i = 0
foreach ($d in $albums) {
    $i++
    Write-Progress -Activity 'EXIF' -Status $d.Name -PercentComplete ([int](100 * $i / $albums.Count))
    $a = Get-Content (Join-Path $d.FullName 'album.json') -Raw | ConvertFrom-Json
    # TryParse su [ref]$null PowerShellyje meta klaida ir tyliai palieka data
    # neuzpildyta - todel parsinam per ParseExact griezta formata.
    $start = Parse-Ymd ([string]$a.date)
    $end   = Parse-Ymd ([string]$a.dateEnd)
    if (-not $end) { $end = $start }
    if (-not $start) {
        $rows += [pscustomobject]@{
            Aplankas = $d.Name; AlbumoData = $a.date; AlbumoPabaiga = $a.dateEnd
            ExifNuo = ''; ExifIki = ''; ExifKiek = 0; SugadintasLaikrodis = 0; BeExif = 0
            UzPeriodo = ''; Verdiktas = 'ALBUMO DATA NEPERSKAITYTA'
        }
        continue
    }

    $dates = @(); $reset = 0; $noExif = 0
    foreach ($f in (Get-ChildItem (Join-Path $d.FullName 'originals') -File -EA SilentlyContinue)) {
        if ($f.Extension -notmatch '(?i)\.(jpe?g)$') { continue }
        $val = $null
        try {
            $img = [System.Drawing.Image]::FromFile($f.FullName)
            try { $p = $img.GetPropertyItem(36867); $val = [Text.Encoding]::ASCII.GetString($p.Value).Trim([char]0).Trim() } catch {}
            $img.Dispose()
        } catch {}
        $dt = $null
        if ($val -match '^(\d{4}):(\d{2}):(\d{2})') {
            $dt = Parse-Ymd ($Matches[1] + '-' + $Matches[2] + '-' + $Matches[3])
        }
        if ($dt) {
            if (Is-ResetClock $dt) { $reset++ } else { $dates += $dt }
        } else { $noExif++ }
    }

    if (-not $dates.Count) {
        $rows += [pscustomobject]@{
            Aplankas = $d.Name; AlbumoData = $a.date; AlbumoPabaiga = $a.dateEnd
            ExifNuo = ''; ExifIki = ''; ExifKiek = 0; SugadintasLaikrodis = $reset; BeExif = $noExif
            UzPeriodo = ''; Verdiktas = $(if ($reset) { 'TIK sugadintas laikrodis' } else { 'EXIF nera' })
        }
        continue
    }

    $sorted  = $dates | Sort-Object
    $min     = $sorted[0]; $max = $sorted[-1]
    $outside = @($dates | Where-Object { $_.Date -lt $start.Date -or $_.Date -gt $end.Date }).Count

    $verdict = if ($outside -eq 0) { 'OK' }
               elseif ($outside -eq $dates.Count) { 'VISOS UZ PERIODO' }
               else { 'DALIS UZ PERIODO' }

    $rows += [pscustomobject]@{
        Aplankas = $d.Name; AlbumoData = $a.date; AlbumoPabaiga = $a.dateEnd
        ExifNuo = $min.ToString('yyyy-MM-dd'); ExifIki = $max.ToString('yyyy-MM-dd'); ExifKiek = $dates.Count
        SugadintasLaikrodis = $reset; BeExif = $noExif; UzPeriodo = $outside; Verdiktas = $verdict
    }
}
Write-Progress -Activity 'EXIF' -Completed

# Apsauga nuo tylaus nulio: jei EXIF neperskaitytas NIEKUR, tai ne archyvo
# savybe, o skripto klaida - tokios ataskaitos rodyti negalima.
$withExif = @($rows | Where-Object { $_.ExifKiek -gt 0 }).Count
if ($withExif -eq 0) {
    Write-Output "KLAIDA: ne viename albume neperskaityta EXIF data."
    Write-Output "Tai beveik tikrai skripto, o ne archyvo problema - ataskaita neirasoma."
    exit 1
}

$rows | Sort-Object Aplankas | Export-Csv $OutCsv -NoTypeInformation -Encoding UTF8

Write-Output ("Istirta albumu: {0}" -f $rows.Count)
Write-Output ""
$rows | Group-Object Verdiktas | Sort-Object Count -Descending | ForEach-Object {
    Write-Output ("  {0,-26} {1}" -f $_.Name, $_.Count)
}
Write-Output ""
Write-Output "=== VISOS NUOTRAUKOS UZ ALBUMO PERIODO (data beveik tikrai klaidinga) ==="
$rows | Where-Object { $_.Verdiktas -eq 'VISOS UZ PERIODO' } | Sort-Object Aplankas | ForEach-Object {
    $sk = if ($_.ExifNuo -eq $_.ExifIki) { $_.ExifNuo } else { $_.ExifNuo + '..' + $_.ExifIki }
    Write-Output ("  album.json {0,-12} EXIF {1,-24} ({2} nuotr.)  {3}" -f $_.AlbumoData, $sk, $_.ExifKiek, $_.Aplankas)
}
Write-Output ""
Write-Output ("Ataskaita: " + $OutCsv)
