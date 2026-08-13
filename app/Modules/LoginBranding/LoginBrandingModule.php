<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\LoginBranding;

use VeciAhorra\Core\Config;

final class LoginBrandingModule
{
    private const STYLE_HANDLE = 'veciahorra-login-branding';

    public function register(): void
    {
        add_action('login_enqueue_scripts', [$this, 'enqueue']);
        add_filter('login_headerurl', [$this, 'logoUrl']);
        add_filter('login_headertext', [$this, 'logoText']);
        add_filter('login_message', [$this, 'intro']);
        add_filter('login_site_html_link', [$this, 'backToSite']);
        add_action('login_footer', [$this, 'roleHelp']);
    }

    public function enqueue(): void
    {
        wp_enqueue_style(
            self::STYLE_HANDLE,
            VA_PLUGIN_URL . 'assets/login/login-branding.css',
            [],
            Config::PLUGIN_VERSION
        );
        $logoId = (int) get_theme_mod('custom_logo');
        $logoUrl = $logoId > 0 ? wp_get_attachment_image_url($logoId, 'full') : false;
        if (is_string($logoUrl) && $logoUrl !== '') {
            $secureLogoUrl = set_url_scheme($logoUrl, is_ssl() ? 'https' : 'http');
            wp_add_inline_style(
                self::STYLE_HANDLE,
                '.login h1 a{background-image:url("' . esc_url_raw($secureLogoUrl) . '");}'
            );
        }
    }

    public function logoUrl(): string
    {
        return home_url('/');
    }

    public function logoText(): string
    {
        return 'Ir a VeciAhorra';
    }

    public function intro(string $message): string
    {
        return '<section class="va-login-intro" aria-labelledby="va-login-title">'
            . '<h2 id="va-login-title">Bienvenido a VeciAhorra</h2>'
            . '<p>Ingresa para acceder a tu panel.</p>'
            . '</section>' . $message;
    }

    public function backToSite(string $link): string
    {
        return '<p id="backtoblog"><a href="' . esc_url(home_url('/'))
            . '">&larr; Volver a VeciAhorra</a></p>';
    }

    public function roleHelp(): void
    {
        echo '<p class="va-login-role-help">Clientes, minimarkets, repartidores y prestadores acceden desde aquí.</p>';
    }
}
