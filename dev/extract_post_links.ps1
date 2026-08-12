<#
    Is klajunas.lt irasu istraukia isorines nuorodas.

    Pirmame indekse teksta nuvaliau nuo HTML, todel nuorodos, paslėptos po zodziu
    "Rezultatai", dingo. Cia imami patys href atributai.

    Skirstymas:
      dbsportas - dbsportas.lt / dbtopas.lt varzybu puslapiai
      protokolas- old.klajunas.lt/archyvas
      kita      - visa kita (straipsniai, nuotrauku albumai)

    Tik SKAITO API. Raso CSV.
#>
param(
    [string]$Api    = 'https://klajunas.lt/wp-json/wp/v2/posts',
    [string]$OutCsv = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\klajunas_post_links.csv',
    [int]$PerPage   = 100,
    [int]$DelayMs   = 300
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'

$rows = @(); $page = 1; $total = $null
while ($true) {
    $url = "$Api" + '?per_page=' + $PerPage + '&page=' + $page + '&orderby=date&order=desc&_fields=id,date,link,title,content'
    try {
        $req = [Net.WebRequest]::Create($url); $req.UserAgent = $ua; $req.Timeout = 60000
        $resp = $req.GetResponse()
        if ($null -eq $total) { $total = $resp.Headers['X-WP-TotalPages'] }
        $sr = New-Object IO.StreamReader($resp.GetResponseStream(), [Text.Encoding]::UTF8)
        try { $json = $sr.ReadToEnd() } finally { $sr.Close(); $resp.Close() }
    } catch {
        if ($_.Exception.Message -match '400|invalid_page') { break }
        throw
    }
    # @($json | ConvertFrom-Json) PowerShellyje grazina VIENA objekta, kurio
    # savybes yra masyvai - tada visu irasu nuorodos sulimpa i viena eilute.
    # Butina pirma priskirti, tik tada vynioti.
    $parsed = ConvertFrom-Json $json
    $items = @($parsed)
    if (-not $items.Count) { break }
    if ($items[0].id -isnot [int] -and $items[0].id -isnot [long]) {
        Write-Output 'KLAIDA: JSON masyvas neissiskaide i atskirus irasus.'
        exit 1
    }
    foreach ($p in $items) {
        $html = [string]$p.content.rendered
        foreach ($m in [regex]::Matches($html, '(?is)<a[^>]+href\s*=\s*["'']([^"'']+)["''][^>]*>(.*?)</a>')) {
            $href = $m.Groups[1].Value
            if ($href -notmatch '^https?://') { continue }
            # savos svetaines vidines nuorodos nedomina
            if ($href -match '(?i)klajunas\.lt' -and $href -notmatch '(?i)old\.klajunas\.lt') { continue }
            $anchor = ([Net.WebUtility]::HtmlDecode(($m.Groups[2].Value -replace '<[^>]+>', ' ' -replace '\s+', ' '))).Trim()
            $kind = if ($href -match '(?i)dbsportas\.lt|dbtopas\.lt') { 'dbsportas' }
                    elseif ($href -match '(?i)old\.klajunas\.lt/archyvas') { 'protokolas' }
                    else { 'kita' }
            $rows += [pscustomobject]@{
                IrasoData = ([string]$p.date).Substring(0, 10)
                Irasas    = [string]$p.link
                Tipas     = $kind
                Nuoroda   = $href
                Tekstas   = $anchor
            }
        }
    }
    Write-Progress -Activity 'nuorodos' -Status ("puslapis $page / $total") -PercentComplete $(if ($total) { [int](100 * $page / [int]$total) } else { 0 })
    if ($total -and $page -ge [int]$total) { break }
    $page++
    Start-Sleep -Milliseconds $DelayMs
}
Write-Progress -Activity 'nuorodos' -Completed

if (-not $rows.Count) { Write-Output 'KLAIDA: nuorodu nerasta.'; exit 1 }
$rows | Export-Csv $OutCsv -NoTypeInformation -Encoding UTF8
Write-Output ("Nuorodu is viso: {0}" -f $rows.Count)
$rows | Group-Object Tipas | Sort-Object Count -Descending | ForEach-Object { Write-Output ("  {0,-12} {1}" -f $_.Name, $_.Count) }
Write-Output ("Irasu su dbsportas nuoroda: {0}" -f (@($rows | Where-Object { $_.Tipas -eq 'dbsportas' } | Select-Object -ExpandProperty Irasas -Unique)).Count)
Write-Output ("Ataskaita: {0}" -f $OutCsv)
