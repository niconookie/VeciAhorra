<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Identity;

use VeciAhorra\Modules\Minimarket\Ownership\StoreOwnershipRepository;

final class MinimarketRole
{
    public const ROLE = 'veciahorra_minimarket';
    public const CAPABILITY = 'veciahorra_manage_store';
    public const STORE_META_KEY = '_veciahorra_store_id';

    public function register(): void
    {
        add_role(self::ROLE, 'Minimarket VeciAhorra', [
            'read' => true,
            self::CAPABILITY => true,
        ]);
        get_role(self::ROLE)?->add_cap(self::CAPABILITY);
        get_role('administrator')?->add_cap(self::CAPABILITY);

        add_action('show_user_profile', [$this, 'field']);
        add_action('edit_user_profile', [$this, 'field']);
        add_action('personal_options_update', [$this, 'save']);
        add_action('edit_user_profile_update', [$this, 'save']);
    }

    public function field(\WP_User $user): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $table = $wpdb->prefix . \VeciAhorra\Core\Config::TABLE_PREFIX . 'stores';
        $stores = $wpdb->get_results("SELECT id, business_name, status FROM {$table} ORDER BY business_name", ARRAY_A);
        try {
            $selected = (new StoreOwnershipRepository())->resolveStoreIdForOwnerUser((int) $user->ID) ?? 0;
        } catch (\RuntimeException) {
            $selected = 0;
        }
        wp_nonce_field('veciahorra_assign_store_' . $user->ID, 'veciahorra_store_nonce');
        ?>
        <h2><?php esc_html_e('Minimarket VeciAhorra', 'veciahorra'); ?></h2>
        <table class="form-table"><tr>
            <th><label for="veciahorra_store_id"><?php esc_html_e('Store asociado', 'veciahorra'); ?></label></th>
            <td><select name="veciahorra_store_id" id="veciahorra_store_id">
                <option value="0"><?php esc_html_e('Sin asociación', 'veciahorra'); ?></option>
                <?php foreach ((array) $stores as $store) : ?>
                    <option value="<?php echo esc_attr((string) $store['id']); ?>" <?php selected($selected, (int) $store['id']); ?>>
                        <?php echo esc_html($store['business_name'] . ' (' . $store['status'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select><p class="description"><?php esc_html_e('La asignación se guarda en el Store; el perfil conserva una proyección de compatibilidad.', 'veciahorra'); ?></p></td>
        </tr></table>
        <?php
    }

    public function save(int $userId): void
    {
        if (! current_user_can('manage_options')
            || ! isset($_POST['veciahorra_store_nonce'])
            || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['veciahorra_store_nonce'])), 'veciahorra_assign_store_' . $userId)) {
            return;
        }
        $storeId = isset($_POST['veciahorra_store_id']) ? absint($_POST['veciahorra_store_id']) : 0;
        try {
            (new StoreOwnershipRepository())->setOwnerStoreForUser(
                $userId,
                $storeId > 0 ? $storeId : null
            );
        } catch (\RuntimeException $exception) {
            add_settings_error(
                'veciahorra_store_ownership',
                $exception->getMessage(),
                __('No fue posible cambiar el Store porque la asignación requiere revisión.', 'veciahorra'),
                'error'
            );
        }
    }
}
