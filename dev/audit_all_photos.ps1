<#
    Globalus patikrinimas: ar kiekviena vietinio archyvo nuotrauka yra B2 ir ar
    ji turi irasa DB.

    Nesiremiama keliais. Kelias gali skirtis (senas, kanoninis, pervadintas), o
    failo vardas ir dydis - ne. Todel lyginama poromis "vardas + dydis":

      vietinis failas -> ar toks yra B2?          (jei ne - neikelta)
                      -> ar tas B2 failas yra DB? (jei ne - nesusieta)

    Papildomai tikrinama atgal: ar nera DB eiluciu, kuriu failo B2 nebera.

    Numeracijos priesaga ("0001_") nuimama - tas pats failas viename kelyje
    buna sunumeruotas, kitame ne.
#>
param(
    [string]$Base = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas',
    [string]$ConfigPhp = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\server\b2-config.php',
    [string]$Out = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\patikrinimas.csv'
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

# --- B2 ---
$cfg = Get-Content $ConfigPhp -Raw
$kid = [regex]::Match($cfg, "B2_KEY_ID',\s*'([^']+)").Groups[1].Value
$key = [regex]::Match($cfg, "B2_APP_KEY',\s*'([^']+)").Groups[1].Value
$bid = [regex]::Match($cfg, "B2_BUCKET_ID',\s*'([^']+)").Groups[1].Value
$auth = Invoke-RestMethod -Uri 'https://api.backblazeb2.com/b2api/v2/b2_authorize_account' `
    -Headers @{ Authorization = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("$kid`:$key")) }
$b2ByPair = @{}   # "vardas|dydis" -> raktu sarasas
$b2Keys = @{}
$start = $null
do {
    $body = @{ bucketId = $bid; prefix = 'albums/'; maxFileCount = 10000 }
    if ($start) { $body.startFileName = $start }
    $r = Invoke-RestMethod -Uri ($auth.apiUrl + '/b2api/v2/b2_list_file_names') -Method Post `
        -Headers @{ Authorization = $auth.authorizationToken } -Body ($body | ConvertTo-Json) -ContentType 'application/json'
    foreach ($f in $r.files) {
        $name = [string]$f.fileName
        # NEfiltruojam pagal "/originals/": seni, per admin ikelti albumai laiko
        # nuotraukas tiesiai albumo aplanke. Anksciau toks filtras 122 tvarkingas
        # nuotraukas parode kaip "DB yra, B2 failo nera".
        if ($name -notmatch '\.(jpg|jpeg|png|gif|webp|mp4|mov|avi|heic)$') { continue }
        $bare = (($name -split '/')[-1] -replace '^\d{4}_', '').ToLower()
        $pair = $bare + '|' + [int64]$f.contentLength
        if (-not $b2ByPair.ContainsKey($pair)) { $b2ByPair[$pair] = @() }
        $b2ByPair[$pair] += $name
        $b2Keys[$name] = $true
    }
    $start = $r.nextFileName
} while ($start)
Write-Output ("B2 nuotrauku: {0}" -f $b2Keys.Count)

# --- DB ---
$dbRows = Db-Read 'SELECT b2_key FROM photos'
$dbKeys = @{}
foreach ($x in $dbRows) { $dbKeys[[string]$x.b2_key] = $true }
Write-Output ("DB nuotrauku eiluciu: {0}" -f $dbKeys.Count)

# --- vietinis archyvas ---
$rows = @()
$albums = 0
foreach ($root in @((Join-Path $Base 'sutvarkyta\albums'), (Join-Path $Base 'perkelta i web'))) {
    if (-not (Test-Path $root)) { continue }
    Get-ChildItem $root -Directory -Recurse -Depth 1 -EA SilentlyContinue |
        Where-Object { Test-Path (Join-Path $_.FullName 'album.json') } | ForEach-Object {
            $albums++
            $albumName = $_.Name
            $orig = Join-Path $_.FullName 'originals'
            if (-not (Test-Path $orig)) { return }
            foreach ($f in (Get-ChildItem $orig -File -EA SilentlyContinue)) {
                if ($f.Name -notmatch '\.(jpg|jpeg|png|gif|webp|mp4|mov|avi|heic)$') { continue }
                $pair = ($f.Name -replace '^\d{4}_', '').ToLower() + '|' + $f.Length
                $status = ''
                if (-not $b2ByPair.ContainsKey($pair)) {
                    $status = 'NEIKELTA I B2'
                } else {
                    $inDb = $false
                    foreach ($k in $b2ByPair[$pair]) { if ($dbKeys.ContainsKey($k)) { $inDb = $true; break } }
                    # Windows PowerShell 5.1 neturi ternary operatoriaus.
                    if ($inDb) { $status = 'ok' } else { $status = 'B2 YRA, DB NERA' }
                }
                if ($status -ne 'ok') {
                    $rows += [pscustomobject]@{ Albumas = $albumName; Failas = $f.Name; Dydis = $f.Length; Bukle = $status }
                }
            }
        }
}
Write-Output ("vietiniu albumu: {0}" -f $albums)

# --- atgal: DB eilutes be failo B2 ---
$ghost = @($dbRows | Where-Object { -not $b2Keys.ContainsKey([string]$_.b2_key) })
Write-Output ""
$notUploaded = @($rows | Where-Object { $_.Bukle -eq 'NEIKELTA I B2' })
$notLinked   = @($rows | Where-Object { $_.Bukle -eq 'B2 YRA, DB NERA' })
Write-Output ("neikelta i B2      : {0}" -f $notUploaded.Count)
Write-Output ("B2 yra, DB nera    : {0}" -f $notLinked.Count)
Write-Output ("DB yra, B2 failo ne: {0}" -f $ghost.Count)

$byAlbum = $rows | Group-Object Albumas | Sort-Object -Property @{e = { -$_.Count }}
Write-Output ""
Write-Output "albumai su trukumais (top 20):"
$byAlbum | Select-Object -First 20 | ForEach-Object {
    $u = @($_.Group | Where-Object { $_.Bukle -eq 'NEIKELTA I B2' }).Count
    $l = @($_.Group | Where-Object { $_.Bukle -eq 'B2 YRA, DB NERA' }).Count
    Write-Output ("   {0,-52} neikelta {1,4}   nesusieta {2,4}" -f $_.Name, $u, $l)
}
$rows | Export-Csv $Out -NoTypeInformation -Encoding UTF8
Write-Output ("`nCSV: {0}" -f $Out)
