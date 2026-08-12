<#
    Prikabina nuotraukas prie albumu, kurie DB dar tusti.

    Tas pats, ka daro admin "B2 Sync", tik tiesiogiai ir be salutinio poveikio:
    admin sinchronizacija nezinomiems B2 aplankams PATI susikuria albumus ir dar
    paskelbia juos viesai - butent taip atsirado 36 albumai aplanku vardais.
    Cia nieko nekuriama: einam tik per esamus albumus ir ju source_path.

    Nuotraukos rasomos su ta pacia strukura kaip B2 Sync (source_type=b2_sync,
    file_ext, file_size, checksum is B2). Albumo matomumo neliecia - juodrastis
    lieka juodrasciu, kol pats ji paskelbsi.
#>
param(
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

$byFolder = @{}
$start = $null
do {
    $body = @{ bucketId = $bid; prefix = 'albums/'; maxFileCount = 10000 }
    if ($start) { $body.startFileName = $start }
    $r = Invoke-RestMethod -Uri ($auth.apiUrl + '/b2api/v2/b2_list_file_names') -Method Post `
        -Headers @{ Authorization = $auth.authorizationToken } -Body ($body | ConvertTo-Json) -ContentType 'application/json'
    foreach ($f in $r.files) {
        $name = [string]$f.fileName
        if ($name -notmatch '/originals/') { continue }
        if ($name -notmatch '\.(jpg|jpeg|png|gif|webp|mp4|mov|avi|heic)$') { continue }
        $p = $name -split '/'
        $fold = $p[0] + '/' + $p[1] + '/' + $p[2]
        if (-not $byFolder.ContainsKey($fold)) { $byFolder[$fold] = @() }
        $byFolder[$fold] += [pscustomobject]@{
            Key = $name; Name = $p[-1]; Size = [int64]$f.contentLength; Sha = [string]$f.contentSha1
        }
    }
    $start = $r.nextFileName
} while ($start)
Write-Output ("B2 aplanku su nuotraukomis: {0}" -f $byFolder.Count)

$albums = Db-Read 'SELECT a.id, a.title, a.source_path, a.visibility, (SELECT COUNT(*) FROM photos p WHERE p.album_id = a.id) n FROM albums a ORDER BY a.id'
$todo = @(); $noFiles = @()
foreach ($a in $albums) {
    if ([int]$a.n -gt 0) { continue }
    $sp = [string]$a.source_path
    if (-not $byFolder.ContainsKey($sp)) { $noFiles += ("#{0} {1}  ({2})" -f $a.id, $a.title, $sp); continue }
    $todo += [pscustomobject]@{ Id = [int]$a.id; Title = [string]$a.title; Files = @($byFolder[$sp] | Sort-Object Name) }
}
Write-Output ("albumu be nuotrauku      : {0}" -f @($albums | Where-Object { [int]$_.n -eq 0 }).Count)
Write-Output ("prikabinsim              : {0} albumu, {1:N0} nuotrauku" -f $todo.Count, (($todo | ForEach-Object { $_.Files.Count } | Measure-Object -Sum).Sum))
Write-Output ("failu B2 nerasta         : {0}" -f $noFiles.Count)
$noFiles | Select-Object -First 10 | ForEach-Object { Write-Output ('   ' + $_) }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$sql = 'INSERT INTO photos (uuid, album_id, source_type, b2_bucket, b2_key, original_filename, file_ext, original_format, file_size, checksum_sha1, sort_order, visibility, metadata_json, synced_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),NOW())'
$total = 0; $albDone = 0
foreach ($t in $todo) {
    $batch = @()
    $i = 0
    foreach ($f in $t.Files) {
        $i++
        $ext = ''
        if ($f.Name -match '\.([A-Za-z0-9]+)$') { $ext = $Matches[1].ToLower() }
        $meta = (@{ title = $f.Name; description = ''; albumId = $t.Id; visibility = 'published'; downloadable = $true } | ConvertTo-Json -Compress)
        $batch += @{
            sql = $sql
            params = @([Guid]::NewGuid().ToString(), $t.Id, 'b2_sync', $Bucket, $f.Key, $f.Name,
                $ext, $ext, $f.Size, $f.Sha, $i, 'published', $meta)
        }
        if ($batch.Count -ge 100) { Invoke-DbBatch $batch | Out-Null; $total += $batch.Count; $batch = @() }
    }
    if ($batch.Count) { Invoke-DbBatch $batch | Out-Null; $total += $batch.Count }
    $albDone++
    Write-Output ("[{0}/{1}] #{2,-4} {3,-46} {4} nuotr." -f $albDone, $todo.Count, $t.Id, $t.Title, $t.Files.Count)
}
Write-Output ("`nprikabinta nuotrauku: {0:N0}" -f $total)
$chk = Db-Read 'SELECT COUNT(*) c FROM albums a WHERE (SELECT COUNT(*) FROM photos p WHERE p.album_id = a.id) = 0'
Write-Output ("albumu be nuotrauku liko: {0}" -f $chk[0].c)
