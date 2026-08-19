<#
    Grazina pavadinimus i pavidala "Pavadinimas (Metai)".

    Vieta is pavadinimo isimama: galerija ja ir taip rodo atskira eilute po
    pavadinimu, todel skliaustuose ji tik kartojosi.

    Keiciama TIK paskutine skliaustu grupe, kurioje yra metai: is jos paliekami
    vien metai. Kitos skliaustu grupes ("(Spring cup WRE, ilga)",
    "(Prienai, Jonava)", "(50)") lieka nepaliestos - jos yra pavadinimo dalis.
#>
param([switch]$Execute)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

$albums = Db-Read 'SELECT id,title,event_date FROM albums ORDER BY event_date,id'
$plan = @()
foreach ($a in $albums) {
    $t = ([string]$a.title).Trim()
    $m = [regex]::Match($t, '^(.*)\(([^()]*\d{4}[^()]*)\)(\s*[^()]*)$')
    if (-not $m.Success) { continue }
    $inner = $m.Groups[2].Value
    $ym = [regex]::Match($inner, '\b((19|20)\d{2})\b')
    if (-not $ym.Success) { continue }
    $year = $ym.Groups[1].Value
    if ($inner.Trim() -eq $year) { continue }          # jau tik metai
    $new = ($m.Groups[1].Value + '(' + $year + ')' + $m.Groups[3].Value)
    $new = ($new -replace '\s{2,}', ' ').Trim()
    if ($new -ne $t) { $plan += [pscustomobject]@{ Id = [int]$a.id; Buvo = $t; Tapo = $new } }
}
Write-Output ("keisim: {0}" -f $plan.Count)
$plan | Select-Object -First 15 | ForEach-Object { Write-Output ("   {0,-56} -> {1}" -f $_.Buvo, $_.Tapo) }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }
$done = 0
foreach ($p in $plan) { $done += Db-Write 'UPDATE albums SET title=?, updated_at=NOW() WHERE id=?' @($p.Tapo, $p.Id) }
Write-Output ("`npakeista: {0}" -f $done)
