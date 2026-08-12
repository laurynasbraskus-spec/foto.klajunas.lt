<#
    Sulygina klubo archyvo indeksa su archyvo albumais.

    Ankstesnis bandymas derino pavieniais zodziais ("taure"), todel Klajuno,
    Luknos ir Finalai tapo tuo paciu renginiu - is 118 "kandidatu" tikras buvo
    vienas. Cia kiekviena serija turi aiskiai nurodyta albumo vardo sablona, ir
    reikalaujama, kad sutaptu IR serija, IR metai.

    Nieko nekeicia - tik raso ataskaita.
#>
param(
    [string]$Base = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta'
)
$ErrorActionPreference = 'Stop'

# Serija archyve -> kaip ta pati serija atrodo albumo aplanko varde.
# Sablonai specialiai siauri: bendriniai zodziai ("taure", "finalai") be
# serijos vardo neleidziami, nes butent jie ir sukure netikrus atitikmenis.
$series = @(
    @{ A = '^Bobų vasara$';                 F = 'bobu-vasara|bobu\b' }
    @{ A = '^Prologas$';                    F = 'prologas' }
    @{ A = '^Moksleivių žaidynės$';         F = 'moksleiv' }
    @{ A = '^Kopija$';                      F = 'kopija' }
    @{ A = '^Klajūno maratonas';            F = 'klajuno-maratonas' }
    @{ A = '^Klajūno taurė$';               F = 'klajuno-taure' }
    @{ A = '^Molėtų čempionatas$';          F = 'moletu-cempionatas|rajono-cempionatas' }
    @{ A = '^Snaigė$';                      F = 'snaige' }
    @{ A = '^Sprintas$';                    F = 'sprintas' }
    @{ A = '^Šeimų taurė$';                 F = 'seimu-taure' }
    @{ A = '^Molėtų estafetės$';            F = 'moletu-estafetes' }
    @{ A = '^Molėtų taurės finalai$';       F = 'moletu-taures-finalai|^finalai|__finalai' }
    @{ A = '^Sezono uždarymas$';            F = 'sezono-uzdarymas' }
    @{ A = '^Luknos taurė$';                F = 'luknos-taure' }
    @{ A = '^Bėgimas aplink Želvos ežerą$'; F = 'zelvos-ezera|zelvos' }
)

$idx = @(Import-Csv (Join-Path $Base 'klajunas_archive_index.csv') | Where-Object { $_.Data })
$root = Join-Path $Base 'albums'

$albums = @()
Get-ChildItem $root -Directory -Recurse -Depth 1 -EA SilentlyContinue |
    Where-Object { Test-Path (Join-Path $_.FullName 'album.json') } | ForEach-Object {
        $a = Get-Content (Join-Path $_.FullName 'album.json') -Raw | ConvertFrom-Json
        $albums += [pscustomobject]@{
            Name = $_.Name; Path = $_.FullName
            Date = [string]$a.date; DateEnd = [string]$a.dateEnd
            Conf = [string]$a.dateConfidence; Place = [string]$a.eventPlace
        }
    }
Write-Output ("albumu: {0}   archyvo irasu su data: {1}" -f $albums.Count, $idx.Count)

$rows = @()
foreach ($s in $series) {
    $pages = @($idx | Where-Object { $_.Serija -match $s.A })
    foreach ($p in $pages) {
        $yr = ($p.Data -split '-')[0]
        $cand = @($albums | Where-Object { $_.Name -match $s.F -and $_.Date -like ("$yr*") })
        if ($cand.Count -ne 1) { continue }   # 0 arba keli - nesprendziam automatiskai
        $a = $cand[0]
        $archEnd = [string]$p.DataIki
        # Daugiadieniams turi sutapti IR pradzia, IR pabaiga - kitaip intervalas
        # lieka neteisingas net kai pradzia atspeta.
        $dateOk  = ($a.Date -eq $p.Data) -and (($a.DateEnd -replace '^\s*$', '') -eq $archEnd)
        $placeNew = ($p.Vieta -and -not $a.Place)
        if ($dateOk -and -not $placeNew) { continue }
        $rows += [pscustomobject]@{
            Albumas      = $a.Name
            AlbumoData   = $a.Date
            AlbumoPabaiga= $a.DateEnd
            ArchyvoData  = $p.Data
            ArchyvoPabaiga = $archEnd
            DatosBusena  = $(if ($dateOk) { 'sutampa' } else { 'SKIRIASI' })
            Pasitikejimas= $a.Conf
            AlbumoVieta  = $a.Place
            ArchyvoVieta = $p.Vieta
            Serija       = $p.Serija
            Nuoroda      = $p.Nuoroda
        }
    }
}

$rows = $rows | Sort-Object Albumas -Unique
$out = Join-Path $Base 'archive_match_report.csv'
$rows | Export-Csv $out -NoTypeInformation -Encoding UTF8

$diff = @($rows | Where-Object { $_.DatosBusena -eq 'SKIRIASI' })
$weak = @($diff | Where-Object { $_.Pasitikejimas -in @('google-ikelimas', 'nezinoma') })
$strong = @($diff | Where-Object { $_.Pasitikejimas -notin @('google-ikelimas', 'nezinoma') })
$placeOnly = @($rows | Where-Object { $_.DatosBusena -eq 'sutampa' -and $_.ArchyvoVieta })

Write-Output ''
Write-Output ("Griezti atitikmenys is viso : {0}" -f $rows.Count)
Write-Output ("  datos skiriasi            : {0}" -f $diff.Count)
Write-Output ("  is ju albumo data silpna  : {0}   <- cia archyvas nusveria" -f $weak.Count)
Write-Output ("  is ju albumo data stipri  : {0}   <- reikia ziureti rankomis" -f $strong.Count)
Write-Output ("  data sutampa, vieta nauja : {0}" -f $placeOnly.Count)
Write-Output ''
if ($weak.Count) {
    Write-Output '=== ARCHYVAS NUSVERIA (albumo data buvo tik Google ikelimo zyma) ==='
    $weak | Sort-Object ArchyvoData | ForEach-Object {
        $was = $_.AlbumoData + $(if ($_.AlbumoPabaiga) { '..' + $_.AlbumoPabaiga } else { '' })
        $now = $_.ArchyvoData + $(if ($_.ArchyvoPabaiga) { '..' + $_.ArchyvoPabaiga } else { '' })
        Write-Output ("  {0}  ->  {1}   vieta: {2}" -f $was, $now, $(if ($_.ArchyvoVieta) { $_.ArchyvoVieta } else { '-' }))
        Write-Output ("      {0}   {1}" -f $_.Albumas, $_.Serija)
    }
}
if ($strong.Count) {
    Write-Output ''
    Write-Output '=== KONFLIKTAS: albumo data irodyta, bet archyvas rodo kita ==='
    $strong | Sort-Object ArchyvoData | ForEach-Object {
        $was = $_.AlbumoData + $(if ($_.AlbumoPabaiga) { '..' + $_.AlbumoPabaiga } else { '' })
        $now = $_.ArchyvoData + $(if ($_.ArchyvoPabaiga) { '..' + $_.ArchyvoPabaiga } else { '' })
        Write-Output ("  albumas {0} ({1})   archyvas {2}" -f $was, $_.Pasitikejimas, $now)
        Write-Output ("      {0}" -f $_.Albumas)
    }
}
Write-Output ''
Write-Output ("Ataskaita: {0}" -f $out)
