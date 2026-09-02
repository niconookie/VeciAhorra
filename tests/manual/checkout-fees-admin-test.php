<?php

declare(strict_types=1);

require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (! $condition) throw new RuntimeException($message);
};
$originalUser = get_current_user_id();
$admin = get_users(['role' => 'administrator', 'number' => 1]);
$subscriber = wp_insert_user([
    'user_login' => 'va_fee_subscriber_' . wp_generate_password(10, false, false),
    'user_pass' => wp_generate_password(24, true, true),
    'user_email' => 'va-fee-' . wp_generate_password(8, false, false) . '@example.test',
    'role' => 'subscriber',
]);
if ($admin === [] || is_wp_error($subscriber)) throw new RuntimeException('Admin test fixture unavailable.');

try {
    wp_set_current_user((int) $admin[0]->ID);
    $page = new \VeciAhorra\Modules\Checkout\Admin\CheckoutFeeSettingsPage();
    ob_start();
    $page->render();
    $html = (string) ob_get_clean();
    $assert(str_contains($html, 'veciahorra_checkout_fees_save'), 'Admin action missing.');
    $assert(str_contains($html, '_wpnonce'), 'Nonce missing.');
    foreach (['platform_fee_clp', 'delivery_fee_clp', 'delivery_minimum_subtotal_clp'] as $field) {
        $assert(str_contains($html, 'name="' . $field . '"'), "Field {$field} missing.");
    }

    wp_set_current_user((int) $subscriber);
    $handler = static fn (): Closure => static function ($message, $title, array $args): never {
        throw new RuntimeException('wp_die_' . (string) ($args['response'] ?? 0));
    };
    add_filter('wp_die_handler', $handler);
    try {
        $page->render();
        $assert(false, 'Unauthorized render accepted.');
    } catch (RuntimeException $exception) {
        $assert($exception->getMessage() === 'wp_die_403', 'Unauthorized render did not return 403.');
    } finally {
        remove_filter('wp_die_handler', $handler);
    }
} finally {
    wp_set_current_user($originalUser);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    wp_delete_user((int) $subscriber);
}

echo "PASS checkout-fees-admin capability=manage_options nonce=1 assertions={$assertions}\n";
