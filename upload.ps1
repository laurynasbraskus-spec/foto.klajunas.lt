<#
    Trumpinys dev\upload_site.ps1 - kad ikelti butu galima is saknies.

    Patys ikelimo skriptai guli dev\, bet dirbama beveik visada saknyje, todel
    ".\upload_site.ps1" ten nerandamas. Skriptas nuo darbinio katalogo
    nepriklauso (visi keliai jame absoliutus), tad uztenka ji issikviesti pagal
    savo paties vieta.

        .\upload.ps1 -Files @('img.php','admin/index.php') -Execute
        .\upload.ps1 -Files index.html -Execute

    Visi argumentai perduodami toliau nepakeisti.
#>
& (Join-Path $PSScriptRoot 'dev\upload_site.ps1') @args
exit $LASTEXITCODE
