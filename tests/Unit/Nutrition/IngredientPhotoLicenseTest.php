<?php

namespace Tests\Unit\Nutrition;

use App\Modules\Nutrition\Support\IngredientPhotoLicense;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IngredientPhotoLicenseTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function allowedLicenses(): array
    {
        return [
            'CC0' => ['CC0', false],
            'CC0 1.0' => ['CC0 1.0', false],
            'Public domain' => ['Public domain', false],
            'CC BY 2.0' => ['CC BY 2.0', true],
            'CC BY 4.0' => ['CC BY 4.0', true],
            'CC BY 3.0 de' => ['CC BY 3.0 de', true],
            'CC BY-SA 4.0' => ['CC BY-SA 4.0', true],
            'CC BY-SA 3.0 de' => ['CC BY-SA 3.0 de', true],
            'CC BY-SA 2.5 es' => ['CC BY-SA 2.5 es', true],
        ];
    }

    #[DataProvider('allowedLicenses')]
    public function test_an_allowed_license_is_accepted(string $license, bool $requiresAttribution): void
    {
        $this->assertTrue(IngredientPhotoLicense::isAllowed($license));
        $this->assertSame($requiresAttribution, IngredientPhotoLicense::requiresAttribution($license));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function disallowedLicenses(): array
    {
        return [
            'non-commercial' => ['CC BY-NC 4.0'],
            'non-commercial share-alike' => ['CC BY-NC-SA 4.0'],
            'no derivatives' => ['CC BY-ND 4.0'],
            'GFDL' => ['GFDL'],
            'GFDL 1.2' => ['GFDL 1.2'],
            'no version' => ['CC BY'],
            'no version share-alike' => ['CC BY-SA'],
            'lowercase' => ['cc by-sa 4.0'],
            'uppercase port' => ['CC BY 3.0 DE'],
            'trailing text' => ['CC BY 4.0 or later'],
            'empty' => [''],
        ];
    }

    #[DataProvider('disallowedLicenses')]
    public function test_a_disallowed_license_is_rejected(string $license): void
    {
        $this->assertFalse(IngredientPhotoLicense::isAllowed($license));
    }
}
