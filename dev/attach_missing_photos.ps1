<#
    Sukuria DB eilutes nuotraukoms, kurios albumo B2 aplanke YRA, bet DB ju
    nera - todel galerijoje jos nesimato.

    Skiriasi nuo attach_photos.ps1: tas liecia tik tuscius albumus, o sis
    papildo ir tuos, kurie jau turi nuotrauku.

    Nieko netrina ir neperkelia - tik prideda trukstamas eilutes.
#>
param(
    [int[]]$AlbumIds = @(),
    [string]$ConfigPhp = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\server\b2-config.php',
    [string]$Bucket = 'ok-klajunas-foto',
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

function B2-Post([string]$url, [hashtable]$body) {
    $json = $body | ConvertTo-Json -Compress
    return Invoke-RestMethod -Uri $url -Method Post `
        -Headers @{ Authorization = $auth.authorizationToken } `
        -Body ([Text.Encoding]::UTF8.GetBytes($json)) -ContentType 'application/json; charset=utf-8'
}

$byFolder = @{}
$start = $null
do {
    $body = @{ bucketId = $bid; prefix = 'albums/'; maxFileCount = 10000 }
    if ($start) { $body.startFileName = $start }
    $r = B2-Post ($auth.apiUrl + '/b2api/v2/b2_list_file_names') $body
    foreach ($f in $r.files) {
        $name = [string]$f.fileName
        if ($name -notmatch '/originals/') { continue }
        if ($name -notmatch '\.(jpg|jpeg|png|gif|webp|mp4|mov|avi|heic)$') { continue }
        $p = $name -split '/'
        $fold = $p[0] + '/' + $p[1] + '/' + $p[2]
        if (-not $byFolder.ContainsKey($fold)) { $byFolder[$fold] = @() }
        $byFolder[$fold] += [pscustomobject]@{ Key = $name; Name = $p[-1]; Size = [int64]$f.contentLength }
    }
    $start = $r.nextFileName
} while ($start)
Write-Output ("B2 aplanku su nuotraukomis: {0}" -f $byFolder.Count)

$where = ''
$prm = @()
if ($AlbumIds.Count) {
    $ph = ($AlbumIds | ForEach-Object { '?' }) -join ','
    $where = " WHERE id IN ($ph)"
    $prm = $AlbumIds
}
$albums = Db-Read ("SELECT id,title,source_path,visibility FROM albums$where ORDER BY id") $prm
$dbKeys = @{}
foreach ($p in (Db-Read 'SELECT b2_key FROM photos')) { $dbKeys[[string]$p.b2_key] = $true }

$plan = @()
foreach ($a in $albums) {
    $sp = [string]$a.source_path
    if (-not $byFolder.ContainsKey($sp)) { continue }
    $missing = @($byFolder[$sp] | Where-Object { -not $dbKeys.ContainsKey($_.Key) })
    if (-not $missing.Count) { continue }
    $plan += [pscustomobject]@{ Id = [int]$a.id; Title = [string]$a.title; Files = $missing }
}
$total = ($plan | ForEach-Object { $_.Files.Count } | Measure-Object -Sum).Sum
Write-Output ("albumu su trukstamomis eilutemis: {0}   nuotrauku: {1}" -f $plan.Count, $total)
foreach ($p in $plan) {
    Write-Output ("   #{0,-4} {1,-44} +{2}" -f $p.Id, $p.Title, $p.Files.Count)
    $p.Files | Select-Object -First 4 | ForEach-Object { Write-Output ('        ' + $_.Name) }
}
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$created = 0
foreach ($p in $plan) {
    $max = Db-Read 'SELECT COALESCE(MAX(sort_order),0) m FROM photos WHERE album_id=?' @($p.Id)
    $i = [int]$max[0].m
    foreach ($f in ($p.Files | Sort-Object Name)) {
        $i++
        $ext = ''
        if ($f.Name -match '\.([A-Za-z0-9]+)$') { $ext = $Matches[1].ToLower() }
        $meta = (@{ title = $f.Name; description = ''; albumId = $p.Id; visibility = 'published'; downloadable = $true } | ConvertTo-Json -Compress)
        Db-Write 'INSERT INTO photos (uuid, album_id, source_type, b2_bucket, b2_key, original_filename, file_ext, original_format, file_size, sort_order, visibility, metadata_json, synced_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),NOW())' `
            @([Guid]::NewGuid().ToString(), $p.Id, 'b2_sync', $Bucket, $f.Key, $f.Name, $ext, $ext, $f.Size, $i, 'published', $meta) | Out-Null
        $created++
    }
}
Write-Output ("`nsukurta eiluciu: {0}" -f $created)
