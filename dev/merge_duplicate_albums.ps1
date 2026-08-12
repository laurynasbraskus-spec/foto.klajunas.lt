<#
    Sujungia albumu dublikatus: tas pats renginys DB yra du kartus, nes failai
    guli ir senu, ir kanoniniu keliu.

    Poros nustatytos lyginant nuotrauku rinkinius (failu vardai be numeracijos
    priesagos) - ne pavadinimus ir ne datas.

    Tvarka grieztai tokia:
      1) patikrinam, kad LIEKANCIO albumo visos nuotraukos B2 tikrai yra;
      2) tik tada trinam DB irasa, kuris nebereikalingas;
      3) B2 failai NEtrinami cia - tam yra atskiras zingsnis, kad butu galima
         pirma pamatyti rezultata galerijoje.

    Pries trinant albuma nuimamos nuorodos i ji: virselio nuotrauka, zymos,
    zip atsisiuntimai ir vaiku parent_id.
#>
param(
    [string]$ConfigPhp = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\server\b2-config.php',
    # Poros formatu "liekantis:trinamas", pvz. -Pairs '493:26','505:15'
    [string[]]$Pairs = @(),
    # Ar liekanti albuma paskelbti (kai paliekamas naujas juodrastis)
    [switch]$Publish,
    [switch]$Execute
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

# DEMESIO: vidinis kintamasis NEGALI vadintis $pairs - PowerShellyje tai tas
# pats kintamasis kaip parametras [string[]]$Pairs, o tipo apribojimas paverstu
# jo turini tekstu ir ciklas neivyktu.
# paliekam = kanoninis kelias (su datos raktu), trinam = senas
$pairList = @(
    @{ Keep = 97;  Drop = 4   }
    @{ Keep = 100; Drop = 41  }
    @{ Keep = 101; Drop = 22  }
    @{ Keep = 70;  Drop = 21  }
    @{ Keep = 74;  Drop = 50  }
    @{ Keep = 85;  Drop = 16  }
    @{ Keep = 71;  Drop = 51  }
)
if ($Pairs.Count) {
    $pairList = @()
    foreach ($s in $Pairs) {
        $ab = $s -split ':'
        if ($ab.Count -ne 2) { Write-Output ("bloga pora: " + $s); exit 1 }
        $pairList += @{ Keep = [int]$ab[0]; Drop = [int]$ab[1] }
    }
}

# Trinamas irasas gali tureti aprasymu ir nuorodu, kuriu liekantis dar neturi -
# tokius laukus persinesam, kad informacija nedingtu kartu su irasu.
$carry = @('subtitle', 'description', 'location_name', 'author_name', 'copyright_text',
    'event_date', 'event_date_end', 'sport_type', 'country_code', 'seo_title',
    'seo_description', 'dbsportas_url', 'klajunas_url', 'other_url', 'notes_internal')

$cfg = Get-Content $ConfigPhp -Raw
$kid = [regex]::Match($cfg, "B2_KEY_ID',\s*'([^']+)").Groups[1].Value
$key = [regex]::Match($cfg, "B2_APP_KEY',\s*'([^']+)").Groups[1].Value
$bid = [regex]::Match($cfg, "B2_BUCKET_ID',\s*'([^']+)").Groups[1].Value
$auth = Invoke-RestMethod -Uri 'https://api.backblazeb2.com/b2api/v2/b2_authorize_account' `
    -Headers @{ Authorization = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("$kid`:$key")) }
$all = @{}; $start = $null
do {
    $body = @{ bucketId = $bid; maxFileCount = 10000 }
    if ($start) { $body.startFileName = $start }
    $r = Invoke-RestMethod -Uri ($auth.apiUrl + '/b2api/v2/b2_list_file_names') -Method Post `
        -Headers @{ Authorization = $auth.authorizationToken } -Body ($body | ConvertTo-Json) -ContentType 'application/json'
    foreach ($f in $r.files) { $all[[string]$f.fileName] = $true }
    $start = $r.nextFileName
} while ($start)
Write-Output ("B2 failu: {0}" -f $all.Count)

$ok = @(); $blocked = @()
foreach ($p in $pairList) {
    $keep = Db-Read 'SELECT id,title,source_path FROM albums WHERE id = ?' @($p.Keep)
    $drop = Db-Read 'SELECT id,title,source_path FROM albums WHERE id = ?' @($p.Drop)
    if (-not $keep.Count -or -not $drop.Count) { $blocked += ("#{0}/#{1}: vieno is albumu nebera" -f $p.Keep, $p.Drop); continue }

    $kp = Db-Read 'SELECT b2_key FROM photos WHERE album_id = ?' @($p.Keep)
    $missing = @($kp | Where-Object { -not $all.ContainsKey([string]$_.b2_key) })
    if ($missing.Count) {
        $blocked += ("#{0} ({1}): {2} nuotrauku failu B2 nera - netrinam poros" -f $p.Keep, $keep[0].title, $missing.Count)
        continue
    }
    $dp = Db-Read 'SELECT COUNT(*) c FROM photos WHERE album_id = ?' @($p.Drop)
    Write-Output ("paliekam #{0,-4} {1,3} nuotr.  {2}" -f $p.Keep, $kp.Count, $keep[0].source_path)
    Write-Output ("  trinam #{0,-4} {1,3} nuotr.  {2}" -f $p.Drop, $dp[0].c, $drop[0].source_path)
    $ok += $p
}
Write-Output ""
Write-Output ("sujungsim poru: {0}" -f $ok.Count)
$blocked | ForEach-Object { Write-Output ('   SUSTABDYTA: ' + $_) }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$done = 0
foreach ($p in $ok) {
    $d = [int]$p.Drop

    # 1) persinesam laukus, kuriu liekantis irasas dar neturi
    $oldRow = Db-Read ('SELECT ' + ($carry -join ',') + ' FROM albums WHERE id = ?') @($d)
    $newRow = Db-Read ('SELECT ' + ($carry -join ',') + ' FROM albums WHERE id = ?') @([int]$p.Keep)
    $set = @{}
    foreach ($c in $carry) {
        $ov = [string]$oldRow[0].$c
        $nv = [string]$newRow[0].$c
        if ($ov -ne '' -and $nv -eq '') { $set[$c] = $ov }
    }
    if ($set.Count) {
        $k = @($set.Keys)
        $usql = 'UPDATE albums SET ' + (($k | ForEach-Object { "$_ = ?" }) -join ', ') + ', updated_at = NOW() WHERE id = ?'
        Db-Write $usql (@($k | ForEach-Object { $set[$_] }) + @([int]$p.Keep)) | Out-Null
        Write-Output ("   #{0}: persinesta lauku {1}" -f $p.Keep, $set.Count)
    }
    if ($Publish) { Db-Write 'UPDATE albums SET visibility = ?, updated_at = NOW() WHERE id = ?' @('published', [int]$p.Keep) | Out-Null }

    # nuorodos i trinama albuma - pirma nuimam, kitaip isorinis raktas neleis
    Db-Write 'UPDATE albums SET cover_photo_id = NULL WHERE id = ?' @($d) | Out-Null
    Db-Write 'UPDATE albums SET parent_id = NULL WHERE parent_id = ?' @($d) | Out-Null
    Db-Write 'DELETE FROM album_tags WHERE album_id = ?' @($d) | Out-Null
    Db-Write 'DELETE FROM zip_downloads WHERE album_id = ?' @($d) | Out-Null
    Db-Write 'DELETE FROM photo_tags WHERE photo_id IN (SELECT id FROM photos WHERE album_id = ?)' @($d) | Out-Null
    Db-Write 'DELETE FROM photos WHERE album_id = ?' @($d) | Out-Null
    Db-Write 'DELETE FROM albums WHERE id = ?' @($d) | Out-Null
    $done++
}
Write-Output ("`nsujungta poru: {0}" -f $done)
$left = Db-Read 'SELECT COUNT(*) c FROM albums'
Write-Output ("albumu liko: {0}" -f $left[0].c)
