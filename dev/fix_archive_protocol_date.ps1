<#
    Pataiso korekturos klaida klubo archyvo protokole.

    sprint09.htm irasyta "2009.10.22", nors renginys vyko 2009.10.06 (patvirtinta
    2026-08-08: nuotraukose lapai dar nenukrite, EXIF rodo 10-06).

    Keiciama BAITU lygmeniu: senas ir naujas tekstas vienodo ilgio, todel failo
    koduote (UTF-8 su BOM) ir visos lietuviskos raides lieka nepaliestos.

    Serveryje pirma daroma atsargine kopija pervadinant. Jei kopija nepavyksta -
    originalas neliecziamas.

    Be -Execute tik parodo plana.
#>
param(
    [string]$FtpHost = 'ftp.klajunas.lt',
    [string]$User    = 'klajunas',
    [string]$Url     = 'https://old.klajunas.lt/archyvas/sprint09.htm',
    [string]$OldText = '2009.10.22',
    [string]$NewText = '2009.10.06',
    # Tikslus kelias serveryje nezinomas - isbandom kandidatus per katalogo sarasa.
    [string[]]$RemoteDirs = @(
        '/domains/old.klajunas.lt/public_html/archyvas',
        '/domains/klajunas.lt/public_html/old/archyvas',
        '/domains/klajunas.lt/public_html/archyvas',
        '/domains/klajunas.lt/old/archyvas'
    ),
    [string]$FileName = 'sprint09.htm',
    [switch]$Execute,
    [switch]$Active,
    [switch]$NoTls
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

if ($OldText.Length -ne $NewText.Length) { Write-Host 'STOP: tekstai skirtingo ilgio - baitu keitimas negalimas.'; exit 1 }

Write-Host "Atsisiunciam esama faila: $Url"
$wc = New-Object Net.WebClient
$wc.Headers.Add('User-Agent', 'Mozilla/5.0')
$bytes = $wc.DownloadData($Url)
Write-Host ("  dydis: {0} B" -f $bytes.Length)

# Randam visas vietas, kur yra senas tekstas (ASCII skaitmenys ir taskai)
$pat = [Text.Encoding]::ASCII.GetBytes($OldText)
$new = [Text.Encoding]::ASCII.GetBytes($NewText)
$positions = @()
for ($i = 0; $i -le $bytes.Length - $pat.Length; $i++) {
    $match = $true
    for ($j = 0; $j -lt $pat.Length; $j++) { if ($bytes[$i + $j] -ne $pat[$j]) { $match = $false; break } }
    if ($match) { $positions += $i }
}
Write-Host ("  '{0}' rasta {1} k." -f $OldText, $positions.Count)
if ($positions.Count -ne 1) {
    Write-Host "STOP: tikimasi lygiai vieno atitikmens. Rankinis patikrinimas butinas."
    exit 1
}
foreach ($p in $positions) { for ($j = 0; $j -lt $new.Length; $j++) { $bytes[$p + $j] = $new[$j] } }
Write-Host ("  pakeista ties baitu {0}: {1} -> {2}" -f $positions[0], $OldText, $NewText)

if (-not $Execute) {
    Write-Host ''
    Write-Host 'BANDOMASIS REZIMAS - serveris neliestas. Realiam darbui pridek -Execute'
    exit 0
}

$sec  = Read-Host "FTP slaptazodis" -AsSecureString
$cred = New-Object System.Net.NetworkCredential($User, $sec)
function New-Req([string]$uri, [string]$method) {
    $r = [System.Net.FtpWebRequest]::Create($uri)
    $r.Method = $method; $r.Credentials = $cred
    $r.EnableSsl = -not $NoTls; $r.UseBinary = $true
    $r.UsePassive = -not $Active; $r.KeepAlive = $false; $r.Timeout = 120000
    return $r
}
function Ftp-Names([string]$dirUri) {
    $resp = (New-Req $dirUri ([System.Net.WebRequestMethods+Ftp]::ListDirectory)).GetResponse()
    try {
        $sr = New-Object IO.StreamReader($resp.GetResponseStream())
        try { $txt = $sr.ReadToEnd() } finally { $sr.Close() }
    } finally { $resp.Close() }
    return @($txt -split "`r?`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ })
}

# Kelia randam per katalogo sarasa - spejimais nesiremiam.
$dir = $null
foreach ($d in $RemoteDirs) {
    $uri = "ftp://$FtpHost$d/"
    try {
        $names = Ftp-Names $uri
        if ($names -contains $FileName) { $dir = $d; Write-Host ("Rastas kelias: {0}" -f $d); break }
        Write-Host ("  {0} - katalogas yra, bet {1} nera" -f $d, $FileName)
    } catch { Write-Host ("  {0} - nepasiekiamas" -f $d) }
    Start-Sleep -Milliseconds 400
}
if (-not $dir) { Write-Host 'STOP: failo serveryje nerasta ne viename kandidate. Nurodyk -RemoteDirs rankomis.'; exit 1 }

$remote = "ftp://$FtpHost$dir/$FileName"
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$bak = "$FileName.bak-$stamp"
$r = New-Req $remote ([System.Net.WebRequestMethods+Ftp]::Rename)
$r.RenameTo = $bak
$r.GetResponse().Close()
Start-Sleep -Milliseconds 300
if ((Ftp-Names "ftp://$FtpHost$dir/") -notcontains $bak) { Write-Host 'STOP: kopija nepatvirtinta - nekeliam.'; exit 1 }
Write-Host ("Kopija serveryje: {0}" -f $bak)

$req = New-Req $remote ([System.Net.WebRequestMethods+Ftp]::UploadFile)
$req.ContentLength = $bytes.Length
$st = $req.GetRequestStream()
try { $st.Write($bytes, 0, $bytes.Length) } finally { $st.Close() }
$req.GetResponse().Close()
Write-Host ("Ikelta: {0} B" -f $bytes.Length)

Start-Sleep -Milliseconds 600
$check = (New-Object Net.WebClient).DownloadString($Url + '?v=' + $stamp)
if ($check -match [regex]::Escape($NewText)) { Write-Host "PATIKRINTA: puslapyje dabar $NewText" }
else { Write-Host "DEMESIO: puslapyje $NewText nerasta - patikrink rankomis, kopija $bak" }
