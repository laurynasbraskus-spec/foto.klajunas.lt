<#
    Perkelia albuma i kanonini B2 kelia: nukopijuoja failus, patikrina, perjungia
    DB ir tik tada isvalo sena aplanka.

    Tas pats, ka daro admin mygtukas "Perkelti i kanonini", tik is komandines
    eilutes - kai reikia sutvarkyti kelis albumus is karto.

    Kanoninis kelias skaiciuojamas canonical_prefixes.php - tuo paciu kodu, kaip
    serveryje, kad rezultatas sutaptu iki simbolio.

    Sauga: senas aplankas trinamas tik isitikinus, kad VISI failai jau yra
    naujame ir kad DB rodo i nauja kelia.
#>
param(
    [Parameter(Mandatory = $true)][int[]]$AlbumIds,
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

# B2 vardai gali tureti lietuvisku raidziu. Invoke-RestMethod su TEKSTINIU kunu
# ir "application/json" be charset siuncia ji NE UTF-8 koduote, ir serveris gauna
# iskraipyta varda: "Šironija2013.jpg" nukeliavo kaip "Sironija2013.jpg", o
# patikra po to teisingai pranese, kad failo truksta. Todel kuna visada
# siunciam kaip UTF-8 baitus.
function B2-Post([string]$url, [hashtable]$body) {
    $json = $body | ConvertTo-Json -Compress
    $bytes = [Text.Encoding]::UTF8.GetBytes($json)
    return Invoke-RestMethod -Uri $url -Method Post `
        -Headers @{ Authorization = $auth.authorizationToken } `
        -Body $bytes -ContentType 'application/json; charset=utf-8'
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

$ph = ($AlbumIds | ForEach-Object { '?' }) -join ','
$albums = Db-Read ("SELECT id,title,event_date,event_date_end,source_path FROM albums WHERE id IN ($ph)") $AlbumIds

# kanoniniai keliai - tuo paciu PHP kodu, kaip serveris
$enc = New-Object Text.UTF8Encoding($false)
$inp = @($albums | ForEach-Object {
        [pscustomobject]@{ name = [string]$_.id; title = [string]$_.title; date = [string]$_.event_date; dateEnd = [string]$_.event_date_end }
    })
$inF = Join-Path $env:TEMP 'canon_move_in.json'
$outF = Join-Path $env:TEMP 'canon_move_out.json'
[IO.File]::WriteAllText($inF, ($inp | ConvertTo-Json -Depth 4), $enc)
$tmpScript = Join-Path $env:TEMP 'canonical_prefixes.php'
Copy-Item (Join-Path $PSScriptRoot 'canonical_prefixes.php') $tmpScript -Force
& $Php $tmpScript $inF $outF | Out-Null
$parsed = ConvertFrom-Json ([IO.File]::ReadAllText($outF))
$canon = @{}
foreach ($x in @($parsed)) { $canon[[string]$x.name] = [string]$x.prefix }

foreach ($a in $albums) {
    $id = [int]$a.id
    $old = ([string]$a.source_path).TrimEnd('/')
    $new = $canon[[string]$id]
    Write-Output ("`n#{0} {1}" -f $id, $a.title)
    Write-Output ("   is: {0}" -f $old)
    Write-Output ("   i : {0}" -f $new)
    if ($new -eq '' -or $new -eq $old) { Write-Output '   jau kanoninis - praleidziam'; continue }

    $src = B2-List ($old + '/')
    if ($src.Count -eq 0) { Write-Output '   KLAIDA: senas aplankas tuscias'; continue }
    $dst = B2-List ($new + '/')
    Write-Output ("   failu sename {0}, naujame {1}" -f $src.Count, $dst.Count)
    if (-not $Execute) { continue }

    # 1) kopijuojam trukstamus
    $copied = 0; $failed = 0
    foreach ($k in $src.Keys) {
        $target = $new + $k.Substring($old.Length)
        if ($dst.ContainsKey($target) -and $dst[$target].Size -eq $src[$k].Size) { continue }
        try {
            B2-Post ($auth.apiUrl + '/b2api/v2/b2_copy_file') @{ sourceFileId = $src[$k].Id; fileName = $target } | Out-Null
            $copied++
        } catch { $failed++; Write-Output ('   kopijuoti nepavyko: ' + $target) }
    }
    Write-Output ("   nukopijuota {0}, klaidu {1}" -f $copied, $failed)
    if ($failed -gt 0) { Write-Output '   NEperjungiam DB - pirma reikia sutvarkyti kopijavima'; continue }

    # 2) patikra: visi failai turi buti naujame kelyje
    $dst = B2-List ($new + '/')
    $missing = 0
    foreach ($k in $src.Keys) {
        $target = $new + $k.Substring($old.Length)
        if (-not ($dst.ContainsKey($target) -and $dst[$target].Size -eq $src[$k].Size)) { $missing++ }
    }
    if ($missing -gt 0) { Write-Output ("   NEperjungiam DB: naujame kelyje truksta {0} failu" -f $missing); continue }

    # 3) DB
    $photos = Db-Read 'SELECT id,b2_key FROM photos WHERE album_id=?' @($id)
    foreach ($p in $photos) {
        $k = [string]$p.b2_key
        if (-not $k.StartsWith($old)) { continue }
        Db-Write 'UPDATE photos SET b2_key=? WHERE id=?' @(($new + $k.Substring($old.Length)), [int]$p.id) | Out-Null
    }
    Db-Write 'UPDATE albums SET source_path=?, updated_at=NOW() WHERE id=?' @($new, $id) | Out-Null
    Write-Output ("   DB perjungta ({0} nuotrauku)" -f $photos.Count)

    # 4) senas aplankas - tik dabar
    $check = Db-Read 'SELECT source_path FROM albums WHERE id=?' @($id)
    if (([string]$check[0].source_path).TrimEnd('/') -ne $new) { Write-Output '   DB kelias neatitinka - seno aplanko netrinam'; continue }
    $del = 0; $delFail = @()
    foreach ($k in $src.Keys) {
        try {
            B2-Post ($auth.apiUrl + '/b2api/v2/b2_delete_file_version') @{ fileId = $src[$k].Id; fileName = $k } | Out-Null
            $del++
        } catch {
            # Anksciau klaida buvo praryjama tyliai, ir senas aplankas likdavo su
            # vienu kitu failu, o ataskaita rode svaru rezultata.
            $delFail += $k
        }
    }
    Write-Output ("   senas aplankas isvalytas: {0} failu" -f $del)
    if ($delFail.Count) {
        Write-Output ("   NEISTRINTA {0} failu - aplankas liko:" -f $delFail.Count)
        $delFail | Select-Object -First 5 | ForEach-Object { Write-Output ('      ' + $_) }
    }
}
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)" }
