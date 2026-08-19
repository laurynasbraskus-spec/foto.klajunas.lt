<#
    Priskiria zymas albumams, kurie ju neturi.

    Iki siol zymos dėtos dviem asimis:
      dalyvavimas - "Organizuojam" arba "Dalyvaujam"
      rusis       - "Orientavimosi sportas", "Bėgimas", "Čiuožiam",
                    "Rogaining", "Lunatikai", "Takas daugiadienės",
                    "Čempionatas", "Uždarymas" ir pan.

    Is kur imama:
      - dalyvavimas: is klajunas.lt nuorodos kelio (/organizuojam/ arba
        /dalyvaujam/) - tai paties klubo priskyrimas, ne spejimas. Jei nuorodos
        nera, sios zymos NEdedam;
      - rusis: is pavadinimo raktazodziu.

    Esamu zymu nelieciam - tik pridedam truksitancias.
#>
param(
    [switch]$OnlyUntagged,
    [switch]$Execute
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

$tags = @{}
foreach ($t in (Db-Read 'SELECT id,name FROM tags')) { $tags[[string]$t.name] = [int]$t.id }
Write-Output ("zymu zodyne: {0}" -f $tags.Count)

$albums = Db-Read 'SELECT id,title,klajunas_url FROM albums ORDER BY event_date,id'
$have = @{}
foreach ($r in (Db-Read 'SELECT album_id,tag_id FROM album_tags')) {
    $a = [int]$r.album_id
    if (-not $have.ContainsKey($a)) { $have[$a] = @{} }
    $have[$a][[int]$r.tag_id] = $true
}

function Type-Tag([string]$title) {
    $t = $title.ToLower()
    if ($t -match 'rogaining|rogainingo') { return 'Rogaining' }
    if ($t -match 'lunatik') { return 'Lunatikai' }
    if ($t -match 'takas') { return 'Takas daugiadienės' }
    if ($t -match 'slidėmis|slidemis|žygis|zygis|žygiai') { return 'Čiuožiam' }
    if ($t -match 'maratonas|pusmaratonis|bėgimas|begimas|bėgimai|krosas|trail') { return 'Bėgimas' }
    if ($t -match 'uždarymas|uzdarymas') { return 'Uždarymas' }
    if ($t -match 'čempionatas|cempionatas|čempionatai') { return 'Čempionatas' }
    return 'Orientavimosi sportas'
}

$plan = @()
foreach ($a in $albums) {
    $id = [int]$a.id
    $cur = if ($have.ContainsKey($id)) { $have[$id] } else { @{} }
    if ($OnlyUntagged -and $cur.Count -gt 0) { continue }

    $want = @()
    $u = [string]$a.klajunas_url
    if ($u -match '/organizuojam/') { $want += 'Organizuojam' }
    elseif ($u -match '/dalyvaujam/') { $want += 'Dalyvaujam' }
    $want += (Type-Tag ([string]$a.title))

    foreach ($name in ($want | Sort-Object -Unique)) {
        if (-not $tags.ContainsKey($name)) { continue }
        if ($cur.ContainsKey($tags[$name])) { continue }
        $plan += [pscustomobject]@{ Id = $id; Title = [string]$a.title; Tag = $name; TagId = $tags[$name] }
    }
}

Write-Output ("`npridesim zymu: {0}   albumams: {1}" -f $plan.Count, (@($plan | Group-Object Id).Count))
$plan | Group-Object Id | Select-Object -First 14 | ForEach-Object {
    $g=$_.Group
    Write-Output ("   {0,-46} -> {1}" -f ([string]$g[0].Title).Substring(0,[Math]::Min(46,([string]$g[0].Title).Length)), (($g | ForEach-Object { $_.Tag }) -join ", "))
}
Write-Output ""
$plan | Group-Object Tag | Sort-Object -Property @{e = { -$_.Count }} | ForEach-Object {
    Write-Output ("   {0,-24} +{1}" -f $_.Name, $_.Count)
}
$still = @()
foreach ($a in $albums) {
    $id = [int]$a.id
    $has = ($have.ContainsKey($id) -and $have[$id].Count -gt 0) -or (@($plan | Where-Object { $_.Id -eq $id }).Count -gt 0)
    if (-not $has) { $still += ("#{0} {1}" -f $id, $a.title) }
}
Write-Output ("`nliktu be zymu: {0}" -f $still.Count)
$still | Select-Object -First 10 | ForEach-Object { Write-Output ('   ' + $_) }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$done = 0
foreach ($p in $plan) {
    Db-Write 'INSERT INTO album_tags (album_id, tag_id) VALUES (?,?)' @($p.Id, $p.TagId) | Out-Null
    $done++
}
Write-Output ("`npridėta zymu: {0}" -f $done)
