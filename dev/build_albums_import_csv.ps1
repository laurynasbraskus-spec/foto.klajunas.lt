<#
    Sudeda albums_import.csv, kuri priima admin CSV importas.

    Surenka is keliu saltiniu:
      album.json                  - pavadinimas, datos, vieta, fotografas, aprasymas
      album_post_matches.csv      - klajunas.lt pranesimas
      klajunas_post_links.csv     - dbsportas ir kitos nuorodos is to pranesimo
      klajunas_archive_index.csv  - old.klajunas.lt protokolas (atsarginis variantas)

    Nuorodu pirmenybe pagal susitarima:
      klajunas_url  - pranesimas klajunas.lt; jei jo nera - protokolas old.klajunas.lt
      dbsportas_url - varzybu puslapis dbsportas.lt / dbtopas.lt
      other_url     - kitas saltinis (straipsnis, nuotrauku albumas)

    Nieko nekeicia archyve - tik raso CSV.
#>
param(
    [string]$Base = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta',
    [string]$Out  = '',
    # Zemiau sio slenkscio sutapimai jau duodavo klaidingu nuorodu.
    [int]$MinScore = 6
)
$ErrorActionPreference = 'Stop'
if ($Out -eq '') { $Out = Join-Path $Base 'albums_import.csv' }
# Sekmingai ikelti albumai perkeliami i "perkelta i web", todel skaityti reikia
# abi vietas - kitaip CSV kas karta buvu trumpesnis uz tikra archyva.
$roots = @((Join-Path $Base 'albums'), (Join-Path (Split-Path $Base -Parent) 'perkelta i web'))
$roots = @($roots | Where-Object { Test-Path $_ })
$root = $roots[0]

function Load-Csv([string]$name) {
    $p = Join-Path $Base $name
    if (-not (Test-Path $p)) { Write-Output ("  ispejimas: nerastas $name"); return @() }
    return @(Import-Csv $p)
}
# $matches yra AUTOMATINIS PowerShell kintamasis - kiekviena -match operacija ji
# perrasytu, todel lentele privalo tureti kitoki varda.
$postByAlbum = @{}
$postScore   = @{}
# Silpni sutapimai duoda KLAIDINGAS nuorodas: "Naujametinis begimas" gaudavo
# nuoroda i Zelvos begima. Klaidinga nuoroda blogiau nei jokia, todel imam tik
# tuos, kur pavadinimu sutapimas tikrai stiprus.
foreach ($m in (Load-Csv 'album_post_matches.csv')) {
    if (-not $m.Nuoroda) { continue }
    if ([int]$m.Taskai -lt $MinScore) { continue }
    $postByAlbum[$m.Albumas] = $m.Nuoroda
    $postScore[$m.Albumas]   = [int]$m.Taskai
}
$linksByPost = @{}
foreach ($l in (Load-Csv 'klajunas_post_links.csv')) {
    if (-not $linksByPost.ContainsKey($l.Irasas)) { $linksByPost[$l.Irasas] = @() }
    $linksByPost[$l.Irasas] += $l
}
# Kanoninis kelias - tas pats, i kuri kelia upload_albums_to_b2.ps1. Vietinis
# aplanko vardas cia netinka: DB susieja albuma su B2 aplanku per source_path,
# o failai gulės kanoniniame kelyje, ne vietiniame.
$canonByName = @{}
$pj = Join-Path $PSScriptRoot 'prefix_out.json'
if (-not (Test-Path $pj)) { $pj = $PrefixJson }
if ($pj -and (Test-Path $pj)) {
    $parsedPref = ConvertFrom-Json ([IO.File]::ReadAllText($pj))
    foreach ($x in @($parsedPref)) { $canonByName[[string]$x.name] = [string]$x.prefix }
    Write-Output ("  kanoniniu keliu uzkrauta: {0}" -f $canonByName.Count)
} else {
    Write-Output '  KLAIDA: nerastas prefix_out.json - source_path butu klaidingas.'
    exit 1
}

# db_album_match.csv cia buvo naudojamas source_path perrasyti senuoju DB keliu.
# Ta prasme jis turejo tol, kol DB keliai dar nebuvo kanoniniai. Po migracijos
# failas paseno ir eme daryti prieszinga: 10 albumu gaudavo iki migracijos
# buvusi kelia, todel importas ju nebeatpazindavo ir laike naujais. Saka isimta
# 2026-08-27 - source_path visada imamas is prefix_out.json, o slug generuojamas
# is to paties kelio zemiau.

$protoByDate = @{}
foreach ($a in (Load-Csv 'klajunas_archive_index.csv')) {
    if (-not $a.Data) { continue }
    if (-not $protoByDate.ContainsKey($a.Data)) { $protoByDate[$a.Data] = @() }
    $protoByDate[$a.Data] += $a.Nuoroda
}

# Nuotrauku talpyklos nera "informacijos saltinis" - jas imam tik tada, kai kito nera.
$photoHosts = 'picasaweb|photos\.app\.goo\.gl|plus\.google|flic\.kr|flickr|facebook|goo\.gl/photos'

$rows = @()
foreach ($rt in $roots) { Get-ChildItem $rt -Directory -Recurse -Depth 1 -EA SilentlyContinue |
    Where-Object { Test-Path (Join-Path $_.FullName 'album.json') } | Sort-Object Name | ForEach-Object {
        $name = $_.Name
        $a = Get-Content (Join-Path $_.FullName 'album.json') -Raw | ConvertFrom-Json
        # source_path turi rodyti ten, kur failai GULES B2 - t.y. i kanonini kelia.
        # Vietinis aplanko vardas cia netinka: DB per si lauka sieja albuma su B2.
        if (-not $canonByName.ContainsKey($name)) {
            Write-Output ("  praleista (nera kanoninio kelio): {0}" -f $name)
            return
        }
        $sourcePath = $canonByName[$name]

        $post = if ($postByAlbum.ContainsKey($name)) { $postByAlbum[$name] } else { '' }
        $dbs = ''; $other = ''
        if ($post -and $linksByPost.ContainsKey($post)) {
            $ll = @($linksByPost[$post])
            # bendras varzybu puslapis pirmiau uz konkretaus dalyvio rezultatus
            $dbCand = @($ll | Where-Object { $_.Tipas -eq 'dbsportas' -and $_.Nuoroda -match '/varz/\d+' })
            $general = @($dbCand | Where-Object { $_.Nuoroda -notmatch '/rezpar/' })
            if ($general.Count) { $dbs = $general[0].Nuoroda } elseif ($dbCand.Count) { $dbs = $dbCand[0].Nuoroda }
            $oCand = @($ll | Where-Object { $_.Tipas -eq 'kita' })
            $article = @($oCand | Where-Object { $_.Nuoroda -notmatch $photoHosts })
            if ($article.Count) { $other = $article[0].Nuoroda } elseif ($oCand.Count) { $other = $oCand[0].Nuoroda }
        }
        $klaj = $post
        if ($klaj -eq '' -and $a.date -and $protoByDate.ContainsKey([string]$a.date)) {
            $pp = @($protoByDate[[string]$a.date])
            if ($pp.Count -eq 1) { $klaj = $pp[0] }   # tik vienareiksmis atitikmuo
        }

        $rows += [pscustomobject]@{
            source_path      = $sourcePath
            title            = [string]$a.displayName
            slug             = ''
            event_date       = [string]$a.date
            event_date_end   = [string]$a.dateEnd
            location_name    = [string]$a.eventPlace
            author_name      = [string]$a.photographer
            copyright_text   = [string]$a.copyrightText
            description      = [string]$a.albumDescription
            dbsportas_url    = $dbs
            klajunas_url     = $klaj
            other_url        = $other
            visibility       = 'draft'
            download_enabled = 1
            media_count      = [string]$a.mediaCount
            notes_internal   = ('dateSource=' + [string]$a.dateSource + '; dateConfidence=' + [string]$a.dateConfidence + $(if ($postScore.ContainsKey($name)) { '; postMatch=' + $postScore[$name] } else { '' }))
        }
    }
}

if (-not $rows.Count) { Write-Output 'KLAIDA: albumu nerasta.'; exit 1 }

# slug reikalingas ON DUPLICATE KEY - generuojam is kelio, kad butu stabilus
foreach ($r in $rows) {
    if ($r.slug -eq '') {
        $s = ($r.source_path -replace '^albums/\d{4}/', '')
        $s = $s.ToLower() -replace '[^a-z0-9]+', '-'
        $r.slug = $s.Trim('-')
    }
}

$rows | Export-Csv $Out -NoTypeInformation -Encoding UTF8
Write-Output ("Albumu             : {0}" -f $rows.Count)
Write-Output ("  su aprasymu      : {0}" -f @($rows | Where-Object { $_.description }).Count)
Write-Output ("  su klajunas_url  : {0}" -f @($rows | Where-Object { $_.klajunas_url }).Count)
Write-Output ("  su dbsportas_url : {0}" -f @($rows | Where-Object { $_.dbsportas_url }).Count)
Write-Output ("  su other_url     : {0}" -f @($rows | Where-Object { $_.other_url }).Count)
Write-Output ("  su vieta         : {0}" -f @($rows | Where-Object { $_.location_name }).Count)
Write-Output ("CSV: {0}" -f $Out)
