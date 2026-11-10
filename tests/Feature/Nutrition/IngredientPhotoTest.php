<?php

namespace Tests\Feature\Nutrition;

use App\Modules\Nutrition\Enums\IngredientPhotoSource;
use App\Modules\Nutrition\Models\IngredientPhoto;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IngredientPhotoTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function fileNames(): array
    {
        return [
            'already canonical' => ['Avocado_Hass.jpg', 'Avocado_Hass.jpg'],
            'spaces' => ['Avocado Hass - halved.jpg', 'Avocado_Hass_-_halved.jpg'],
            'repeated underscores' => ['Avocado__Hass___halved.jpg', 'Avocado_Hass_halved.jpg'],
            'spaces next to underscores' => ['Avocado _ Hass.jpg', 'Avocado_Hass.jpg'],
            'File: prefix' => ['File:Avocado Hass.jpg', 'Avocado_Hass.jpg'],
            'surrounding whitespace' => ['  File: Avocado Hass.jpg ', 'Avocado_Hass.jpg'],
            'lowercase first letter' => ['avocado_Hass.jpg', 'Avocado_Hass.jpg'],
            'accented first letter' => ['école.jpg', 'École.jpg'],
        ];
    }

    #[DataProvider('fileNames')]
    public function test_canonical_file_gives_one_spelling(string $file, string $canonical): void
    {
        $this->assertSame($canonical, IngredientPhoto::canonicalFile($file));
    }

    public function test_the_urls_encode_spaces_commas_and_accents(): void
    {
        $photo = $this->photo('Café au lait, cup.jpg');

        $this->assertSame('https://commons.wikimedia.org/wiki/Special:FilePath/Caf%C3%A9%20au%20lait%2C%20cup.jpg?width=960', $photo->url);
        $this->assertSame('https://commons.wikimedia.org/wiki/Special:FilePath/Caf%C3%A9%20au%20lait%2C%20cup.jpg?width=330', $photo->thumbnail_url);
        $this->assertSame('https://commons.wikimedia.org/wiki/File:Caf%C3%A9%20au%20lait%2C%20cup.jpg', $photo->credit_url);
    }

    public function test_the_urls_follow_the_config(): void
    {
        config([
            'services.ingredient_photos.base_url' => 'https://images.example.test/files/',
            'services.ingredient_photos.width' => 1280,
            'services.ingredient_photos.thumbnail_width' => 250,
        ]);

        $photo = $this->photo('Avocado_Hass.jpg');

        $this->assertSame('https://images.example.test/files/Avocado_Hass.jpg?width=1280', $photo->url);
        $this->assertSame('https://images.example.test/files/Avocado_Hass.jpg?width=250', $photo->thumbnail_url);
    }

    public function test_the_configured_widths_are_wikimedia_standard_widths(): void
    {
        $this->assertContains(config('services.ingredient_photos.width'), IngredientPhoto::STANDARD_WIDTHS);
        $this->assertContains(config('services.ingredient_photos.thumbnail_width'), IngredientPhoto::STANDARD_WIDTHS);
    }

    private function photo(string $path): IngredientPhoto
    {
        return new IngredientPhoto(['source' => IngredientPhotoSource::Commons, 'path' => $path, 'license' => 'CC0 1.0', 'position' => 1]);
    }
}
