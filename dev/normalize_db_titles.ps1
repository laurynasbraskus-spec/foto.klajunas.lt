<#
    Suvienodina albumu pavadinimus pagal dvi taisykles:

    1) Metai visada gale, skliaustuose:  "Klajūno taurė (2022)".
    2) Bėgimas aplink Želvos ežerą rašomas vienodai, romėniškais skaičiais:
       "XVI tradicinis bėgimas ""Aplink Želvos ežerą"" (2006)".

    Kur metai stovi ne gale ("Lunatikai 2011 - II"), jie perkeliami i gala
    ("Lunatikai - II (2011)"), bet tik jei pavadinime tie metai vieninteliai ir
    sutampa su renginio data - kitaip pavadinimas paliekamas ir isvardijamas
    ranciniam sprendimui.

    Be -Execute nieko nekeicia.
#>
param([switch]$Execute)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

function To-Roman([int]$n) {
    if ($n -le 0 -or $n -gt 3999) { return '' }
    $map = @(
        @(1000, 'M'), @(900, 'CM'), @(500, 'D'), @(400, 'CD'), @(100, 'C'), @(90, 'XC'),
        @(50, 'L'), @(40, 'XL'), @(10, 'X'), @(9, 'IX'), @(5, 'V'), @(4, 'IV'), @(1, 'I')
    )
    $out = ''
    foreach ($p in $map) { while ($n -ge $p[0]) { $out += $p[1]; $n -= $p[0] } }
    return $out
}
function From-Roman([string]$s) {
    $v = @{ 'I' = 1; 'V' = 5; 'X' = 10; 'L' = 50; 'C' = 100; 'D' = 500; 'M' = 1000 }
    $s = $s.ToUpper(); $total = 0; $prev = 0
    for ($i = $s.Length - 1; $i -ge 0; $i--) {
        $c = [string]$s[$i]
        if (-not $v.ContainsKey($c)) { return 0 }
        $cur = $v[$c]
        if ($cur -lt $prev) { $total -= $cur } else { $total += $cur; $prev = $cur }
    }
    return $total
}

$albums = Db-Read 'SELECT id,title,event_date FROM albums ORDER BY event_date,id'
$plan = @(); $manual = @()

foreach ($a in $albums) {
    $t = [string]$a.title
    $orig = $t
    $year = ''
    if ([string]$a.event_date -match '^(\d{4})-') { $year = $Matches[1] }

    # --- 1) Zelvos begimas i viena pavidala ---
    if ($t -match '(?i)elvos\s+e[žz]er') {
        $num = 0
        if ($t -match '^\s*(\d{1,3})\b') { $num = [int]$Matches[1] }
        elseif ($t -match '^\s*([IVXLC]+)\b') { $num = From-Roman $Matches[1] }
        # priesaga skliaustuose gale (pvz. fotografas) issaugoma
        $suffix = ''
        if ($t -match '\(([^)]*(?:nuotraukos|foto)[^)]*)\)\s*$') { $suffix = $Matches[1].Trim() }
        if ($num -gt 0 -and $year -ne '') {
            $t = ('{0} tradicinis bėgimas "Aplink Želvos ežerą" ({1})' -f (To-Roman $num), $year)
            if ($suffix -ne '') { $t += ' – ' + $suffix }
        }
    }

    # --- 2) metai i gala, skliaustuose ---
    if ($t -notmatch '\((19|20)\d{2}\)') {
        if ($t -match '^(.*?)\s+((19|20)\d{2})\s*(\([^)]+\))\s*$') {
            # "... 2025 (pajūris)"  ->  "... (pajūris) (2025)"
            $t = ('{0} {1} ({2})' -f $Matches[1].Trim(), $Matches[4], $Matches[2])
        } elseif ($t -match '^(.*?)\s+((19|20)\d{2})\s*$') {
            $t = ('{0} ({1})' -f $Matches[1].Trim(), $Matches[2])
        } else {
            # Metai kabutese yra renginio pavadinimo dalis ("OS „Žiema 2022""),
            # ju liesti negalima - kitaip lieka „Žiema " su tuscia vieta.
            $inQuotes = $t -match '[„"][^„"]*\b(19|20)\d{2}\b[^„"]*["“]'
            $years = @([regex]::Matches($t, '\b(19|20)\d{2}\b') | ForEach-Object { $_.Value })
            if (-not $inQuotes -and $years.Count -eq 1 -and $year -ne '' -and $years[0] -eq $year) {
                # Iskerpam metus kartu su po ju einanciu "m." ir jungiamuoju
                # bruksneliu: "Lunatikai 2017-I" -> "Lunatikai I",
                # "2018 m. Klajūno sezono uždarymas" -> "Klajūno sezono uždarymas".
                # Bruksnys PRIKLIJUOTAS prie metu ("2017-I") yra tik jungtis -
                # ji nuimam. Bruksnys su tarpais ("2019 - Kantantas") skiria du
                # pavadinimus, todel lieka.
                $keepDash = ($t -match ('\b' + $year + '\b\s*(?:m\.)?\s+[-–]\s+\S'))
                if ($t -match ('^(.*?)\s*\b' + $year + '\b\s*(?:m\.)?\s*[-–]?\s*(.*)$')) {
                    $head = ($Matches[1] + $(if ($keepDash) { ' - ' } else { ' ' }) + $Matches[2])
                    $head = ($head -replace '\s{2,}', ' ').Trim(" -–,")
                    # "2012 lunatikai II" -> pirma raide didzioji
                    if ($head -ne '' -and $orig -match '^\d' -and $head -cmatch '^\p{Ll}') {
                        $head = $head.Substring(0, 1).ToUpper() + $head.Substring(1)
                    }
                    if ($head -ne '') { $t = ('{0} ({1})' -f $head, $year) }
                }
            } elseif ($years.Count -ge 1 -and -not $inQuotes) {
                $manual += ("#{0}  {1}" -f $a.id, $orig)
            } elseif ($inQuotes) {
                $manual += ("#{0}  {1}   (metai kabutese - pavadinimo dalis)" -f $a.id, $orig)
            }
        }
    }

    $t = ($t -replace '\s{2,}', ' ').Trim()
    if ($t -ne $orig) { $plan += [pscustomobject]@{ Id = [int]$a.id; Buvo = $orig; Tapo = $t } }
}

Write-Output ("keisim pavadinimu: {0}" -f $plan.Count)
foreach ($p in $plan) { Write-Output ("   {0,-56} -> {1}" -f $p.Buvo, $p.Tapo) }
if ($manual.Count) {
    Write-Output ""
    Write-Output ("NELIESTA - metai pavadinimo viduryje, reikia zmogaus sprendimo ({0}):" -f $manual.Count)
    $manual | ForEach-Object { Write-Output ('   ' + $_) }
}
if (-not $Execute) { Write-Output "`n(bandomasis rezimas - pridek -Execute)"; exit 0 }

$done = 0
foreach ($p in $plan) { $done += Db-Write 'UPDATE albums SET title=?, updated_at=NOW() WHERE id=?' @($p.Tapo, $p.Id) }
Write-Output ("`npakeista: {0}" -f $done)
