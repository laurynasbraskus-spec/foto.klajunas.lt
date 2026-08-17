<#
    Nusiurbia dbsportas.lt varzybu sarasa pagal metus.

    Paieska "?search=YYYY" grazina visus tu metu startus, todel uztenka vienos
    uzklausos metams, o ne vienos kiekvienam albumui.

    Is kiekvienos eilutes imama: data, pavadinimas, varzybu ID (nuoroda),
    salis ir vietove. Rezultatas - CSV, kuriuo po to tikrinamos albumu vietos.

    Puslapis atiduodamas UTF-8, bet Invoke-WebRequest ji dekoduoja neteisingai
    (vietoj "Šalis" gaunam "Å alis"), todel imami zali baitai ir dekoduojama
    patiems.
#>
param(
    [int]$From = 2004,
    [int]$To = 2026,
    [string]$Out = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\dbsportas_varzybos.csv',
    [int]$DelayMs = 700
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$rows = @()
for ($y = $From; $y -le $To; $y++) {
    $url = 'https://dbsportas.lt/lt/varz?search=' + $y
    try {
        $resp = Invoke-WebRequest -Uri $url -TimeoutSec 120 -UseBasicParsing
        $html = [Text.Encoding]::UTF8.GetString($resp.RawContentStream.ToArray())
    } catch {
        Write-Output ("{0}: nepavyko - {1}" -f $y, $_.Exception.Message)
        continue
    }
    $n = 0
    # viena eilute: data | <a href="varz/ID">pavadinimas</a> | ... salis ... | vietove
    $re = '<td[^>]*>(\d{4}-\d{2}-\d{2})<br></td>\s*<td[^>]*><a href="varz/(\d+)"[^>]*>(.*?)</a></td>\s*<td[^>]*>.*?</div>\s*([A-Z]{3})</td><td[^>]*>(.*?)</td>'
    foreach ($m in [regex]::Matches($html, $re, 'Singleline')) {
        $title = [regex]::Replace($m.Groups[3].Value, '<[^>]+>', '').Trim()
        $place = [regex]::Replace($m.Groups[5].Value, '<[^>]+>', '').Trim()
        $rows += [pscustomobject]@{
            Data = $m.Groups[1].Value
            Pavadinimas = [Net.WebUtility]::HtmlDecode($title)
            Salis = $m.Groups[4].Value
            Vietove = [Net.WebUtility]::HtmlDecode($place)
            Id = $m.Groups[2].Value
            Nuoroda = 'https://dbsportas.lt/lt/varz/' + $m.Groups[2].Value
        }
        $n++
    }
    Write-Output ("{0}: {1} varzybu" -f $y, $n)
    Start-Sleep -Milliseconds $DelayMs
}
if (-not $rows.Count) { Write-Output 'KLAIDA: nieko nesurinkta - patikrink puslapio strukturа.'; exit 1 }
$rows | Sort-Object Data | Export-Csv $Out -NoTypeInformation -Encoding UTF8
Write-Output ("`nis viso: {0}   CSV: {1}" -f $rows.Count, $Out)
