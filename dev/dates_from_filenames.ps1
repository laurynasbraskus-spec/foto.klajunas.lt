<#
    Nustato albumo data pagal nuotrauku failu vardus.

    Telefonai raso varda su laiko zyme: "20170421_120637_resized.jpg". Tai pati
    fotografavimo akimirka - stipresnis irodymas uz klubo naujienos data, nes
    naujiena skelbiama po renginio (praktikoje 1-11 dienu veliau).

    Datos imamos tik tos, kurios tikros (menuo 1-12, diena 1-31) ir patenka i
    albumo metus - kitaip i akis krenta atsitiktiniai skaiciu sutapimai
    varduose ("IMG_20991045.jpg").

    Be -Execute nieko nekeicia.
#>
param(
    [int[]]$AlbumIds = @(),
    [switch]$OnlyDrafts,
    [switch]$Execute
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

if ($AlbumIds.Count) {
    $ph = ($AlbumIds | ForEach-Object { '?' }) -join ','
    $albums = Db-Read ("SELECT id,title,event_date,event_date_end,visibility FROM albums WHERE id IN ($ph) ORDER BY event_date") $AlbumIds
} elseif ($OnlyDrafts) {
    $albums = Db-Read "SELECT id,title,event_date,event_date_end,visibility FROM albums WHERE visibility='draft' ORDER BY event_date"
} else {
    $albums = Db-Read "SELECT id,title,event_date,event_date_end,visibility FROM albums ORDER BY event_date"
}
Write-Output ("tikrinama albumu: {0}" -f $albums.Count)

$plan = @()
foreach ($a in $albums) {
    $year = ''
    if ([string]$a.event_date -match '^(\d{4})-') { $year = $Matches[1] }
    $photos = Db-Read 'SELECT original_filename FROM photos WHERE album_id=?' @([int]$a.id)
    $dates = @()
    foreach ($p in $photos) {
        $n = [string]$p.original_filename
        foreach ($m in [regex]::Matches($n, '(20\d{2})(\d{2})(\d{2})[_-]?\d{6}')) {
            $y = $m.Groups[1].Value; $mo = [int]$m.Groups[2].Value; $d = [int]$m.Groups[3].Value
            if ($mo -lt 1 -or $mo -gt 12 -or $d -lt 1 -or $d -gt 31) { continue }
            if ($year -ne '' -and $y -ne $year) { continue }
            $dates += ("{0}-{1:d2}-{2:d2}" -f $y, $mo, $d)
        }
    }
    if (-not $dates.Count) { continue }
    $u = @($dates | Sort-Object -Unique)
    $min = $u[0]; $max = $u[-1]
    $curStart = [string]$a.event_date
    $curEnd = [string]$a.event_date_end
    if ($curEnd -eq '') { $curEnd = $curStart }
    # Jei albumo laikotarpis jau apima varduose esancias datas - nieko nekeiciam.
    if ($curStart -le $min -and $curEnd -ge $max) { continue }
    $newEnd = if ($max -ne $min) { $max } else { '' }
    $plan += [pscustomobject]@{
        Id = [int]$a.id; Title = [string]$a.title; Vis = [string]$a.visibility
        Buvo = $curStart + $(if ([string]$a.event_date_end -ne '') { ' .. ' + $a.event_date_end } else { '' })
        Nauja = $min + $(if ($newEnd -ne '') { ' .. ' + $newEnd } else { '' })
        Start = $min; End = $newEnd; Kiek = $photos.Count
    }
}
Write-Output ("keisim: {0}" -f $plan.Count)
foreach ($p in $plan) { Write-Output ("   #{0,-4} {1,-44} {2,-24} -> {3}" -f $p.Id, $p.Title, $p.Buvo, $p.Nauja) }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$done = 0
foreach ($p in $plan) {
    $note = "`ndata nustatyta pagal nuotrauku failu vardu laiko zymes (" + $p.Nauja + "); ankstesne data buvo " + $p.Buvo + "."
    if ($p.End -ne '') {
        $done += Db-Write 'UPDATE albums SET event_date=?, event_date_end=?, notes_internal=CONCAT(COALESCE(notes_internal,""),?), updated_at=NOW() WHERE id=?' @($p.Start, $p.End, $note, $p.Id)
    } else {
        $done += Db-Write 'UPDATE albums SET event_date=?, event_date_end=NULL, notes_internal=CONCAT(COALESCE(notes_internal,""),?), updated_at=NOW() WHERE id=?' @($p.Start, $note, $p.Id)
    }
}
Write-Output ("`npakeista: {0}" -f $done)
