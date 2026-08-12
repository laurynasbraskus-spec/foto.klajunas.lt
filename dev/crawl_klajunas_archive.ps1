<#
    Suindeksuoja old.klajunas.lt/archyvas.htm matricos puslapius: is kiekvieno
    rezultatu puslapio istraukia serija, data ir VIETA.

    Reikalinga todel, kad daugelio klubo vidaus renginiu (Snaige, Kopija, Bobu
    vasara, Prologas) dbsportas.lt neturi - klubo archyvas yra vienintelis
    autoritetingas saltinis.

    Puslapiu antrastes atrodo taip:
        Klajuno maratonas 1999 08 22 Stirniai Rezultatai ...
        Luknos taure 2008 2008.04.25 Lukna Rezultatai ...
    Todel vieta imama is karto po datos, iki zodzio "Rezultatai".

    Tik SKAITO tinklalapius ir raso CSV. Tarp uzklausu daroma pauze.
#>
param(
    [string]$Index  = 'https://old.klajunas.lt/archyvas.htm',
    [string]$OutCsv = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\klajunas_archive_index.csv',
    [int]$DelayMs   = 400
)
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'

# Dalis puslapiu yra UTF-16LE, dalis UTF-8, o seniausi - windows-1257 (lietuviu).
# Be sito lietuviskos raides vietovardziuose virsta siuksles.
function Decode([byte[]]$b) {
    if ($b.Length -ge 2 -and $b[0] -eq 0xFF -and $b[1] -eq 0xFE) { return [Text.Encoding]::Unicode.GetString($b) }
    try {
        $strict = New-Object Text.UTF8Encoding($false, $true)
        return $strict.GetString($b)
    } catch {
        return [Text.Encoding]::GetEncoding(1257).GetString($b)
    }
}
function Get-Text([string]$url) {
    $wc = New-Object Net.WebClient
    $wc.Headers.Add('User-Agent', $ua)
    return Decode $wc.DownloadData($url)
}
function To-Plain([string]$html) {
    return ($html -replace '(?is)<script.*?</script>', ' ' -replace '(?is)<style.*?</style>', ' ' `
                  -replace '<[^>]+>', ' ' -replace '&nbsp;', ' ' -replace '&#8211;', '-' `
                  -replace '\uFEFF', '' -replace '\s+', ' ').Trim()
}

$monNames = @{ 'sausio'=1;'vasario'=2;'kovo'=3;'balandžio'=4;'gegužės'=5;'birželio'=6;
               'liepos'=7;'rugpjūčio'=8;'rugsėjo'=9;'spalio'=10;'lapkričio'=11;'gruodžio'=12 }

# Grazina datu sarasa kartu su pozicija tekste - pozicija reikalinga vietai rasti.
# Daugiadieniai renginiai uzrasyti intervalais: "2009.05.27-28" arba
# "2020.07.31-08.02". Be ju paimdavom tik pradzia, o vieta likdavo tuscia, nes
# iskart po datos eidavo skaitmuo.
function Find-Dates([string]$plain) {
    $out = @()
    # 2008.04.25 | 2008-04-25 | 2008/04/25 | 1999 08 22  (skirtukas gali buti ir tarpas)
    # po jos neprivalomas intervalas: -28  arba  -08.02  arba  -2009.05.28
    $rx = '\b((?:19|20)\d{2})[\.\-/ ](\d{1,2})[\.\-/ ](\d{1,2})(?:\s*[-–]\s*(?:((?:19|20)\d{2})[\.\-/ ])?(?:(\d{1,2})[\.\-/ ])?(\d{1,2})\b)?'
    foreach ($m in [regex]::Matches($plain, $rx)) {
        $y = [int]$m.Groups[1].Value; $mo = [int]$m.Groups[2].Value; $d = [int]$m.Groups[3].Value
        if ($mo -lt 1 -or $mo -gt 12 -or $d -lt 1 -or $d -gt 31) { continue }
        $end = ''
        if ($m.Groups[6].Success) {
            $ey = if ($m.Groups[4].Success) { [int]$m.Groups[4].Value } else { $y }
            $emo = if ($m.Groups[5].Success) { [int]$m.Groups[5].Value } else { $mo }
            $ed = [int]$m.Groups[6].Value
            if ($emo -ge 1 -and $emo -le 12 -and $ed -ge 1 -and $ed -le 31) {
                $cand = ('{0:0000}-{1:00}-{2:00}' -f $ey, $emo, $ed)
                # pabaiga turi buti VELIAU uz pradzia - kitaip tai ne intervalas,
                # o salia atsidures kitas skaicius (pvz. rezultatu lentele)
                if ($cand -gt ('{0:0000}-{1:00}-{2:00}' -f $y, $mo, $d)) { $end = $cand }
            }
        }
        $out += [pscustomobject]@{
            Date = ('{0:0000}-{1:00}-{2:00}' -f $y, $mo, $d); DateEnd = $end
            Pos = $m.Index; Len = $m.Length
        }
    }
    $names = ($monNames.Keys -join '|')
    foreach ($m in [regex]::Matches($plain, "(?i)\b((?:19|20)\d{2})\s*m\.?\s*($names)\s*(\d{1,2})\s*d")) {
        $mo = $monNames[$m.Groups[2].Value.ToLower()]
        if ($mo) {
            $out += [pscustomobject]@{ Date = ('{0:0000}-{1:00}-{2:00}' -f [int]$m.Groups[1].Value, $mo, [int]$m.Groups[3].Value); DateEnd = ''; Pos = $m.Index; Len = $m.Length }
        }
    }
    return @($out | Sort-Object Pos)
}

# Zodziai, kurie vietos pavadinimui nepriklauso: lenteliu antrastes ir nuorodu
# etiketes. Be ju gaudavom "Baltelis Peržiūrėti".
$script:StopWords = 'rezultatai|rez|rezultatas|peržiūrėti|perziureti|nuotraukos|foto|protokolas|' +
                    'žemėlapis|zemelapis|atsisiųsti|tarpiniai|vieta|dalyvis|pavardė|vardas|klubas|' +
                    'laikas|taškai|bauda|mokykla|nr|temp|oras|starto|startas|schema|diplomai'
# Vietovardzio tesinys daznai yra mazaja raide: "Ropeikiskes miskas",
# "Kerocio ez. poilsiaviete". Sena taisykle eme tik didziaja raide prasidedancius
# zodzius ir antraji nukirsdavo, todel duomenys buvo skurdesni uz jau turimus.
$script:ContWords = 'miškas|miske|miško|giria|ežeras|ežero|ež|ez|poilsiavietė|poilsiaviete|' +
                    'kalniukas|kalnas|kaimas|apylinkės|apylinkes|prie|apyl|mstl|sen|raj|rajonas|r|k|vs|apie|už|uz'

# Vieta - tai kas eina iskart po datos.
function Find-Place([string]$plain, $dateHit) {
    if (-not $dateHit) { return '' }
    $start = $dateHit.Pos + $dateHit.Len
    if ($start -ge $plain.Length) { return '' }
    $tail = $plain.Substring($start, [Math]::Min(80, $plain.Length - $start))
    # oro salygos ("+10, apsiniauke") ir "Temp: +3" raso is karto po vietos
    $tail = $tail -replace '(?i)\s*,?\s*[+-]\d+.*$', '' -replace "(?i)\s*\b($script:StopWords)\b\s*[:.].*$", ''
    if ($tail -match '^\s*$') { return '' }

    $words = @($tail -split '\s+' | Where-Object { $_ })
    $take = @()
    foreach ($w in $words) {
        $bare = $w.Trim('.', ',', '(', ')', '„', '"', '–', '-')
        if ($bare -eq '') { if ($take.Count) { $take += $w; continue } else { continue } }
        if ($bare -match '^\d') { break }
        if ($bare -imatch "^($script:StopWords)$") { break }
        $isCap  = $bare -cmatch '^[A-ZĄČĘĖĮŠŲŪŽ]'
        $isCont = $bare -imatch "^($script:ContWords)$"
        if ($isCap -or $isCont -or $w -eq '-') { $take += $w }
        elseif ($take.Count) { break }
        else { break }
        if ($take.Count -ge 6) { break }
    }
    return (($take -join ' ').Trim(' ', ',', '-', '.', ':', '(', ')'))
}

Write-Output "Skaitom indeksa: $Index"
$idx = Get-Text $Index
$rows = [regex]::Matches($idx, '(?is)<tr[^>]*>(.*?)</tr>')
$links = @{}
foreach ($r in $rows) {
    $cells = [regex]::Matches($r.Groups[1].Value, '(?is)<t[dh][^>]*>(.*?)</t[dh]>')
    if ($cells.Count -lt 2) { continue }
    $series = (To-Plain $cells[0].Groups[1].Value)
    if ($series -match 'Varžybos') { continue }
    for ($i = 1; $i -lt $cells.Count; $i++) {
        foreach ($h in [regex]::Matches($cells[$i].Groups[1].Value, 'href\s*=\s*["'']([^"'']+)["'']')) {
            $u = $h.Groups[1].Value
            if ($u -notmatch 'old\.klajunas\.lt/archyvas/') { continue }
            if (-not $links.ContainsKey($u)) { $links[$u] = $series }
        }
    }
}
Write-Output ("Rasta archyvo puslapiu: {0}" -f $links.Count)

$out = @(); $n = 0; $fail = 0
foreach ($u in ($links.Keys | Sort-Object)) {
    $n++
    Write-Progress -Activity 'Archyvas' -Status $u -PercentComplete ([int](100 * $n / $links.Count))
    try { $html = Get-Text $u } catch { $fail++; continue }
    $plain = To-Plain $html
    $title = (To-Plain ([regex]::Match($html, '(?is)<title>(.*?)</title>').Groups[1].Value))
    # @() butinas: return @(...) PowerShellyje isvynioja VIENO elemento masyva,
    # tada .Count yra tuscias ir data tyliai dingsta. Butent del to praeitas
    # paleidimas rado tik 11 datu is 149.
    $hits  = @(Find-Dates $plain)

    # Renginio data yra antrasteje, todel imam PIRMA rasta - ne bet kuria.
    # Failo vardo metai naudojami tik kaip patikra, o ne kaip filtras: dalis
    # pavadinimu (mindunam70) skaiciu turi visai kita prasme.
    $first   = if ($hits.Count) { $hits[0] } else { $null }
    $date    = if ($first) { $first.Date } else { '' }
    $dateEnd = if ($first) { $first.DateEnd } else { '' }
    $place   = Find-Place $plain $first

    $stem = [IO.Path]::GetFileNameWithoutExtension($u)
    $yy = [regex]::Match($stem, '(\d{2})(?!.*\d)')
    $fileYear = ''
    if ($yy.Success) {
        $v = [int]$yy.Groups[1].Value
        $cand = if ($v -ge 89) { 1900 + $v } else { 2000 + $v }
        if ($cand -le (Get-Date).Year) { $fileYear = $cand }
    }
    $mismatch = ''
    if ($date -and $fileYear -and ($date -notlike ("$fileYear*"))) { $mismatch = 'metai nesutampa su failo vardu' }

    $out += [pscustomobject]@{
        Serija     = $links[$u]
        Data       = $date
        DataIki    = $dateEnd
        Vieta      = $place
        FailoMetai = $fileYear
        Ispejimas  = $mismatch
        VisosDatos = (@($hits | ForEach-Object { $_.Date } | Sort-Object -Unique) -join ' ')
        Antraste   = $title
        Nuoroda    = $u
        Fragmentas = $plain.Substring(0, [Math]::Min(160, $plain.Length))
    }
    Start-Sleep -Milliseconds $DelayMs
}
Write-Progress -Activity 'Archyvas' -Completed

if (-not $out.Count) { Write-Output 'KLAIDA: neatsisiusta ne vieno puslapio.'; exit 1 }

# Jei puslapyje datu RASTA, bet i Data laukа nepateko - tai ne archyvo savybe,
# o skripto klaida (taip nutiko del vieno elemento masyvo isvyniojimo).
$lost = @($out | Where-Object { $_.VisosDatos -and -not $_.Data })
if ($lost.Count) {
    Write-Output ("KLAIDA: {0} puslapiuose data rasta, bet i ataskaita nepateko." -f $lost.Count)
    $lost | Select-Object -First 5 | ForEach-Object { Write-Output ("  {0}  VisosDatos={1}" -f ($_.Nuoroda -replace '.*/', ''), $_.VisosDatos) }
    Write-Output 'Ataskaita neirasoma.'
    exit 1
}

$out | Sort-Object Serija, Data | Export-Csv $OutCsv -NoTypeInformation -Encoding UTF8

Write-Output ''
Write-Output ("Atsisiusta puslapiu : {0}  (nepavyko {1})" -f $out.Count, $fail)
Write-Output ("Su data             : {0}" -f @($out | Where-Object { $_.Data }).Count)
Write-Output ("Su vieta            : {0}" -f @($out | Where-Object { $_.Vieta }).Count)
Write-Output ("Ispejimu            : {0}" -f @($out | Where-Object { $_.Ispejimas }).Count)
Write-Output ("Ataskaita           : {0}" -f $OutCsv)
