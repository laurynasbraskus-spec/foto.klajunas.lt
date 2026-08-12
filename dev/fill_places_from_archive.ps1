<#
    Perkelia renginiu vietoves is klubo archyvo indekso i album.json.

    Vien datos sutapimo NEPAKANKA: ta pacia diena galejo vykti skirtingi
    renginiai (pvz. albumas "Lietuvos cempionatas" ir archyvo "Sprintas" ta pacia
    2009.10.22). Todel vietove rasoma tik tada, kai sutampa IR data, IR serija.

    A pakopa - sutampa data ir serija       -> rasoma automatiskai
    B pakopa - sutampa tik data             -> tik i ataskaita, nerasoma

    Vietove niekada neperrasoma, jei ji jau uzpildyta.
    Be -Execute tik parodo, ka darytu.
#>
param(
    [string]$Base = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta',
    [switch]$Execute
)
$ErrorActionPreference = 'Stop'
$root = Join-Path $Base 'albums'

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
    @{ A = '^Molėtų taurės finalai$';       F = 'moletu-taures-finalai|finalai' }
    @{ A = '^Sezono uždarymas$';            F = 'sezono-uzdarymas' }
    @{ A = '^Luknos taurė$';                F = 'luknos-taure' }
    @{ A = '^Bėgimas aplink Želvos ežerą$'; F = 'zelvos' }
)

# Vietovardis, kuriame yra dvitaskis, skaiciai ar "REZ" - tai ne vieta, o
# nenukirstas puslapio tekstas. Tokiu neimam.
function Looks-Like-Place([string]$v) {
    if (-not $v) { return $false }
    if ($v.Length -gt 46) { return $false }
    if ($v -match '[:;]|\d|(?i)\brez\b|(?i)rezultat') { return $false }
    return ($v -cmatch '^[A-ZĄČĘĖĮŠŲŪŽ]')
}

$idx = @(Import-Csv (Join-Path $Base 'klajunas_archive_index.csv') | Where-Object { $_.Data -and $_.Vieta })
Write-Output ("archyvo irasu su data ir vieta: {0}" -f $idx.Count)

$albums = @()
Get-ChildItem $root -Directory -Recurse -Depth 1 -EA SilentlyContinue |
    Where-Object { Test-Path (Join-Path $_.FullName 'album.json') } | ForEach-Object {
        $a = Get-Content (Join-Path $_.FullName 'album.json') -Raw | ConvertFrom-Json
        $albums += [pscustomobject]@{
            Name = $_.Name; Json = (Join-Path $_.FullName 'album.json')
            Date = [string]$a.date; Place = [string]$a.eventPlace; Obj = $a
        }
    }
$noPlace = @($albums | Where-Object { -not $_.Place })
Write-Output ("albumu is viso: {0}   be vietoves: {1}" -f $albums.Count, $noPlace.Count)

$tierA = @(); $tierB = @(); $rejected = @()
foreach ($al in $noPlace) {
    if ($al.Date -notmatch '^\d{4}-\d{2}-\d{2}$') { continue }
    $sameDay = @($idx | Where-Object { $_.Data -eq $al.Date })
    if (-not $sameDay.Count) { continue }

    $seriesHit = @()
    foreach ($p in $sameDay) {
        foreach ($s in $series) {
            if ($p.Serija -match $s.A -and $al.Name -match $s.F) { $seriesHit += $p; break }
        }
    }
    $pick = $null; $tier = ''
    if ($seriesHit.Count -eq 1) { $pick = $seriesHit[0]; $tier = 'A' }
    elseif ($sameDay.Count -eq 1) { $pick = $sameDay[0]; $tier = 'B' }
    else { $tier = 'B'; $pick = $null }

    if (-not $pick) {
        $tierB += [pscustomobject]@{ Albumas = $al.Name; Data = $al.Date; Vieta = ''; Serija = '(keli variantai)'; Pastaba = ('ta pacia diena archyve ' + $sameDay.Count + ' renginiai') }
        continue
    }
    if (-not (Looks-Like-Place $pick.Vieta)) {
        $rejected += [pscustomobject]@{ Albumas = $al.Name; Data = $al.Date; Vieta = $pick.Vieta; Serija = $pick.Serija }
        continue
    }
    $row = [pscustomobject]@{ Albumas = $al.Name; Data = $al.Date; Vieta = $pick.Vieta; Serija = $pick.Serija; Nuoroda = $pick.Nuoroda; Json = $al.Json; Obj = $al.Obj }
    if ($tier -eq 'A') { $tierA += $row } else { $tierB += $row }
}

Write-Output ''
Write-Output ("A pakopa (data + serija) : {0}   <- rasoma" -f $tierA.Count)
Write-Output ("B pakopa (tik data)      : {0}   <- tik ataskaitai" -f $tierB.Count)
Write-Output ("atmesta (vieta atrodo netvarkinga): {0}" -f $rejected.Count)
Write-Output ''
if ($tierA.Count) {
    Write-Output '=== A pakopa ==='
    $tierA | Sort-Object Data | ForEach-Object { Write-Output ("  {0}  {1,-24} {2}" -f $_.Data, $_.Vieta, $_.Albumas) }
}
if ($rejected.Count) {
    Write-Output ''
    Write-Output '=== atmesta: istrauktas tekstas nepanasus i vietovarti ==='
    $rejected | ForEach-Object { Write-Output ("  {0}  '{1}'   {2}" -f $_.Data, $_.Vieta, $_.Albumas) }
}

if ($Execute) {
    $n = 0
    foreach ($r in $tierA) {
        $a = $r.Obj
        $a | Add-Member -NotePropertyName 'eventPlace' -NotePropertyValue $r.Vieta -Force
        $a | Add-Member -NotePropertyName 'eventPlaceSource' -NotePropertyValue $r.Nuoroda -Force
        $a | ConvertTo-Json -Depth 6 | Set-Content $r.Json -Encoding utf8
        $n++
    }
    Write-Output ''
    Write-Output ("Irasyta vietoviu: {0}" -f $n)
} else {
    Write-Output ''
    Write-Output 'BANDOMASIS REZIMAS - niekas nekeista. Realiam darbui pridek -Execute'
}

$tierB | Select-Object Albumas, Data, Vieta, Serija, Pastaba |
    Export-Csv (Join-Path $Base 'place_candidates_review.csv') -NoTypeInformation -Encoding UTF8
Write-Output ("B pakopos ataskaita: {0}" -f (Join-Path $Base 'place_candidates_review.csv'))
