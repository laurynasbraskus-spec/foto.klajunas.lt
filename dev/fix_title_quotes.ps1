<#
    Sutvarko kabutes albumu pavadinimuose.

    Google Photos albumu varduose kabuciu naudoti negalima, todel jos buvo
    pakeistos pabraukimais: 'bėgimas _Aplink Želvos ežerą_'. Pasitaiko ir
    ,,dvieju kableliu" varianto. Viesam pavadinimui grazinam lietuviskas
    kabutes: bėgimas „Aplink Želvos ežerą".

    Skliaustuose esancios datos ('(Čekija, 2022-08-26_27)') NEliecamos kaip
    kabutes - ten pabraukimas yra datos intervalo skirtukas. Jos sutvarkomos
    atskirai: data nuimama, paliekami metai, vieta keliauja i location_name.
#>
param(
    [string]$Csv = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\Google Photos archyvas\sutvarkyta\albums_import.csv',
    [switch]$Execute
)
$ErrorActionPreference = 'Stop'
$rows = @(Import-Csv $Csv)
$changed = @()

foreach ($r in $rows) {
    $t = [string]$r.title
    $orig = $t

    # 1) skliaustai su data ir/ar vieta: (Čekija, 2022-08-26_27) / (2017-05-20_21, Stripeikiai)
    if ($t -match '^(.*?)\s*\(([^)]*)\)\s*$') {
        $head = $Matches[1].Trim()
        $inner = $Matches[2]
        if ($inner -match '(\d{4})-\d{2}-\d{2}(?:[_/-]\d{2}(?:-\d{2})?)?') {
            $year = $Matches[1]
            $rest = ($inner -replace '\d{4}-\d{2}-\d{2}(?:[_/-]\d{2}(?:-\d{2})?)?', '').Trim(" ,`t")
            # Ne viskas skliaustuose yra vieta: "ilgoje trasoje", "sprintas",
            # "estafetes" yra rungties apibudinimas ir priklauso pavadinimui.
            $isDiscipline = $rest -match '(?i)tras|sprint|estafet|distanc|vidutin|ilgoj|trumpoj|klasik'
            if ($rest -ne '' -and $isDiscipline) {
                $head = ($head + ' ' + $rest).Trim()
            } elseif ($rest -ne '' -and [string]$r.location_name -eq '') {
                $r.location_name = $rest
            }
            $t = if ($head -match ('\b' + $year + '\b')) { $head } else { $head + ' ' + $year }
        }
    }

    # 2) ,,tekstas_  ->  „tekstas"
    $t = [regex]::Replace($t, ',,([^_"]+)_', '„$1"')
    # 3) _tekstas_  ->  „tekstas"   (tik poroje, kad nepaliestume vienisu pabraukimu)
    $t = [regex]::Replace($t, '_([^_]+)_', '„$1"')
    # 4) datos taskais pavadinimo gale: "2022.02.16" -> "2022", o jei tie
    #    metai pavadinime jau yra (OS „Žiema 2022"), data tiesiog nuimam.
    $t = [regex]::Replace($t, '\s(\d{4})\.\d{2}\.\d{2}\s*$', {
            param($m)
            $head = $t.Substring(0, $m.Index)
            if ($head -match ('\b' + $m.Groups[1].Value + '\b')) { '' } else { ' ' + $m.Groups[1].Value }
        })

    $t = ($t -replace '\s{2,}', ' ').Trim()
    if ($t -ne $orig) { $changed += [pscustomobject]@{ Buvo = $orig; Tapo = $t }; $r.title = $t }
}

Write-Output ("pakeista: {0}" -f $changed.Count)
$changed | ForEach-Object { Write-Output ("   {0,-52} -> {1}" -f $_.Buvo, $_.Tapo) }
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }
$rows | Export-Csv $Csv -NoTypeInformation -Encoding UTF8
Write-Output ("`nirasyta: {0}" -f $Csv)
