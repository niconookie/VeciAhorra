<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Checkout\Admin;

use Throwable;
use VeciAhorra\Modules\Checkout\Service\DeliveryFlagService;

final class DeliveryFlagSettingsPage
{
    public function __construct(private ?DeliveryFlagService $service = null) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_veciahorra_delivery_flag_save', [$this, 'save']);
    }

    public function menu(): void
    {
        add_submenu_page('veciahorra', 'Habilitación de despacho', 'Habilitación de despacho', 'manage_options', 'veciahorra-delivery-flags', [$this, 'render']);
    }

    public function save(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Método no permitido.', 'veciahorra'), '', ['response' => 405]);
        }
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'veciahorra'), '', ['response' => 403]);
        }
        check_admin_referer('veciahorra_delivery_flag_save');
        try {
            ($this->service ?? new DeliveryFlagService())->update([
                'entity' => $_POST['entity'] ?? null,
                'id' => $_POST['id'] ?? null,
                'expected' => $_POST['expected'] ?? null,
                'enabled' => $_POST['enabled'] ?? null,
            ]);
            $status = 'saved';
        } catch (Throwable $throwable) {
            $status = $throwable instanceof \VeciAhorra\Exceptions\ConflictException ? 'stale' : 'invalid';
        }
        wp_safe_redirect(add_query_arg(['page' => 'veciahorra-delivery-flags', 'va_status' => $status], admin_url('admin.php')));
        exit;
    }

    public function render(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('No autorizado.', 'veciahorra'), '', ['response' => 403]);
        }
        $service = $this->service ?? new DeliveryFlagService();
        $status = isset($_GET['va_status']) && is_string($_GET['va_status']) ? sanitize_key(wp_unslash($_GET['va_status'])) : '';
        $labels = ['store' => 'Minimarkets', 'product' => 'Productos', 'inventory' => 'Ofertas / inventario'];
        ?>
        <div class="wrap"><h1><?php esc_html_e('Habilitación de despacho', 'veciahorra'); ?></h1>
        <p><?php esc_html_e('El despacho solo estará disponible cuando minimarket, producto y oferta estén habilitados. Todos permanecen cerrados por defecto.', 'veciahorra'); ?></p>
        <?php if ($status === 'saved') : ?><div class="notice notice-success"><p><?php esc_html_e('Estado actualizado.', 'veciahorra'); ?></p></div><?php endif; ?>
        <?php if ($status === 'invalid') : ?><div class="notice notice-error"><p><?php esc_html_e('Solicitud inválida o entidad inexistente.', 'veciahorra'); ?></p></div><?php endif; ?>
        <?php if ($status === 'stale') : ?><div class="notice notice-warning"><p><?php esc_html_e('El estado cambió. Recarga la página e inténtalo nuevamente.', 'veciahorra'); ?></p></div><?php endif; ?>
        <?php foreach ($labels as $entity => $heading) : ?>
            <h2><?php echo esc_html($heading); ?></h2><table class="widefat striped"><thead><tr><th>ID</th><th>Entidad</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
            <?php foreach ($service->listing($entity) as $row) : $enabled = (int) $row['delivery_enabled'] === 1; ?>
                <tr><td><?php echo esc_html((string) $row['id']); ?></td><td><?php echo esc_html((string) $row['label']); ?></td><td><?php echo esc_html($enabled ? 'Habilitado' : 'Deshabilitado'); ?></td><td>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="veciahorra_delivery_flag_save"><input type="hidden" name="entity" value="<?php echo esc_attr($entity); ?>"><input type="hidden" name="id" value="<?php echo esc_attr((string) $row['id']); ?>"><input type="hidden" name="expected" value="<?php echo esc_attr($enabled ? '1' : '0'); ?>"><input type="hidden" name="enabled" value="<?php echo esc_attr($enabled ? '0' : '1'); ?>"><?php wp_nonce_field('veciahorra_delivery_flag_save'); ?><?php submit_button($enabled ? 'Deshabilitar despacho' : 'Habilitar despacho', 'secondary', 'submit', false); ?>
                </form></td></tr>
            <?php endforeach; ?></tbody></table>
        <?php endforeach; ?></div><?php
    }
}
