<?php

namespace App\Picnic;

class PicnicImage
{
    public const SIZES = ['tiny', 'small', 'medium', 'large', 'extra-large'];

    /**
     * Build a public static image URL for a Picnic image id.
     * Images do not require authentication.
     */
    public static function url(
        string $countryCode,
        string $imageId,
        string $size = 'medium',
        ?string $namespace = null,
    ): string {
        if (!in_array($size, self::SIZES, true)) {
            $size = 'medium';
        }

        $path = $imageId;
        if ($namespace !== null && $namespace !== '' && !str_contains($imageId, '/')) {
            $path = $namespace . '/' . $imageId;
        }

        return sprintf(
            'https://storefront-prod.%s.picnicinternational.com/static/images/%s/%s.png',
            strtolower($countryCode),
            $path,
            $size,
        );
    }

    public static function staticBaseUrl(string $countryCode): string
    {
        return sprintf(
            'https://storefront-prod.%s.picnicinternational.com/static/images',
            strtolower($countryCode),
        );
    }
}
