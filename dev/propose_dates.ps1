<#
    Siulo datas albumams, kuriu data dar nepatvirtinta.

    Lyginama su dviem klubo saltiniais, kurie jau surinkti:
      klajunas_archive_index.csv  - old.klajunas.lt protokolai (data + pavadinimas + vieta)
      klajunas_posts.csv          - klajunas.lt naujienos (data + pavadinimas)

    Sugretinimas pagal zodzius pavadinime, TIK tu paciu metu ribose. Metai imami
    is albumo pavadinimo (jie ten visi yra) arba is turimos datos.

    Nieko nekeicia - tik parodo, kur atsakymas jau yra po ranka, o kur teks
    ieskoti rankomis (dbsportas.lt, lbma.lt kalendorius).
#>
param(
    [string]$Csv  = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\albums_import.csv',
    [string]$Base = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta',
    [string]$B2   = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\dev\b2_prefixes.json',
    [string]$Out  = ''
)
$ErrorActionPreference = 'Stop'
if ($Out -eq '') { $Out = Join-Path $Base 'datu_pasiulymai.csv' }

function Fold([string]$s) {
    $s = $s.ToLower()
    $map = @{ 'ą'='a';'č'='c';'ę'='e';'ė'='e';'į'='i';'š'='s';'ų'='u';'ū'='u';'ž'='z' }
    foreach ($k in $map.Keys) { $s = $s -replace $k, $map[$k] }
    return ($s -replace '[^a-z0-9 ]', ' ') -replace '\s+', ' '
}
# Bendriniai zodziai sutampa visur ir tik kelia triuksma.
$stop = @('taure','taures','begimas','begimo','lietuvos','os','klajuno','klajunas','m','ir','su','nuotraukos','cempionatas','cempionatai','varzybos','etapas','dienos')

function Tokens([string]$s) {
    $t = @((Fold $s) -split ' ' | Where-Object { $_.Length -ge 3 -and $stop -notcontains $_ -and $_ -notmatch '^\d+$' })
    return , $t
}

$rows = @(Import-Csv $Csv)
$parsedB2 = ConvertFrom-Json ([IO.File]::ReadAllText($B2))
# NE $b2 - tai butu tas pats kintamasis kaip parametras [string]$B2.
$b2Set = @{}
foreach ($x in @($parsedB2)) { $b2Set[[string]$x] = $true }
$todo = @($rows | Where-Object { -not $b2Set.ContainsKey([string]$_.source_path) })
Write-Output ("albumu be patvirtintos datos: {0}" -f $todo.Count)

$src = @()
$idxPath = Join-Path $Base 'klajunas_archive_index.csv'
if (Test-Path $idxPath) {
    foreach ($a in @(Import-Csv $idxPath)) {
        if ([string]$a.Data -match '^\d{4}-\d{2}-\d{2}$') {
            $src += [pscustomobject]@{ Data = [string]$a.Data; Pav = (([string]$a.Pavadinimas) + ' ' + [string]$a.Vieta).Trim(); Kur = 'protokolas'; Nuoroda = [string]$a.Nuoroda }
        }
    }
}
$postPath = Join-Path $Base 'klajunas_posts.csv'
if (Test-Path $postPath) {
    foreach ($a in @(Import-Csv $postPath)) {
        if ([string]$a.Data -match '^\d{4}-\d{2}-\d{2}$') {
            $src += [pscustomobject]@{ Data = [string]$a.Data; Pav = [string]$a.Pavadinimas; Kur = 'klajunas.lt'; Nuoroda = [string]$a.Nuoroda }
        }
    }
}
Write-Output ("saltiniu irasu: {0}" -f $src.Count)
foreach ($s in $src) { $s | Add-Member -NotePropertyName Tok -NotePropertyValue (Tokens $s.Pav) -Force }

$res = @()
foreach ($r in $todo) {
    $title = [string]$r.title
    $year = ''
    if ($title -match '\b((?:19|20)\d{2})\b') { $year = $Matches[1] }
    elseif ([string]$r.event_date -match '^(\d{4})-') { $year = $Matches[1] }
    $mine = Tokens $title
    $best = $null; $bestScore = 0
    if ($year -ne '' -and $mine.Count) {
        foreach ($s in $src) {
            if (-not $s.Data.StartsWith($year)) { continue }
            $hit = 0
            foreach ($t in $mine) { if ($s.Tok -contains $t) { $hit++ } }
            if ($hit -gt $bestScore) { $bestScore = $hit; $best = $s }
        }
    }
    # SVARBU: klajunas.lt irasas turi PASKELBIMO data, ne renginio. Rezultatai
    # skelbiami po renginio, todel irasas 2019-11-07 puikiai dera su albumu,
    # fotografuotu 2019-11-02..05 - tai patvirtinimas, ne priestaravimas.
    # Protokolo (old.klajunas.lt) data yra paties renginio - ji lyginama tiesiai.
    $verdict = 'saltinio nerasta'
    $delta = ''
    if ($best -and $bestScore -ge 1) {
        $mineDate = [string]$r.event_date
        if ($mineDate -match '^\d{4}-\d{2}-\d{2}$') {
            $d = ([datetime]$best.Data - [datetime]$mineDate).Days
            $delta = $d
            if ($best.Kur -eq 'protokolas') {
                $verdict = if ($d -eq 0) { 'patvirtina' } else { 'PRIESTARAUJA' }
            } else {
                if ($d -ge 0 -and $d -le 14) { $verdict = 'patvirtina' }
                elseif ($d -lt 0 -and $d -ge -2) { $verdict = 'patvirtina' }   # anonsas pries renginį
                else { $verdict = 'PRIESTARAUJA' }
            }
        } else {
            $verdict = 'albumas be datos'
        }
    }
    $res += [pscustomobject]@{
        Pavadinimas = $title
        DabartineData = [string]$r.event_date
        SaltinioData = if ($best -and $bestScore -ge 1) { $best.Data } else { '' }
        Skirtumas = $delta
        Verdiktas = $verdict
        Sutapo = $bestScore
        Saltinis = if ($best -and $bestScore -ge 1) { $best.Kur } else { '' }
        SaltinioPav = if ($best -and $bestScore -ge 1) { $best.Pav } else { '' }
        Nuoroda = if ($best -and $bestScore -ge 1) { $best.Nuoroda } else { '' }
        Nuotrauku = [string]$r.media_count
        Kelias = [string]$r.source_path
    }
}
$res | Sort-Object -Property Verdiktas, Pavadinimas | Export-Csv $Out -NoTypeInformation -Encoding UTF8
Write-Output ''
foreach ($v in @('patvirtina', 'PRIESTARAUJA', 'albumas be datos', 'saltinio nerasta')) {
    $n = @($res | Where-Object { $_.Verdiktas -eq $v }).Count
    Write-Output ("{0,-18} {1}" -f $v, $n)
}
Write-Output ("`nCSV: {0}" -f $Out)
