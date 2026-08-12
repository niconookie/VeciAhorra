<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerAccess;

final class CustomerAccessModule
{
    public const SHORTCODE = 'veciahorra_customer_registration';
    private const PAGE_SLUG = 'registro-cliente';
    private const HEADER_STYLE = 'veciahorra-global-header';

    /** @var list<string> */
    private const HEADER_MENU_LOCATIONS = [
        'menu_1',
        'menu_mobile',
        'primary',
        'primary-menu',
        'header',
        'header-menu',
        'mobile',
    ];

    /** @var list<string> */
    private array $errors = [];

    public function register(): void
    {
        add_action('init', [$this, 'ensureRegistrationPage'], 30);
        add_action('template_redirect', [$this, 'handleRegistration']);
        add_action('admin_init', [$this, 'redirectBusinessUsersFromAdmin']);
        add_shortcode(self::SHORTCODE, [$this, 'renderRegistration']);
        add_filter('login_redirect', [$this, 'loginRedirect'], 20, 3);
        add_filter('show_admin_bar', [$this, 'showAdminBar']);
        add_filter('wp_nav_menu_objects', [$this, 'filterRoleMenuItems'], 20, 2);
        add_filter('wp_nav_menu_items', [$this, 'appendAccessLinks'], 20, 2);
        add_action('wp_enqueue_scripts', [$this, 'enqueueHeaderStyle']);
    }

    public function ensureRegistrationPage(): void
    {
        if ($this->registrationPageId() > 0 || ! current_user_can('manage_options')) {
            return;
        }

        wp_insert_post([
            'post_title' => 'Registro cliente',
            'post_name' => self::PAGE_SLUG,
            'post_content' => '[' . self::SHORTCODE . ']',
            'post_status' => 'publish',
            'post_type' => 'page',
        ]);
    }

    public function handleRegistration(): void
    {
        if (
            ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST'
            || ! isset($_POST['veciahorra_customer_registration'])
        ) {
            return;
        }

        if (! isset($_POST['_va_customer_nonce']) || ! wp_verify_nonce(
            sanitize_text_field(wp_unslash((string) $_POST['_va_customer_nonce'])),
            'veciahorra_customer_registration'
        )) {
            $this->errors[] = 'La sesión del formulario venció. Recarga la página e inténtalo nuevamente.';
            return;
        }

        if (is_user_logged_in()) {
            wp_safe_redirect($this->customerPanelUrl());
            exit;
        }

        $firstName = sanitize_text_field(wp_unslash((string) ($_POST['first_name'] ?? '')));
        $lastName = sanitize_text_field(wp_unslash((string) ($_POST['last_name'] ?? '')));
        $email = sanitize_email(wp_unslash((string) ($_POST['email'] ?? '')));
        $rawEmail = trim(wp_unslash((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');

        if ($firstName === '') $this->errors[] = 'Ingresa tu nombre.';
        if ($lastName === '') $this->errors[] = 'Ingresa tu apellido.';
        if ($rawEmail === '') $this->errors[] = 'Ingresa tu correo electrónico.';
        elseif ($email === '' || ! is_email($email)) $this->errors[] = 'Ingresa un correo electrónico válido.';
        elseif (email_exists($email)) $this->errors[] = 'Ya existe una cuenta con ese correo electrónico.';
        if (strlen($password) < 8) $this->errors[] = 'La contraseña debe tener al menos 8 caracteres.';
        if ($password !== $confirmation) $this->errors[] = 'Las contraseñas no coinciden.';

        if ($this->errors !== []) {
            return;
        }

        $userId = function_exists('wc_create_new_customer')
            ? wc_create_new_customer($email, '', $password, [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'display_name' => trim($firstName . ' ' . $lastName),
            ])
            : new \WP_Error('woocommerce_required', 'No fue posible crear la cuenta en este momento.');

        if (is_wp_error($userId)) {
            $this->errors[] = $this->publicCreationError($userId);
            return;
        }

        $user = new \WP_User((int) $userId);
        $user->set_role('customer');
        wp_set_current_user((int) $userId);
        wp_set_auth_cookie((int) $userId, true, is_ssl());
        wp_safe_redirect($this->customerPanelUrl());
        exit;
    }

    public function renderRegistration(): string
    {
        if (is_user_logged_in()) {
            return '<section class="va-customer-registration"><h1>Ya tienes una sesión iniciada</h1>'
                . '<p><a class="va-button" href="' . esc_url($this->destinationFor(wp_get_current_user()))
                . '">Ir a mi panel</a> <a href="' . esc_url(wp_logout_url(home_url('/')))
                . '">Cerrar sesión</a></p></section>';
        }

        $messages = '';
        if ($this->errors !== []) {
            $messages = '<div class="va-alert va-alert--error" role="alert"><ul>';
            foreach ($this->errors as $error) $messages .= '<li>' . esc_html($error) . '</li>';
            $messages .= '</ul></div>';
        }

        ob_start();
        ?>
        <section class="va-customer-registration">
            <h1>Crear cuenta de cliente</h1>
            <p>Regístrate para consultar tus compras y comprar en los comercios de tu barrio.</p>
            <?php echo $messages; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <form method="post" action="">
                <?php wp_nonce_field('veciahorra_customer_registration', '_va_customer_nonce'); ?>
                <input type="hidden" name="veciahorra_customer_registration" value="1">
                <label>Nombre *<input name="first_name" required autocomplete="given-name" value="<?php echo esc_attr(wp_unslash((string) ($_POST['first_name'] ?? ''))); ?>"></label>
                <label>Apellido *<input name="last_name" required autocomplete="family-name" value="<?php echo esc_attr(wp_unslash((string) ($_POST['last_name'] ?? ''))); ?>"></label>
                <label>Correo electrónico *<input name="email" type="email" required autocomplete="email" value="<?php echo esc_attr(wp_unslash((string) ($_POST['email'] ?? ''))); ?>"></label>
                <label>Contraseña *<input name="password" type="password" minlength="8" required autocomplete="new-password"></label>
                <label>Confirmar contraseña *<input name="password_confirmation" type="password" minlength="8" required autocomplete="new-password"></label>
                <button class="va-button" type="submit">Crear mi cuenta</button>
            </form>
            <p>¿Ya tienes una cuenta? <a href="<?php echo esc_url(wp_login_url($this->customerPanelUrl())); ?>">Iniciar sesión</a></p>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public function loginRedirect(string $redirectTo, string $requested, \WP_User|\WP_Error $user): string
    {
        return $user instanceof \WP_User ? $this->destinationFor($user) : $redirectTo;
    }

    public function redirectBusinessUsersFromAdmin(): void
    {
        if (! is_user_logged_in() || current_user_can('manage_options') || wp_doing_ajax()) {
            return;
        }
        wp_safe_redirect($this->destinationFor(wp_get_current_user()));
        exit;
    }

    public function showAdminBar(bool $show): bool
    {
        return is_user_logged_in() && ! current_user_can('manage_options') ? false : $show;
    }

    public function appendAccessLinks(string $items, \stdClass $args): string
    {
        if (! $this->isHeaderMenu($args)) {
            return $items;
        }

        if (! is_user_logged_in()) {
            return $items . $this->menuLink('Registrarse', $this->registrationUrl())
                . $this->menuLink('Iniciar sesión', wp_login_url($this->customerPanelUrl()));
        }

        $user = wp_get_current_user();

        return $items . $this->userNameMenuItem($user)
            . $this->menuLink('Mi panel', $this->destinationFor($user))
            . $this->menuLink('Cerrar sesión', wp_logout_url(home_url('/')));
    }

    public function filterRoleMenuItems(array $items, \stdClass $args): array
    {
        if (! $this->isHeaderMenu($args)) {
            return $items;
        }

        $user = wp_get_current_user();
        $isBusinessUser = array_intersect(
                ['veciahorra_minimarket', 'veciahorra_courier', 'veciahorra_service_provider'],
                (array) $user->roles
            ) !== [];

        $customerUrl = untrailingslashit($this->customerPanelUrl());
        $servicesUrl = untrailingslashit($this->pageUrlByShortcode('veciahorra_services', '/servicios/'));
        $registrationUrl = untrailingslashit($this->registrationUrl());
        $loginUrl = untrailingslashit(wp_login_url($this->customerPanelUrl()));
        $normalized = [];
        $servicesItem = null;

        foreach ($items as $item) {
            $url = isset($item->url) ? untrailingslashit((string) $item->url) : '';
            $title = isset($item->title) ? strtolower(trim(wp_strip_all_tags((string) $item->title))) : '';

            // Session actions are rendered once, after the role-aware base navigation.
            if ($url === $registrationUrl || $url === $loginUrl || in_array($title, ['registrarse', 'iniciar sesión'], true)) {
                continue;
            }
            if ($isBusinessUser && $url === $customerUrl) {
                continue;
            }
            if ($url === $servicesUrl || $title === 'servicios') {
                $servicesItem ??= $item;
                continue;
            }
            $normalized[] = $item;
        }

        $servicesItem ??= $this->navigationItem('Servicios', $servicesUrl);
        $purchaseIndex = null;
        foreach ($normalized as $index => $item) {
            if (isset($item->url) && untrailingslashit((string) $item->url) === $customerUrl) {
                $purchaseIndex = $index;
                break;
            }
        }

        if ($purchaseIndex === null) {
            $normalized[] = $servicesItem;
        } else {
            array_splice($normalized, $purchaseIndex + 1, 0, [$servicesItem]);
        }

        return array_values($normalized);
    }

    public function enqueueHeaderStyle(): void
    {
        if (is_admin()) {
            return;
        }

        wp_enqueue_style(
            self::HEADER_STYLE,
            VA_PLUGIN_URL . 'assets/frontend/css/global-header.css',
            [],
            \VeciAhorra\Core\Config::PLUGIN_VERSION
        );
    }

    public function destinationFor(\WP_User $user): string
    {
        if (user_can($user, 'manage_options')) return admin_url('admin.php?page=veciahorra');
        if (user_can($user, 'veciahorra_manage_store')) return $this->pageUrlByShortcode('veciahorra_minimarket_panel', '/panel-minimarket/');
        if (user_can($user, 'veciahorra_manage_deliveries')) return $this->pageUrlByShortcode('veciahorra_courier_panel', '/panel-repartidor/');
        if (user_can($user, 'veciahorra_manage_service_profile')) return $this->pageUrlByShortcode('veciahorra_service_provider_panel', '/panel-prestador/');
        return $this->customerPanelUrl();
    }

    private function registrationPageId(): int
    {
        $page = get_page_by_path(self::PAGE_SLUG, OBJECT, 'page');
        return $page instanceof \WP_Post ? (int) $page->ID : 0;
    }

    private function registrationUrl(): string
    {
        $id = $this->registrationPageId();
        return $id > 0 ? (string) get_permalink($id) : home_url('/' . self::PAGE_SLUG . '/');
    }

    private function customerPanelUrl(): string
    {
        return $this->pageUrlByShortcode('veciahorra_customer_panel', '/mis-compras/');
    }

    private function pageUrlByShortcode(string $shortcode, string $fallback): string
    {
        $pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1]);
        foreach ($pages as $page) {
            if (has_shortcode($page->post_content, $shortcode)) return (string) get_permalink($page);
        }
        return home_url($fallback);
    }

    private function menuLink(string $label, string $url): string
    {
        return '<li class="menu-item va-access-menu-item"><a href="' . esc_url($url) . '">'
            . esc_html($label) . '</a></li>';
    }

    private function userNameMenuItem(\WP_User $user): string
    {
        $name = trim((string) $user->display_name);
        if ($name === '') {
            $name = (string) $user->user_login;
        }

        return '<li class="menu-item va-access-menu-item va-access-menu-item--identity">'
            . '<span class="va-access-user-name">' . esc_html($name) . '</span></li>';
    }

    private function isHeaderMenu(\stdClass $args): bool
    {
        return in_array((string) ($args->theme_location ?? ''), self::HEADER_MENU_LOCATIONS, true);
    }

    private function navigationItem(string $label, string $url): \stdClass
    {
        $item = new \stdClass();
        $item->ID = 0;
        $item->db_id = 0;
        $item->menu_item_parent = 0;
        $item->object_id = 0;
        $item->object = 'custom';
        $item->type = 'custom';
        $item->type_label = __('Enlace personalizado');
        $item->title = $label;
        $item->url = $url;
        $item->target = '';
        $item->attr_title = '';
        $item->description = '';
        $item->classes = ['menu-item', 'menu-item-type-custom', 'va-global-menu-item'];
        $item->xfn = '';
        $item->status = 'publish';

        return $item;
    }

    private function publicCreationError(\WP_Error $error): string
    {
        return in_array($error->get_error_code(), ['registration-error-email-exists', 'existing_user_email'], true)
            ? 'Ya existe una cuenta con ese correo electrónico.'
            : 'No fue posible crear la cuenta. Revisa los datos e inténtalo nuevamente.';
    }
}
