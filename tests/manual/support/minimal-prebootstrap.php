<?php
declare(strict_types=1);

// Loaded by the disposable php.ini before WordPress and before every test body.
$minimalNetworkFunctions = [
    'gethostbyname', 'gethostbynamel', 'gethostbyaddr', 'dns_get_record',
    'checkdnsrr', 'dns_check_record', 'getmxrr', 'dns_get_mx',
    'curl_init', 'curl_multi_init', 'curl_exec', 'curl_multi_exec',
    'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server',
    'stream_socket_accept',
];
foreach (array_merge($minimalNetworkFunctions, get_extension_funcs('sockets') ?: []) as $minimalFunction) {
    if (function_exists($minimalFunction)) {
        throw new RuntimeException('PREBOOTSTRAP_NETWORK_FUNCTION_ENABLED');
    }
}
foreach (['http', 'https', 'ftp', 'ftps'] as $minimalWrapper) {
    if (in_array($minimalWrapper, stream_get_wrappers(), true)) {
        stream_wrapper_unregister($minimalWrapper);
    }
}
if (getenv('MINIMAL_GATEWAY') !== 'mock' || getenv('VECIAHORRA_PAYMENT_GATEWAY') !== 'mock'
    || getenv('payment_gateway') !== 'mock') {
    throw new RuntimeException('PREBOOTSTRAP_MOCK_REQUIRED');
}
foreach (getenv() as $minimalName => $minimalValue) {
    if (preg_match('/WEBPAY|PUBLIC_ORIGIN/i', $minimalName) && $minimalValue !== '') {
        throw new RuntimeException('PREBOOTSTRAP_WEBPAY_CONFIGURATION_FORBIDDEN');
    }
}

/** Read only literal database authorities; never execute wp-config to inspect it. */
function minimalConfigLiteral(string $config, string $name): string
{
    $tokens = array_values(array_filter(token_get_all($config), static fn ($token): bool =>
        !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $values = [];
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'define'
            || ($tokens[$i + 1] ?? null) !== '(') { continue; }
        $key = $tokens[$i + 2] ?? null;
        if (!is_array($key) || $key[0] !== T_CONSTANT_ENCAPSED_STRING
            || substr($key[1], 1, -1) !== $name) { continue; }
        $value = $tokens[$i + 4] ?? null;
        if (($tokens[$i + 3] ?? null) !== ',' || !is_array($value)
            || $value[0] !== T_CONSTANT_ENCAPSED_STRING || ($tokens[$i + 5] ?? null) !== ')'
            || !preg_match('/\A([\'\"])([A-Za-z0-9_.:\[\]-]+)\1\z/', $value[1], $match)) {
            throw new RuntimeException('PREBOOTSTRAP_DB_LITERAL_REQUIRED');
        }
        $values[] = $match[2];
    }
    if (count($values) !== 1) { throw new RuntimeException('PREBOOTSTRAP_DB_LITERAL_REQUIRED'); }
    return $values[0];
}

function minimalRuntimeAuthority(): array
{
    $identifier = getenv('MINIMAL_RUNTIME_IDENTIFIER');
    if (!is_string($identifier) || !preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $identifier)) {
        throw new RuntimeException('PREBOOTSTRAP_IDENTIFIER_REQUIRED');
    }
    $ini = realpath((string) php_ini_loaded_file());
    if ($ini === false || basename($ini) !== 'php.ini'
        || realpath((string) getenv('PHPRC')) !== $ini
        || realpath((string) ini_get('auto_prepend_file')) !== realpath(__FILE__)
        || !hash_equals((string) getenv('MINIMAL_PHP_INI_SHA256'), hash_file('sha256', $ini))) {
        throw new RuntimeException('PREBOOTSTRAP_INI_AUTHORITY');
    }
    if (basename(dirname($ini)) !== $identifier) {
        throw new RuntimeException('PREBOOTSTRAP_IDENTIFIER_AUTHORITY');
    }
    $root = str_replace('\\', '/', dirname($ini)) . '/wordpress';
    if (str_replace('\\', '/', (string) realpath($root)) !== $root
        || str_replace('\\', '/', (string) getenv('MINIMAL_WORDPRESS_ROOT')) !== $root
        || !is_file($root . '/minimal-disposable.marker')
        || trim((string) file_get_contents($root . '/minimal-disposable.marker')) !== $identifier) {
        throw new RuntimeException('PREBOOTSTRAP_RUNTIME_AUTHORITY');
    }
    $path = $root . '/wp-config.php';
    $config = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($config) || !hash_equals((string) getenv('MINIMAL_WP_CONFIG_SHA256'), hash('sha256', $config))) {
        throw new RuntimeException('PREBOOTSTRAP_CONFIG_HASH');
    }
    $database = minimalConfigLiteral($config, 'DB_NAME');
    $host = minimalConfigLiteral($config, 'DB_HOST');
    if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $database) || $database !== $identifier) {
        throw new RuntimeException('PREBOOTSTRAP_DB_NAME');
    }
    if (!preg_match('/\A(?:127\.0\.0\.1|\[::1\])(?::([1-9][0-9]{0,4}))?\z/', $host, $port)
        || (isset($port[1]) && (int) $port[1] > 65535)) {
        throw new RuntimeException('PREBOOTSTRAP_DB_HOST');
    }
    $source = 'C:/xampp/htdocs/VeciAhorra-hotfix-0314-minimal';
    $plugin = $root . '/wp-content/plugins/veciahorra';
    if (str_replace('\\', '/', (string) realpath($source)) !== $source
        || str_replace('\\', '/', (string) realpath($plugin)) !== $plugin) {
        throw new RuntimeException('PREBOOTSTRAP_PLUGIN_AUTHORITY');
    }
    $files = ['veciahorra.php'];
    foreach (['app', 'assets'] as $directory) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source . '/' . $directory,
            FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) { $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($source) + 1); }
        }
    }
    foreach ($files as $file) {
        if (!is_file($plugin . '/' . $file)
            || !hash_equals(hash_file('sha256', $source . '/' . $file), hash_file('sha256', $plugin . '/' . $file))) {
            throw new RuntimeException('PREBOOTSTRAP_PLUGIN_AUTHORITY');
        }
    }
    return ['root' => $root, 'plugin' => $plugin, 'database' => $database, 'host' => $host];
}

$minimalAuthority = minimalRuntimeAuthority();
foreach (['DB_NAME' => $minimalAuthority['database'], 'DB_HOST' => $minimalAuthority['host']] as $minimalName => $minimalValue) {
    if (defined($minimalName) && constant($minimalName) !== $minimalValue) {
        throw new RuntimeException('PREBOOTSTRAP_DB_AUTHORITY');
    }
    if (!defined($minimalName)) { define($minimalName, $minimalValue); }
}
require_once $minimalAuthority['plugin'] . '/vendor/autoload.php';
$minimalDenyClient = static function (string $class): void {
    if (str_starts_with($class, 'Transbank\\') || str_starts_with($class, 'GuzzleHttp\\')) {
        throw new RuntimeException('PREBOOTSTRAP_EXTERNAL_CLIENT_FORBIDDEN');
    }
};
foreach (get_declared_classes() as $minimalClass) { $minimalDenyClient($minimalClass); }
spl_autoload_register($minimalDenyClient, true, true);
if (str_replace('\\', '/', (string) (new ReflectionClass(\VeciAhorra\Core\Config::class))->getFileName())
    !== $minimalAuthority['plugin'] . '/app/Core/Config.php') {
    throw new RuntimeException('PREBOOTSTRAP_PLUGIN_AUTOLOAD');
}
define('MINIMAL_PREBOOTSTRAP_ISOLATED', true);


/** Exact local authority. PHP network functions remain disabled; the runner owns transport. */
function minimalLoopbackUrl(string $url): array
{
    $host = getenv('MINIMAL_RUNTIME_HTTP_HOST');
    $port = getenv('MINIMAL_RUNTIME_HTTP_PORT');
    $parts = parse_url($url);
    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
        || !is_string($port) || !preg_match('/\A[1-9][0-9]{0,4}\z/', $port) || (int) $port > 65535
        || !is_array($parts) || ($parts['scheme'] ?? '') !== 'http'
        || ($parts['host'] ?? '') !== ($host === '::1' ? '[::1]' : $host)
        || ($parts['port'] ?? 80) !== (int) $port
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
        || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
        throw new RuntimeException('AUDIT_HTTP_AUTHORITY_FORBIDDEN');
    }
    return $parts;
}

function minimalLoopbackHttp(mixed $preempt, array $args, string $url): array
{
    minimalLoopbackUrl($url); // Reject before publishing any transport request.
    $method = $args['method'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        throw new RuntimeException('AUDIT_HTTP_METHOD_FORBIDDEN');
    }
    $directory = dirname(minimalRuntimeAuthority()['root']) . '/loopback-http';
    if (!is_dir($directory) || is_link($directory)) { throw new RuntimeException('AUDIT_HTTP_RUNNER_REQUIRED'); }
    $base = $directory . '/' . bin2hex(random_bytes(16));
    try {
        file_put_contents($base . '.pending', json_encode(['url' => $url, 'method' => $method], JSON_THROW_ON_ERROR));
        rename($base . '.pending', $base . '.request.json');
        $deadline = microtime(true) + 10;
        while (!is_file($base . '.response.json')) {
            if (microtime(true) > $deadline) { throw new RuntimeException('AUDIT_HTTP_RUNNER_TIMEOUT'); }
            usleep(10000);
        }
        $response = json_decode(file_get_contents($base . '.response.json'), true, 512, JSON_THROW_ON_ERROR);
        if (isset($response['error'])) { throw new RuntimeException($response['error']); }
        if (isset($response['headers']['location'])) {
            minimalLoopbackUrl($response['headers']['location']);
        }
        return $response;
    } finally {
        foreach (['.pending', '.request.json', '.response.json'] as $suffix) {
            if (is_file($base . $suffix)) { unlink($base . $suffix); }
        }
    }
}

// WordPress converts preinitialized filters to WP_Hook before installation HTTP calls.
$GLOBALS['wp_filter']['pre_http_request'][PHP_INT_MIN][] = [
    'function' => 'minimalLoopbackHttp', 'accepted_args' => 3,
];
