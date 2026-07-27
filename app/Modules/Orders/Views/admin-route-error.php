<?php

declare(strict_types=1);

if (! defined('ABSPATH')) exit;
?>
<div class="wrap veciahorra-orders-admin">
    <h1><?= esc_html__('Ruta administrativa de pedidos no válida', 'veciahorra'); ?></h1>
    <div class="notice notice-error inline" role="alert">
        <p><?= esc_html($errorMessage); ?></p>
    </div>
    <p><a href="<?= esc_url($returnUrl); ?>">&larr; <?= esc_html__('Volver a pedidos', 'veciahorra'); ?></a></p>
</div>
