<#
    Isvalo B2 aplankus, i kuriuos DB nebeturi jokios nuorodos.

    Trinama TIK tada, kai visos aplanko nuotraukos turi tiksliai toki pati
    atitikmeni (vardas + dydis) kitame aplanke, kuris DB tebera naudojamas.
    Kitaip tariant, trinam tik kopijas - niekada vienintele egzemplioriu.

    Toks buvo praeitos klaidos mechanizmas: aplankas atrode "nenaudojamas",
    nes DB rode i kitokia kelia, ir po trynimo trys nuotraukos liko be failu.
    Todel cia lyginama ne pagal kelius, o pagal patys failus.
#>
param(
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

# Kuna siunciam UTF-8 baitais: su tekstiniu kunu ir "application/json" be
# charset lietuviskos raides varde iskraipomos ir B2 gauna kita faila
# ("Šironija.jpg" -> "Sironija.jpg"). Trinant tai reikstu, kad failas lieka.
function B2-Post([string]$url, [hashtable]$body) {
    $json = $body | ConvertTo-Json -Compress
    return Invoke-RestMethod -Uri $url -Method Post `
        -Headers @{ Authorization = $auth.authorizationToken } `
        -Body ([Text.Encoding]::UTF8.GetBytes($json)) -ContentType 'application/json; charset=utf-8'
}

$files = @{}
$start = $null
do {
    $body = @{ bucketId = $bid; prefix = 'albums/'; maxFileCount = 10000 }
    if ($start) { $body.startFileName = $start }
    $r = B2-Post ($auth.apiUrl + '/b2api/v2/b2_list_file_names') $body
    foreach ($f in $r.files) { $files[[string]$f.fileName] = [int64]$f.contentLength }
    $start = $r.nextFileName
} while ($start)
Write-Output ("B2 failu: {0}" -f $files.Count)

# aplankas -> failai; ir kurie aplankai DB naudojami
$folderFiles = @{}
foreach ($k in $files.Keys) {
    $p = $k -split '/'
    if ($p.Count -lt 4) { continue }
    $fold = $p[0] + '/' + $p[1] + '/' + $p[2]
    if (-not $folderFiles.ContainsKey($fold)) { $folderFiles[$fold] = @{} }
    $folderFiles[$fold][$p[-1]] = $files[$k]
}
$used = @{}
$dbPhotos = Db-Read 'SELECT b2_key FROM photos'
foreach ($p in $dbPhotos) {
    $seg = ([string]$p.b2_key) -split '/'
    if ($seg.Count -ge 3) { $used[$seg[0] + '/' + $seg[1] + '/' + $seg[2]] = $true }
}
Write-Output ("aplanku is viso: {0}   DB naudojami: {1}" -f $folderFiles.Count, $used.Count)

$orphans = @($folderFiles.Keys | Where-Object { -not $used.ContainsKey($_) } | Sort-Object)
Write-Output ("be nuorodos is DB: {0}" -f $orphans.Count)

$delete = @(); $keep = @()
foreach ($o in $orphans) {
    $mine = $folderFiles[$o]
    # nuotraukos (ne metadata) - tik ju kopijas privalom rasti
    $imgs = @($mine.Keys | Where-Object { $_ -match '\.(jpg|jpeg|png|gif|mp4|mov|avi|heic)$' })
    if (-not $imgs.Count) { $delete += [pscustomobject]@{ Path = $o; Kodel = 'nera nuotrauku (tik metaduomenys)' }; continue }

    $twin = $null
    foreach ($u in $used.Keys) {
        $their = $folderFiles[$u]
        if (-not $their) { continue }
        # Vardai lyginami NEPAISANT raidziu registro ir numeracijos priesagos:
        # ta pati nuotrauka viename aplanke buna "0001_6240313.jpg", kitame
        # "_6240313.JPG". Dydis privalo sutapti tiksliai - jis ir yra irodymas,
        # kad tai tas pats failas, o ne panasiai pavadintas kitas.
        $theirIdx = @{}
        $theirBySize = @{}
        foreach ($tn in $their.Keys) {
            $k = ($tn -replace '^\d{4}_', '').TrimStart('_').ToLower() + '|' + $their[$tn]
            $theirIdx[$k] = $true
            $sz = [string]$their[$tn]
            if (-not $theirBySize.ContainsKey($sz)) { $theirBySize[$sz] = 0 }
            $theirBySize[$sz]++
        }
        $mineBySize = @{}
        foreach ($n in $mine.Keys) {
            $sz = [string]$mine[$n]
            if (-not $mineBySize.ContainsKey($sz)) { $mineBySize[$sz] = 0 }
            $mineBySize[$sz]++
        }
        $allFound = $true
        foreach ($n in $imgs) {
            $k = ($n -replace '^\d{4}_', '').TrimStart('_').ToLower() + '|' + $mine[$n]
            if ($theirIdx.ContainsKey($k)) { continue }
            # Vardas gali buti pakeistas ("202.jpg" -> "202-Pries-starta.jpg").
            # Tada tapatybe irodo dydis, bet TIK jei tokio dydzio failas abiejuose
            # aplankuose yra lygiai vienas - kitaip nebutu aisku, kuris kuriam.
            $sz = [string]$mine[$n]
            if ($theirBySize.ContainsKey($sz) -and $theirBySize[$sz] -eq 1 -and $mineBySize[$sz] -eq 1) { continue }
            $allFound = $false; break
        }
        if ($allFound) { $twin = $u; break }
    }
    if ($twin) { $delete += [pscustomobject]@{ Path = $o; Kodel = ('kopija yra ' + $twin) } }
    else { $keep += [pscustomobject]@{ Path = $o; N = $imgs.Count } }
}

Write-Output ""
Write-Output ("TRINSIM (turi kopija kitur): {0}" -f $delete.Count)
$delete | ForEach-Object { Write-Output ("   {0}`n      {1}" -f $_.Path, $_.Kodel) }
Write-Output ""
Write-Output ("PALIEKAM (kopijos nerasta): {0}" -f $keep.Count)
$keep | ForEach-Object { Write-Output ("   {0,4} nuotr.  {1}" -f $_.N, $_.Path) }

if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }
if (-not $delete.Count) { exit 0 }

$del = 0; $err = 0
foreach ($d in $delete) {
    $start = $null
    do {
        $body = @{ bucketId = $bid; prefix = ($d.Path + '/'); maxFileCount = 1000 }
        if ($start) { $body.startFileName = $start }
        $r = B2-Post ($auth.apiUrl + '/b2api/v2/b2_list_file_versions') $body
        foreach ($f in $r.files) {
            try {
                B2-Post ($auth.apiUrl + '/b2api/v2/b2_delete_file_version') @{ fileId = $f.fileId; fileName = $f.fileName } | Out-Null
                $del++
            } catch { $err++ }
        }
        $start = $r.nextFileName
    } while ($start)
}
Write-Output ("`nistrinta failu: {0}   klaidu: {1}" -f $del, $err)
