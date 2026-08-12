<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket;

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Minimarket\Identity\MinimarketRole;
use VeciAhorra\Modules\Minimarket\Routes\MinimarketRoutes;

final class MinimarketModule
{
    public const SHORTCODE = 'veciahorra_minimarket_panel';

    public function register(): void
    {
        (new MinimarketRole())->register();
        $routes = new MinimarketRoutes();
        add_action('rest_api_init', [$routes, 'register']);
        add_shortcode(self::SHORTCODE, [$this, 'render']);
    }

    public function render(): string
    {
        if (! is_user_logged_in()) {
            return '<p>Debes <a href="' . esc_url(wp_login_url(get_permalink())) . '">iniciar sesión</a> para acceder al panel.</p>';
        }
        wp_enqueue_style('veciahorra-minimarket', VA_PLUGIN_URL . 'assets/frontend/css/minimarket-panel.css', [], Config::PLUGIN_VERSION);
        wp_enqueue_script('veciahorra-minimarket', VA_PLUGIN_URL . 'assets/frontend/js/minimarket-panel.js', [], Config::PLUGIN_VERSION, true);
        wp_add_inline_script('veciahorra-minimarket', 'window.VeciAhorraMinimarket=' . wp_json_encode([
            'restUrl' => esc_url_raw(rest_url('veciahorra/v1/minimarket/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]) . ';', 'before');
        ob_start(); require VA_PLUGIN_PATH . 'app/Modules/Minimarket/Views/panel.php'; return (string) ob_get_clean();
    }
}
