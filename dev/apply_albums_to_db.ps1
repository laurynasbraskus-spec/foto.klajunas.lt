<#
    Perkelia albums_import.csv i DB tiesiogiai per db-api.php - be Export/Import
    naršyklėje.

    Albumas surandamas pagal source_path (tai vienintelis laukas, siejantis DB
    irasa su B2 aplanku). Jei tokio nera - pagal slug'a.

    Rezimai:
      fill      (numatytas) - rasom tik ten, kur DB laukas tuscias
      overwrite            - perrasom viska, kas CSV nera tuscia
      titles               - liecia TIK pavadinima (ir tik ten, kur DB
                             pavadinimas atrodo kaip aplanko vardas)

    Be -Execute nieko nekeicia, tik parodo, kas butu daroma.
#>
param(
    [string]$Csv = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\albums_import.csv',
    [ValidateSet('fill', 'overwrite', 'titles')][string]$Mode = 'fill',
    [switch]$Execute,
    [switch]$Insert          # ar kurti naujus irasus tiems, kuriu DB dar nera
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

$rows = @(Import-Csv $Csv)
if ($rows.Count -lt 2) { Write-Output 'KLAIDA: CSV neissiskaide.'; exit 1 }
Write-Output ("CSV eiluciu: {0}" -f $rows.Count)

$db = Db-Read 'SELECT id, source_path, slug, title, event_date, event_date_end, location_name, author_name, copyright_text, description, dbsportas_url, klajunas_url, other_url, visibility FROM albums'
Write-Output ("DB albumu  : {0}" -f $db.Count)

$byPath = @{}; $bySlug = @{}
foreach ($d in $db) {
    if ($d.source_path) { $byPath[[string]$d.source_path] = $d }
    if ($d.slug)        { $bySlug[[string]$d.slug] = $d }
}

# Pavadinimas, kuri sukure automatinis B2 skenavimas: tai aplanko vardas, ne
# zmogaus pavadinimas. Butent sitie ir turi buti pakeisti.
function Is-FolderTitle([string]$t) {
    if ($t -match '^albums/') { return $true }
    if ($t -match '^\d{4}-\d{2}-\d{2}.*__') { return $true }
    if ($t -match '^[A-Za-z0-9]+(-[A-Za-z0-9]+){2,}$' -and $t -notmatch '\s') { return $true }
    # Senasis archyvo pavidalas: "Visaginas 3x4 (2006-08-13/16)". Data yra
    # atskirame lauke, todel pavadinime ji tik kartojasi. Toki pavadinima
    # laikom laikinu ir keiciam tvarkingu.
    if ($t -match '\((19|20)\d{2}([-/]\d{2})') { return $true }
    if ($t -match '\((19|20)\d{2}\)\s*$') { return $true }
    return $false
}

$textCols = @('title', 'location_name', 'author_name', 'copyright_text', 'description',
    'dbsportas_url', 'klajunas_url', 'other_url')
$dateCols = @('event_date', 'event_date_end')

# Pavadinimas su vienais metais prie kitu metu datos yra pries tai ivykusi
# klaida, ne duomenys. Tokio irasyti negalima - jis atrodytu teisingas ir
# niekas nebepastebetu. Praleidziam ir isvardijam gale.
$conflicts = @()
function Title-Conflicts($row) {
    $d = [string]$row.event_date
    if ($d -notmatch '^(\d{4})-') { return $false }
    $dy = $Matches[1]
    $ys = @([regex]::Matches([string]$row.title, '\b(19|20)\d{2}\b') | ForEach-Object { $_.Value })
    return ($ys.Count -gt 0 -and $ys -notcontains $dy)
}

# B2 aplanku sarasas - kad nekurtume irasu albumams, kuriu failu dar nera.
$b2Prefixes = @{}
$b2File = Join-Path $PSScriptRoot 'b2_prefixes.json'
if (Test-Path $b2File) {
    $parsedB2 = ConvertFrom-Json ([IO.File]::ReadAllText($b2File))
    foreach ($x in @($parsedB2)) { $b2Prefixes[[string]$x] = $true }
    Write-Output ("B2 aplanku sarase: {0}" -f $b2Prefixes.Count)
} elseif ($Insert) {
    Write-Output 'KLAIDA: nerastas b2_prefixes.json - be jo kurtume tuscius albumus.'
    exit 1
}

$upd = 0; $ins = 0; $skip = 0; $noFiles = 0; $plan = @()
foreach ($r in $rows) {
    $sp = [string]$r.source_path
    # TIK pagal source_path. Slug'o kelias buvo paveldetas is senesnio,
    # netikslaus sugretinimo ir jau davė klaidą: albumas "Vilnius rogaining"
    # gavo "Sezono uždarymas" pavadinima vien todel, kad abu vyko 2017-11-11.
    # B2 kelias yra vienintelis dalykas, siejantis irasa su tikrais failais.
    $cur = $null
    if ($sp -and $byPath.ContainsKey($sp)) { $cur = $byPath[$sp] }

    if (-not $cur) {
        # Kurti irasa albumui, kurio failu B2 dar nera, reikstu tuscia albuma
        # galerijoje. Tokius praleidziam - jie atsiras, kai bus ikelti failai.
        if ($Insert -and $b2Prefixes.Count -and -not $b2Prefixes.ContainsKey($sp)) { $noFiles++; continue }
        if ($Insert) { $plan += [pscustomobject]@{ Veiksmas = 'INSERT'; Id = 0; Path = $sp; Title = $r.title; Laukai = 'visi' }; $ins++ }
        else { $skip++ }
        continue
    }

    $titleBad = Title-Conflicts $r
    if ($titleBad) { $conflicts += ("{0}  (data {1})" -f $r.title, $r.event_date) }

    $set = @{}
    if ($Mode -eq 'titles') {
        if ($r.title -and (Is-FolderTitle ([string]$cur.title)) -and $r.title -ne [string]$cur.title) {
            $set['title'] = [string]$r.title
        }
    } else {
        foreach ($c in ($textCols + $dateCols)) {
            if ($c -eq 'title' -and $titleBad) { continue }
            $new = [string]$r.$c
            if ($new -eq '') { continue }
            $old = [string]$cur.$c
            if ($Mode -eq 'overwrite') {
                if ($new -ne $old) { $set[$c] = $new }
            } else {
                # fill: tuscia arba aplanko vardo pavidalo pavadinimas laikomas tusciu
                if ($old -eq '' -or ($c -eq 'title' -and (Is-FolderTitle $old))) { $set[$c] = $new }
            }
        }
    }
    if (-not $set.Count) { $skip++; continue }
    $plan += [pscustomobject]@{ Veiksmas = 'UPDATE'; Id = [int]$cur.id; Path = $sp; Title = $r.title; Laukai = (($set.Keys | Sort-Object) -join ',') ; Set = $set }
    $upd++
}

Write-Output ""
Write-Output ("Keisti  : {0}" -f $upd)
Write-Output ("Kurti   : {0}" -f $ins)
Write-Output ("Nieko   : {0}" -f $skip)
Write-Output ("Be failu B2 (nekuriam): {0}" -f $noFiles)
if ($conflicts.Count) {
    Write-Output ""
    Write-Output ("Pavadinimas NErasomas - metai pavadinime nesutampa su data ({0}):" -f $conflicts.Count)
    $conflicts | ForEach-Object { Write-Output ('   ' + $_) }
}
Write-Output ""
$plan | Where-Object { $_.Veiksmas -eq 'UPDATE' } | Select-Object -First 15 | ForEach-Object {
    Write-Output ("  #{0,-4} {1,-46} <- {2}" -f $_.Id, $_.Title, $_.Laukai)
}
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$done = 0; $errs = @()
foreach ($p in $plan) {
    try {
        if ($p.Veiksmas -eq 'UPDATE') {
            $cols = @($p.Set.Keys)
            $sql = 'UPDATE albums SET ' + (($cols | ForEach-Object { "$_ = ?" }) -join ', ') + ', updated_at = NOW() WHERE id = ?'
            $prm = @($cols | ForEach-Object { $p.Set[$_] }) + @($p.Id)
            Db-Write $sql $prm | Out-Null
        } else {
            $src = $rows | Where-Object { [string]$_.source_path -eq $p.Path } | Select-Object -First 1
            # uuid yra privalomas ir unikalus - be jo antras irasas krinta su
            # 'Duplicate entry' del tuscios reiksmes.
            $sql = 'INSERT INTO albums (uuid, source_type, source_path, slug, title, event_date, event_date_end, location_name, author_name, copyright_text, description, dbsportas_url, klajunas_url, other_url, visibility, download_enabled, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())'
            $nz = { param($v) if ([string]$v -eq '') { $null } else { [string]$v } }
            $prm = @([Guid]::NewGuid().ToString(), 'b2', $p.Path, [string]$src.slug, [string]$src.title,
                (& $nz $src.event_date), (& $nz $src.event_date_end),
                [string]$src.location_name, [string]$src.author_name, [string]$src.copyright_text,
                [string]$src.description, [string]$src.dbsportas_url, [string]$src.klajunas_url,
                [string]$src.other_url, 'draft', 1)
            Db-Write $sql $prm | Out-Null
        }
        $done++
    } catch {
        $errs += ("{0} #{1}: {2}" -f $p.Veiksmas, $p.Id, $_.Exception.Message)
    }
}
Write-Output ""
Write-Output ("Ivykdyta: {0}   klaidu: {1}" -f $done, $errs.Count)
$errs | Select-Object -First 10 | ForEach-Object { Write-Output ('   ' + $_) }
