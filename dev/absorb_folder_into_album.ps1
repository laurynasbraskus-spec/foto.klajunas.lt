<#
    Prijungia atskirai likusi B2 aplanka prie esamo albumo.

    Reikia, kai po sujungimu ar pervadinimu lieka aplankas, kurio nuotraukos
    niekur nerodomos, bet jos NEBUTINAI dublikatai - gali buti to paties
    renginio kadrai kitu vardu.

    Failai nukopijuojami i albumo aplanka, patikrinami, sukuriamos DB eilutes
    ir tik tada senasis aplankas isvalomas.

    Jei albume jau yra failas tokiu paciu vardu (be numeracijos priesagos) IR
    tokio paties dydzio - jis praleidziamas: tai tas pats failas.
#>
param(
    [Parameter(Mandatory = $true)][string]$FromPrefix,
    [Parameter(Mandatory = $true)][int]$IntoAlbumId,
    [string]$ConfigPhp = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\server\b2-config.php',
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
function B2-List([string]$prefix) {
    $m = @{}; $s = $null
    do {
        $b = @{ bucketId = $bid; prefix = $prefix; maxFileCount = 1000 }
        if ($s) { $b.startFileName = $s }
        $r = B2-Post ($auth.apiUrl + '/b2api/v2/b2_list_file_names') $b
        foreach ($f in $r.files) { $m[[string]$f.fileName] = [pscustomobject]@{ Id = [string]$f.fileId; Size = [int64]$f.contentLength } }
        $s = $r.nextFileName
    } while ($s)
    return , $m
}
function Bare([string]$name) { return ($name -replace '^\d{4}_', '') }

$from = $FromPrefix.TrimEnd('/')
$alb = Db-Read 'SELECT id,title,source_path FROM albums WHERE id=?' @($IntoAlbumId)
if (-not $alb.Count) { Write-Output 'KLAIDA: albumo nera.'; exit 1 }
$to = ([string]$alb[0].source_path).TrimEnd('/')
Write-Output ("is : {0}" -f $from)
Write-Output ("i  : #{0} {1}" -f $alb[0].id, $alb[0].title)
Write-Output ("     {0}" -f $to)
if ($from -eq $to) { Write-Output 'KLAIDA: tas pats aplankas.'; exit 1 }

$src = B2-List ($from + '/')
$dst = B2-List ($to + '/')
Write-Output ("failu: sename {0}, albume {1}" -f $src.Count, $dst.Count)

$dstIdx = @{}
foreach ($k in $dst.Keys) { $dstIdx[(Bare (($k -split '/')[-1])) + '|' + $dst[$k].Size] = $true }

$plan = @(); $same = 0
foreach ($k in $src.Keys) {
    $bare = Bare (($k -split '/')[-1])
    if ($dstIdx.ContainsKey($bare + '|' + $src[$k].Size)) { $same++; continue }
    $plan += $k
}
Write-Output ("perkelsim: {0}   jau yra albume: {1}" -f $plan.Count, $same)
$plan | Where-Object { $_ -match '/originals/' } | ForEach-Object { Write-Output ('   ' + ($_ -split '/')[-1]) }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }
if (-not $plan.Count) { Write-Output 'Nieko perkelti nereikia.'; exit 0 }

$copied = 0; $failed = 0
foreach ($k in $plan) {
    $target = $to + $k.Substring($from.Length)
    try { B2-Post ($auth.apiUrl + '/b2api/v2/b2_copy_file') @{ sourceFileId = $src[$k].Id; fileName = $target } | Out-Null; $copied++ }
    catch { $failed++; Write-Output ('   kopijuoti nepavyko: ' + $target) }
}
Write-Output ("nukopijuota {0}, klaidu {1}" -f $copied, $failed)
if ($failed -gt 0) { Write-Output 'NUTRAUKIAM - DB neliecam.'; exit 1 }

$dst = B2-List ($to + '/')
$bad = 0
foreach ($k in $plan) {
    $target = $to + $k.Substring($from.Length)
    if (-not ($dst.ContainsKey($target) -and $dst[$target].Size -eq $src[$k].Size)) { $bad++ }
}
if ($bad -gt 0) { Write-Output ("NUTRAUKIAM: albumo aplanke truksta {0} failu." -f $bad); exit 1 }

$maxSort = Db-Read 'SELECT COALESCE(MAX(sort_order),0) m FROM photos WHERE album_id=?' @($IntoAlbumId)
$i = [int]$maxSort[0].m
$created = 0
foreach ($k in $plan) {
    if ($k -notmatch '/originals/') { continue }
    $i++
    $target = $to + $k.Substring($from.Length)
    $name = ($target -split '/')[-1]
    $ext = ''
    if ($name -match '\.([A-Za-z0-9]+)$') { $ext = $Matches[1].ToLower() }
    $meta = (@{ title = $name; description = ''; albumId = $IntoAlbumId; visibility = 'published'; downloadable = $true } | ConvertTo-Json -Compress)
    Db-Write 'INSERT INTO photos (uuid, album_id, source_type, b2_bucket, b2_key, original_filename, file_ext, original_format, file_size, sort_order, visibility, metadata_json, synced_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),NOW())' `
        @([Guid]::NewGuid().ToString(), $IntoAlbumId, 'b2_sync', 'ok-klajunas-foto', $target, $name, $ext, $ext, $src[$k].Size, $i, 'published', $meta) | Out-Null
    $created++
}
Write-Output ("sukurta DB eiluciu: {0}" -f $created)

$del = 0; $delFail = @()
foreach ($k in $src.Keys) {
    try { B2-Post ($auth.apiUrl + '/b2api/v2/b2_delete_file_version') @{ fileId = $src[$k].Id; fileName = $k } | Out-Null; $del++ }
    catch { $delFail += $k }
}
Write-Output ("senas aplankas isvalytas: {0} failu" -f $del)
if ($delFail.Count) { Write-Output ("NEISTRINTA {0}" -f $delFail.Count) }
$n = Db-Read 'SELECT COUNT(*) c FROM photos WHERE album_id=?' @($IntoAlbumId)
Write-Output ("albume dabar nuotrauku: {0}" -f $n[0].c)
