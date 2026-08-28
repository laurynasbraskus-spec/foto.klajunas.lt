<#
    Perkuria b2_prefixes.json - sarasa B2 aplanku, kuriuose tikrai yra failu.

    Si sarasa naudoja apply_albums_to_db.ps1 (-Insert): be jo butu sukurti
    albumai, kuriu failu B2 dar nera, ir galerijoje atsirastu tusti irasai.
    Iki siol sarasas buvo sudetas vienkartiniu budu, todel po kiekvieno kelimo
    likdavo pasenes.
#>
param(
    [string]$ConfigPhp = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\server\b2-config.php',
    [string]$Out = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\dev\b2_prefixes.json'
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$cfg = Get-Content $ConfigPhp -Raw
$kid = [regex]::Match($cfg, "B2_KEY_ID',\s*'([^']+)").Groups[1].Value
$key = [regex]::Match($cfg, "B2_APP_KEY',\s*'([^']+)").Groups[1].Value
$bid = [regex]::Match($cfg, "B2_BUCKET_ID',\s*'([^']+)").Groups[1].Value
$auth = Invoke-RestMethod -Uri 'https://api.backblazeb2.com/b2api/v2/b2_authorize_account' `
    -Headers @{ Authorization = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("$kid`:$key")) }

$folders = @{}
$start = $null
do {
    $body = @{ bucketId = $bid; prefix = 'albums/'; maxFileCount = 10000 }
    if ($start) { $body.startFileName = $start }
    $r = Invoke-RestMethod -Uri ($auth.apiUrl + '/b2api/v2/b2_list_file_names') -Method Post `
        -Headers @{ Authorization = $auth.authorizationToken } -Body ($body | ConvertTo-Json) -ContentType 'application/json'
    foreach ($f in $r.files) {
        $p = ([string]$f.fileName) -split '/'
        if ($p.Count -lt 4) { continue }
        $folders[$p[0] + '/' + $p[1] + '/' + $p[2]] = $true
    }
    $start = $r.nextFileName
} while ($start)

# Nulinis rezultatas beveik visada reiskia sugedusia uzklausa, ne tuscia
# kibira. Perrasyti sarasa tokiu atveju reikstu sunaikinti vieninteli
# apsauginį patikrinima pries INSERT.
if ($folders.Count -lt 2) { Write-Output 'KLAIDA: rasta per mazai aplanku - nerasom.'; exit 1 }

$enc = New-Object Text.UTF8Encoding($false)
$list = @($folders.Keys | Sort-Object)
[IO.File]::WriteAllText($Out, ($list | ConvertTo-Json -Depth 2), $enc)
Write-Output ("B2 aplanku: {0}" -f $list.Count)
Write-Output ("irasyta: {0}" -f $Out)
