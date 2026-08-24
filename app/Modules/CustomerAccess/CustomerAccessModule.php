<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\CustomerAccess;

final class CustomerAccessModule
{
    public const SHORTCODE = 'veciahorra_customer_registration';
    private const PAGE_SLUG = 'registro-cliente';
    private const HEADER_STYLE = 'veciahorra-global-header';
    private const HEADER_SCRIPT = 'veciahorra-global-header';
    private const REGISTRATION_STYLE = 'veciahorra-customer-registration';

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
        add_filter('woocommerce_prevent_admin_access', [$this, 'allowZonalAdminAccess']);
        add_filter('show_admin_bar', [$this, 'showAdminBar']);
        add_action('admin_enqueue_scripts', [$this, 'disableZonalCommandPalette'], 1);
        add_action('admin_bar_menu', [$this, 'simplifyZonalAdminBar'], 999);
        add_filter('wp_nav_menu_objects', [$this, 'filterRoleMenuItems'], 20, 2);
        add_filter('wp_nav_menu_items', [$this, 'appendAccessLinks'], 20, 2);
        add_filter('body_class', [$this, 'bodyClass']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueHeaderStyle']);
        add_action('wp_body_open', [$this, 'renderGlobalHeader'], 5);
        add_action('wp_enqueue_scripts', [$this, 'enqueueRegistrationStyle']);
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
            wp_safe_redirect($this->requestedRedirectUrl());
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
        wp_safe_redirect($this->requestedRedirectUrl());
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
        <section class="va-customer-registration" aria-labelledby="va-customer-registration-title">
            <header class="va-customer-registration__header">
                <p class="va-customer-registration__eyebrow">Cuenta VeciAhorra</p>
                <h1 id="va-customer-registration-title">Crear cuenta de cliente</h1>
                <p>Regístrate para consultar tus compras y comprar en los comercios de tu barrio.</p>
            </header>
            <?php echo $messages; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <form method="post" action="">
                <?php wp_nonce_field('veciahorra_customer_registration', '_va_customer_nonce'); ?>
                <input type="hidden" name="veciahorra_customer_registration" value="1">
                <input type="hidden" name="redirect_to" value="<?php echo esc_attr($this->requestedRedirectUrl()); ?>">
                <div class="va-customer-registration__grid">
                    <label class="va-customer-registration__field">Nombre *<input name="first_name" type="text" required autocomplete="given-name" value="<?php echo esc_attr(wp_unslash((string) ($_POST['first_name'] ?? ''))); ?>"></label>
                    <label class="va-customer-registration__field">Apellido *<input name="last_name" type="text" required autocomplete="family-name" value="<?php echo esc_attr(wp_unslash((string) ($_POST['last_name'] ?? ''))); ?>"></label>
                    <label class="va-customer-registration__field va-customer-registration__field--full">Correo electrónico *<input name="email" type="email" required autocomplete="email" value="<?php echo esc_attr(wp_unslash((string) ($_POST['email'] ?? ''))); ?>"></label>
                    <label class="va-customer-registration__field">Contraseña *<input name="password" type="password" minlength="8" required autocomplete="new-password"></label>
                    <label class="va-customer-registration__field">Confirmar contraseña *<input name="password_confirmation" type="password" minlength="8" required autocomplete="new-password"></label>
                </div>
                <div class="va-customer-registration__actions">
                    <button class="va-button" type="submit">Crear mi cuenta</button>
                </div>
            </form>
            <p class="va-customer-registration__login">¿Ya tienes una cuenta? <a href="<?php echo esc_url(wp_login_url($this->customerPanelUrl())); ?>">Iniciar sesión</a></p>
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
        if (current_user_can(\VeciAhorra\Modules\ZonalAdmin\Identity\ZonalAdminRole::CAPABILITY_READ)) {
            global $pagenow;
            $page = isset($_GET['page']) ? sanitize_key(wp_unslash((string) $_GET['page'])) : '';
            if (($pagenow === 'admin.php' && $page === 'veciahorra-zonal-stores') || $pagenow === 'profile.php') {
                return;
            }
        }
        wp_safe_redirect($this->destinationFor(wp_get_current_user()));
        exit;
    }

    public function showAdminBar(bool $show): bool
    {
        return is_user_logged_in() && ! current_user_can('manage_options') ? false : $show;
    }

    public function disableZonalCommandPalette(): void
    {
        if (! $this->isRestrictedZonalAdmin()) {
            return;
        }

        remove_action('admin_enqueue_scripts', 'wp_enqueue_command_palette_assets');
    }

    public function simplifyZonalAdminBar(\WP_Admin_Bar $adminBar): void
    {
        if (! $this->isRestrictedZonalAdmin()) {
            return;
        }

        $adminBar->remove_node('wp-logo');
        $adminBar->remove_node('site-name');
        $adminBar->remove_node('command-palette');
    }

    public function appendAccessLinks(string $items, \stdClass $args): string
    {
        if (! $this->isHeaderMenu($args)) {
            return $items;
        }

        if (! empty($args->va_header_primary)) {
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
        $isBusinessUser = user_can($user, \VeciAhorra\Modules\ZonalAdmin\Identity\ZonalAdminRole::CAPABILITY_READ)
            || array_intersect(
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
        wp_enqueue_script(
            self::HEADER_SCRIPT,
            VA_PLUGIN_URL . 'assets/frontend/js/global-header.js',
            [],
            \VeciAhorra\Core\Config::PLUGIN_VERSION,
            true
        );
    }

    public function renderGlobalHeader(): void
    {
        if (is_admin()) return;

        $routes = new \VeciAhorra\Modules\Frontend\Support\PublicRouteResolver();
        $catalogUrl = $routes->catalog() ?: home_url('/');
        $servicesUrl = $this->pageUrlByShortcode('veciahorra_services', '/servicios/');
        $cartUrl = $routes->cart() ?: home_url('/');
        $sector = null;
        $cartCount = 0;
        try {
            $sector = (new \VeciAhorra\Modules\Sectorization\CurrentSector())->current();
            $owner = is_user_logged_in()
                ? ['user_id' => get_current_user_id()]
                : ['session_id' => (new \VeciAhorra\Modules\Frontend\Support\CartSession())->identifier()];
            $cart = (new \VeciAhorra\Modules\Cart\Service\CartService(new \VeciAhorra\Modules\Cart\Repository\CartRepository()))->getPublicCart($owner);
            foreach ($cart['items'] as $item) $cartCount += max(0, (int) ($item['quantity'] ?? 0));
        } catch (\Throwable) {
            $sector = is_array($sector) ? $sector : null;
        }

        $user = wp_get_current_user();
        $accountUrl = is_user_logged_in() ? $this->destinationFor($user) : wp_login_url($this->customerPanelUrl());
        $currentLabel = is_front_page() ? 'Inicio' : wp_strip_all_tags((string) (get_the_title() ?: 'VeciAhorra'));
        ?>
        <header class="va-global-header" data-va-global-header data-rest-url="<?php echo esc_url(rest_url('veciahorra/v1/')); ?>" data-current-sector="<?php echo esc_attr(is_array($sector) ? (string) $sector['id'] : ''); ?>">
            <div class="va-global-header__main">
                <div class="va-global-header__container va-global-header__primary">
                    <a class="va-global-header__brand" href="<?php echo esc_url(home_url('/')); ?>" aria-label="VeciAhorra, ir al inicio">
                        <img class="va-global-header__logo" src="<?php echo esc_url(VA_PLUGIN_URL . 'assets/frontend/images/veciahorra-logo-horizontal.png'); ?>" alt="VeciAhorra" width="1600" height="400">
                    </a>
                    <button class="va-global-header__menu-toggle" type="button" aria-expanded="false" aria-controls="va-global-navigation"><span aria-hidden="true">☰</span><span class="screen-reader-text">Abrir menú principal</span></button>
                    <button class="va-global-header__sector" type="button" aria-expanded="false" aria-controls="va-global-sector-panel">
                        <span class="va-global-header__icon" aria-hidden="true">⌖</span><span><small>Tu microzona</small><strong data-va-sector-label><?php echo esc_html(is_array($sector) ? (string) $sector['name'] : 'Seleccionar zona'); ?></strong><em><?php echo esc_html(is_array($sector) ? (string) $sector['commune'] : 'Ubicación pendiente'); ?></em></span><i aria-hidden="true">⌄</i>
                    </button>
                    <form class="va-global-header__search" action="<?php echo esc_url($catalogUrl); ?>" method="get" role="search" data-va-header-search data-products-url="<?php echo esc_url($catalogUrl); ?>" data-services-url="<?php echo esc_url($servicesUrl); ?>" data-current-commune="<?php echo esc_attr(is_array($sector) ? (string) $sector['commune'] : ''); ?>">
                        <label class="screen-reader-text" for="va-global-search-scope">Ámbito de búsqueda</label><select id="va-global-search-scope" name="scope" data-va-header-search-scope><option value="products">Productos</option><option value="services">Servicios</option></select>
                        <label class="screen-reader-text" for="va-global-search">Buscar productos</label><span aria-hidden="true">⌕</span>
                        <input id="va-global-search" name="search" type="search" placeholder="¿Qué producto necesitas?" autocomplete="off" aria-describedby="va-global-search-context">
                        <button type="submit">Buscar</button>
                        <p class="screen-reader-text" id="va-global-search-context" data-va-header-search-context role="status" aria-live="polite">Busca productos disponibles en tu microzona.</p>
                    </form>
                    <div class="va-global-header__account">
                        <a href="<?php echo esc_url($accountUrl); ?>"><span class="va-global-header__round-icon" aria-hidden="true">👤</span><span><small><?php echo is_user_logged_in() ? esc_html('Hola, ' . ($user->first_name ?: $user->display_name)) : 'Bienvenido'; ?></small><strong><?php echo is_user_logged_in() ? 'Mi cuenta' : 'Iniciar sesión'; ?></strong></span></a>
                        <?php if (! is_user_logged_in()): ?><a class="va-global-header__register" href="<?php echo esc_url($this->registrationUrl()); ?>">Registrarse</a><?php endif; ?>
                    </div>
                    <a class="va-global-header__cart" href="<?php echo esc_url($cartUrl); ?>"><span aria-hidden="true">🛒</span><span>Carrito</span><b data-va-header-cart-count <?php echo $cartCount > 0 ? '' : 'hidden'; ?>><?php echo esc_html((string) $cartCount); ?></b></a>
                </div>
                <div class="va-global-header__sector-panel" id="va-global-sector-panel" hidden>
                    <div class="va-global-header__container"><label for="va-global-sector-select">Selecciona tu microzona</label><select id="va-global-sector-select" data-va-header-sector-select><option value="">Cargando microzonas…</option></select><p data-va-header-sector-message role="status" aria-live="polite"></p></div>
                </div>
            </div>
            <nav class="va-global-header__nav" id="va-global-navigation" aria-label="Navegación principal">
                <div class="va-global-header__container va-global-header__nav-inner">
                    <a class="va-global-header__categories" href="<?php echo esc_url($catalogUrl); ?>"><span aria-hidden="true">☰</span>Categorías</a>
                    <ul class="va-global-header__menu">
                        <?php foreach ($this->headerNavigationLinks($catalogUrl) as $link): ?><li><a href="<?php echo esc_url($link['url']); ?>"><?php echo esc_html($link['label']); ?></a></li><?php endforeach; ?>
                    </ul>
                    <span class="va-global-header__nav-spacer"></span>
                    <a href="<?php echo esc_url($this->pageUrlByShortcode('veciahorra_minimarket_registration', '/registro-minimarket/')); ?>">Vende en VeciAhorra</a>
                    <a href="<?php echo esc_url($this->pageUrlByShortcode('veciahorra_courier_registration', '/registro-repartidor/')); ?>">Reparte con nosotros</a>
                </div>
            </nav>
            <?php if (! is_front_page()): ?><nav class="va-global-header__breadcrumb" aria-label="Migas de pan"><div class="va-global-header__container"><a href="<?php echo esc_url(home_url('/')); ?>">Inicio</a><span aria-hidden="true">›</span><span aria-current="page"><?php echo esc_html($currentLabel); ?></span></div></nav><?php endif; ?>
        </header>
        <?php
    }

    public function allowZonalAdminAccess(bool $prevent): bool
    {
        return current_user_can(\VeciAhorra\Modules\ZonalAdmin\Identity\ZonalAdminRole::CAPABILITY_READ)
            ? false
            : $prevent;
    }

    /** @param list<string> $classes @return list<string> */
    public function bodyClass(array $classes): array
    {
        if ($this->isRegistrationPage()) {
            $classes[] = 'va-customer-registration-page';
        }
        $page = get_queried_object();
        if ($page instanceof \WP_Post && has_shortcode($page->post_content, 'veciahorra_customer_panel')) {
            $classes[] = 'va-customer-purchases-page';
        }
        return $classes;
    }

    public function enqueueRegistrationStyle(): void
    {
        if (! $this->isRegistrationPage()) {
            return;
        }
        wp_enqueue_style(
            self::REGISTRATION_STYLE,
            VA_PLUGIN_URL . 'assets/frontend/css/customer-registration.css',
            [],
            \VeciAhorra\Core\Config::PLUGIN_VERSION
        );
    }

    public function destinationFor(\WP_User $user): string
    {
        if (user_can($user, 'manage_options')) return admin_url('admin.php?page=veciahorra');
        if (user_can($user, \VeciAhorra\Modules\ZonalAdmin\Identity\ZonalAdminRole::CAPABILITY_READ)) return admin_url('admin.php?page=veciahorra-zonal-stores');
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

    public function registrationUrl(?string $redirectTo = null): string
    {
        $id = $this->registrationPageId();
        $url = $id > 0 ? (string) get_permalink($id) : home_url('/' . self::PAGE_SLUG . '/');
        if ($redirectTo === null) {
            return $url;
        }

        $redirectTo = wp_validate_redirect(esc_url_raw($redirectTo), '');
        return $redirectTo !== '' ? add_query_arg('redirect_to', $redirectTo, $url) : $url;
    }

    /** @return list<array{label:string,url:string}> */
    private function headerNavigationLinks(string $catalogUrl): array
    {
        $locations = get_nav_menu_locations();
        $menuId = (int) ($locations['menu_1'] ?? $locations['primary'] ?? 0);
        $items = $menuId > 0 ? wp_get_nav_menu_items($menuId) : [];
        $items = is_array($items)
            ? $this->filterRoleMenuItems($items, (object) ['theme_location'=>'menu_1', 'va_header_primary'=>true])
            : [];
        $urlFor = static function (array $labels, string $fallback) use ($items): string {
            foreach ($items as $item) {
                $title = strtolower(remove_accents(trim(wp_strip_all_tags((string) ($item->title ?? '')))));
                if (in_array($title, $labels, true) && ! empty($item->url)) return (string) $item->url;
            }
            return $fallback;
        };

        $services = $this->pageUrlByShortcode('veciahorra_services', '/servicios/');
        return [
            ['label'=>'Ofertas', 'url'=>$urlFor(['ofertas'], $catalogUrl)],
            ['label'=>'VeciMarket', 'url'=>$urlFor(['catalogo','vecimarket'], $catalogUrl)],
            ['label'=>'VeciServicios', 'url'=>$urlFor(['servicios','veciservicios'], $services)],
            ['label'=>'Quiénes somos', 'url'=>$urlFor(['nosotros','quienes somos'], home_url('/nosotros/'))],
        ];
    }

    private function isRegistrationPage(): bool
    {
        $page = get_queried_object();
        return $page instanceof \WP_Post
            && $page->post_type === 'page'
            && has_shortcode($page->post_content, self::SHORTCODE);
    }

    private function requestedRedirectUrl(): string
    {
        $value = $_POST['redirect_to'] ?? $_GET['redirect_to'] ?? null;
        if (! is_string($value)) {
            return $this->customerPanelUrl();
        }

        $url = esc_url_raw(wp_unslash($value));
        return $url !== '' ? wp_validate_redirect($url, $this->customerPanelUrl()) : $this->customerPanelUrl();
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

    private function isRestrictedZonalAdmin(): bool
    {
        return current_user_can(\VeciAhorra\Modules\ZonalAdmin\Identity\ZonalAdminRole::CAPABILITY_READ)
            && ! current_user_can('manage_options');
    }
}
