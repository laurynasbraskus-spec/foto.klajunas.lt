<#
    Ikelia pakeistus foto.klajunas.lt failus per FTPS.

    Slaptazodzio skripte NERA - paprasys paleidus, paslepta ivestimi.

    Kiekvienam failui ta pati tvarka: eiluciu pabaigos i LF, atsargine kopija
    serveryje (pervadinimas), ikelimas, dydzio patikra. Jei kopija nepavyksta,
    tas failas praleidziamas - originalas lieka nepaliestas.

        .\upload_site.ps1                   # parodo plana, nieko nekeicia
        .\upload_site.ps1 -Execute          # ikelia
        .\upload_site.ps1 -Rollback -Execute # grazina naujausias kopijas
#>
param(
    [string]$FtpHost    = 'ftp.klajunas.lt',
    [string]$User       = 'klajunas',
    [string]$LocalRoot  = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\server\public_html',
    [string]$RemoteRoot = '/domains/foto.klajunas.lt/public_html',
    [string[]]$Files    = @('admin/index.php', 'index.html'),
    [switch]$Execute,
    [switch]$Rollback,
    [string]$LogFile    = '',
    # Pasyviuoju rezimu duomenu jungtis eina i serverio nurodyta atskira
    # prievada. Kai kurie tinklai (VPN, mobilus internetas, grieztos ugniasienes)
    # jo neisleidzia - tada verta pabandyti -Active.
    [switch]$Active,
    # Kai kurie Pure-FTPd nustatymai reikalauja, kad duomenu kanalas atnaujintu
    # valdymo kanalo TLS sesija; .NET to nemoka. -NoTls duomenu kanala palieka
    # atviru, valdymo kanalas su prisijungimo duomenimis lieka sifruotas.
    [switch]$NoTls
)

$ErrorActionPreference = 'Stop'
if ($LogFile) { try { Start-Transcript -Path $LogFile -Force | Out-Null } catch {} }
function Done([int]$code) { if ($LogFile) { try { Stop-Transcript | Out-Null } catch {} }; exit $code }

$sec  = Read-Host "FTP slaptazodis" -AsSecureString
$cred = New-Object System.Net.NetworkCredential($User, $sec)

function New-Req([string]$uri, [string]$method) {
    $r = [System.Net.FtpWebRequest]::Create($uri)
    $r.Method = $method
    $r.Credentials = $cred
    $r.EnableSsl = -not $NoTls    # AUTH TLS - atviru tekstu nesijungiam
    $r.UseBinary = $true
    $r.UsePassive = -not $Active
    $r.KeepAlive = $false
    $r.Timeout = 180000
    return $r
}

# Serveris leidzia ribota kieki jungciu ("user number 1 of 50") ir po keliu
# greitu viena po kitos pradeda ju nebepriimti. Todel kiekviena operacija
# kartojama su didejancia pauze, o tarp ju paliekamas nedidelis tarpas.
function Invoke-Ftp([scriptblock]$op, [int]$tries = 4) {
    for ($i = 1; $i -le $tries; $i++) {
        try { return & $op }
        catch {
            if ($i -eq $tries) { throw }
            Start-Sleep -Milliseconds (400 * $i)
        }
    }
}

function Ftp-Names([string]$dirUri) {
    return Invoke-Ftp {
        $resp = (New-Req $dirUri ([System.Net.WebRequestMethods+Ftp]::ListDirectory)).GetResponse()
        try {
            $sr = New-Object IO.StreamReader($resp.GetResponseStream())
            try { $txt = $sr.ReadToEnd() } finally { $sr.Close() }
        } finally { $resp.Close() }
        return @($txt -split "`r?`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ })
    }
}
function Ftp-MakeDir([string]$dirUri) {
    return Invoke-Ftp {
        $resp = (New-Req $dirUri.TrimEnd('/') ([System.Net.WebRequestMethods+Ftp]::MakeDirectory)).GetResponse()
        try { return $true } finally { $resp.Close() }
    }
}
function Ftp-Size([string]$uri) {
    return Invoke-Ftp {
        $resp = (New-Req $uri ([System.Net.WebRequestMethods+Ftp]::GetFileSize)).GetResponse()
        try { return $resp.ContentLength } finally { $resp.Close() }
    }
}
function Ftp-Rename([string]$uri, [string]$newName) {
    Invoke-Ftp {
        $r = New-Req $uri ([System.Net.WebRequestMethods+Ftp]::Rename)
        $r.RenameTo = $newName
        $r.GetResponse().Close()
    } | Out-Null
}
function Ftp-Upload([string]$uri, [byte[]]$bytes) {
    Invoke-Ftp {
        $req = New-Req $uri ([System.Net.WebRequestMethods+Ftp]::UploadFile)
        $req.ContentLength = $bytes.Length
        $st = $req.GetRequestStream()
        try { $st.Write($bytes, 0, $bytes.Length) } finally { $st.Close() }
        $req.GetResponse().Close()
    } | Out-Null
}

# Serveris laiko LF. Windows kopija su CRLF butu tokia pat funkciskai, bet
# skirtusi dydziu - o dydis yra vienintele patikra, kuria turim po ikelimo.
function To-Lf([string]$path) {
    $raw = [IO.File]::ReadAllText($path)
    $lf  = $raw -replace "`r`n", "`n"
    if ($lf -ne $raw) {
        [IO.File]::WriteAllText($path, $lf, (New-Object Text.UTF8Encoding($false)))
        return $true
    }
    return $false
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
# @(...) butinas: su vienu failu foreach grazina objekta, ne masyva, ir tada
# $plan.Count nieko negrazina - pabaigoje matydavosi "Ikelta sekmingai: 1 is ".
$plan = @(foreach ($rel in $Files) {
    $local  = Join-Path $LocalRoot ($rel -replace '/', '\')
    $remote = "ftp://$FtpHost$RemoteRoot/$rel"
    $dirUri = "ftp://$FtpHost$RemoteRoot/" + (Split-Path $rel -Parent).Replace('\', '/')
    if ((Split-Path $rel -Parent) -eq '') { $dirUri = "ftp://$FtpHost$RemoteRoot/" }
    [pscustomobject]@{
        Rel = $rel; Local = $local; Remote = $remote; DirUri = $dirUri.TrimEnd('/') + '/'
        Name = Split-Path $rel -Leaf
        Exists = Test-Path -LiteralPath $local
    }
})

Write-Host "Serveris: $FtpHost   Katalogas: $RemoteRoot"
Write-Host ""

if ($Rollback) {
    foreach ($p in $plan) {
        $names = Ftp-Names $p.DirUri
        $baks = @($names | Where-Object { $_ -like ($p.Name + '.bak-*') } | Sort-Object -Descending)
        if (-not $baks.Count) { Write-Host ("  {0}: kopiju nerasta" -f $p.Rel); continue }
        Write-Host ("  {0}: graziname {1}" -f $p.Rel, $baks[0])
        if ($Execute) { Ftp-Rename ($p.DirUri + $baks[0]) $p.Name; Write-Host "     grazinta" }
    }
    if (-not $Execute) { Write-Host "`n(bandomasis rezimas - pridek -Execute)" }
    Done 0
}

# --- planas ---
# Buvimo serveryje NEsprendziam pagal SIZE klaida: nutrukes rysys atrodo lygiai
# taip pat kaip nesantis failas, o tada butume perrase originala be kopijos.
# Todel kataloga isvardijam - ir jei to padaryti nepavyksta, sustojam.
$dirCache = @{}
$anyMissing = $false
foreach ($p in $plan) {
    if (-not $p.Exists) { Write-Host ("  NERA vietinio failo: " + $p.Local); $anyMissing = $true; continue }
    if (-not $dirCache.ContainsKey($p.DirUri)) {
        try { $dirCache[$p.DirUri] = Ftp-Names $p.DirUri }
        catch {
            # Katalogo gali tiesiog dar nebuti - naujas poaplankis (pvz. ikelti/).
            # Bandomajame rezime nieko nekuriam, tik pasakom. Su -Execute sukuriam
            # ir skaitom is naujo; jei katalogas TIKRAI yra, o listingas luzo del
            # rysio, MKD grazins klaida ir mes sustosim - t.y. neperrasysim failo
            # be atsargines kopijos.
            if (-not $Execute) {
                Write-Host ("  Katalogo " + $p.DirUri + " serveryje nera - su -Execute jis bus sukurtas.")
                $dirCache[$p.DirUri] = @()
            } else {
                try {
                    Ftp-MakeDir $p.DirUri | Out-Null
                    Write-Host ("  Sukurtas katalogas " + $p.DirUri)
                    Start-Sleep -Milliseconds 250
                    $dirCache[$p.DirUri] = Ftp-Names $p.DirUri
                } catch {
                    Write-Host ("  Nepavyko sukurti katalogo " + $p.DirUri + ": " + $_.Exception.Message); Done 1
                }
            }
        }
        Start-Sleep -Milliseconds 250
    }
    $conv = To-Lf $p.Local
    $size = (Get-Item -LiteralPath $p.Local).Length
    $onServer = ($dirCache[$p.DirUri] -contains $p.Name)
    $p | Add-Member -NotePropertyName OnServer -NotePropertyValue $onServer -Force
    Write-Host ("  {0,-18} vietinis {1,8} B{2}   serveryje: {3}" -f `
        $p.Rel, $size, $(if ($conv) { ' (CRLF->LF)' } else { '           ' }), `
        $(if ($onServer) { 'yra' } else { 'nera' }))
}
if ($anyMissing) { Write-Host "`nTrūksta vietiniu failu - nutraukiam."; Done 1 }

if (-not $Execute) {
    Write-Host "`nBandomasis rezimas. Realiam ikelimui: .\upload_site.ps1 -Execute"
    Done 0
}

# --- ikelimas ---
$ok = 0; $skipped = @()
foreach ($p in $plan) {
    Write-Host ""
    Write-Host ("--- " + $p.Rel + " ---")
    $localSize = (Get-Item -LiteralPath $p.Local).Length

    if ($p.OnServer) {
        $bak = $p.Name + '.bak-' + $stamp
        try { Ftp-Rename $p.Remote $bak }
        catch { Write-Host ("  kopija NEPAVYKO: " + $_.Exception.Message); $skipped += $p.Rel; continue }
        Start-Sleep -Milliseconds 250
        $names = try { Ftp-Names $p.DirUri } catch { @() }
        if ($names -notcontains $bak) { Write-Host "  kopija nepatvirtinta - praleidziam"; $skipped += $p.Rel; continue }
        Write-Host ("  kopija: " + $bak)
    } else {
        Write-Host "  (serveryje failo nebuvo)"
    }
    Start-Sleep -Milliseconds 250

    $bytes = [IO.File]::ReadAllBytes($p.Local)
    try { Ftp-Upload $p.Remote $bytes }
    catch {
        Write-Host ("  IKELIMAS NEPAVYKO: " + $_.Exception.Message)
        if ($p.OnServer) { Write-Host ("  originalas saugus kopijoje: " + $p.Name + '.bak-' + $stamp) }
        $skipped += $p.Rel; continue
    }
    Start-Sleep -Milliseconds 250

    $after = try { Ftp-Size $p.Remote } catch { -1 }
    if ($after -eq $localSize) { Write-Host ("  ikelta: " + $after + " B"); $ok++ }
    elseif ($after -lt 0) { Write-Host "  ikelta, bet dydzio patikrinti nepavyko - patikrink rankiniu budu"; $ok++ }
    else { Write-Host ("  DYDIS NESUTAMPA: serveryje " + $after + ", tiketasi " + $localSize); $skipped += $p.Rel }
}

Write-Host ""
Write-Host ("Ikelta sekmingai: {0} is {1}" -f $ok, $plan.Count)
if ($skipped.Count) {
    Write-Host ("Problemos: " + ($skipped -join ', '))
    Write-Host "Grazinti: .\upload_site.ps1 -Rollback -Execute"
}
Done 0
