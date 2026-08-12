<#
    Klientas tiesioginiam ryšiui su foto DB (be jokio CSV rato).

        . .\db.ps1
        Db-Read  "SELECT COUNT(*) c FROM albums"
        Db-Read  "SELECT id,title FROM albums WHERE visibility=?" @('published')
        Db-Write "UPDATE albums SET title=? WHERE id=?" @('Naujametinis begimas 2008', 12)

    Uzklausos vykdomos per nesiojama PHP (PowerShell MySQL kliento neturi).
    SQL keliauja failu, ne argumentu: per komandine eilute lietuviskos raides
    ir kabutes nueina iskraipytos.
#>
$script:DbPhp = 'C:\Users\KLAJNA~1\AppData\Local\Temp\claude\C--Users-Klaj-nas-Documents-Claude-foto-klajunas-lt\ee9b9aef-6272-4900-ad34-133ecc991fe9\scratchpad\php\php.exe'
$script:DbIni = 'C:\Users\KLAJNA~1\AppData\Local\Temp\claude\C--Users-Klaj-nas-Documents-Claude-foto-klajunas-lt\ee9b9aef-6272-4900-ad34-133ecc991fe9\scratchpad\php\php.ini'
$script:DbRunner = 'C:\Users\KLAJNA~1\AppData\Local\Temp\claude\C--Users-Klaj-nas-Documents-Claude-foto-klajunas-lt\ee9b9aef-6272-4900-ad34-133ecc991fe9\scratchpad\dbq.php'
$script:DbTmp = 'C:\Users\KLAJNA~1\AppData\Local\Temp\claude\C--Users-Klaj-nas-Documents-Claude-foto-klajunas-lt\ee9b9aef-6272-4900-ad34-133ecc991fe9\scratchpad'

function Invoke-DbBatch([object[]]$Queries) {
    $enc = New-Object Text.UTF8Encoding($false)
    $inF = Join-Path $script:DbTmp ('q_' + [Guid]::NewGuid().ToString('N') + '.json')
    $outF = $inF -replace '\.json$', '_out.json'
    $payload = if ($Queries.Count -eq 1) { $Queries[0] } else { $Queries }
    [IO.File]::WriteAllText($inF, ($payload | ConvertTo-Json -Depth 8), $enc)
    $null = & $script:DbPhp -c $script:DbIni $script:DbRunner $inF $outF
    if (-not (Test-Path $outF)) { throw 'uzklausa neivykdyta - nera atsakymo failo' }
    # ConvertFrom-Json rezultata BUTINA pirma priskirti ir tik tada vynioti,
    # kitaip vienas irasas virsta objektu su masyvo laukais.
    $parsed = ConvertFrom-Json ([IO.File]::ReadAllText($outF))
    $res = @($parsed)
    Remove-Item $inF, $outF -Force -EA SilentlyContinue
    foreach ($r in $res) { if (-not $r.ok) { throw ('DB klaida: ' + $r.error) } }
    return $res
}

# NAUDOJIMAS: rezultata BUTINA priskirti kintamajam ($a = Db-Read ...).
# Tiesiai i konvejeri (Db-Read ... | ForEach-Object) siusti negalima - del
# kablelio apacioje pro konvejeri praeitu visas masyvas kaip vienas objektas.
function Db-Read([string]$Sql, [object[]]$Params = @()) {
    $r = Invoke-DbBatch @(@{ sql = $Sql; params = @($Params) })
    $rows = $r[0].rows
    # Kablelis butinas: "return @($x)" viena elementa isvynioja atgal i objekta,
    # ir tada .Count nieko negrazina - o skriptai pagal ji sprendzia, ar irasas
    # rastas. Del sito visos septynios poros atrode kaip "albumo nebera".
    return , @($rows)
}

function Db-Write([string]$Sql, [object[]]$Params = @()) {
    $r = Invoke-DbBatch @(@{ sql = $Sql; params = @($Params) })
    return [int]$r[0].affected
}

function Db-Table([string]$Sql, [object[]]$Params = @()) {
    Db-Read $Sql $Params | Format-Table -AutoSize
}
