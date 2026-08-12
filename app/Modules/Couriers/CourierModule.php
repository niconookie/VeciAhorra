<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Couriers;

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\Couriers\Admin\CourierAdminPage;
use VeciAhorra\Modules\Couriers\Identity\CourierRole;
use VeciAhorra\Modules\Couriers\Routes\CourierRoutes;

final class CourierModule
{
    public const SHORTCODE='veciahorra_courier_panel';
    public function register():void
    {
        add_action('init',[CourierRole::class,'register']);
        add_action('rest_api_init',[new CourierRoutes(),'register']);
        (new CourierAdminPage())->register();
        add_shortcode(self::SHORTCODE,[$this,'render']);
    }
    public function render():string
    {
        if (! is_user_logged_in()) {
            return '<p>Debes <a href="' . esc_url(wp_login_url(get_permalink()))
                . '">iniciar sesión</a> para acceder al Panel Repartidor.</p>';
        }
        (new FrontendAssets())->enqueue();
        wp_enqueue_style('veciahorra-courier',VA_PLUGIN_URL.'assets/frontend/css/courier-panel.css',[],Config::PLUGIN_VERSION);
        wp_enqueue_script('veciahorra-courier',VA_PLUGIN_URL.'assets/frontend/js/courier-panel.js',['veciahorra-frontend'],Config::PLUGIN_VERSION,true);
        return '<section class="va-courier" data-va-courier>'
            . '<header class="va-courier__header"><p class="va-courier__eyebrow">Repartos VeciAhorra</p><h1>Panel de repartidor</h1>'
            . '<p>Revisa las entregas disponibles y las entregas que tienes asignadas.</p><div data-va-courier-message aria-live="polite">Cargando…</div></header>'
            . '<section aria-labelledby="va-courier-summary-title"><h2 id="va-courier-summary-title">Resumen</h2><div class="va-courier__summary" data-va-courier-summary></div></section>'
            . '<section aria-labelledby="va-courier-available-title"><h2 id="va-courier-available-title">Entregas disponibles</h2><div data-va-courier-available></div></section>'
            . '<section aria-labelledby="va-courier-owned-title"><h2 id="va-courier-owned-title">Mis entregas</h2><div data-va-courier-owned></div></section></section>';
    }
}
