<?php
declare(strict_types=1);
namespace VeciAhorra\Modules\Cart\Proximity;

use InvalidArgumentException;

final class GeoPoint
{
    /** @return array{latitude:float,longitude:float} */
    public static function validate(mixed $latitude, mixed $longitude): array
    {
        $result = [];
        foreach (['latitude' => [$latitude, 90], 'longitude' => [$longitude, 180]] as $name => [$value, $limit]) {
            if ((!is_int($value) && !is_float($value) && !is_string($value))
                || !is_numeric($value) || !is_finite((float)$value) || abs((float)$value) > $limit) {
                throw new InvalidArgumentException('Las coordenadas del punto no son válidas.');
            }
            $result[$name] = (float)$value;
        }
        return $result;
    }

    /** Geodesic distance in metres, independent of prices and browser estimates. */
    public static function distance(array $a, array $b): float
    {
        $lat = deg2rad($b['latitude'] - $a['latitude']);
        $lon = deg2rad($b['longitude'] - $a['longitude']);
        $h = sin($lat / 2) ** 2 + cos(deg2rad($a['latitude'])) * cos(deg2rad($b['latitude'])) * sin($lon / 2) ** 2;
        return 6371008.8 * 2 * asin(sqrt(min(1.0, max(0.0, $h))));
    }
}
