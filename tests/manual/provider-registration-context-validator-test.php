<?php

declare(strict_types=1);

function home_url(string $path = ''): string { return 'https://example.test' . $path; }
function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
function get_posts(array $args): array { return []; }
function has_shortcode(string $content, string $shortcode): bool { return false; }
function untrailingslashit(string $value): string { return rtrim($value, '/\\'); }
function add_query_arg(string $key, string $value, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($key) . '=' . rawurlencode($value);
}
function esc_url_raw(string $url): string { return $url; }
function wp_validate_redirect(string $url, string $fallback = ''): string
{
    if (str_starts_with($url, '/')) return str_starts_with($url, '//') ? $fallback : $url;
    $parts = parse_url($url);
    return is_array($parts) && ($parts['host'] ?? '') === 'example.test' ? $url : $fallback;
}

require_once dirname(__DIR__, 2) . '/app/Modules/ServiceProviders/Domain/ServicePlanCatalog.php';
require_once dirname(__DIR__, 2) . '/app/Modules/CustomerAccess/CustomerAccessModule.php';

$reflection = new ReflectionClass(VeciAhorra\Modules\CustomerAccess\CustomerAccessModule::class);
$module = $reflection->newInstanceWithoutConstructor();
$providerRedirect = $reflection->getMethod('providerRedirect');
$safeRedirect = $reflection->getMethod('safeInternalRedirect');
$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) throw new RuntimeException($message);
};

foreach (['local', 'featured', 'communal'] as $plan) {
    foreach (["/prestadores/?plan={$plan}", "https://example.test/prestadores/?plan={$plan}"] as $url) {
        $result = $providerRedirect->invoke($module, $url);
        $assert(is_array($result) && $result['plan'] === $plan, "Rechazo destino valido {$url}");
        $assert($result['url'] === "https://example.test/prestadores/?plan={$plan}", 'No canonicalizo destino valido.');
    }
}

$invalid = [
    'https://evil.example/prestadores/?plan=local',
    'ftp://example.test/prestadores/?plan=local',
    '//example.test/prestadores/?plan=local',
    'https://user@example.test/prestadores/?plan=local',
    'https://example.test:444/prestadores/?plan=local',
    '/prestadores-malicioso/?plan=local',
    '/prestadores/../prestadores/?plan=local',
    '/prestadores/%252e%252e/?plan=local',
    '/prestadores/?plan=',
    '/prestadores/?plan=unknown',
    '/prestadores/?plan=LOCAL',
    '/prestadores/?plan=%20local',
    '/prestadores/?plan[]=local',
    '/prestadores/?plan=local&plan=featured',
    '/prestadores/?plan=local&next=x',
];
foreach ($invalid as $url) {
    $assert($providerRedirect->invoke($module, $url) === null, "Acepto destino hostil {$url}");
}

$assert($safeRedirect->invoke($module, '/servicios/?vista=lista') === '/servicios/?vista=lista', 'Rompio redirect interno historico.');
foreach (['https://evil.example/', '//evil.example/x', '/x/../admin', '/x/%252e%252e'] as $url) {
    $assert($safeRedirect->invoke($module, $url) === null, "Redirect general inseguro {$url}");
}
echo "PASS provider-registration-context-validator assertions={$assertions}\n";
