<#
    Sugeneruoja miniatiuras is anksto, kad pirmas albumo atvertimas ju nebelauktu.

    Kodel ne serveryje: img.php miniatiura kuria pirma karta jos paprasyta -
    parsisiunčia originala is B2 ir perkoduoja, o tai uztrunka apie sekunde.
    Atvertus nauja albuma tas laukimas tenka pirmam ziurovui. Cron'o, exec ar
    shell_exec serveryje naudoti negalim, todel is anksto paprasom is cia: tas
    pats img.php, tie patys adresai, kuriuos vėliau prasys narsykles. Jokio naujo
    kelio serveryje neatsiranda.

    Ejimas per vieso vardo adresa (ne tiesiai i serveri) apsildo abu podelius:
    ir serverio cache/b2_img_cache, ir Cloudflare krasta. Kraste apsyla tik tas
    PoP, per kuri einam, bet brangioji dalis - B2 parsiuntimas ir perkodavimas -
    padaroma vienareiksmiskai.

        .\warm_thumbs.ps1 -Album klajuno-taure-2026
        .\warm_thumbs.ps1 -Prefix albums/2026/2026-08-23__Klajuno-taure-2026
        .\warm_thumbs.ps1 -All
        .\warm_thumbs.ps1 -Album klajuno-taure-2026 -Views    # ir dideles perziuros

    -Album  ima adresus tiesiai is galerijos API, todel visada sutampa su tuo, ko
            prasys narsykle. Tinka PASKELBTIEMS albumams.
    -Prefix adresus sudaro pats is B2 failu saraso - tinka ir juodrasciams, kuriu
            API dar nerodo. Butent si veiksena naudojama ikelimo metu.
#>
param(
    [string]$Album  = '',
    [string]$Prefix = '',
    [switch]$All,
    # Dideles perziuros (w=1400) sveria apie 425 KB, o serverio podelio riba yra
    # 300 MB - vieno 285 nuotrauku albumo perziuros uzimtu 121 MB, t.y. treciadali
    # visos talpos ir istumtu kitu albumu miniatiuras. Todel numatytai sildom tik
    # tinklelio miniatiuras, o perziuros - tik paprasius.
    [switch]$Views,
    [string]$Site   = 'https://foto.klajunas.lt',
    [string]$ConfigPhp = 'C:\Users\Klajūnas\Documents\Claude\foto.klajunas.lt\server\b2-config.php',
    # img.php riba yra 300 uzklausu per 60 s is vieno IP. Laikomes gerokai zemiau:
    # persistengus uzsiblokuotume patys ir dalis miniatiuru liktu nesugeneruotos.
    [int]$Rps = 2,
    [int]$TimeoutSec = 30,
    # Dalijimas i lygiagrecius srautus. Sildymo greiti lemia ne pauze, o serverio
    # darbas: salta miniatiura kainuoja apie 1,4 s (B2 parsiuntimas + perkodavimas),
    # todel nuosekliai visas archyvas uztruktu apie 5 valandas. Paleidus kelis
    # procesus su -Of N ir skirtingu -Shard, darbas pasidalijamas.
    #     .\warm_thumbs.ps1 -All -Shard 1 -Of 3
    [int]$Shard = 1,
    [int]$Of = 1
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

if (-not $Album -and -not $Prefix -and -not $All) {
    Write-Output 'Nurodyk -Album <slug>, -Prefix <B2 kelias> arba -All.'
    exit 1
}

# Miniatiuros adreso parametrai. Privalo sutapti su thumb_url()/view_url()
# b2-gallery.php - kitaip sugeneruotume KITA podelio irasa nei prasys narsykle,
# ir visas sildymas nueitu veltui. -Album veiksena si konstanta nenaudoja: ten
# adresai imami is API.
$ThumbQuery = 'w=420&q=76&fmt=webp&v=7'
$ViewQuery  = 'w=1400&q=83&fmt=webp&v=7'

function Get-ApiJson([string]$url) {
    $r = Invoke-WebRequest -Uri $url -TimeoutSec $TimeoutSec -UseBasicParsing
    return ConvertFrom-Json $r.Content
}

# --- Adresu surinkimas ---------------------------------------------------
$urls = @()

if ($Prefix) {
    # Juodrasciams API netinka - ju ji nerodo. Adresus sudarom is B2 saraso.
    $cfg = Get-Content $ConfigPhp -Raw
    $kid = [regex]::Match($cfg, "B2_KEY_ID',\s*'([^']+)").Groups[1].Value
    $key = [regex]::Match($cfg, "B2_APP_KEY',\s*'([^']+)").Groups[1].Value
    $bid = [regex]::Match($cfg, "B2_BUCKET_ID',\s*'([^']+)").Groups[1].Value
    $auth = Invoke-RestMethod -Uri 'https://api.backblazeb2.com/b2api/v2/b2_authorize_account' `
        -Headers @{ Authorization = 'Basic ' + [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("$kid`:$key")) }

    $p = $Prefix.TrimEnd('/') + '/'
    $files = @()
    $start = $null
    do {
        $body = @{ bucketId = $bid; prefix = $p; maxFileCount = 10000 }
        if ($start) { $body.startFileName = $start }
        $r = Invoke-RestMethod -Uri ($auth.apiUrl + '/b2api/v2/b2_list_file_names') -Method Post `
            -Headers @{ Authorization = $auth.authorizationToken } -Body ($body | ConvertTo-Json) -ContentType 'application/json'
        $files += @($r.files | ForEach-Object { [string]$_.fileName })
        $start = $r.nextFileName
    } while ($start)

    # Tie patys filtrai kaip is_image() ir is_derived_or_legacy_asset_path().
    $imgs = @($files | Where-Object {
        $_ -match '\.(jpe?g|png|webp|heic|heif)$' -and
        $_ -notmatch '/jpg-originals/' -and $_ -notmatch '/archive-originals/'
    })
    Write-Output ("B2 po {0}: {1} failu, is ju nuotrauku {2}" -f $Prefix, $files.Count, $imgs.Count)
    foreach ($f in $imgs) {
        $enc = [Uri]::EscapeDataString($f)
        $urls += "$Site/img.php?file=$enc&$ThumbQuery"
        if ($Views) { $urls += "$Site/img.php?file=$enc&$ViewQuery" }
    }
}
else {
    # Paskelbtiems albumams adresus duoda pati galerija - taip jie garantuotai
    # sutampa su tuo, ka atiduos naršyklei, net jei thumb_url() kada pasikeis.
    $slugs = @()
    if ($All) {
        $cursor = ''
        do {
            $u = "$Site/b2-gallery.php?limit=200&v=warm"
            if ($cursor) { $u += '&cursor=' + [Uri]::EscapeDataString($cursor) }
            $d = Get-ApiJson $u
            $slugs += @($d.items | Where-Object { $_.type -eq 'folder' } | ForEach-Object { [string]$_.path })
            $cursor = [string]$d.nextCursor
        } while ($cursor)
        Write-Output ("Albumu: {0}" -f $slugs.Count)
    }
    else { $slugs = @($Album) }

    foreach ($s in $slugs) {
        # API limit apkerpa iki 200, todel BUTINA eiti per nextCursor. Be to
        # didziausias archyvo albumas (604 nuotraukos) butu apsildytas tik
        # trecdaliu, ir likusios miniatiuros vis tiek lauktu pirmo ziurovo.
        $n = 0
        $cur = ''
        do {
            $u = "$Site/b2-gallery.php?path=" + [Uri]::EscapeDataString($s) + "&limit=200&v=warm"
            if ($cur) { $u += '&cursor=' + [Uri]::EscapeDataString($cur) }
            $d = Get-ApiJson $u
            $ph = @($d.items | Where-Object { $_.type -eq 'photo' })
            foreach ($p in $ph) {
                if ($p.thumbUrl) { $urls += "$Site/" + $p.thumbUrl }
                if ($Views -and $p.viewUrl) { $urls += "$Site/" + $p.viewUrl }
            }
            $n += $ph.Count
            $cur = [string]$d.nextCursor
        } while ($cur)
        if (-not $All) { Write-Output ("Albumas {0}: {1} nuotrauku" -f $s, $n) }
    }
}

$urls = @($urls | Select-Object -Unique)
if ($Of -gt 1) {
    # Kas N-tas adresas, pradedant nuo $Shard. Skaitiklis, o ne IndexOf: pastarasis
    # kiekvienam elementui perbegtu visa masyva, t.y. 12 tukst. adresu virstu
    # 154 mln. palyginimu.
    $visi = $urls.Count
    $dalis = New-Object Collections.ArrayList
    for ($k = $Shard - 1; $k -lt $visi; $k += $Of) { [void]$dalis.Add($urls[$k]) }
    $urls = @($dalis)
    Write-Output ("Srautas {0} is {1}: {2} adresai is {3}" -f $Shard, $Of, $urls.Count, $visi)
}
if (-not $urls.Count) { Write-Output 'Nieko sildyti nereikia.'; exit 0 }
Write-Output ("Adresu: {0}   (~{1:N1} min. esant {2} uzkl./s)" -f $urls.Count, ($urls.Count / [double]$Rps / 60), $Rps)
Write-Output ''

# --- Sildymas -----------------------------------------------------------
$delayMs = [int](1000 / [Math]::Max(1, $Rps))
$stat = @{}
$fail = 0
$sw = [Diagnostics.Stopwatch]::StartNew()
$i = 0
foreach ($u in $urls) {
    $i++
    try {
        $r = Invoke-WebRequest -Uri $u -TimeoutSec $TimeoutSec -UseBasicParsing
        $cs = [string]$r.Headers['cf-cache-status']
        if (-not $cs) { $cs = 'be-CDN' }
        if (-not $stat.ContainsKey($cs)) { $stat[$cs] = 0 }
        $stat[$cs]++
    }
    catch {
        $fail++
        if ($fail -le 5) { Write-Output ("  NEPAVYKO: " + $_.Exception.Message) }
    }
    if ($i % 50 -eq 0) {
        Write-Output ("  {0}/{1}  ({2:N0} s)" -f $i, $urls.Count, $sw.Elapsed.TotalSeconds)
    }
    Start-Sleep -Milliseconds $delayMs
}
$sw.Stop()

Write-Output ''
Write-Output ("Uzklausu     : {0} per {1:N0} s" -f $urls.Count, $sw.Elapsed.TotalSeconds)
foreach ($k in ($stat.Keys | Sort-Object)) { Write-Output ("  {0,-10} {1}" -f $k, $stat[$k]) }
Write-Output ("Nepavyko     : {0}" -f $fail)
if ($fail -gt 0) { exit 1 }
exit 0
