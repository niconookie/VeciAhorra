<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Frontend\Support;

use VeciAhorra\Core\Session;

/**
 * Bridges the existing PHP session with Cart's public session header.
 */
final class CartSession
{
    private const KEY = 'veciahorra_cart_session';

    public function prepareForRequest(): void
    {
        if (is_admin() || ! is_singular()) {
            return;
        }

        $post = get_post();

        if (
            ! $post instanceof \WP_Post
            || ! has_shortcode($post->post_content, 'veciahorra_frontend')
        ) {
            return;
        }

        $this->identifier();
    }

    public function identifier(): string
    {
        $identifier = Session::get(self::KEY);

        if (is_string($identifier) && preg_match('/^[a-f0-9]{64}$/', $identifier) === 1) {
            return $identifier;
        }

        $identifier = bin2hex(random_bytes(32));
        Session::put(self::KEY, $identifier);

        return $identifier;
    }
}
