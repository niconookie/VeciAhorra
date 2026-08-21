<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Minimarket\Onboarding\PublicIntake;

use VeciAhorra\Core\Config;

final class PublicOnboardingAssets
{
    public function enqueue(): void
    {
        wp_enqueue_style('veciahorra-minimarket-onboarding', VA_PLUGIN_URL . 'assets/frontend/css/minimarket-onboarding.css', [], Config::PLUGIN_VERSION);
        wp_enqueue_script('veciahorra-minimarket-onboarding', VA_PLUGIN_URL . 'assets/frontend/js/minimarket-onboarding.js', [], Config::PLUGIN_VERSION, true);
    }
}
