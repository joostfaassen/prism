<?php

namespace App\Tracking;

final class GeoUtils
{
    private const EARTH_RADIUS_M = 6371000.0;

    public static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $phi1 = deg2rad($lat1);
        $phi2 = deg2rad($lat2);
        $dPhi = deg2rad($lat2 - $lat1);
        $dLambda = deg2rad($lon2 - $lon1);

        $a = sin($dPhi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dLambda / 2) ** 2;

        return 2 * self::EARTH_RADIUS_M * atan2(sqrt($a), sqrt(1 - $a));
    }

    public static function isInsideRadius(
        float $pointLat,
        float $pointLon,
        float $zoneLat,
        float $zoneLon,
        int $radiusMeters,
    ): bool {
        return self::haversineMeters($pointLat, $pointLon, $zoneLat, $zoneLon) <= (float) $radiusMeters;
    }
}
