<?php

namespace App\Modules\Nutrition\Models;

use App\Modules\Nutrition\Enums\IngredientPhotoSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo of an ingredient, in display order (SPEC 17). Position 1 is the main photo.
 *
 * A photo belongs to whoever owns its ingredient. System ingredients only have `commons` photos, which
 * `IngredientSeeder` rebuilds on every run.
 */
#[Fillable(['ingredient_id', 'source', 'path', 'license', 'license_url', 'author', 'position'])]
class IngredientPhoto extends Model
{
    /**
     * The widths Wikimedia serves thumbnails at without rate-limiting.
     *
     * @see https://www.mediawiki.org/wiki/Common_thumbnail_sizes
     *
     * @var list<int>
     */
    public const STANDARD_WIDTHS = [20, 40, 60, 120, 250, 330, 500, 960, 1280, 1920, 3840];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => IngredientPhotoSource::class,
            'position' => 'integer',
        ];
    }

    /**
     * The one spelling of a Commons file name: no `File:` prefix, underscores instead of spaces, no repeated
     * underscores and an uppercase first character. Commons treats every other spelling as the same file.
     */
    public static function canonicalFile(string $file): string
    {
        $file = trim($file);
        if (str_starts_with($file, 'File:')) {
            $file = trim(substr($file, strlen('File:')));
        }

        $file = (string) preg_replace('/_+/', '_', str_replace(' ', '_', $file));

        return mb_strtoupper(mb_substr($file, 0, 1)).mb_substr($file, 1);
    }

    /**
     * The ingredient the photo belongs to.
     *
     * @return BelongsTo<Ingredient, $this>
     */
    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /**
     * The photo at the detail-view width.
     *
     * @return Attribute<string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->source) {
            IngredientPhotoSource::Commons => $this->commonsUrl((int) config('services.ingredient_photos.width')),
        });
    }

    /**
     * The photo at the list and card width.
     *
     * @return Attribute<string, never>
     */
    protected function thumbnailUrl(): Attribute
    {
        return Attribute::get(fn (): string => match ($this->source) {
            IngredientPhotoSource::Commons => $this->commonsUrl((int) config('services.ingredient_photos.thumbnail_width')),
        });
    }

    /**
     * The page that credits the photo: its Commons file page.
     *
     * @return Attribute<string|null, never>
     */
    protected function creditUrl(): Attribute
    {
        return Attribute::get(fn (): ?string => match ($this->source) {
            IngredientPhotoSource::Commons => 'https://commons.wikimedia.org/wiki/File:'.rawurlencode($this->path),
        });
    }

    private function commonsUrl(int $width): string
    {
        return rtrim((string) config('services.ingredient_photos.base_url'), '/').'/'.rawurlencode($this->path).'?width='.$width;
    }
}
