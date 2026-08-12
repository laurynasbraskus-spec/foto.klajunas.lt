<#
    Perkelia DB irasus i kanoninius B2 kelius.

    Situacija: dalies albumu failai B2 guli DVIEJOSE vietose - senu keliu
    (i ji rodo DB) ir kanoniniu (ten ikelta is archyvo). Trinti kanonini butu
    atbulai; teisinga perrasyti DB i kanonini ir tik tada atlaisvinti sena.

    Sauga:
      - kiekvienai nuotraukai kanoniniame aplanke ieskomas failas TOKIU PACIU
        vardu (be numeracijos priesagos) IR TOKIO PACIO dydzio;
      - jei bent vienai nuotraukai atitikmens nera, visas albumas praleidziamas;
      - B2 cia nieko netrinama. Senas aplankas lieka vietoje ir ji galima
        isvalyti atskirai, jau pamacius, kad galerija veikia.
#>
param(
    [string]$ConfigPhp = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\server\b2-config.php',
    # Albumai, kuriu kanoninis aplankas uzkoduoja kita data nei DB. Perkelti
    # galima tik issiaiskinus, kuri data teisinga - kitaip kelias ir rodoma
    # data prasilenktu. #14 Telse: DB 2019-08-15..19, aplankas 08-17_19.
    [int[]]$Exclude = @(14),
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
$files = @{}; $start = $null
do {
    $body = @{ bucketId = $bid; prefix = 'albums/'; maxFileCount = 10000 }
    if ($start) { $body.startFileName = $start }
    $r = Invoke-RestMethod -Uri ($auth.apiUrl + '/b2api/v2/b2_list_file_names') -Method Post `
        -Headers @{ Authorization = $auth.authorizationToken } -Body ($body | ConvertTo-Json) -ContentType 'application/json'
    foreach ($f in $r.files) { $files[[string]$f.fileName] = [int64]$f.contentLength }
    $start = $r.nextFileName
} while ($start)

$folderFiles = @{}
foreach ($k in $files.Keys) {
    $p = $k -split '/'
    if ($p.Count -lt 4) { continue }
    $fold = $p[0] + '/' + $p[1] + '/' + $p[2]
    if (-not $folderFiles.ContainsKey($fold)) { $folderFiles[$fold] = @{} }
    $folderFiles[$fold][$p[-1]] = $files[$k]
}
Write-Output ("B2 aplanku: {0}" -f $folderFiles.Count)

# Kanoninis kelias atpazistamas is pavidalo: albums/<metai>/<YYYY-MM-DD...>__<vardas>
function Is-Canonical([string]$path) { return ($path -match '/\d{4}-\d{2}-\d{2}[^/]*__') }

$albums = Db-Read 'SELECT id, title, source_path FROM albums ORDER BY id'
$used = @{}
foreach ($a in $albums) { $used[[string]$a.source_path] = $true }

$plan = @(); $skipped = @()
foreach ($a in $albums) {
    $src = [string]$a.source_path
    if (Is-Canonical $src) { continue }
    if ($Exclude -contains [int]$a.id) { $skipped += ("#{0} {1}  (sazmoningai praleista)" -f $a.id, $a.title); continue }
    $photos = Db-Read 'SELECT id, b2_key FROM photos WHERE album_id = ?' @([int]$a.id)
    if (-not $photos.Count) { continue }

    # kandidatai: kanoniniai aplankai, kuriu DB dar nenaudoja
    $cands = @($folderFiles.Keys | Where-Object { (Is-Canonical $_) -and -not $used.ContainsKey($_) })
    $best = $null; $bestMap = $null
    foreach ($c in $cands) {
        $their = $folderFiles[$c]
        $map = @{}
        $allOk = $true
        foreach ($p in $photos) {
            $name = ([string]$p.b2_key -split '/')[-1]
            $bare = $name -replace '^\d{4}_', ''
            $size = $files[[string]$p.b2_key]
            $hit = $null
            foreach ($tn in $their.Keys) {
                if (($tn -replace '^\d{4}_', '') -eq $bare -and $their[$tn] -eq $size) { $hit = $tn; break }
            }
            if (-not $hit) { $allOk = $false; break }
            $map[[int]$p.id] = ($c + '/originals/' + $hit)
        }
        if ($allOk) { $best = $c; $bestMap = $map; break }
    }
    if (-not $best) { $skipped += ("#{0} {1}  ({2})" -f $a.id, $a.title, $src); continue }
    $used[$best] = $true
    $plan += [pscustomobject]@{ Id = [int]$a.id; Title = [string]$a.title; From = $src; To = $best; Map = $bestMap }
}

Write-Output ("`nperkelsim albumu: {0}" -f $plan.Count)
foreach ($p in $plan) {
    Write-Output ("   #{0,-4} {1}" -f $p.Id, $p.Title)
    Write-Output ("        {0}`n     -> {1}  ({2} nuotr.)" -f $p.From, $p.To, $p.Map.Count)
}
Write-Output ("`nnepavyko surasti kanoninio atitikmens: {0}" -f $skipped.Count)
$skipped | ForEach-Object { Write-Output ('   ' + $_) }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$done = 0
foreach ($p in $plan) {
    # NE $pid - tai PowerShell automatinis kintamasis, kurio perrasyti negalima.
    foreach ($photoId in $p.Map.Keys) {
        Db-Write 'UPDATE photos SET b2_key = ? WHERE id = ?' @($p.Map[$photoId], $photoId) | Out-Null
    }
    Db-Write 'UPDATE albums SET source_path = ?, updated_at = NOW() WHERE id = ?' @($p.To, $p.Id) | Out-Null
    $done++
}
Write-Output ("`nperkelta albumu: {0}" -f $done)

# patikra: ar visi nauji keliai tikrai yra B2
$bad = 0
$after = Db-Read 'SELECT b2_key FROM photos'
foreach ($x in $after) { if (-not $files.ContainsKey([string]$x.b2_key)) { $bad++ } }
Write-Output ("nuotrauku, kuriu failo B2 nera: {0}" -f $bad)
