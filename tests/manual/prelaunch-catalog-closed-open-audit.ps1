param([Parameter(Mandatory=$true)][string]$ConfigPath)
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$original = [IO.File]::ReadAllBytes($ConfigPath)
$beforeHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $ConfigPath).Hash.ToLowerInvariant()
$fixturePath = Join-Path ([IO.Path]::GetTempPath()) ('va-prelaunch-fixture-' + [guid]::NewGuid().ToString('N') + '.json')
$sessionPath = Join-Path ([IO.Path]::GetTempPath()) ('va-prelaunch-session-' + [guid]::NewGuid().ToString('N') + '.json')
$run = $null
$previousSector = $null

function Set-LaunchFlags([bool]$enabled) {
    [IO.File]::WriteAllBytes($ConfigPath, $original)
    $encoding = [Text.UTF8Encoding]::new($false)
    $text = $encoding.GetString($original)
    $pattern = '(?m)^\s*/\*\s*(?:That.s all, stop editing|Eso es todo, deja de editar).*$'
    $match = [regex]::Match($text, $pattern)
    if (-not $match.Success) { throw 'WordPress final marker not found.' }
    $literal = if ($enabled) { 'true' } else { 'false' }
    $insert = "define('VECIAHORRA_PUBLIC_REGISTRATION_ENABLED', $literal);`r`ndefine('VECIAHORRA_PUBLIC_COMMERCE_ENABLED', $literal);`r`n`r`n"
    [IO.File]::WriteAllBytes($ConfigPath, $encoding.GetBytes($text.Insert($match.Index, $insert)))
}

try {
    $fixtureJson = & C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'anonymous-product-offer-browser-fixture.php') setup
    if ($LASTEXITCODE -ne 0) { throw 'Fixture setup failed.' }
    [IO.File]::WriteAllText($fixturePath, $fixtureJson, [Text.UTF8Encoding]::new($false))
    $fixture = $fixtureJson | ConvertFrom-Json
    $run = $fixture.run
    $previousSector = & C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'anonymous-product-offer-sector-state.php') set ([string]$fixture.zone)
    if ($LASTEXITCODE -ne 0) { throw 'Sector setup failed.' }
    $sessionJson = & C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'anonymous-product-offer-session-fixture.php')
    if ($LASTEXITCODE -ne 0) { throw 'Session setup failed.' }
    [IO.File]::WriteAllText($sessionPath, $sessionJson, [Text.UTF8Encoding]::new($false))

    Set-LaunchFlags $false
    & python (Join-Path $PSScriptRoot 'prelaunch-catalog-closed-open-browser.py') closed $fixturePath $sessionPath
    if ($LASTEXITCODE -ne 0) { throw 'Closed browser evidence failed.' }

    Set-LaunchFlags $true
    & python (Join-Path $PSScriptRoot 'prelaunch-catalog-closed-open-browser.py') open $fixturePath $sessionPath
    if ($LASTEXITCODE -ne 0) { throw 'Open browser evidence failed.' }
} finally {
    [IO.File]::WriteAllBytes($ConfigPath, $original)
    if ($null -ne $previousSector) {
        & C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'anonymous-product-offer-sector-state.php') restore $previousSector
    }
    if ($null -ne $run) {
        & C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'anonymous-product-offer-browser-fixture.php') cleanup $run
    }
    foreach ($path in @($fixturePath, $sessionPath)) { if (Test-Path $path) { Remove-Item -LiteralPath $path -Force } }
}

$afterHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $ConfigPath).Hash.ToLowerInvariant()
if ($beforeHash -ne $afterHash) { throw 'wp-config.php was not restored byte-for-byte.' }
"WP_CONFIG_SHA256_BEFORE=$beforeHash"
"WP_CONFIG_SHA256_AFTER=$afterHash"
& C:\xampp\php\php.exe (Join-Path $PSScriptRoot 'prelaunch-residue-audit.php')
if ($LASTEXITCODE -ne 0) { throw 'Residue audit failed.' }
