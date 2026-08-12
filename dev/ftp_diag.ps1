<#
    Isbando visus FTP duomenu kanalo derinius ir pasako, kuris veikia.
    Tik SKAITO katalogo sarasa - serveryje nieko nekeicia.
#>
param(
    [string]$FtpHost   = 'ftp.klajunas.lt',
    [string]$User      = 'klajunas',
    [string]$RemoteDir = '/domains/foto.klajunas.lt/public_html/admin',
    [string]$LogFile   = ''
)

$ErrorActionPreference = 'Continue'
if ($LogFile) { try { Start-Transcript -Path $LogFile -Force | Out-Null } catch {} }

$sec  = Read-Host "FTP slaptazodis" -AsSecureString
$cred = New-Object System.Net.NetworkCredential($User, $sec)
$uri  = "ftp://$FtpHost$RemoteDir/"

Write-Host ""
Write-Host "Bandom: $uri"
Write-Host ""

$combos = @(
    @{ Name = 'pasyvus + TLS';    Passive = $true;  Tls = $true  },
    @{ Name = 'pasyvus be TLS';   Passive = $true;  Tls = $false },
    @{ Name = 'aktyvus + TLS';    Passive = $false; Tls = $true  },
    @{ Name = 'aktyvus be TLS';   Passive = $false; Tls = $false }
)

$winner = $null
foreach ($c in $combos) {
    $label = $c.Name
    try {
        $r = [System.Net.FtpWebRequest]::Create($uri)
        $r.Method      = [System.Net.WebRequestMethods+Ftp]::ListDirectory
        $r.Credentials = $cred
        $r.EnableSsl   = $c.Tls
        $r.UsePassive  = $c.Passive
        $r.UseBinary   = $true
        $r.KeepAlive   = $false
        $r.Timeout     = 25000

        $resp = $r.GetResponse()
        try {
            $sr  = New-Object IO.StreamReader($resp.GetResponseStream())
            try { $txt = $sr.ReadToEnd() } finally { $sr.Close() }
        } finally { $resp.Close() }

        $n = @($txt -split "`r?`n" | Where-Object { $_.Trim() }).Count
        Write-Host ("  {0,-18} VEIKIA  ({1} failai)" -f $label, $n)
        if (-not $winner) { $winner = $c }
    } catch {
        $msg = $_.Exception.Message -replace 'Exception calling "GetResponse" with "0" argument\(s\): ', ''
        Write-Host ("  {0,-18} nepavyko  {1}" -f $label, $msg.Trim('"'))
    }
    Start-Sleep -Milliseconds 700
}

Write-Host ""
if ($winner) {
    $flags = @()
    if (-not $winner.Passive) { $flags += '-Active' }
    if (-not $winner.Tls)     { $flags += '-NoTls' }
    $flagStr = if ($flags.Count) { ($flags -join ' ') + ' ' } else { '' }
    Write-Host ("Veikiantis derinys: " + $winner.Name)
    Write-Host ""
    Write-Host "Ikelimui naudok:"
    Write-Host ("  .\upload_site.ps1 " + $flagStr + "-Execute")
} else {
    Write-Host "Ne vienas derinys neveikia."
    Write-Host "Vadinasi kliuva ne rezimas, o tinklas arba serveris."
    Write-Host "Tada kelk failus per FileZilla - ji TLS sesijos atnaujinima moka, o .NET ne."
}

if ($LogFile) { try { Stop-Transcript | Out-Null } catch {} }
Write-Host ""
Write-Host "Langa gali uzdaryti."
