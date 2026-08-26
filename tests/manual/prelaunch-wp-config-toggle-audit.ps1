param([Parameter(Mandatory=$true)][string]$ConfigPath)
$ErrorActionPreference = 'Stop'
$backup = Join-Path ([IO.Path]::GetTempPath()) ('veciahorra-wp-config-' + [guid]::NewGuid().ToString('N') + '.bak')
$original = [IO.File]::ReadAllBytes($ConfigPath)
$beforeHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $ConfigPath).Hash.ToLowerInvariant()
$beforeSize = $original.Length
[IO.File]::WriteAllBytes($backup, $original)
function Set-Flags([bool]$registration, [bool]$commerce) {
    $r = if ($registration) {'true'} else {'false'}
    $c = if ($commerce) {'true'} else {'false'}
    Set-FlagLiterals $r $c
}
function Set-FlagLiterals([string]$r, [string]$c) {
    [IO.File]::WriteAllBytes($ConfigPath, $original)
    $encoding = [Text.UTF8Encoding]::new($false)
    $text = $encoding.GetString($original)
    $pattern = '(?m)^\s*/\*\s*(?:That.s all, stop editing|Eso es todo, deja de editar).*$'
    $match = [regex]::Match($text, $pattern)
    if (-not $match.Success) { throw 'WordPress final marker not found.' }
    $insert = "define('VECIAHORRA_PUBLIC_REGISTRATION_ENABLED', $r);`r`ndefine('VECIAHORRA_PUBLIC_COMMERCE_ENABLED', $c);`r`n`r`n"
    $updated = $text.Insert($match.Index, $insert)
    [IO.File]::WriteAllBytes($ConfigPath, $encoding.GetBytes($updated))
}
try {
    & python tests/manual/prelaunch-http-toggle-audit.py absent
    if ($LASTEXITCODE -ne 0) { throw 'Absent HTTP matrix failed.' }
    Set-Flags $false $false
    & python tests/manual/prelaunch-http-toggle-audit.py false
    if ($LASTEXITCODE -ne 0) { throw 'False HTTP matrix failed.' }
    foreach ($literal in @("'true'", "'1'", "'yes'", '1', 'null')) {
        Set-FlagLiterals $literal $literal
        & python tests/manual/prelaunch-http-toggle-audit.py hostile
        if ($LASTEXITCODE -ne 0) { throw "Hostile literal HTTP matrix failed: $literal" }
    }
    Set-Flags $true $true
    & python tests/manual/prelaunch-http-toggle-audit.py true
    if ($LASTEXITCODE -ne 0) { throw 'True HTTP matrix failed.' }
    & python tests/manual/anonymous-product-offer-browser-test.py
    if ($LASTEXITCODE -ne 0) { throw 'Enabled anonymous commerce browser regression failed.' }
    $enabledTests = @(
        'tests/manual/cart-foundation-test.php',
        'tests/manual/public-checkout-test.php',
        'tests/manual/public-payment-status-projection-test.php',
        'tests/manual/woocommerce-webpay-gateway-test.php'
    )
    foreach ($test in $enabledTests) {
        if ($test -eq 'tests/manual/woocommerce-webpay-gateway-test.php') {
            $prepend = (Resolve-Path 'tests/manual/support/prelaunch-enabled.php').Path
            & C:\xampp\php\php.exe -d "auto_prepend_file=$prepend" $test
        } else {
            & C:\xampp\php\php.exe $test
        }
        if ($LASTEXITCODE -ne 0) { throw "Enabled regression failed: $test" }
    }
} finally {
    [IO.File]::WriteAllBytes($ConfigPath, [IO.File]::ReadAllBytes($backup))
    Remove-Item -LiteralPath $backup -Force
}
$afterHash = (Get-FileHash -Algorithm SHA256 -LiteralPath $ConfigPath).Hash.ToLowerInvariant()
$afterSize = (Get-Item -LiteralPath $ConfigPath).Length
$restored = [IO.File]::ReadAllBytes($ConfigPath)
$flagCount = ([regex]::Matches([Text.Encoding]::UTF8.GetString($restored), 'VECIAHORRA_PUBLIC_(REGISTRATION|COMMERCE)_ENABLED')).Count
"WP_CONFIG_SHA256_BEFORE=$beforeHash"
"WP_CONFIG_SIZE_BEFORE=$beforeSize"
"WP_CONFIG_SHA256_AFTER=$afterHash"
"WP_CONFIG_SIZE_AFTER=$afterSize"
"WP_CONFIG_TEMP_FLAGS_AFTER=$flagCount"
if ($beforeHash -ne $afterHash -or $beforeSize -ne $afterSize -or $flagCount -ne 0) { throw 'wp-config.php was not restored byte-for-byte.' }
