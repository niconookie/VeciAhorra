<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Admin;

use Throwable;
use VeciAhorra\Modules\Checkout\Service\CheckoutFeeConfiguration;

final class CheckoutFeeSettingsPage
{
    public function __construct(private ?CheckoutFeeConfiguration $configuration = null) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_veciahorra_checkout_fees_save', [$this, 'save']);
    }

    public function menu(): void
    {
        add_submenu_page('veciahorra', 'Cargos de checkout', 'Cargos de checkout', 'manage_options', 'veciahorra-checkout-fees', [$this, 'render']);
    }

    public function save(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'veciahorra'), '', ['response' => 403]);
        }
        check_admin_referer('veciahorra_checkout_fees_save');
        try {
            ($this->configuration ?? new CheckoutFeeConfiguration())->save([
                'platform_fee_clp' => $_POST['platform_fee_clp'] ?? null,
                'delivery_fee_clp' => $_POST['delivery_fee_clp'] ?? null,
                'delivery_minimum_subtotal_clp' => $_POST['delivery_minimum_subtotal_clp'] ?? null,
            ]);
            $status = 'saved';
        } catch (Throwable) {
            $status = 'invalid';
        }
        wp_safe_redirect(add_query_arg(['page' => 'veciahorra-checkout-fees', 'va_status' => $status], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'veciahorra'), '', ['response' => 403]);
        }
        $values = ($this->configuration ?? new CheckoutFeeConfiguration())->current();
        $status = isset($_GET['va_status']) ? sanitize_key((string) wp_unslash($_GET['va_status'])) : '';
        ?>
        <div class="wrap"><h1><?php esc_html_e('Cargos de checkout', 'veciahorra'); ?></h1>
        <?php if ($status === 'saved') : ?><div class="notice notice-success"><p><?php esc_html_e('Configuración guardada.', 'veciahorra'); ?></p></div><?php endif; ?>
        <?php if ($status === 'invalid') : ?><div class="notice notice-error"><p><?php esc_html_e('Los valores deben ser enteros CLP no negativos y canónicos.', 'veciahorra'); ?></p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="veciahorra_checkout_fees_save"><?php wp_nonce_field('veciahorra_checkout_fees_save'); ?>
            <table class="form-table"><tbody>
            <?php foreach (['platform_fee_clp' => 'Cargo por uso de VeciAhorra', 'delivery_fee_clp' => 'Cargo por despacho', 'delivery_minimum_subtotal_clp' => 'Subtotal mínimo para despacho'] as $field => $label) : ?>
                <tr><th scope="row"><label for="<?php echo esc_attr($field); ?>"><?php echo esc_html($label); ?></label></th><td><input class="regular-text" id="<?php echo esc_attr($field); ?>" name="<?php echo esc_attr($field); ?>" inputmode="numeric" pattern="0|[1-9][0-9]*" value="<?php echo esc_attr((string) $values[$field]); ?>" required><p class="description">CLP enteros, máximo <?php echo esc_html(number_format(CheckoutFeeConfiguration::MAX_CLP, 0, ',', '.')); ?>.</p></td></tr>
            <?php endforeach; ?>
            </tbody></table><?php submit_button(__('Guardar cargos', 'veciahorra')); ?></form></div>
        <?php
    }
}
