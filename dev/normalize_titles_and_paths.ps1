<#
    Sutvarko albums_import.csv:

    1) Pavadinimai. Archyve jie buvo rasomi su data skliaustuose - "Moletu
       taures finalai (2006-05-23)". Viesam rodymui to nereikia: data ir taip
       matoma atskirai. Paliekam tik metus gale - "Moletu taures finalai 2006".
       Jei skliaustuose buvo ir vieta ("(2021-08-29, Rokantiskes)"), ji
       perkeliama i location_name, o ne dingsta.

    2) source_path. Ankstesnis CSV skaiciuotas dar pries datos raktu ir
       kanoninio vardo pataisymus, todel keliai nesutapo su tuo, kas realiai
       guli B2 ("2006-08-13__Visaginas-3x4_16" vs "2006-08-13_16__Visaginas-3x4").
       Perskaiciuojam tuo paciu PHP kodu, kuri naudoja serveris.

    Metu nuemimas kelio NEkeicia: canonical_album_prefix ir data skliaustuose,
    ir metus gale numeta vienodai. Tai patikrinama zingsnyje 3.
#>
param(
    [string]$Csv = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\albums_import.csv',
    [string]$Php = '',
    [switch]$Execute
)
$ErrorActionPreference = 'Stop'
if ($Php -eq '') {
    $Php = 'C:\Users\KLAJNA~1\AppData\Local\Temp\claude\C--Users-Klaj-nas-Documents-Claude-foto-klajunas-lt\ee9b9aef-6272-4900-ad34-133ecc991fe9\scratchpad\php\php.exe'
}
$rows = @(Import-Csv $Csv)
Write-Output ("eiluciu: {0}" -f $rows.Count)

# --- 1. pavadinimai ---
$reFull  = '^(.*?)\s*\((\d{4})-\d{2}-\d{2}(?:\s*[/-]\s*\d{2}(?:-\d{2})?)?(?:\s*,\s*([^)]+))?\)\s*$'
$reYear  = '^(.*?)\s*\((\d{4})\)\s*$'
$changed = @()
foreach ($r in $rows) {
    $t = [string]$r.title
    $name = ''; $year = ''; $place = ''
    if ($t -match $reFull)      { $name = $Matches[1]; $year = $Matches[2]; $place = [string]$Matches[3] }
    elseif ($t -match $reYear)  { $name = $Matches[1]; $year = $Matches[2] }
    else { continue }
    $name = $name.Trim()
    if ($name -eq '') { continue }
    # Jei metai pavadinime jau yra, antru kartu nekartojam.
    $new = if ($name -match ('\b' + $year + '\b')) { $name } else { $name + ' ' + $year }
    if ($place -ne '' -and [string]$r.location_name -eq '') { $r.location_name = $place.Trim() }
    if ($new -ne $t) { $changed += [pscustomobject]@{ Buvo = $t; Tapo = $new; Vieta = $place }; $r.title = $new }
}
Write-Output ("pavadinimu pataisyta: {0}" -f $changed.Count)
$changed | Select-Object -First 12 | ForEach-Object { Write-Output ("   {0,-46} -> {1}" -f $_.Buvo, $_.Tapo) }

# --- 2. keliai ---
$tmp = Join-Path $env:TEMP 'canon_in.json'
$out = Join-Path $env:TEMP 'canon_out.json'
$inp = @($rows | ForEach-Object {
        [pscustomobject]@{ name = [string]$_.slug; title = [string]$_.title; date = [string]$_.event_date; dateEnd = [string]$_.event_date_end }
    })
[IO.File]::WriteAllText($tmp, ($inp | ConvertTo-Json -Depth 4), (New-Object Text.UTF8Encoding($false)))
$script = Join-Path $PSScriptRoot 'canonical_prefixes.php'
$tmpScript = Join-Path $env:TEMP 'canonical_prefixes.php'
Copy-Item $script $tmpScript -Force
& $Php $tmpScript $tmp $out | Write-Output
$parsed = ConvertFrom-Json ([IO.File]::ReadAllText($out))
$calc = @($parsed)
if ($calc.Count -ne $rows.Count) { Write-Output 'KLAIDA: keliu kiekis nesutampa.'; exit 1 }

# Kelio perrasyti negalima aklai: dalies albumu failai tebeguli senuose,
# nekanoniniuose keliuose. Perrasius DB rodytu i neegzistuojanti aplanka ir
# albumas liktu be nuotrauku. Todel nauja kelia imam tik tada, kai jis B2
# tikrai yra; kitu atveju paliekam sena, jei failai ten.
$b2File = Join-Path $PSScriptRoot 'b2_prefixes.json'
$b2 = @{}
if (Test-Path $b2File) {
    $parsedB2 = ConvertFrom-Json ([IO.File]::ReadAllText($b2File))
    foreach ($x in @($parsedB2)) { $b2[[string]$x] = $true }
    Write-Output ("B2 aplanku sarase: {0}" -f $b2.Count)
} else {
    Write-Output 'KLAIDA: nerastas b2_prefixes.json - be jo kelio keisti negalima.'
    exit 1
}

$pathChanged = 0; $keptOld = @(); $neither = @()
for ($i = 0; $i -lt $rows.Count; $i++) {
    $new = [string]$calc[$i].prefix
    $old = [string]$rows[$i].source_path
    if ($new -eq $old) { continue }
    if ($b2.ContainsKey($new)) {
        if ($pathChanged -lt 12) { Write-Output ("   {0}`n     -> {1}" -f $old, $new) }
        $rows[$i].source_path = $new
        $pathChanged++
    } elseif ($b2.ContainsKey($old)) {
        $keptOld += ("{0}  (naujas {1} B2 nnera)" -f $old, $new)
    } else {
        $rows[$i].source_path = $new     # niekur dar neikelta - imam kanonini
        $neither += $new
        $pathChanged++
    }
}
Write-Output ("keliu pataisyta      : {0}" -f $pathChanged)
Write-Output ("paliktas senas kelias: {0}  (failai tebeguli ten)" -f $keptOld.Count)
$keptOld | Select-Object -First 10 | ForEach-Object { Write-Output ('   ' + $_) }
Write-Output ("dar neikelta i B2    : {0}" -f $neither.Count)

if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }
$rows | Export-Csv $Csv -NoTypeInformation -Encoding UTF8
Write-Output ("`nirasyta: {0}" -f $Csv)
