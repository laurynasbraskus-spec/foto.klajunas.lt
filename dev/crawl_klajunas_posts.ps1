<#
    Susikuria klajunas.lt iraso indeksa per WordPress REST API.

    Reikalingas albumu nuorodoms: pagrindine nuoroda i renginio pranesima
    klajunas.lt, o is iraso teksto traukiami Klajuno prizininkai.

    Tik SKAITO. Raso CSV su ID, data, pavadinimu, nuoroda ir isvalytu tekstu.
#>
param(
    [string]$Api    = 'https://klajunas.lt/wp-json/wp/v2/posts',
    [string]$OutCsv = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\klajunas_posts.csv',
    [int]$PerPage   = 100,
    [int]$DelayMs   = 300
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'

function Strip-Html([string]$h) {
    if (-not $h) { return '' }
    $t = $h -replace '(?is)<script.*?</script>', ' ' -replace '(?is)<style.*?</style>', ' '
    $t = $t -replace '(?i)<br\s*/?>', "`n" -replace '(?i)</p>', "`n"
    $t = $t -replace '<[^>]+>', ' '
    $t = [Net.WebUtility]::HtmlDecode($t)
    return ($t -replace '[ \t]+', ' ' -replace '\r', '' -replace "\n{3,}", "`n`n").Trim()
}

$rows = @(); $page = 1; $total = $null
while ($true) {
    $url = "$Api" + '?per_page=' + $PerPage + '&page=' + $page + '&orderby=date&order=desc&_fields=id,date,link,title,content,excerpt'
    try {
        $req = [Net.WebRequest]::Create($url)
        $req.UserAgent = $ua
        $req.Timeout = 60000
        $resp = $req.GetResponse()
        if ($null -eq $total) { $total = $resp.Headers['X-WP-TotalPages'] }
        $sr = New-Object IO.StreamReader($resp.GetResponseStream(), [Text.Encoding]::UTF8)
        try { $json = $sr.ReadToEnd() } finally { $sr.Close(); $resp.Close() }
    } catch {
        # paskutinis puslapis grazina 400 - tai normali pabaiga
        if ($_.Exception.Message -match '400|rest_post_invalid_page_number') { break }
        throw
    }
    $items = $json | ConvertFrom-Json
    if (-not $items -or -not @($items).Count) { break }
    foreach ($p in @($items)) {
        $rows += [pscustomobject]@{
            Id      = $p.id
            Data    = ([string]$p.date).Substring(0, 10)
            Pavadinimas = Strip-Html ([string]$p.title.rendered)
            Nuoroda = [string]$p.link
            Tekstas = Strip-Html ([string]$p.content.rendered)
        }
    }
    Write-Progress -Activity 'klajunas.lt irasai' -Status ("puslapis $page / $total") -PercentComplete $(if ($total) { [int](100 * $page / [int]$total) } else { 0 })
    if ($total -and $page -ge [int]$total) { break }
    $page++
    Start-Sleep -Milliseconds $DelayMs
}
Write-Progress -Activity 'klajunas.lt irasai' -Completed

if (-not $rows.Count) { Write-Output 'KLAIDA: neparsisiusta ne vieno iraso.'; exit 1 }
$rows | Sort-Object Data -Descending | Export-Csv $OutCsv -NoTypeInformation -Encoding UTF8

Write-Output ("Irasu is viso : {0}" -f $rows.Count)
Write-Output ("Metu ruozas   : {0} .. {1}" -f (($rows | Sort-Object Data | Select-Object -First 1).Data), (($rows | Sort-Object Data | Select-Object -Last 1).Data))
Write-Output ("Su tekstu     : {0}" -f @($rows | Where-Object { $_.Tekstas }).Count)
Write-Output ("Mini 'Klajun' : {0}" -f @($rows | Where-Object { $_.Tekstas -match '(?i)klajūn|klajun' }).Count)
Write-Output ("Ataskaita     : {0}" -f $OutCsv)
