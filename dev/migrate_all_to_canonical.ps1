<#
    Perkelia VISUS dar nekanoninius albumus i kanoninius B2 kelius, dalimis.

    Eile sudaroma pati: kiekvienam albumui apskaiciuojamas kanoninis kelias tuo
    paciu PHP kodu, kaip serveryje, ir imami tie, kuriu source_path skiriasi.
    Po kiekvienos dalies eile sudaroma is naujo - taip nesvarbu, kas pasikeite
    tarp daliu, ir nera rizikos perkelti ta pati albuma du kartus.

    SUSTOJA ties pirma problema: jei dalyje buvo kopijavimo klaidu, neistrintu
    failu arba albumas liko neperkeltas, toliau nebeeina. Geriau sustoti ir
    parodyti, nei tyliai varyti per 200 albumu.
#>
param(
    [int]$ChunkSize = 10,
    [int]$MaxChunks = 100,
    [string]$Php = 'C:\Users\KLAJNA~1\AppData\Local\Temp\claude\C--Users-Klaj-nas-Documents-Claude-foto-klajunas-lt\ee9b9aef-6272-4900-ad34-133ecc991fe9\scratchpad\php\php.exe'
)
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'db.ps1')

$enc = New-Object Text.UTF8Encoding($false)
$script = Join-Path $env:TEMP 'canonical_prefixes.php'
Copy-Item (Join-Path $PSScriptRoot 'canonical_prefixes.php') $script -Force
$mover = Join-Path $PSScriptRoot 'move_album_to_canonical.ps1'

function Get-Todo {
    $albums = Db-Read 'SELECT id,title,event_date,event_date_end,source_path FROM albums ORDER BY event_date,id'
    $inF = Join-Path $env:TEMP 'mig_in.json'
    $outF = Join-Path $env:TEMP 'mig_out.json'
    $inp = @($albums | ForEach-Object {
            [pscustomobject]@{ name = [string]$_.id; title = [string]$_.title; date = [string]$_.event_date; dateEnd = [string]$_.event_date_end }
        })
    [IO.File]::WriteAllText($inF, ($inp | ConvertTo-Json -Depth 4), $enc)
    & $Php $script $inF $outF | Out-Null
    $parsed = ConvertFrom-Json ([IO.File]::ReadAllText($outF))
    $canon = @{}
    foreach ($x in @($parsed)) { $canon[[string]$x.name] = [string]$x.prefix }
    $todo = @()
    foreach ($a in $albums) {
        if ($canon[[string]$a.id] -ne [string]$a.source_path) { $todo += [int]$a.id }
    }
    return , $todo
}

$startTotal = (Get-Todo).Count
Write-Output ("pradzioje reikia perkelti: {0}" -f $startTotal)
$chunk = 0
while ($chunk -lt $MaxChunks) {
    $todo = Get-Todo
    if (-not $todo.Count) { Write-Output "`nVISI ALBUMAI KANONINIAI."; break }
    $ids = @($todo | Select-Object -First $ChunkSize)
    $chunk++
    Write-Output ("`n===== dalis {0}: albumai {1}   (liko {2}) =====" -f $chunk, ($ids -join ','), $todo.Count)

    $out = & $mover -AlbumIds $ids -Execute 2>&1 | Out-String -Width 200
    Write-Output $out

    $copyErr = 0
    foreach ($m in [regex]::Matches($out, 'nukopijuota \d+, klaidu (\d+)')) { $copyErr += [int]$m.Groups[1].Value }
    $notDeleted = ([regex]::Matches($out, 'NEISTRINTA')).Count
    $notSwitched = ([regex]::Matches($out, 'NEperjungiam|NEperkeliam|KLAIDA')).Count

    # ar visi sios dalies albumai tikrai perkelti
    $after = Get-Todo
    $stillThere = @($ids | Where-Object { $after -contains $_ })

    if ($copyErr -gt 0 -or $notDeleted -gt 0 -or $notSwitched -gt 0 -or $stillThere.Count -gt 0) {
        Write-Output ""
        Write-Output "!!! SUSTOJAM - dalyje buvo problemu:"
        Write-Output ("   kopijavimo klaidu : {0}" -f $copyErr)
        Write-Output ("   neistrintu failu  : {0}" -f $notDeleted)
        Write-Output ("   neperjungta       : {0}" -f $notSwitched)
        Write-Output ("   liko neperkelta   : {0}  ({1})" -f $stillThere.Count, ($stillThere -join ','))
        exit 2
    }
    Write-Output ("dalis {0} svari. Kanoniniu jau: {1}" -f $chunk, ($startTotal - $after.Count))
}
Write-Output "`nBAIGTA."
