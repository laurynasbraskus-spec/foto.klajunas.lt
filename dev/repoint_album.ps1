<#
    Perkelia viena albuma i nurodyta B2 aplanka ir, jei reikia, pataiso datas.

    Skirta atvejams, kai B2 yra du to paties albumo aplankai su SKIRTINGAIS
    datos raktais, o DB rodo i ta, kurio data klaidinga:

        2011-07-16__Aplink-Zasliu-ezera   <- teisinga (lbma.lt + EXIF)
        2011-07-19__Aplink-Zasliu-ezera   <- i si rode DB

    Perkeliama tik tada, kai kiekvienai albumo nuotraukai tikslinis aplankas
    turi faila tokiu paciu vardu (be numeracijos) ir tokio paties dydzio.

        .\repoint_album.ps1 -AlbumId 7 -To 'albums/2011/2011-07-16__Aplink-Zasliu-ezera' -Date 2011-07-16 -Execute
#>
param(
    [Parameter(Mandatory = $true)][int]$AlbumId,
    [Parameter(Mandatory = $true)][string]$To,
    [string]$Date = '',
    [string]$DateEnd = '',
    [string]$ConfigPhp = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\server\b2-config.php',
    # Kai tas pats failas dviejuose aplankuose pavadintas skirtingai
    # ("0001_2016-09-18-12.49.41.jpg" ir "2016-09-18 12.49.41.jpg"), tapatybe
    # irodo dydis. Naudojama TIK jei tokio dydzio failas abiejuose aplankuose
    # yra lygiai vienas - kitaip nebutu aisku, kuris kuriam.
    [switch]$MatchBySize,
    [switch]$Execute
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

$cfg = Get-Content $ConfigPhp -Raw
$kid = [regex]::Match($cfg, "B2_KEY_ID',\s*'([^']+)").Groups[1].Value
$key = [regex]::Match($cfg, "B2_APP_KEY',\s*'([^']+)").Groups[1].Value
$bid = [regex]::Match($cfg, "B2_BUCKET_ID',\s*'([^']+)").Groups[1].Value
$auth = Invoke-RestMethod -Uri 'https://api.backblazeb2.com/b2api/v2/b2_authorize_account' `
    -Headers @{ Authorization = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("$kid`:$key")) }

$target = @{}; $start = $null
do {
    $body = @{ bucketId = $bid; prefix = ($To.TrimEnd('/') + '/originals/'); maxFileCount = 1000 }
    if ($start) { $body.startFileName = $start }
    $r = Invoke-RestMethod -Uri ($auth.apiUrl + '/b2api/v2/b2_list_file_names') -Method Post `
        -Headers @{ Authorization = $auth.authorizationToken } -Body ($body | ConvertTo-Json) -ContentType 'application/json'
    foreach ($f in $r.files) { $target[([string]$f.fileName -split '/')[-1]] = [int64]$f.contentLength }
    $start = $r.nextFileName
} while ($start)
Write-Output ("tiksliniame aplanke failu: {0}" -f $target.Count)
if (-not $target.Count) { Write-Output 'KLAIDA: tikslinis aplankas tuscias.'; exit 1 }

$album = Db-Read 'SELECT id, title, source_path, event_date, event_date_end FROM albums WHERE id = ?' @($AlbumId)
if (-not $album.Count) { Write-Output 'KLAIDA: albumo nera.'; exit 1 }
Write-Output ("#{0} {1}`n   is: {2}`n   i : {3}" -f $album[0].id, $album[0].title, $album[0].source_path, $To)

$photos = Db-Read 'SELECT id, b2_key, file_size FROM photos WHERE album_id = ?' @($AlbumId)
$map = @{}; $bad = @()
foreach ($p in $photos) {
    $name = ([string]$p.b2_key -split '/')[-1]
    $bare = $name -replace '^\d{4}_', ''
    $size = [int64]$p.file_size
    $hit = $null
    foreach ($tn in $target.Keys) {
        if (($tn -replace '^\d{4}_', '') -ne $bare) { continue }
        if ($size -gt 0 -and $target[$tn] -ne $size) { continue }
        $hit = $tn; break
    }
    if (-not $hit -and $MatchBySize -and $size -gt 0) {
        $sameSize = @($target.Keys | Where-Object { $target[$_] -eq $size })
        $mineSame = @($photos | Where-Object { [int64]$_.file_size -eq $size })
        if ($sameSize.Count -eq 1 -and $mineSame.Count -eq 1) { $hit = $sameSize[0] }
    }
    if ($hit) { $map[[int]$p.id] = ($To.TrimEnd('/') + '/originals/' + $hit) } else { $bad += $name }
}
Write-Output ("nuotrauku: {0}   rasta tiksliniame: {1}   nerasta: {2}" -f $photos.Count, $map.Count, $bad.Count)
$bad | Select-Object -First 10 | ForEach-Object { Write-Output ('   nerasta: ' + $_) }
if ($bad.Count) { Write-Output "`nNEperkeliam - tikslinis aplankas nepilnas."; exit 1 }
if ($Date) { Write-Output ("   data: {0} -> {1}" -f $album[0].event_date, $Date) }
if ($DateEnd) { Write-Output ("   pabaiga: {0} -> {1}" -f $album[0].event_date_end, $DateEnd) }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

foreach ($photoId in $map.Keys) {
    Db-Write 'UPDATE photos SET b2_key = ? WHERE id = ?' @($map[$photoId], $photoId) | Out-Null
}
if ($Date -and $DateEnd) {
    Db-Write 'UPDATE albums SET source_path = ?, event_date = ?, event_date_end = ?, updated_at = NOW() WHERE id = ?' @($To, $Date, $DateEnd, $AlbumId) | Out-Null
} elseif ($Date) {
    Db-Write 'UPDATE albums SET source_path = ?, event_date = ?, updated_at = NOW() WHERE id = ?' @($To, $Date, $AlbumId) | Out-Null
} elseif ($DateEnd) {
    Db-Write 'UPDATE albums SET source_path = ?, event_date_end = ?, updated_at = NOW() WHERE id = ?' @($To, $DateEnd, $AlbumId) | Out-Null
} else {
    Db-Write 'UPDATE albums SET source_path = ?, updated_at = NOW() WHERE id = ?' @($To, $AlbumId) | Out-Null
}
Write-Output ("`nperkelta: {0} nuotrauku" -f $map.Count)
