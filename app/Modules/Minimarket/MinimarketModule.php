<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket;

use VeciAhorra\Core\Config;
use VeciAhorra\Modules\Minimarket\Identity\MinimarketRole;
use VeciAhorra\Modules\Minimarket\Onboarding\Application\StartStoreOnboarding;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\ConfiguredCurrentOnboardingTerms;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\HmacRateLimitKeyDeriver;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\MariaDbNamedRateLimitLockManager;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\OnboardingLegalAuthorityValidator;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\OnboardingLegalLinkProvider;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicOnboardingAssets;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicOnboardingController;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicOnboardingErrorTranslator;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicOnboardingHandler;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicOnboardingPageState;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicOnboardingRenderer;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicOnboardingRequestFactory;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\PublicRequestGuard;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\RandomIdempotencyKeyIssuer;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\RateLimitIdentityFactory;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\RemoteAddressResolver;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\TransientPublicOnboardingRateLimiter;
use VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake\WordPressTransientRateLimitBucketStore;
use VeciAhorra\Modules\Minimarket\Onboarding\StoreOnboardingApplicationRepository;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\ChileanRutNormalizer;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\OnboardingEmailNormalizer;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\RandomOnboardingPublicIdGenerator;
use VeciAhorra\Modules\Minimarket\Onboarding\Support\SystemOnboardingClock;
use VeciAhorra\Modules\Minimarket\Routes\MinimarketRoutes;

final class MinimarketModule
{
    public const SHORTCODE = 'veciahorra_minimarket_panel';
    public const ONBOARDING_SHORTCODE = 'veciahorra_minimarket_onboarding';

    public function register(): void
    {
        (new MinimarketRole())->register();
        $routes = new MinimarketRoutes();
        add_action('rest_api_init', [$routes, 'register']);
        add_shortcode(self::SHORTCODE, [$this, 'render']);
        $this->registerPublicOnboarding();
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

    private function registerPublicOnboarding(): void
    {
        add_action('init', [$this, 'bootPublicOnboarding'], 25);
    }

    public function bootPublicOnboarding(): void
    {
        global $wpdb;
        if (! $wpdb instanceof \wpdb) return;
        $state = new PublicOnboardingPageState();
        $legalValidator = new OnboardingLegalAuthorityValidator();
        $emails = new OnboardingEmailNormalizer();
        $ruts = new ChileanRutNormalizer();
        $keys = new HmacRateLimitKeyDeriver();
        $locks = new MariaDbNamedRateLimitLockManager($wpdb);
        $bucketStore = new WordPressTransientRateLimitBucketStore($wpdb, $locks);
        $limiter = new TransientPublicOnboardingRateLimiter($keys, $bucketStore);
        $start = new StartStoreOnboarding(
            new StoreOnboardingApplicationRepository(),
            new SystemOnboardingClock(),
            new RandomOnboardingPublicIdGenerator(),
            new ConfiguredCurrentOnboardingTerms($legalValidator),
            $emails,
            $ruts
        );
        $errorTranslator = new PublicOnboardingErrorTranslator();
        $handler = new PublicOnboardingHandler(
            $start,
            new RateLimitIdentityFactory($emails, $ruts, $keys),
            $limiter,
            $errorTranslator
        );
        $controller = new PublicOnboardingController(
            new PublicRequestGuard(),
            new PublicOnboardingRequestFactory(),
            $handler,
            new RemoteAddressResolver(),
            $errorTranslator,
            $state
        );
        $renderer = new PublicOnboardingRenderer(
            new RandomIdempotencyKeyIssuer(),
            new OnboardingLegalLinkProvider($legalValidator),
            $state,
            new PublicOnboardingAssets()
        );
        add_action('template_redirect', [$controller, 'handle'], 1);
        add_shortcode(self::ONBOARDING_SHORTCODE, [$renderer, 'render']);
        add_filter('body_class', static function (array $classes): array {
            $page = get_queried_object();
            if ($page instanceof \WP_Post && has_shortcode($page->post_content, self::ONBOARDING_SHORTCODE)) $classes[] = 'va-onboarding-page';
            return $classes;
        });
    }
}
