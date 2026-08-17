<#
    Sugretina albumus su dbsportas.lt varzybomis ir siulo vieta bei nuoroda.

    Taisykle (kaip nurode Laurynas): jei sutampa data IR bent siek tiek
    pavadinimas - tai tos pacios varzybos.

    Data lyginama visame albumo laikotarpyje (daugiadieniams - nuo pradzios iki
    pabaigos). Pavadinimo panasumas skaiciuojamas pagal bendrus zodzius,
    nuemus lietuviskas raides ir bendrinius zodzius, kurie sutampa visur
    ("taure", "cempionatas", "begimas").

    Rasoma tik tada, kai vieta DB tuscia arba kai skiriasi ir pridedamas
    -Overwrite. Nuoroda pridedama, jei jos dar nera.
#>
param(
    [switch]$Overwrite,
    [switch]$Execute,
    [string]$Db = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\dbsportas_varzybos.csv',
    [string]$Out = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\dbsportas_sugretinimas.csv'
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

function Fold([string]$s) {
    $s = $s.ToLower()
    $map = @{ 'ą'='a';'č'='c';'ę'='e';'ė'='e';'į'='i';'š'='s';'ų'='u';'ū'='u';'ž'='z' }
    foreach ($k in $map.Keys) { $s = $s -replace $k, $map[$k] }
    $s = (($s -replace '[^a-z0-9 ]', ' ') -replace '\s+', ' ').Trim()
    # dbsportas trumpina ("Lietuvos čemp. vidutinė"), archyve rasoma pilnai
    # ("LT čempionatas") - be sio suvienodinimo tie patys startai neturi nė
    # vieno bendro zodzio.
    $syn = @(
        @('\bcemp\b', 'cempionatas'), @('\bcempionato\b', 'cempionatas'),
        @('\bcempionate\b', 'cempionatas'), @('\bcempionatai\b', 'cempionatas'),
        @('\blt\b', 'lietuvos'), @('\blc\b', 'lietuvos cempionatas'),
        @('\blosf\b', 'lietuvos'), @('\bvidut\b', 'vidutine'),
        @('\bdaugiadiene\b', 'daugiadienes'), @('\betapas\b', 'etapo'), @('\bet\b', 'etapo')
    )
    foreach ($p in $syn) { $s = $s -replace $p[0], $p[1] }
    return ($s -replace '\s+', ' ').Trim()
}
$stop = @('taure','taures','begimas','begimo','begte','lietuvos','os','m','ir','su','cempionatas',
          'cempionatai','varzybos','etapas','etapo','dienos','trasoje','sportas','orientavimosi','klajuno',
          # Sie zodziai sutampa atsitiktinai ir jau davė klaidingu poru:
          # "Ežerų šventė" <-> "Jūros šventė", "MIX 2016" <-> "WUOC relay mix".
          'svente','sventes','mix','relay','open','cup','sprintas','estafete','estafetes')
function Tok([string]$s) {
    return , @((Fold $s) -split ' ' | Where-Object { $_.Length -ge 3 })
}
# Bendriniai zodziai ("taure", "cempionatas") sutampa pusėje visu varzybu, todel
# vieni patys nieko neirodo. Bet ir isvis ju atmesti negalima: "Klajūno taurė"
# susideda TIK is tokiu zodziu, ir be ju albumas nesutapdavo su savimi paciu.
# Todel jie skaiciuojami, tik puse svorio: reikšmingas sutapimas = 2 taškai,
# bendrinis = 1. Priimam nuo 2 tasku - t.y. arba vienas retas zodis, arba du
# bendriniai is eiles.
function Score($mine, $their) {
    $s = 0
    foreach ($t in $mine) {
        if ($their -notcontains $t) { continue }
        $s += $(if ($stop -contains $t) { 1 } else { 2 })
    }
    return $s
}

$comps = @(Import-Csv $Db)
$byDate = @{}
foreach ($c in $comps) {
    $d = [string]$c.Data
    if (-not $byDate.ContainsKey($d)) { $byDate[$d] = @() }
    $byDate[$d] += $c
}
Write-Output ("dbsportas varzybu: {0}   skirtingu datu: {1}" -f $comps.Count, $byDate.Count)

$albums = Db-Read "SELECT id,title,event_date,event_date_end,location_name,dbsportas_url FROM albums WHERE event_date IS NOT NULL AND event_date<>'' ORDER BY event_date"
# "Nepriskirtu nuotrauku" rinkiniai nera renginys - ju datos apima menesius, tad
# bet koks sutapimas butu atsitiktinis.
$albums = @($albums | Where-Object { [string]$_.title -notmatch '(?i)^mix\b|nepriskirt|personalij|plakat' })
Write-Output ("albumu su data (be nepriskirtu rinkiniu): {0}" -f $albums.Count)

$res = @()
foreach ($a in $albums) {
    $start = [datetime][string]$a.event_date
    $end = if ([string]$a.event_date_end -ne '') { [datetime][string]$a.event_date_end } else { $start }
    $mine = Tok ([string]$a.title)
    $best = $null; $bestScore = -1
    for ($d = $start; $d -le $end; $d = $d.AddDays(1)) {
        $key = $d.ToString('yyyy-MM-dd')
        if (-not $byDate.ContainsKey($key)) { continue }
        foreach ($c in $byDate[$key]) {
            $their = Tok ([string]$c.Pavadinimas)
            $hit = Score $mine $their
            # vienintele tos dienos varzyba be zodziu sutapimo dar nera atsakymas,
            # bet ji verta parodyti - todel gauna 0, o ne neigiama.
            if ($hit -gt $bestScore) { $bestScore = $hit; $best = $c }
        }
    }
    if (-not $best) { continue }
    $res += [pscustomobject]@{
        Id = [int]$a.id
        Albumas = [string]$a.title
        Data = [string]$a.event_date
        VietaDB = [string]$a.location_name
        VietaDbsportas = [string]$best.Vietove
        VarzybosDbsportas = [string]$best.Pavadinimas
        Sutapo = $bestScore
        Nuoroda = [string]$best.Nuoroda
        TuriNuoroda = $(if ([string]$a.dbsportas_url -ne '') { 'taip' } else { 'ne' })
    }
}
$res | Sort-Object -Property @{e = { -$_.Sutapo }}, Data | Export-Csv $Out -NoTypeInformation -Encoding UTF8

$strong = @($res | Where-Object { [int]$_.Sutapo -ge 2 })
$weak = @($res | Where-Object { [int]$_.Sutapo -lt 2 })
Write-Output ""
Write-Output ("rasta pagal data ir pavadinima : {0}" -f $strong.Count)
Write-Output ("rasta tik pagal data           : {0}  (nerasomi be perziuros)" -f $weak.Count)
$newPlace = @($strong | Where-Object { $_.VietaDB -eq '' -and $_.VietaDbsportas -ne '' })
$difPlace = @($strong | Where-Object { $_.VietaDB -ne '' -and $_.VietaDbsportas -ne '' -and (Fold $_.VietaDB) -ne (Fold $_.VietaDbsportas) })
$newLink = @($strong | Where-Object { $_.TuriNuoroda -eq 'ne' })
Write-Output ("  iraso vieta (buvo tuscia)    : {0}" -f $newPlace.Count)
Write-Output ("  vieta skiriasi               : {0}  (raso tik su -Overwrite)" -f $difPlace.Count)
Write-Output ("  prideda dbsportas nuoroda    : {0}" -f $newLink.Count)
Write-Output ("`nCSV: {0}" -f $Out)
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$upd = 0
foreach ($r in $strong) {
    $sets = @(); $vals = @()
    if ($r.VietaDbsportas -ne '') {
        if ($r.VietaDB -eq '') {
            $sets += 'location_name=?'; $vals += $r.VietaDbsportas
        } elseif ((Fold $r.VietaDB) -ne (Fold $r.VietaDbsportas)) {
            # Kai viena vieta yra kitos patikslinimas ("Suginčiai" ir "Suginčiai,
            # Molėtų r."), imam tikslesne. Kai jos visai skirtingos - neliecam be
            # -Overwrite, nes musu irasas gali buti tikslesnis uz dbsportas
            # ("Daugai, Dzūkija" vs "Dzūkija").
            $ours = Fold $r.VietaDB
            $theirs = Fold $r.VietaDbsportas
            if ($theirs.StartsWith($ours) -or $theirs -like ('*' + $ours + '*')) {
                $sets += 'location_name=?'; $vals += $r.VietaDbsportas
            } elseif ($Overwrite) {
                $sets += 'location_name=?'; $vals += $r.VietaDbsportas
            }
        }
    }
    if ($r.TuriNuoroda -eq 'ne') { $sets += 'dbsportas_url=?'; $vals += $r.Nuoroda }
    if (-not $sets.Count) { continue }
    $sql = 'UPDATE albums SET ' + ($sets -join ', ') + ', updated_at=NOW() WHERE id=?'
    $upd += Db-Write $sql ($vals + @($r.Id))
}
Write-Output ("`natnaujinta albumu: {0}" -f $upd)
