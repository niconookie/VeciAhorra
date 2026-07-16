<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend\Support;

use InvalidArgumentException;

/**
 * Renders an explicit allowlist of internal views and components.
 */
final class ViewRenderer
{
    private const VIEWS = [
        'layout' => 'Views/layout.php',
        'page-placeholder' => 'Views/page-placeholder.php',
        'catalog' => 'Views/catalog.php',
        'product-detail' => 'Views/product-detail.php',
        'cart' => 'Views/cart.php',
        'checkout' => 'Views/checkout.php',
        'button' => 'Components/button.php',
        'card' => 'Components/card.php',
        'loader' => 'Components/loader.php',
        'alert' => 'Components/alert.php',
        'empty-state' => 'Components/empty-state.php',
    ];

    /** @param array<string, mixed> $data */
    public function render(string $view, array $data = []): string
    {
        if (! isset(self::VIEWS[$view])) {
            throw new InvalidArgumentException('Unknown frontend view.');
        }

        $path = dirname(__DIR__) . '/' . self::VIEWS[$view];

        if (! is_file($path)) {
            throw new InvalidArgumentException('Frontend view is unavailable.');
        }

        extract($data, EXTR_SKIP);
        ob_start();

        try {
            require $path;

            return (string) ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    }
}
