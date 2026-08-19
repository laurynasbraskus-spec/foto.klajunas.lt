<#
    Atskiria dali albumo nuotrauku i NAUJA albuma.

    Reikia, kai i viena aplanka sumesti keli renginiai: "Lunatikai 2014" turejo
    ir I, ir II zygi, o "Rajono cempionatas 2006-2007" - net penkis skirtingus
    fotoaparatu rinkinius.

    Failu sarasas paduodamas tekstiniu failu - po viena varda eiluteje.
    Numeracijos priesaga ("0001_") nesvarbu. Kartu perkeliamas ir kiekvieno
    failo metadata/ palydovas.

    Tvarka: nukopijuojam -> patikrinam kiekviena faila -> sukuriam albuma ->
    perkeliam DB eilutes -> ir tik tada trinam is senojo aplanko. Failams, kurie
    B2 yra, bet DB eilutes neturi, eilutes sukuriamos - kitaip perkeliant jie
    tyliai dingtu is apskaitos.
#>
param(
    [Parameter(Mandatory = $true)][int]$SourceAlbumId,
    [Parameter(Mandatory = $true)][string]$NewTitle,
    [Parameter(Mandatory = $true)][string]$NewDate,
    [string]$NewDateEnd = '',
    [string]$NewPlace = '',
    [Parameter(Mandatory = $true)][string]$FilesFrom,
    [string]$ConfigPhp = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\server\b2-config.php',
    [string]$Php = 'C:\Users\KLAJNA~1\AppData\Local\Temp\claude\C--Users-Klaj-nas-Documents-Claude-foto-klajunas-lt\ee9b9aef-6272-4900-ad34-133ecc991fe9\scratchpad\php\php.exe',
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

# Kuna siunciam UTF-8 baitais - kitaip lietuviskos raides varde iskraipomos.
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

$src = Db-Read 'SELECT id,title,source_path FROM albums WHERE id=?' @($SourceAlbumId)
if (-not $src.Count) { Write-Output 'KLAIDA: saltinio albumo nera.'; exit 1 }
$oldPrefix = ([string]$src[0].source_path).TrimEnd('/')
Write-Output ("saltinis: #{0} {1}" -f $src[0].id, $src[0].title)
Write-Output ("   {0}" -f $oldPrefix)

# kanoninis kelias naujam albumui - tuo paciu kodu kaip serveryje
$enc = New-Object Text.UTF8Encoding($false)
$inF = Join-Path $env:TEMP 'split_in.json'
$outF = Join-Path $env:TEMP 'split_out.json'
$script = Join-Path $env:TEMP 'canonical_prefixes.php'
Copy-Item (Join-Path $PSScriptRoot 'canonical_prefixes.php') $script -Force
$inp = [pscustomobject]@{ name = 'new'; title = $NewTitle; date = $NewDate; dateEnd = $NewDateEnd }
# ConvertTo-Json is VIENO elemento masyvo padaro objekta, ne masyva, ir PHP
# skriptas neturi ko iteruoti. Todel skliaustus dedam patys.
[IO.File]::WriteAllText($inF, ('[' + ($inp | ConvertTo-Json -Depth 4) + ']'), $enc)
& $Php $script $inF $outF | Out-Null
$parsed = ConvertFrom-Json ([IO.File]::ReadAllText($outF))
$newPrefix = @($parsed)[0].prefix
Write-Output ("naujas albumas: {0}" -f $NewTitle)
Write-Output ("   {0}" -f $newPrefix)

$wanted = @(Get-Content $FilesFrom | ForEach-Object { $_.Trim() } | Where-Object { $_ })
Write-Output ("perkeliamu failu sarase: {0}" -f $wanted.Count)

$all = B2-List ($oldPrefix + '/')
# Vienas vardas gali tureti KELIS raktus: aplanke buna ir "DSC_0062.JPG", ir
# "0048_DSC_0062.JPG" - tas pats kadras dviem vardais. Anksciau zemelapis
# laike tik viena, todel po skaidymo antroji kopija likdavo senajame albume.
$byBare = @{}
foreach ($k in $all.Keys) {
    $b = Bare (($k -split '/')[-1])
    if (-not $byBare.ContainsKey($b)) { $byBare[$b] = @() }
    $byBare[$b] += $k
}

$move = @(); $missing = @()
foreach ($w in $wanted) {
    if (-not $byBare.ContainsKey($w)) { $missing += $w; continue }
    $move += $byBare[$w]
    # Skliaustai butini: PowerShellyje kablelis rišasi stipriau uz "+", tad
    # @($w + 'a', $w + 'b') sudeliotu visai ne tuos vardus ir palydovai liktu
    # nerasti (o nuotrauka nukeliautu i nauja albuma be savo metaduomenu).
    foreach ($cand in @(($w + '.supplemental-metadata.json'), ($w + '.json'))) {
        if ($byBare.ContainsKey($cand)) { $move += $byBare[$cand] }
    }
}
$move = @($move | Sort-Object -Unique)
Write-Output ("rasta B2: {0} failai (su metaduomenimis)   nerasta: {1}" -f $move.Count, $missing.Count)
$missing | ForEach-Object { Write-Output ('   nerasta: ' + $_) }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }
if ($missing.Count) { Write-Output 'KLAIDA: ne visi failai rasti - nutraukiam.'; exit 1 }

# 1) kopijuojam
$copied = 0; $failed = 0
foreach ($k in $move) {
    $target = $newPrefix + $k.Substring($oldPrefix.Length)
    try {
        B2-Post ($auth.apiUrl + '/b2api/v2/b2_copy_file') @{ sourceFileId = $all[$k].Id; fileName = $target } | Out-Null
        $copied++
    } catch { $failed++; Write-Output ('   kopijuoti nepavyko: ' + $target) }
}
Write-Output ("nukopijuota {0}, klaidu {1}" -f $copied, $failed)
if ($failed -gt 0) { Write-Output 'NUTRAUKIAM - DB neliecam.'; exit 1 }

# 2) patikra
$dst = B2-List ($newPrefix + '/')
$bad = 0
foreach ($k in $move) {
    $target = $newPrefix + $k.Substring($oldPrefix.Length)
    if (-not ($dst.ContainsKey($target) -and $dst[$target].Size -eq $all[$k].Size)) { $bad++ }
}
if ($bad -gt 0) { Write-Output ("NUTRAUKIAM: naujame kelyje truksta {0} failu." -f $bad); exit 1 }

# 3) naujas albumas
$slug = (($newPrefix -split '/')[-1]).ToLower() -replace '[^a-z0-9]+', '-'
$slug = $slug.Trim('-')
$sql = 'INSERT INTO albums (uuid, source_type, source_path, slug, title, event_date, event_date_end, location_name, visibility, download_enabled, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
$endVal = $null
if ($NewDateEnd -ne '') { $endVal = $NewDateEnd }
Db-Write $sql @([Guid]::NewGuid().ToString(), 'b2', $newPrefix, $slug, $NewTitle, $NewDate, $endVal, $NewPlace, 'draft', 1) | Out-Null
$newRow = Db-Read 'SELECT id FROM albums WHERE source_path=? LIMIT 1' @($newPrefix)
$newId = [int]$newRow[0].id
Write-Output ("sukurtas albumas #{0} (juodrastis)" -f $newId)

# 4) DB eilutes
$rows = Db-Read 'SELECT id,b2_key FROM photos WHERE album_id=?' @($SourceAlbumId)
$byKey = @{}
foreach ($r in $rows) { $byKey[[string]$r.b2_key] = [int]$r.id }
$moved = 0; $created = 0; $i = 0
foreach ($k in $move) {
    if ($k -notmatch '/originals/') { continue }
    $i++
    $target = $newPrefix + $k.Substring($oldPrefix.Length)
    if ($byKey.ContainsKey($k)) {
        Db-Write 'UPDATE photos SET album_id=?, b2_key=?, updated_at=NOW() WHERE id=?' @($newId, $target, $byKey[$k]) | Out-Null
        $moved++
    } else {
        $name = ($target -split '/')[-1]
        $ext = ''
        if ($name -match '\.([A-Za-z0-9]+)$') { $ext = $Matches[1].ToLower() }
        $meta = (@{ title = $name; description = ''; albumId = $newId; visibility = 'published'; downloadable = $true } | ConvertTo-Json -Compress)
        Db-Write 'INSERT INTO photos (uuid, album_id, source_type, b2_bucket, b2_key, original_filename, file_ext, original_format, file_size, sort_order, visibility, metadata_json, synced_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW(),NOW())' `
            @([Guid]::NewGuid().ToString(), $newId, 'b2_sync', 'ok-klajunas-foto', $target, $name, $ext, $ext, $all[$k].Size, $i, 'published', $meta) | Out-Null
        $created++
    }
}
Write-Output ("DB: perkelta eiluciu {0}, sukurta nauju {1}" -f $moved, $created)

# 5) trinam is senojo aplanko
$del = 0; $delFail = @()
foreach ($k in $move) {
    try {
        B2-Post ($auth.apiUrl + '/b2api/v2/b2_delete_file_version') @{ fileId = $all[$k].Id; fileName = $k } | Out-Null
        $del++
    } catch { $delFail += $k }
}
Write-Output ("is senojo aplanko istrinta: {0}" -f $del)
if ($delFail.Count) {
    Write-Output ("NEISTRINTA {0}:" -f $delFail.Count)
    $delFail | ForEach-Object { Write-Output ('   ' + $_) }
}
