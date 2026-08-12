<#
    Detaliai istiria albumus, kuriuose VISOS nuotraukos iskrenta uz album.json
    periodo. Rodo fotoaparato modeli, savaites diena ir valandu ruoza - is to
    matyti, ar EXIF patikima, ar laikrodis buvo sugades.

    Nieko nekeicia.
#>
param(
    [string]$Root = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\albums',
    [string[]]$Folders
)
Add-Type -AssemblyName System.Drawing
$lt = [Globalization.CultureInfo]::GetCultureInfo('lt-LT')

function Get-Exif([string]$path, [int]$tag) {
    try {
        $img = [System.Drawing.Image]::FromFile($path)
        try { $p = $img.GetPropertyItem($tag); return [Text.Encoding]::ASCII.GetString($p.Value).Trim([char]0).Trim() }
        catch { return $null } finally { $img.Dispose() }
    } catch { return $null }
}

foreach ($name in $Folders) {
    $dir = @(Get-ChildItem $Root -Directory -Recurse -Depth 1 -EA SilentlyContinue | Where-Object { $_.Name -eq $name })[0]
    if (-not $dir) { Write-Output "NERASTA: $name"; continue }
    $a = Get-Content (Join-Path $dir.FullName 'album.json') -Raw | ConvertFrom-Json

    $stamps = @(); $models = @{}
    foreach ($f in (Get-ChildItem (Join-Path $dir.FullName 'originals') -File -EA SilentlyContinue)) {
        if ($f.Extension -notmatch '(?i)\.(jpe?g)$') { continue }
        $v = Get-Exif $f.FullName 36867
        if ($v -match '^(\d{4}):(\d{2}):(\d{2})[ T](\d{2}):(\d{2})') {
            try {
                $stamps += [datetime]::ParseExact(
                    ('{0}-{1}-{2} {3}:{4}' -f $Matches[1], $Matches[2], $Matches[3], $Matches[4], $Matches[5]),
                    'yyyy-MM-dd HH:mm', $null)
            } catch {}
        }
        $m = Get-Exif $f.FullName 272
        if ($m) { $models[$m] = 1 + [int]$models[$m] }
    }

    Write-Output ("=== " + $dir.Name)
    Write-Output ("    album.json data : {0}{1}" -f $a.date, $(if ($a.dateEnd) { ' .. ' + $a.dateEnd } else { '' }))
    if (-not $stamps.Count) { Write-Output "    EXIF: nera"; Write-Output ''; continue }

    $sorted = $stamps | Sort-Object
    $byDay = $sorted | Group-Object { $_.ToString('yyyy-MM-dd') } | Sort-Object Name
    foreach ($g in $byDay) {
        $d = [datetime]::ParseExact($g.Name, 'yyyy-MM-dd', $null)
        $h = @($g.Group | Sort-Object)
        Write-Output ("    EXIF {0}  {1,-12} {2}-{3}  ({4} nuotr.)" -f `
            $g.Name, $lt.DateTimeFormat.GetDayName($d.DayOfWeek), $h[0].ToString('HH:mm'), $h[-1].ToString('HH:mm'), $g.Count)
    }
    $albDate = $null
    if ($a.date -match '^\d{4}-\d{2}-\d{2}$') {
        $albDate = [datetime]::ParseExact($a.date, 'yyyy-MM-dd', $null)
        Write-Output ("    album.json diena: {0}" -f $lt.DateTimeFormat.GetDayName($albDate.DayOfWeek))
        $gap = ($sorted[0].Date - $albDate.Date).Days
        Write-Output ("    skirtumas       : {0} d." -f $gap)
    }
    Write-Output ("    fotoaparatas    : " + (($models.Keys | ForEach-Object { "$_ x$($models[$_])" }) -join '; '))
    Write-Output ''
}
