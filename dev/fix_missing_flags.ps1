<#
    Nuima klaidingas "nuotrauka dingusi" zymes.

    B2 Sync pazymejo 495 nuotraukas kaip dingusias, nors ju failai B2 yra -
    zyme atsirado todel, kad albumo source_path rode i viena kelia, o failai
    guli kitame. Del sios zymes 30 albumu viesoje galerijoje liko tusti.

    Skriptas NIEKO nespeja: kiekvienai zymetai nuotraukai patikrinama, ar jos
    b2_key tikrai yra bucket'e. Zyme nuimama tik toms, kurios rastos. Jei bent
    viena tikrai dingusi - ji lieka pazymeta ir isvardijama.
#>
param(
    [string]$ConfigPhp = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\server\b2-config.php',
    [switch]$Execute
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

$flagged = Db-Read 'SELECT id, album_id, b2_key FROM photos WHERE is_missing = 1'
Write-Output ("pazymeta dingusiomis: {0}" -f $flagged.Count)
if (-not $flagged.Count) { Write-Output 'nera ka taisyti.'; exit 0 }

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

$found = @($flagged | Where-Object { $all.ContainsKey([string]$_.b2_key) })
$gone  = @($flagged | Where-Object { -not $all.ContainsKey([string]$_.b2_key) })
Write-Output ("failas B2 yra : {0}" -f $found.Count)
Write-Output ("tikrai dingus : {0}" -f $gone.Count)
$gone | Select-Object -First 20 | ForEach-Object { Write-Output ('   ' + $_.b2_key) }

if (-not $found.Count) { Write-Output 'niekas nesikeicia.'; exit 0 }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

# Atnaujinam tik patikrintus id - ne "visus, kur is_missing=1". Taip net jei
# tarp patikrinimo ir irasymo kas nors pasikeistu, paliesim tik tuos, kuriuos
# patys patikrinom.
$ids = @($found | ForEach-Object { [int]$_.id })
$done = 0
for ($i = 0; $i -lt $ids.Count; $i += 200) {
    $chunk = $ids[$i..([Math]::Min($i + 199, $ids.Count - 1))]
    $ph = ($chunk | ForEach-Object { '?' }) -join ','
    $done += Db-Write ("UPDATE photos SET is_missing = 0 WHERE is_missing = 1 AND id IN ($ph)") $chunk
}
Write-Output ("`nzymiu nuimta: {0}" -f $done)
$after = Db-Read 'SELECT COUNT(*) c FROM photos WHERE is_missing = 1'
Write-Output ("liko pazymetu: {0}" -f $after[0].c)
