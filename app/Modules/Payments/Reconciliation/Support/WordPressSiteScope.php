<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Payments\Reconciliation\Support;

use InvalidArgumentException;

final class WordPressSiteScope
{
    public const PREFIX = 'wp-blog:';

    public static function fromBlogId(int $blogId): string
    {
        if ($blogId <= 0) {
            throw new InvalidArgumentException('blog_id no es valido.');
        }

        return self::PREFIX . $blogId;
    }

    public static function current(): string
    {
        return self::fromBlogId((int) get_current_blog_id());
    }

    public static function isValid(string $scope): bool
    {
        return preg_match('/^wp-blog:[1-9]\d*$/D', $scope) === 1;
    }

    private function __construct()
    {
    }
}
