<#
    Perkelia i DB tai, ka Laurynas pataise perziuros lenteleje.

    Lentele buvo sugeneruota anksciau nei paskutiniai automatiniai papildymai,
    todel TUSTI jos langeliai NELAIKOMI trynimu - imamos tik uzpildytos
    reiksmes, kurios skiriasi nuo DB.

    Pavadinimas imamas is stulpelio "Tavo pataisymas". Neliecami tie, kur
    pavadinimo metai nesutampa su renginio data - toks irasas atrodytu
    teisingas, bet klaidintu (zr. sarasa gale).
#>
param(
    [string]$Sheet = 'C:\Users\KLAJNA~1\AppData\Local\Temp\claude\C--Users-Klaj-nas-Documents-Claude-foto-klajunas-lt\ee9b9aef-6272-4900-ad34-133ecc991fe9\scratchpad\ret_albumai.csv',
    [switch]$Execute
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

$rows = @(Import-Csv $Sheet)
$db = Db-Read 'SELECT id,title,event_date,event_date_end,location_name,author_name,klajunas_url,dbsportas_url FROM albums'
$byId = @{}
foreach ($x in $db) { $byId[[string]$x.id] = $x }

function Clean([string]$v) {
    if ($v -match '^(\d{4}-\d{2}-\d{2}) 00:00:00$') { return $Matches[1] }
    return $v
}

$plan = @(); $skipped = @(); $noRow = @()
foreach ($r in $rows) {
    $id = [string]$r.ID
    if ($id -notmatch '^\d+$') { $noRow += ("{0}: {1}" -f $id, $r.'Tavo pataisymas'); continue }
    if (-not $byId.ContainsKey($id)) { $noRow += ("{0}: albumo DB nebera" -f $id); continue }
    $cur = $byId[$id]
    $set = @{}

    # duomenu laukai - tik uzpildyti
    foreach ($pair in @(@('Data', 'event_date'), @('Pabaiga', 'event_date_end'), @('Vieta', 'location_name'),
            @('Fotografas', 'author_name'), @('klajunas.lt', 'klajunas_url'), @('dbsportas.lt', 'dbsportas_url'))) {
        $v = Clean ([string]$r.($pair[0]))
        if ($v -eq '') { continue }
        if ($v -ne [string]$cur.($pair[1])) { $set[$pair[1]] = $v }
    }

    # pavadinimas
    $newTitle = ([string]$r.'Tavo pataisymas').Trim()
    if ($newTitle -ne '' -and $newTitle -ne [string]$cur.title) {
        # kokia data galios po sio irasymo
        $eff = if ($set.ContainsKey('event_date')) { $set['event_date'] } else { [string]$cur.event_date }
        $ty = @([regex]::Matches($newTitle, '\b(19|20)\d{2}\b') | ForEach-Object { $_.Value })
        $dy = ''
        if ($eff -match '^(\d{4})-') { $dy = $Matches[1] }
        if ($ty.Count -gt 0 -and $dy -ne '' -and $ty -notcontains $dy) {
            $skipped += ("#{0}  '{1}'  - pavadinimo metai {2}, o data {3}" -f $id, $newTitle, ($ty -join '/'), $eff)
        } else {
            $set['title'] = $newTitle
        }
    }

    if ($set.Count) { $plan += [pscustomobject]@{ Id = [int]$id; Set = $set; Title = [string]$cur.title } }
}

Write-Output ("keisim albumu: {0}" -f $plan.Count)
foreach ($p in $plan) {
    Write-Output ("   #{0,-5} {1}" -f $p.Id, (($p.Set.Keys | Sort-Object | ForEach-Object { "$_='" + $p.Set[$_] + "'" }) -join '  '))
}
if ($skipped.Count) {
    Write-Output ""
    Write-Output ("NEliesta - pavadinimo metai nesutampa su data ({0}):" -f $skipped.Count)
    $skipped | ForEach-Object { Write-Output ('   ' + $_) }
}
if ($noRow.Count) {
    Write-Output ""
    Write-Output ("Eilutes be albumo DB ({0}) - reikia atskiro veiksmo:" -f $noRow.Count)
    $noRow | ForEach-Object { Write-Output ('   ' + $_) }
}
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$done = 0
foreach ($p in $plan) {
    $cols = @($p.Set.Keys)
    $sql = 'UPDATE albums SET ' + (($cols | ForEach-Object { "$_ = ?" }) -join ', ') + ', updated_at = NOW() WHERE id = ?'
    $done += Db-Write $sql (@($cols | ForEach-Object { $p.Set[$_] }) + @($p.Id))
}
Write-Output ("`natnaujinta: {0}" -f $done)
