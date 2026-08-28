<#
    Kelia albumus i B2 kanoniniais keliais ir, TIK isitikines, kad viskas vietoje,
    perkelia ju aplankus is "sutvarkyta" i "perkelta i web".

    Patikra pries perkeliant yra grieztа: B2 turi tureti lygiai tuos pacius failus
    tokiais paciais dydziais kaip vietinis originals/ ir metadata/. Neatitikus bent
    vienam failui albumas NEperkeliamas ir lieka vietoje.

    Dirbama etapais: po kiekvieno albumo rasoma eilute i loga, tad procesa galima
    nutraukti ir testi - jau ikelti failai praleidziami, o perkelti albumai
    nebedalyvauja (ju nebera saltinio aplanke).
#>
param(
    [string]$PrefixJson = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\dev\prefix_out.json',
    [string]$MoveTo     = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\perkelta i web',
    [string]$ConfigPhp  = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\server\b2-config.php',
    [int]$MaxAlbums     = 0,
    # Albumai, kurie nera vienas renginys ("nepriskirtos renginiui nuotraukos",
    # MIX ir pan.), i B2 NEkeliami savaime - jiems reikia atskiro sprendimo.
    # Su siuo jungikliu jie itraukiami samoningai.
    [switch]$AllowUnassigned,
    # Kiek dienu gali trukti vienas renginys. Ilgesnis tarpas beveik visada
    # reiskia, kad i viena aplanka sumesti keli skirtingi ivykiai.
    [int]$MaxEventDays  = 45,
    [switch]$NoMove,
    [string]$LogFile    = ''
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
if ($LogFile) { try { Start-Transcript -Path $LogFile -Force | Out-Null } catch {} }
function Done([int]$c) { if ($LogFile) { try { Stop-Transcript | Out-Null } catch {} }; exit $c }

# ConvertFrom-Json rezultata BUTINA pirma priskirti ir tik tada vynioti.
$parsed = ConvertFrom-Json ([IO.File]::ReadAllText($PrefixJson))
$albums = @($parsed)
if ($albums.Count -lt 2) { Write-Output 'KLAIDA: JSON neissiskaide.'; Done 1 }

$cfg = Get-Content $ConfigPhp -Raw
$kid = [regex]::Match($cfg, "B2_KEY_ID',\s*'([^']+)").Groups[1].Value
$key = [regex]::Match($cfg, "B2_APP_KEY',\s*'([^']+)").Groups[1].Value
$bid = [regex]::Match($cfg, "B2_BUCKET_ID',\s*'([^']+)").Groups[1].Value
$auth = Invoke-RestMethod -Uri 'https://api.backblazeb2.com/b2api/v2/b2_authorize_account' `
    -Headers @{ Authorization = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("$kid`:$key")) }

function Get-B2Map([string]$prefix) {
    $map = @{}; $start = $null
    do {
        $body = @{ bucketId = $bid; prefix = ($prefix.TrimEnd('/') + '/'); maxFileCount = 1000 }
        if ($start) { $body.startFileName = $start }
        $r = Invoke-RestMethod -Uri ($auth.apiUrl + '/b2api/v2/b2_list_file_names') -Method Post `
            -Headers @{ Authorization = $auth.authorizationToken } -Body ($body | ConvertTo-Json) -ContentType 'application/json'
        foreach ($f in $r.files) { $map[[string]$f.fileName] = [int64]$f.contentLength }
        $start = $r.nextFileName
    } while ($start)
    return $map
}
# B2 nuotraukos gali guleti su rikiavimo priesaga ("0001_DSC09612.jpg"), o
# vietinis archyvas turi originalu varda ("DSC09612.JPG"). Lyginant vien varda
# toks failas atrodo neikeltas, ir jis ikeliamas antra karta - 2026-08-27 taip
# atsirado 474 kopijos 23 albumuose. Todel atitikmens ieskom ir su priesaga.
function Find-Remote([hashtable]$map, [string]$remote, [int64]$size) {
    if ($map.ContainsKey($remote) -and $map[$remote] -eq $size) { return $remote }
    $i = $remote.LastIndexOf('/')
    if ($i -lt 0) { return $null }
    $dir = $remote.Substring(0, $i); $name = $remote.Substring($i + 1)
    if ($name -match '^\d{4}_') { return $null }
    foreach ($k in $map.Keys) {
        if (-not $k.StartsWith($dir + '/')) { continue }
        $kn = $k.Substring($dir.Length + 1)
        if ($kn -notmatch '^\d{4}_(.+)$') { continue }
        if ($Matches[1] -ieq $name -and $map[$k] -eq $size) { return $k }
    }
    return $null
}

$uploadUrl = $null
function Send-File([string]$local, [string]$remote) {
    $bytes = [IO.File]::ReadAllBytes($local)
    $sha = ([BitConverter]::ToString((New-Object Security.Cryptography.SHA1Managed).ComputeHash($bytes)) -replace '-', '').ToLower()
    $enc = ($remote -split '/' | ForEach-Object { [Uri]::EscapeDataString($_) }) -join '/'
    for ($try = 1; $try -le 3; $try++) {
        if (-not $script:uploadUrl) {
            $script:uploadUrl = Invoke-RestMethod -Uri ($auth.apiUrl + '/b2api/v2/b2_get_upload_url') -Method Post `
                -Headers @{ Authorization = $auth.authorizationToken } -Body (@{ bucketId = $bid } | ConvertTo-Json) -ContentType 'application/json'
        }
        try {
            Invoke-RestMethod -Uri $script:uploadUrl.uploadUrl -Method Post -Body $bytes -Headers @{
                Authorization       = $script:uploadUrl.authorizationToken
                'X-Bz-File-Name'    = $enc
                'Content-Type'      = 'b2/x-auto'
                'X-Bz-Content-Sha1' = $sha
            } | Out-Null
            return
        } catch {
            $script:uploadUrl = $null
            if ($try -eq 3) { throw }
            Start-Sleep -Seconds (2 * $try)
        }
    }
}

# Ne vieno renginio albumas atpazistamas dvejopai: is pavadinimo ir is datu
# tarpo. Toks albumas galerijoje atrodo kaip renginys, kurio nebuvo, todel
# jis niekada nekeliamas savaime.
function Is-Unassigned($album, [int]$maxDays) {
    $name = ([string]$album.name + ' ' + [string]$album.title)
    if ($name -match '(?i)nepriskirt|(^|[^a-z])mix([^a-z]|$)|personalij|plakat|grafika') { return $true }
    $d = [string]$album.date
    $e = [string]$album.dateEnd
    if ($d -match '^\d{4}-\d{2}-\d{2}$' -and $e -match '^\d{4}-\d{2}-\d{2}$') {
        if (([datetime]$e - [datetime]$d).Days -gt $maxDays) { return $true }
    }
    return $false
}

$plan = @()
$skippedUnassigned = @()
foreach ($a in $albums) {
    if ([string]$a.date -notmatch '^\d{4}-\d{2}-\d{2}$') { continue }
    if (@('tyrimas', 'exif') -notcontains [string]$a.conf) { continue }
    if (-not (Test-Path ([string]$a.path))) { continue }   # jau perkeltas
    if (-not $AllowUnassigned -and (Is-Unassigned $a $MaxEventDays)) {
        $skippedUnassigned += [string]$a.name
        continue
    }
    $files = @()
    foreach ($sub in @('originals', 'metadata')) {
        $d = Join-Path ([string]$a.path) $sub
        if (-not (Test-Path $d)) { continue }
        foreach ($f in (Get-ChildItem $d -File -EA SilentlyContinue)) {
            $files += [pscustomobject]@{ Local = $f.FullName; Remote = ([string]$a.prefix + '/' + $sub + '/' + $f.Name); Size = $f.Length }
        }
    }
    if (-not $files.Count) { continue }
    $plan += [pscustomobject]@{ Name = [string]$a.name; Path = [string]$a.path; Prefix = [string]$a.prefix; Files = $files }
}
if ($MaxAlbums -gt 0 -and $plan.Count -gt $MaxAlbums) { $plan = $plan[0..($MaxAlbums - 1)] }
Write-Output ("Albumu darbui: {0}   failu: {1:N0}" -f $plan.Count, (($plan | ForEach-Object { $_.Files.Count } | Measure-Object -Sum).Sum))
if ($skippedUnassigned.Count) {
    Write-Output ""
    Write-Output ("NEkeliami - ne vieno renginio albumai ({0}). Jiems reikia atskiro sprendimo," -f $skippedUnassigned.Count)
    Write-Output "arba paleisk su -AllowUnassigned, jei tikrai norima juos ikelti:"
    $skippedUnassigned | ForEach-Object { Write-Output ('   ' + $_) }
    Write-Output ""
}

$moved = 0; $left = @(); $sentBytes = 0; $i = 0
foreach ($al in $plan) {
    $i++
    $existing = Get-B2Map $al.Prefix
    $up = 0
    foreach ($f in $al.Files) {
        if (Find-Remote $existing $f.Remote $f.Size) { continue }
        Send-File $f.Local $f.Remote
        $up++; $sentBytes += $f.Size
    }
    # patikra: perskaitom is naujo ir lyginam kiekviena faila
    $after = Get-B2Map $al.Prefix
    $bad = @()
    foreach ($f in $al.Files) {
        $hit = Find-Remote $after $f.Remote $f.Size
        if (-not $hit) {
            if ($after.ContainsKey($f.Remote)) { $bad += ('dydis skiriasi: ' + $f.Remote) }
            else { $bad += ('truksta: ' + $f.Remote) }
        }
    }
    if ($bad.Count) {
        $left += [pscustomobject]@{ Album = $al.Name; Priezastis = ($bad | Select-Object -First 3) -join '; ' }
        Write-Output ("[{0}/{1}] {2}  ikelta {3}  NEPERKELTA ({4} neatitikimai)" -f $i, $plan.Count, $al.Name, $up, $bad.Count)
        continue
    }
    if ($NoMove) {
        Write-Output ("[{0}/{1}] {2}  ikelta {3}  patikrinta (neperkeliam)" -f $i, $plan.Count, $al.Name, $up)
        continue
    }
    $year = ($al.Prefix -split '/')[1]
    $destDir = Join-Path $MoveTo $year
    New-Item -ItemType Directory -Force -Path $destDir | Out-Null
    $dest = Join-Path $destDir $al.Name
    if (Test-Path $dest) {
        $left += [pscustomobject]@{ Album = $al.Name; Priezastis = 'tikslo aplankas jau egzistuoja' }
        Write-Output ("[{0}/{1}] {2}  NEPERKELTA - tikslas jau yra" -f $i, $plan.Count, $al.Name)
        continue
    }
    # Uzrakintas aplankas (atidarytas kitame procese ar naršyklėje) neturi
    # nutraukti viso darbo - failai i B2 jau ikelti ir patikrinti, tad tik
    # pasizymim ir einam toliau. Perkelti bus kito paleidimo metu.
    try {
        Move-Item -LiteralPath $al.Path -Destination $dest -ErrorAction Stop
    } catch {
        $left += [pscustomobject]@{ Album = $al.Name; Priezastis = ('perkelti nepavyko: ' + $_.Exception.Message) }
        Write-Output ("[{0}/{1}] {2}  ikelta {3}  patikrinta, BET aplankas uzrakintas - neperkelta" -f $i, $plan.Count, $al.Name, $up)
        continue
    }
    $moved++
    Write-Output ("[{0}/{1}] {2}  ikelta {3}  patikrinta  ->  perkelta ({4:N2} GB issiusta)" -f $i, $plan.Count, $al.Name, $up, ($sentBytes / 1GB))
}
Write-Output ''
Write-Output ("Perkelta albumu : {0}" -f $moved)
Write-Output ("Liko vietoje    : {0}" -f $left.Count)
$left | Select-Object -First 20 | ForEach-Object { Write-Output ("   {0} -- {1}" -f $_.Album, $_.Priezastis) }
Done 0
