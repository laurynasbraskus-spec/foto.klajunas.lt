<#
    Perkuria prefix_out.json is vietinio archyvo.

    Si failą naudoja upload_and_move.ps1: is jo ima albumo kelia, data,
    patikimuma ir kanonini B2 kelia. Iki siol jis buvo sudetas vienkartiniu
    budu, todel po album.json pakeitimu (pvz. patvirtinus datas) likdavo
    pasenes ir keliamasis skriptas albumu nebematydavo.

    Kanoninis kelias skaiciuojamas canonical_prefixes.php - tuo paciu kodu,
    kuri naudoja serveris. Perrasyti ta logika PowerShellyje butu rizikinga.
#>
param(
    [string]$Base = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas',
    [string]$Out  = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\dev\prefix_out.json',
    [string]$Php  = 'C:\Users\KLAJNA~1\AppData\Local\Temp\claude\C--Users-Klaj-nas-Documents-Claude-foto-klajunas-lt\ee9b9aef-6272-4900-ad34-133ecc991fe9\scratchpad\php\php.exe'
)
$ErrorActionPreference = 'Stop'

$items = @()
foreach ($root in @((Join-Path $Base 'sutvarkyta\albums'), (Join-Path $Base 'perkelta i web'))) {
    if (-not (Test-Path $root)) { continue }
    Get-ChildItem $root -Directory -Recurse -Depth 1 -EA SilentlyContinue |
        Where-Object { Test-Path (Join-Path $_.FullName 'album.json') } | ForEach-Object {
            $a = Get-Content (Join-Path $_.FullName 'album.json') -Raw | ConvertFrom-Json
            $items += [pscustomobject]@{
                name    = $_.Name
                path    = $_.FullName
                title   = [string]$a.displayName
                date    = [string]$a.date
                dateEnd = [string]$a.dateEnd
                conf    = [string]$a.dateConfidence
            }
        }
}
Write-Output ("albumu archyve: {0}" -f $items.Count)
if ($items.Count -lt 2) { Write-Output 'KLAIDA: per mazai albumu - nutraukiam.'; exit 1 }

$enc = New-Object Text.UTF8Encoding($false)
$in  = Join-Path $env:TEMP 'prefix_in.json'
$tmp = Join-Path $env:TEMP 'prefix_out_tmp.json'
[IO.File]::WriteAllText($in, ($items | ConvertTo-Json -Depth 5), $enc)
# PHP negali atidaryti keliu su lietuviskomis raidemis - skripta kopijuojam.
$script = Join-Path $env:TEMP 'canonical_prefixes.php'
Copy-Item (Join-Path $PSScriptRoot 'canonical_prefixes.php') $script -Force
& $Php $script $in $tmp | Write-Output

$parsed = ConvertFrom-Json ([IO.File]::ReadAllText($tmp))
$res = @($parsed)
if ($res.Count -ne $items.Count) { Write-Output 'KLAIDA: rezultatu kiekis nesutampa.'; exit 1 }
$noPrefix = @($res | Where-Object { [string]$_.prefix -eq '' }).Count
if ($noPrefix -gt 0) { Write-Output ("KLAIDA: {0} irasu be kelio." -f $noPrefix); exit 1 }

[IO.File]::WriteAllText($Out, ($res | ConvertTo-Json -Depth 5), $enc)
Write-Output ("irasyta: {0}" -f $Out)
$byConf = $res | Group-Object conf | Sort-Object Name
foreach ($g in $byConf) { Write-Output ("   {0,-24} {1}" -f $(if ($g.Name) { $g.Name } else { '(tuscia)' }), $g.Count) }
