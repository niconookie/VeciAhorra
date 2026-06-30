<?php

declare(strict_types=1);

namespace VeciAhorra\Modules\Products\Models;

use VeciAhorra\Database\Model;

/**
 * Modelo de Producto.
 *
 * @property int|null $id
 * @property int|null $woo_product_id
 * @property string|null $name
 * @property string|null $slug
 * @property string|null $sku
 * @property string|null $description
 * @property int|null $category_id
 * @property int|null $brand_id
 * @property int|null $unit_id
 * @property int|null $image_id
 * @property string|null $status
 * @property string|null $created_at
 * @property string|null $updated_at
 */
final class Product extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /**
     * Estados permitidos para un producto.
     *
     * @return string[]
     */
    public static function allowedStatuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_ACTIVE,
            self::STATUS_INACTIVE,
        ];
    }
}
