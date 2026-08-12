<#
    Kelia archyvo albumus i B2 IS KARTO i kanoninius kelius.

    Kanoninis kelias imamas is prefix_out.json, kuri suskaiciuoja
    canonical_prefixes.php - tuo paciu kodu, kaip serveris. Perrasyti ta logika
    cia butu rizikinga: menkiausias skirtumas reikstu, kad admin ka tik ikelta
    aplanka laikytu "ne kanoniniu" ir reiketu migracijos.

    Keliama tik originals/ ir metadata/. album.json lieka vietoje.

    PRALEIDZIAMA:
      - albumai be datos (kanoninis kelias gautu einamuosius metus - klaidinga)
      - dateConfidence = google-ikelimas (data neirodyta; pervadinus reiktu migruoti)
      - failai, kurie B2 jau yra (tikrinama pagal varda ir dydi)

    Be -Execute tik parodo plana.
#>
param(
    [string]$PrefixJson = '',
    [string]$ConfigPhp  = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\server\b2-config.php',
    [switch]$Execute,
    [switch]$IncludeUnconfirmed,
    [int]$MaxAlbums = 0,
    [string]$LogFile = ''
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
if ($LogFile) { try { Start-Transcript -Path $LogFile -Force | Out-Null } catch {} }
function Done([int]$c) { if ($LogFile) { try { Stop-Transcript | Out-Null } catch {} }; exit $c }

if ($PrefixJson -eq '') { Write-Output 'Nurodyk -PrefixJson (prefix_out.json)'; Done 1 }

# ConvertFrom-Json rezultata BUTINA pirma priskirti ir tik tada vynioti: @()
# apie pati iskvietima grazina viena objekta su masyvinemis savybemis.
$parsed = ConvertFrom-Json ([IO.File]::ReadAllText($PrefixJson))
$albums = @($parsed)
if ($albums.Count -lt 2) { Write-Output 'KLAIDA: JSON neissiskaide i atskirus albumus.'; Done 1 }

$cfg = Get-Content $ConfigPhp -Raw
$kid = [regex]::Match($cfg, "B2_KEY_ID',\s*'([^']+)").Groups[1].Value
$key = [regex]::Match($cfg, "B2_APP_KEY',\s*'([^']+)").Groups[1].Value
$bid = [regex]::Match($cfg, "B2_BUCKET_ID',\s*'([^']+)").Groups[1].Value
if (-not $kid -or -not $key -or -not $bid) { Write-Output 'KLAIDA: nepavyko nuskaityti B2 raktu.'; Done 1 }

$auth = Invoke-RestMethod -Uri 'https://api.backblazeb2.com/b2api/v2/b2_authorize_account' `
    -Headers @{ Authorization = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("$kid`:$key")) }

function Get-B2Existing([string]$prefix) {
    $map = @{}; $start = $null
    do {
        $body = @{ bucketId = $bid; prefix = ($prefix.TrimEnd('/') + '/'); maxFileCount = 1000 }
        if ($start) { $body.startFileName = $start }
        $r = Invoke-RestMethod -Uri ($auth.apiUrl + '/b2api/v2/b2_list_file_names') -Method Post `
            -Headers @{ Authorization = $auth.authorizationToken } -Body ($body | ConvertTo-Json) -ContentType 'application/json'
        foreach ($f in $r.files) { $map[$f.fileName] = [int64]$f.contentLength }
        $start = $r.nextFileName
    } while ($start)
    return $map
}
$uploadUrl = $null
function Get-UploadUrl {
    $script:uploadUrl = Invoke-RestMethod -Uri ($auth.apiUrl + '/b2api/v2/b2_get_upload_url') -Method Post `
        -Headers @{ Authorization = $auth.authorizationToken } -Body (@{ bucketId = $bid } | ConvertTo-Json) -ContentType 'application/json'
}
function Send-File([string]$local, [string]$remote) {
    $bytes = [IO.File]::ReadAllBytes($local)
    $sha = ([BitConverter]::ToString((New-Object Security.Cryptography.SHA1Managed).ComputeHash($bytes)) -replace '-', '').ToLower()
    $enc = ($remote -split '/' | ForEach-Object { [Uri]::EscapeDataString($_) }) -join '/'
    for ($try = 1; $try -le 3; $try++) {
        if (-not $script:uploadUrl) { Get-UploadUrl }
        try {
            Invoke-RestMethod -Uri $script:uploadUrl.uploadUrl -Method Post -Body $bytes -Headers @{
                Authorization       = $script:uploadUrl.authorizationToken
                'X-Bz-File-Name'    = $enc
                'Content-Type'      = 'b2/x-auto'
                'X-Bz-Content-Sha1' = $sha
            } | Out-Null
            return $true
        } catch {
            # ikelimo URL galioja ribotai - gavus klaida imam nauja
            $script:uploadUrl = $null
            if ($try -eq 3) { throw }
            Start-Sleep -Seconds (2 * $try)
        }
    }
    return $false
}

# --- atranka ---
$plan = @()
$skipNoDate = 0; $skipWeak = 0
foreach ($a in $albums) {
    if ([string]$a.date -notmatch '^\d{4}-\d{2}-\d{2}$') { $skipNoDate++; continue }
    # Baltasis sarasas, ne juodasis: keliam tik tuos, kuriu data tikrai irodyta -
    # 'tyrimas' (klubo archyvas / dbsportas) arba 'exif' (pacios nuotraukos).
    # Google laikas, ikelimo zyma ir 'nezinoma' lieka nuosaly: jei ju datos
    # veliau taisysis, pasikeistu kanoninis kelias ir reiketu migracijos.
    if (-not $IncludeUnconfirmed -and @('tyrimas', 'exif') -notcontains [string]$a.conf) { $skipWeak++; continue }
    $files = @()
    foreach ($sub in @('originals', 'metadata')) {
        $d = Join-Path ([string]$a.path) $sub
        if (-not (Test-Path $d)) { continue }
        foreach ($f in (Get-ChildItem $d -File -EA SilentlyContinue)) {
            $files += [pscustomobject]@{ Local = $f.FullName; Remote = ([string]$a.prefix + '/' + $sub + '/' + $f.Name); Size = $f.Length }
        }
    }
    if (-not $files.Count) { continue }
    $plan += [pscustomobject]@{ Name = [string]$a.name; Prefix = [string]$a.prefix; Files = $files
        Bytes = ($files | Measure-Object Size -Sum).Sum }
}
if ($MaxAlbums -gt 0 -and $plan.Count -gt $MaxAlbums) { $plan = $plan[0..($MaxAlbums - 1)] }

$totalFiles = ($plan | ForEach-Object { $_.Files.Count } | Measure-Object -Sum).Sum
$totalBytes = ($plan | Measure-Object Bytes -Sum).Sum
Write-Output ("Albumu plane      : {0}" -f $plan.Count)
Write-Output ("  praleista be datos      : {0}" -f $skipNoDate)
Write-Output ("  praleista nepatvirtintu : {0}" -f $skipWeak)
Write-Output ("Failu             : {0:N0}" -f $totalFiles)
Write-Output ("Dydis             : {0:N2} GB" -f ($totalBytes / 1GB))
Write-Output ''
if (-not $Execute) {
    $plan | Select-Object -First 10 | ForEach-Object { Write-Output ("  {0,4} failai  {1}" -f $_.Files.Count, $_.Prefix) }
    Write-Output ''
    Write-Output 'BANDOMASIS REZIMAS - i B2 nieko nekelta. Pridek -Execute'
    Done 0
}

$okFiles = 0; $skipFiles = 0; $failed = @(); $sent = 0; $i = 0
foreach ($al in $plan) {
    $i++
    Write-Output ("[{0}/{1}] {2}" -f $i, $plan.Count, $al.Prefix)
    $existing = Get-B2Existing $al.Prefix
    foreach ($f in $al.Files) {
        if ($existing.ContainsKey($f.Remote) -and $existing[$f.Remote] -eq $f.Size) { $skipFiles++; continue }
        try {
            Send-File $f.Local $f.Remote | Out-Null
            $okFiles++; $sent += $f.Size
        } catch {
            $failed += [pscustomobject]@{ File = $f.Remote; Klaida = $_.Exception.Message }
            Write-Output ("     NEPAVYKO: {0} -- {1}" -f $f.Remote, $_.Exception.Message)
        }
    }
    Write-Output ("     ikelta {0}, praleista {1}, is viso issiusta {2:N2} GB" -f $okFiles, $skipFiles, ($sent / 1GB))
}
Write-Output ''
Write-Output ("Ikelta failu   : {0:N0}" -f $okFiles)
Write-Output ("Jau buvo       : {0:N0}" -f $skipFiles)
Write-Output ("Nepavyko       : {0}" -f $failed.Count)
if ($failed.Count) { $failed | Select-Object -First 20 | ForEach-Object { Write-Output ("   " + $_.File + " -- " + $_.Klaida) } }
Done 0
