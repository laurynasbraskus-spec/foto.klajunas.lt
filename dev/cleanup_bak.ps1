<#
    Istrina serveryje likusias .bak-* kopijas, kurias palieka upload_site.ps1.

    Kiekvienas ikelimas serveryje pervadina sena faila i "<vardas>.bak-<data>",
    todel po keliu ikelimu ju prisikaupia. Jos nepasiekiamos per HTTP (.htaccess
    ju neatiduoda), bet uzima vieta ir maisosi.

    Trinama TIK tai, kas atitinka "<vardas>.bak-YYYYMMDD-HHMMSS" - jokiu kitu
    failu skriptas neliecia.

        .\cleanup_bak.ps1                 # tik parodo, ka rastu
        .\cleanup_bak.ps1 -Execute        # istrina
        .\cleanup_bak.ps1 -KeepNewest     # palieka naujausia kopija kiekvienam failui
#>
param(
    [string]$FtpHost   = 'ftp.klajunas.lt',
    [string]$User      = 'klajunas',
    [string[]]$Dirs    = @(
        '/domains/foto.klajunas.lt/public_html',
        '/domains/foto.klajunas.lt/public_html/admin'
    ),
    [switch]$Execute,
    [switch]$KeepNewest,
    [switch]$Active,
    [switch]$NoTls
)
$ErrorActionPreference = 'Stop'

$sec  = Read-Host "FTP slaptazodis" -AsSecureString
$cred = New-Object System.Net.NetworkCredential($User, $sec)

function New-Req([string]$uri, [string]$method) {
    $r = [System.Net.FtpWebRequest]::Create($uri)
    $r.Method = $method
    $r.Credentials = $cred
    $r.EnableSsl = -not $NoTls
    $r.UseBinary = $true
    $r.UsePassive = -not $Active
    $r.KeepAlive = $false
    $r.Timeout = 120000
    return $r
}
function Ftp-Names([string]$dirUri) {
    $r = New-Req $dirUri ([System.Net.WebRequestMethods+Ftp]::ListDirectory)
    $resp = $r.GetResponse()
    try {
        $sr = New-Object IO.StreamReader($resp.GetResponseStream())
        try { ($sr.ReadToEnd() -split "`r?`n" | Where-Object { $_ -ne '' }) } finally { $sr.Close() }
    } finally { $resp.Close() }
}
function Ftp-Delete([string]$uri) {
    $r = New-Req $uri ([System.Net.WebRequestMethods+Ftp]::DeleteFile)
    $r.GetResponse().Close()
}

$found = @()
foreach ($d in $Dirs) {
    $uri = "ftp://$FtpHost$d/"
    Write-Host "--- $d"
    try { $names = @(Ftp-Names $uri) }
    catch { Write-Host ("    nepavyko nuskaityti: " + $_.Exception.Message); continue }
    # Tik tikros atsargines kopijos: vardas + .bak- + data-laikas
    $baks = @($names | Where-Object { $_ -match '\.bak-\d{8}-\d{6}$' })
    if (-not $baks.Count) { Write-Host '    kopiju nera'; continue }
    foreach ($b in ($baks | Sort-Object)) {
        Write-Host ("    " + $b)
        $found += [pscustomobject]@{
            Dir  = $d
            Name = $b
            Uri  = $uri + $b
            Base = ($b -replace '\.bak-\d{8}-\d{6}$', '')
            Stamp = [regex]::Match($b, '\.bak-(\d{8}-\d{6})$').Groups[1].Value
        }
    }
}

if (-not $found.Count) { Write-Host "`nNieko trinti nereikia."; exit 0 }

$toDelete = $found
if ($KeepNewest) {
    # Kiekvienam failui paliekam naujausia kopija - jei kas, bus is ko grazinti.
    $keep = $found | Group-Object Dir, Base | ForEach-Object {
        ($_.Group | Sort-Object Stamp -Descending)[0]
    }
    $toDelete = $found | Where-Object { $keep -notcontains $_ }
    Write-Host ("`nPaliekam naujausias: " + $keep.Count)
}

Write-Host ("`nRasta kopiju: " + $found.Count + ", trinsim: " + $toDelete.Count)
if (-not $Execute) { Write-Host "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$ok = 0; $bad = 0
foreach ($f in $toDelete) {
    try { Ftp-Delete $f.Uri; Write-Host ("  istrinta: " + $f.Name); $ok++ }
    catch { Write-Host ("  NEPAVYKO: " + $f.Name + " - " + $_.Exception.Message); $bad++ }
    Start-Sleep -Milliseconds 200
}
Write-Host ("`nIstrinta: $ok, nepavyko: $bad")
