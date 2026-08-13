<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\ServiceProviders;

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\CustomerAccess\CustomerAccessModule;
use VeciAhorra\Modules\Frontend\Assets\FrontendAssets;
use VeciAhorra\Modules\ServiceProviders\Admin\ServiceProviderAdminPage;
use VeciAhorra\Modules\ServiceProviders\Domain\ServiceCatalog;
use VeciAhorra\Modules\ServiceProviders\Identity\ServiceProviderRole;
use VeciAhorra\Modules\ServiceProviders\Routes\ServiceProviderRoutes;

final class ServiceProviderModule
{
    public const REGISTRATION = 'veciahorra_service_provider_registration';
    public const PANEL = 'veciahorra_service_provider_panel';
    public const SERVICES = 'veciahorra_services';

    public function __construct(private CustomerAccessModule $customerAccess)
    {
    }

    public function register(): void
    {
        add_action('init', [ServiceProviderRole::class, 'register']);
        add_action('rest_api_init', [new ServiceProviderRoutes(), 'register']);
        (new ServiceProviderAdminPage())->register();
        add_shortcode(self::REGISTRATION, [$this, 'landing']);
        add_shortcode(self::PANEL, [$this, 'panel']);
        add_shortcode(self::SERVICES, [$this, 'services']);
    }

    private function assets(): void
    {
        (new FrontendAssets())->enqueue();
        wp_enqueue_style('va-service-providers', VA_PLUGIN_URL . 'assets/frontend/css/service-providers.css', [], Config::PLUGIN_VERSION);
        wp_enqueue_script('va-service-providers', VA_PLUGIN_URL . 'assets/frontend/js/service-providers.js', ['veciahorra-frontend'], Config::PLUGIN_VERSION, true);
    }

    private function logo(): string
    {
        $logoId = (int) get_theme_mod('custom_logo');
        $logo = $logoId > 0 ? wp_get_attachment_image($logoId, 'medium', false, ['class' => 'va-sp-logo', 'alt' => get_bloginfo('name')]) : '';
        return is_string($logo) && $logo !== ''
            ? $logo
            : '<span class="va-sp-wordmark"><b>Veci</b>Ahorra<small>Tu barrio, más cerca de ti</small></span>';
    }

    private function providerForm(bool $wizard): string
    {
        $steps = $wizard ? ' data-va-provider-wizard' : '';
        return '<form id="va-provider-form" class="va-sp-provider-form" data-va-provider-form' . $steps . '>'
            . '<input type="hidden" name="provider_id">'
            . '<section class="va-sp-form-step" data-va-wizard-step="0"><div class="va-sp-form-heading"><span>Paso 1 de 5</span><h3>Elige tu plan</h3><p>Podrás cambiarlo más adelante desde tu panel.</p></div><div class="va-sp-form-plans">'
            . '<label><input type="radio" name="plan" value="local"><span></span><strong>Plan Local</strong><b>$1.000 <small>/ mes</small></b><p>Perfil verificado y aparición en búsquedas de tu comuna.</p><em>Ideal para comenzar</em></label>'
            . '<label><input type="radio" name="plan" value="featured"><span></span><strong>Plan Destacado</strong><b>$2.000 <small>/ mes</small></b><p>Posición preferente, sello destacado y acceso a campañas.</p><em>Mayor exposición</em></label></div></section>'
            . '<section class="va-sp-form-step" data-va-wizard-step="1" hidden><div class="va-sp-form-heading"><span>Paso 2 de 5</span><h3>Crea tu cuenta</h3><p>Estos datos identifican al titular de la solicitud.</p></div><div class="va-sp-fields">'
            . '<label class="full">Nombre completo *<input name="full_name" required placeholder="Ej. José Martínez"></label><label>RUT *<input name="rut" required placeholder="12.345.678-9"></label><label>Correo *<input name="email" type="email" required placeholder="nombre@correo.cl"></label><label>Teléfono *<input name="phone" required placeholder="+56 9 1234 5678"></label></div></section>'
            . '<section class="va-sp-form-step" data-va-wizard-step="2" hidden><div class="va-sp-form-heading"><span>Paso 3 de 5</span><h3>Presenta tu servicio</h3><p>Esta información construye tu ficha pública.</p></div><div class="va-sp-fields">'
            . '<label>Nombre comercial *<input name="business_name" required></label><label>Categoría *<select name="category_key" data-va-category></select></label><label>Subcategoría *<select name="subcategory_key" data-va-subcategory></select></label><label>Comuna *<input name="commune" required></label><label>Años de experiencia<input name="experience_years" type="number" min="0"></label><label>Horario<input name="schedule"></label><label class="full">Descripción<textarea name="description" placeholder="Describe qué haces y por qué un vecino debería elegirte."></textarea></label><label class="full">Cobertura adicional<input name="coverage" placeholder="Separada por comas"></label><label class="full">Especialidades<input name="specialties" placeholder="Máximo 5, separadas por comas"></label></div></section>'
            . '<section class="va-sp-form-step" data-va-wizard-step="3" hidden><div class="va-sp-form-heading"><span>Paso 4 de 5</span><h3>Verifica tu identidad</h3><p>Los datos privados no se muestran en el perfil público.</p></div><div class="va-sp-fields">'
            . '<label>WhatsApp<input name="whatsapp"></label><label>Correo público *<input name="contact_email" type="email" required></label><label>Attachment ID foto<input name="photo_id" type="number" min="0"></label><label class="va-sp-check"><input name="emergency_service" type="checkbox"><span>Atención de urgencias</span></label><label class="va-sp-check full"><input name="terms_accepted" type="checkbox" required><span>Acepto los términos de uso y la verificación de antecedentes declarados. *</span></label></div><p class="va-sp-privacy">Solo solicitamos los datos necesarios para validar y publicar el perfil.</p></section>'
            . '<section class="va-sp-form-step" data-va-wizard-step="4" hidden><div class="va-sp-form-heading"><span>Paso 5 de 5</span><h3>Revisa y confirma</h3><p>Guarda el perfil y envíalo a revisión administrativa.</p></div><div class="va-sp-review" data-va-provider-review></div></section>'
            . '<div class="va-sp-form-actions"><button type="button" class="va-sp-back" data-va-wizard-back>← Volver</button><span data-va-wizard-message>Borrador guardado en este navegador</span><button class="va-sp-button" type="button" data-va-wizard-next>Continuar →</button><button class="va-sp-button" type="submit" data-va-wizard-save hidden>Guardar perfil</button><button class="va-sp-button" type="button" data-va-provider-submit hidden>Enviar a revisión</button></div>'
            . '</form>';
    }

    public function landing(): string
    {
        $this->assets();
        $logo = $this->logo();
        $registrationUrl = $this->customerAccess->registrationUrl($this->servicesUrl());
        return '<main class="va-sp va-sp-landing">'
            . '<section class="va-sp-hero" id="inicio"><div class="va-sp-hero-copy"><p class="va-sp-eyebrow">Prestadores de servicios</p><h1>Tu talento merece ser conocido en tu barrio.</h1><p class="va-sp-lead">Crea tu perfil profesional, aumenta tu visibilidad y conecta con personas que buscan servicios locales.</p><div class="va-sp-actions"><a class="va-sp-button" href="' . esc_url($registrationUrl) . '">Crear mi perfil</a><a class="va-sp-button secondary" href="#como-funciona">Conocer el proceso</a></div><div class="va-sp-trust"><span>✓ Registro general VeciAhorra</span><span>✓ Revisión antes de publicar</span><span>✓ Visibilidad local</span></div></div>'
            . '<div class="va-sp-profile-stage" aria-label="Ejemplo ilustrativo de un perfil profesional"><article class="va-sp-demo-profile"><div class="va-sp-profile-cover"><span class="va-sp-service-icon" aria-hidden="true">🔧</span><span class="va-sp-badge featured">Ejemplo</span></div><div class="va-sp-profile-body"><div class="va-sp-profile-title"><div><p>Servicio profesional local</p><h2>Tu perfil puede estar aquí</h2></div></div><p>Presenta de forma clara tu especialidad, zona de atención y medios de contacto.</p><div class="va-sp-tags"><span>Información ficticia</span><span>Vista ilustrativa</span></div><a class="va-sp-button navy" href="' . esc_url($registrationUrl) . '">Quiero registrarme</a></div></article></div></section>'
            . '<section class="va-sp-section va-sp-how" id="como-funciona"><div class="va-sp-section-heading"><p class="va-sp-eyebrow">Simple y transparente</p><h2>De profesional local a prestador visible en cuatro pasos</h2><p>El proceso está diseñado para ser rápido, confiable y fácil de completar.</p></div><div class="va-sp-steps">'
            . '<article><span>01</span><h3>Elige tu plan</h3><p>Define el nivel de exposición de tu servicio.</p></article><article><span>02</span><h3>Completa tu perfil</h3><p>Cuenta qué haces, dónde atiendes y cómo contactarte.</p></article><article><span>03</span><h3>Verificamos tus datos</h3><p>Revisamos identidad, antecedentes y especialidad.</p></article><article><span>04</span><h3>Publicamos tu servicio</h3><p>Tu perfil queda visible para los vecinos de tu zona.</p></article></div></section>'
            . '<section class="va-sp-section va-sp-plans" id="planes"><div class="va-sp-section-heading left"><p class="va-sp-eyebrow">Planes informativos</p><h2>Elige cuánta visibilidad necesita tu servicio</h2><p>Estos valores son informativos. El registro no realiza cobros ni suscripciones.</p></div><div class="va-sp-plan-grid"><article><h3>Plan Local</h3><strong><small>$</small>1.000 <span>/ mes</span></strong><p>Para comenzar a mostrar tus servicios en tu comuna.</p><ul><li>✓ Perfil público verificado</li><li>✓ Aparición en búsquedas locales</li><li>✓ Datos de contacto y horarios</li><li>✓ Panel de consultas</li></ul><a href="' . esc_url($registrationUrl) . '" class="va-sp-button secondary">Elegir Plan Local</a></article><article class="featured"><span class="va-sp-popular">Mayor exposición</span><h3>Plan Destacado</h3><strong><small>$</small>2.000 <span>/ mes</span></strong><p>Para aparecer antes y participar en acciones comerciales.</p><ul><li>✓ Todo lo incluido en Plan Local</li><li>✓ Posición preferente en resultados</li><li>✓ Sello “Destacado” en el perfil</li><li>✓ Acceso prioritario a campañas</li></ul><a href="' . esc_url($registrationUrl) . '" class="va-sp-button">Elegir Plan Destacado</a></article><article class="communal"><h3>Plan Comunal</h3><strong><small>$</small>3.000 <span>/ mes</span></strong><p>Para ampliar la presencia del servicio en su territorio.</p><ul><li>✓ Todo lo incluido en Plan Local</li><li>✓ Posición preferente en resultados</li><li>✓ Sello “Destacado” en el perfil</li><li>✓ Acceso prioritario a campañas</li><li>✓ Visibilidad en toda la comuna</li></ul><a href="' . esc_url($registrationUrl) . '" class="va-sp-button">Elegir Plan Comunal</a></article></div></section>'
            . '<section class="va-sp-process" id="proceso"><div><p class="va-sp-eyebrow light">Operación VeciAhorra</p><h2>Del registro a la publicación, con control en cada etapa</h2><p>El modelo contempla validación, revisión humana y ciclos de corrección antes de publicar el perfil.</p><div class="va-sp-metrics"><span><strong>48 h</strong>Revisión comunicacional</span><span><strong>5 días</strong>Plazo comunicacional</span><span><strong>1 folio</strong>Trazabilidad por solicitud</span></div></div><div class="va-sp-flow"><article><small>Prestador</small><strong>Registro y antecedentes</strong><span>Selecciona plan y completa perfil</span></article><i>→</i><article><small>Web</small><strong>Validación</strong><span>Controla los datos declarados</span></article><i>→</i><article><small>Administración</small><strong>Revisión</strong><span>Aprueba, observa o rechaza</span></article><i>→</i><article><small>Publicado</small><strong>Perfil activo</strong><span>Aplica exposición según plan</span></article></div></section>'
            . '<footer class="va-sp-footer"><div>' . $logo . '</div><p>Incorporación de prestadores · VeciAhorra</p></footer></main>';
    }

    private function servicesUrl(): string
    {
        $pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1]);
        foreach ($pages as $page) {
            if (has_shortcode($page->post_content, self::SERVICES)) {
                return (string) get_permalink($page);
            }
        }
        return home_url('/servicios/');
    }

    public function panel(): string
    {
        $this->assets();
        if (! is_user_logged_in()) {
            return '<p class="va-sp-access">Debes <a href="' . esc_url(wp_login_url(get_permalink())) . '">iniciar sesión</a>.</p>';
        }
        return '<section class="va-sp va-sp-panel" data-va-provider-panel><header><div><p class="va-sp-eyebrow">Panel prestador</p><h1>Mi perfil de servicios</h1><p>Administra los datos que verán tus vecinos.</p></div><div class="va-sp-panel-summary"><p data-va-provider-status></p><p data-va-provider-observation></p></div></header><div class="va-sp-panel-progress"><span class="active">Perfil</span><i></i><span>Revisión</span><i></i><span>Publicado</span></div><div class="va-sp-panel-card"><section class="va-sp-current-profile" data-va-provider-current-profile hidden aria-live="polite"></section>' . $this->providerForm(false) . '</div></section>';
    }

    public function services(): string
    {
        $this->assets();
        if (! is_user_logged_in()) {
            return $this->landing();
        }
        $categories = wp_json_encode(ServiceCatalog::publicData(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return '<section class="va-sp va-sp-services" data-va-services><header><p class="va-sp-eyebrow">Talento cerca de ti</p><h1>Servicios para tu hogar y tu barrio</h1><p>Encuentra prestadores verificados por categoría y comuna.</p></header><form class="va-sp-service-filters" data-va-services-filter><label>Categoría<select name="category_key"><option value="">Todas las categorías</option></select></label><label>Subcategoría<select name="subcategory_key"><option value="">Todas las subcategorías</option></select></label><label>Comuna<input name="commune" placeholder="Ej. San Miguel"></label><button class="va-sp-button">Buscar servicios</button></form><p class="va-sp-results-status" data-va-services-status aria-live="polite"></p><div class="va-sp-service-grid" data-va-services-list></div><div class="va-sp-public-detail" data-va-service-detail></div><script type="application/json" data-va-service-categories>' . (string) $categories . '</script></section>';
    }
}
