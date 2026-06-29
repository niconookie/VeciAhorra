<?php

use VeciAhorra\Core\Flash;
use VeciAhorra\Core\Session;

$flash = Flash::get();

if (!$flash) {
    return;
}

$class = match ($flash['type']) {

    'success' => 'notice notice-success',

    'error' => 'notice notice-error',

    default => 'notice notice-info',
};

?>

<div class="<?= esc_attr($class); ?> is-dismissible">
    <p><?= esc_html($flash['message']); ?></p>
</div>