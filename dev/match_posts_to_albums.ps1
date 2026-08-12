<#
    Suderina archyvo albumus su klajunas.lt naujienu irasais.

    Pranesimas apie renginy raso PO renginio, todel kandidatai ieskomi laiko
    lange nuo renginio dienos i prieki. Vien datos nepakanka - ta pacia savaite
    galejo buti keli renginiai - todel dar reikalaujamas pavadinimu sutapimas.

    Nieko nekeicia - tik raso ataskaita.
#>
param(
    [string]$Base       = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta',
    [int]$DaysAfter     = 21,
    [int]$DaysBefore    = 2,
    [int]$MinScore      = 2
)
$ErrorActionPreference = 'Stop'

# Lietuviskos raides i lotyniskas, kad "Klajūno" ir "klajuno" sutaptu.
function Norm([string]$s) {
    if (-not $s) { return '' }
    $map = @{ 'ą'='a';'č'='c';'ę'='e';'ė'='e';'į'='i';'š'='s';'ų'='u';'ū'='u';'ž'='z' }
    $t = $s.ToLower()
    foreach ($k in $map.Keys) { $t = $t.Replace($k, $map[$k]) }
    return ($t -replace '[^a-z0-9]+', ' ').Trim()
}
# Zodziai, kurie yra beveik visuose pavadinimuose ir nieko neskiria.
$stop = @('taure','taures','varzybos','os','klubo','lietuvos','moletu','klajuno','klajunas','etapas','rez','foto','ir','bei','m')
function Tokens([string]$s) {
    return @((Norm $s) -split '\s+' | Where-Object { $_.Length -ge 4 -and $stop -notcontains $_ } | Sort-Object -Unique)
}

$posts = @(Import-Csv (Join-Path $Base 'klajunas_posts.csv'))
foreach ($p in $posts) {
    $p | Add-Member -NotePropertyName Tok -NotePropertyValue (Tokens ($p.Pavadinimas + ' ' + $p.Tekstas.Substring(0, [Math]::Min(300, $p.Tekstas.Length)))) -Force
    $p | Add-Member -NotePropertyName TokT -NotePropertyValue (Tokens $p.Pavadinimas) -Force
}
Write-Output ("klajunas.lt irasu: {0}" -f $posts.Count)

$root = Join-Path $Base 'albums'
$albums = @()
Get-ChildItem $root -Directory -Recurse -Depth 1 -EA SilentlyContinue |
    Where-Object { Test-Path (Join-Path $_.FullName 'album.json') } | ForEach-Object {
        $a = Get-Content (Join-Path $_.FullName 'album.json') -Raw | ConvertFrom-Json
        $albums += [pscustomobject]@{
            Name = $_.Name; Json = (Join-Path $_.FullName 'album.json')
            Title = [string]$a.displayName; Date = [string]$a.date; DateEnd = [string]$a.dateEnd
        }
    }
Write-Output ("albumu: {0}" -f $albums.Count)

$rows = @()
foreach ($al in $albums) {
    if ($al.Date -notmatch '^\d{4}-\d{2}-\d{2}$') { continue }
    $d0 = [datetime]::ParseExact($al.Date, 'yyyy-MM-dd', $null)
    $dEnd = $d0
    if ($al.DateEnd -match '^\d{4}-\d{2}-\d{2}$') { $dEnd = [datetime]::ParseExact($al.DateEnd, 'yyyy-MM-dd', $null) }
    $lo = $d0.AddDays(-$DaysBefore); $hi = $dEnd.AddDays($DaysAfter)
    $albTok = Tokens ($al.Title + ' ' + ($al.Name -replace '^\d{4}-\d{2}-\d{2}(_[\d-]+)?__', ''))

    $cands = @()
    foreach ($p in $posts) {
        $pd = [datetime]::ParseExact($p.Data, 'yyyy-MM-dd', $null)
        if ($pd -lt $lo -or $pd -gt $hi) { continue }
        $inTitle = @($albTok | Where-Object { $p.TokT -contains $_ }).Count
        $inBody  = @($albTok | Where-Object { $p.Tok -contains $_ }).Count
        $score = ($inTitle * 2) + $inBody
        if ($score -lt $MinScore) { continue }
        $cands += [pscustomobject]@{ Post = $p; Score = $score; Delta = ($pd - $dEnd).Days }
    }
    $best = @($cands | Sort-Object @{e={-$_.Score}}, @{e={[Math]::Abs($_.Delta)}} | Select-Object -First 1)
    $rows += [pscustomobject]@{
        Albumas   = $al.Name
        Pavadinimas = $al.Title
        Data      = $al.Date
        Kandidatu = $cands.Count
        Taskai    = $(if ($best.Count) { $best[0].Score } else { 0 })
        PoDienu   = $(if ($best.Count) { $best[0].Delta } else { '' })
        IrasoData = $(if ($best.Count) { $best[0].Post.Data } else { '' })
        Irasas    = $(if ($best.Count) { $best[0].Post.Pavadinimas } else { '' })
        Nuoroda   = $(if ($best.Count) { $best[0].Post.Nuoroda } else { '' })
    }
}

$out = Join-Path $Base 'album_post_matches.csv'
$rows | Export-Csv $out -NoTypeInformation -Encoding UTF8
$hit = @($rows | Where-Object { $_.Nuoroda })
$strong = @($hit | Where-Object { [int]$_.Taskai -ge 4 })
Write-Output ''
Write-Output ("Albumu su datomis      : {0}" -f $rows.Count)
Write-Output ("  rastas iraso kandidatas: {0}" -f $hit.Count)
Write-Output ("  is ju stiprus (>=4 t.) : {0}" -f $strong.Count)
Write-Output ("  be kandidato           : {0}" -f ($rows.Count - $hit.Count))
Write-Output ''
Write-Output '=== 15 stipriausiu ==='
$hit | Sort-Object @{e={-[int]$_.Taskai}} | Select-Object -First 15 | ForEach-Object {
    Write-Output ("  {0,2}t +{1,3}d  {2}" -f $_.Taskai, $_.PoDienu, $_.Albumas)
    Write-Output ("             {0}" -f $_.Irasas)
}
Write-Output ''
Write-Output ("Ataskaita: {0}" -f $out)
