<#
    Suderina vietini archyva su DB: pavadinimus, datas, vietas ir aplanku vardus.

    Albumas atpazistamas pagal NUOTRAUKU VARDUS, ne pagal pavadinima ar data -
    tik failai nemeluoja. Pavadinimai ka tik keisti, tad pagal juos gretinti
    butu neimanoma.

    Kas rasoma i album.json: displayName, date, dateEnd, eventPlace.
    Aplankas pervadinamas i toki pati varda, koks bus B2 (kanoninio kelio
    paskutine dalis), kad abi puses sutaptu.

    Be -Execute nieko nekeicia.
#>
param(
    [string]$Base = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas',
    [switch]$NoRename,
    [switch]$Execute
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

# --- DB: albumas -> nuotrauku vardai ---
$albums = Db-Read 'SELECT id,title,event_date,event_date_end,location_name,source_path FROM albums'
$photos = Db-Read 'SELECT album_id,original_filename FROM photos'
$dbNames = @{}
foreach ($p in $photos) {
    $id = [int]$p.album_id
    $n = (([string]$p.original_filename) -replace '^\d{4}_', '').ToLower()
    if (-not $dbNames.ContainsKey($id)) { $dbNames[$id] = @{} }
    $dbNames[$id][$n] = $true
}
$byId = @{}
foreach ($a in $albums) { $byId[[int]$a.id] = $a }
Write-Output ("DB albumu: {0}" -f $albums.Count)

# --- kanoniniai keliai tuo paciu PHP kodu ---
$php = 'C:\Users\KLAJNA~1\AppData\Local\Temp\claude\C--Users-Klaj-nas-Documents-Claude-foto-klajunas-lt\ee9b9aef-6272-4900-ad34-133ecc991fe9\scratchpad\php\php.exe'
$enc = New-Object Text.UTF8Encoding($false)
$inF = Join-Path $env:TEMP 'sync_in.json'
$outF = Join-Path $env:TEMP 'sync_out.json'
$script = Join-Path $env:TEMP 'canonical_prefixes.php'
Copy-Item (Join-Path $PSScriptRoot 'canonical_prefixes.php') $script -Force
$inp = @($albums | ForEach-Object {
        [pscustomobject]@{ name = [string]$_.id; title = [string]$_.title; date = [string]$_.event_date; dateEnd = [string]$_.event_date_end }
    })
[IO.File]::WriteAllText($inF, ($inp | ConvertTo-Json -Depth 4), $enc)
& $php $script $inF $outF | Out-Null
$parsed = ConvertFrom-Json ([IO.File]::ReadAllText($outF))
$canon = @{}
foreach ($x in @($parsed)) { $canon[[string]$x.name] = [string]$x.prefix }

# --- vietiniai albumai ---
$plan = @(); $unmatched = @()
foreach ($root in @((Join-Path $Base 'sutvarkyta\albums'), (Join-Path $Base 'perkelta i web'))) {
    if (-not (Test-Path $root)) { continue }
    Get-ChildItem $root -Directory -Recurse -Depth 1 -EA SilentlyContinue |
        Where-Object { Test-Path (Join-Path $_.FullName 'album.json') } | ForEach-Object {
            $dir = $_
            $orig = Join-Path $dir.FullName 'originals'
            $names = @{}
            if (Test-Path $orig) {
                foreach ($f in (Get-ChildItem $orig -File -EA SilentlyContinue)) { $names[$f.Name.ToLower()] = $true }
            }
            if (-not $names.Count) { return }
            # Vien sutapimu skaicius klaidina: "IMG_1234.jpg" pasitaiko keliuose
            # albumuose, todel didelis DB albumas surenka daug atsitiktiniu
            # sutapimu su mazu vietiniu. Reikalaujam, kad sutaptu didele dalis
            # ABIEJU rinkiniu - tada atsitiktinumas nebeuztenka.
            $bestId = 0; $bestScore = 0.0; $bestHit = 0
            foreach ($id in $dbNames.Keys) {
                $hit = 0
                foreach ($n in $names.Keys) { if ($dbNames[$id].ContainsKey($n)) { $hit++ } }
                if ($hit -eq 0) { continue }
                $score = [double]$hit / [Math]::Max($names.Count, $dbNames[$id].Count)
                if ($score -gt $bestScore) { $bestScore = $score; $bestId = $id; $bestHit = $hit }
            }
            # 1:1 ir be spejimu. Reikalaujam beveik visisko sutapimo (>=95%):
            # jei vietiniame aplanke yra bent kelios nuotraukos, kuriu DB albume
            # nera, tai jau nera tas pats albumas, o supainiojus atskirti butu
            # nebeimanoma. Vienpusio sutapimo nepakanka - dalis rinkiniu
            # persidengia bendriniais "IMG_1234.jpg" vardais.
            if ($bestId -eq 0 -or $bestScore -lt 0.95 -or $bestHit -lt 2) {
                $unmatched += ("{0}  ({1} failu, geriausias sutapimas {2:P0})" -f $dir.Name, $names.Count, $bestScore)
                return
            }
            $a = $byId[$bestId]
            $newName = ($canon[[string]$bestId] -split '/')[-1]
            $plan += [pscustomobject]@{
                Path = $dir.FullName; Folder = $dir.Name; Id = $bestId
                Title = [string]$a.title; Date = [string]$a.event_date; DateEnd = [string]$a.event_date_end
                Place = [string]$a.location_name; NewFolder = $newName; Hit = $bestHit; Files = $names.Count
            }
        }
}
# Vienas albumas - vienam albumui. Jei i ta pati DB irasa taiko du vietiniai
# aplankai, nezinia kuris tikrasis, tad NEliecam nei vieno ir isvardijam.
$dup = @($plan | Group-Object Id | Where-Object { $_.Count -gt 1 })
if ($dup.Count) {
    $badIds = @{}
    foreach ($g in $dup) {
        $badIds[[int]$g.Name] = $true
        Write-Output ("DVIPRASMISKA - i DB #{0} taiko {1} aplankai:" -f $g.Name, $g.Count)
        $g.Group | ForEach-Object { Write-Output ('   ' + $_.Folder) }
    }
    $plan = @($plan | Where-Object { -not $badIds.ContainsKey([int]$_.Id) })
}
Write-Output ("vietiniu albumu susieta 1:1: {0}   nesusieta: {1}" -f $plan.Count, $unmatched.Count)
$unmatched | Select-Object -First 10 | ForEach-Object { Write-Output ('   nesusieta: ' + $_) }

$rename = @($plan | Where-Object { $_.Folder -ne $_.NewFolder })
Write-Output ("`npervadinsim aplanku: {0}" -f $rename.Count)
$rename | Select-Object -First 10 | ForEach-Object { Write-Output ("   {0}`n     -> {1}" -f $_.Folder, $_.NewFolder) }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$jsonDone = 0; $renDone = 0; $errs = @()
foreach ($p in $plan) {
    $f = Join-Path $p.Path 'album.json'
    try {
        $a = Get-Content $f -Raw | ConvertFrom-Json
        $a | Add-Member -NotePropertyName displayName -NotePropertyValue $p.Title -Force
        $a | Add-Member -NotePropertyName date -NotePropertyValue $p.Date -Force
        $a | Add-Member -NotePropertyName dateEnd -NotePropertyValue $p.DateEnd -Force
        if ($p.Place -ne '') { $a | Add-Member -NotePropertyName eventPlace -NotePropertyValue $p.Place -Force }
        $a | Add-Member -NotePropertyName albumId -NotePropertyValue $p.Id -Force
        [IO.File]::WriteAllText($f, ($a | ConvertTo-Json -Depth 8), $enc)
        $jsonDone++
    } catch { $errs += ("album.json {0}: {1}" -f $p.Folder, $_.Exception.Message) }
}
Write-Output ("album.json atnaujinta: {0}" -f $jsonDone)

if (-not $NoRename) {
    foreach ($p in $rename) {
        $parent = Split-Path $p.Path -Parent
        $dest = Join-Path $parent $p.NewFolder
        if (Test-Path $dest) { $errs += ("pervadinti negalima - jau yra: " + $p.NewFolder); continue }
        try { Rename-Item -LiteralPath $p.Path -NewName $p.NewFolder -ErrorAction Stop; $renDone++ }
        catch { $errs += ("pervadinti nepavyko {0}: {1}" -f $p.Folder, $_.Exception.Message) }
    }
    Write-Output ("aplanku pervadinta: {0}" -f $renDone)
}
if ($errs.Count) {
    Write-Output ""
    Write-Output ("klaidos ({0}):" -f $errs.Count)
    $errs | Select-Object -First 15 | ForEach-Object { Write-Output ('   ' + $_) }
}
