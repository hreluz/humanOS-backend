<?php

namespace App\Modules\Nutrition\Support;

/**
 * The licenses an ingredient photo may have (SPEC 17, "Allowed licenses"), in one place so the seeder and the
 * catalog test can't disagree.
 */
class IngredientPhotoLicense
{
    /**
     * `CC0` (any version), `Public domain`, or `CC BY` / `CC BY-SA` with a version and an optional country port.
     */
    private const ALLOWED = '/^(CC0( \d\.\d)?|Public domain|CC BY(-SA)? \d\.\d( [a-z]{2,})?)$/';

    /**
     * Licenses that don't require crediting the author.
     */
    private const NO_ATTRIBUTION = '/^(CC0( \d\.\d)?|Public domain)$/';

    /**
     * Whether a Commons `LicenseShortName` is allowed. Non-commercial, no-derivatives and GFDL-only are not.
     */
    public static function isAllowed(string $license): bool
    {
        return preg_match(self::ALLOWED, $license) === 1;
    }

    /**
     * Whether the license requires an author and a license URL: `false` for `CC0` and `Public domain`.
     */
    public static function requiresAttribution(string $license): bool
    {
        return preg_match(self::NO_ATTRIBUTION, $license) !== 1;
    }
}
