<?php

namespace App\Modules\Nutrition\Http\Resources;

use App\Modules\Nutrition\Models\IngredientPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IngredientPhoto
 */
class IngredientPhotoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * The photo at the detail-view width. Use it as given; never build photo URLs.
             *
             * @example https://commons.wikimedia.org/wiki/Special:FilePath/Example_avocado_whole.jpg?width=960
             */
            'url' => $this->url,
            /**
             * The photo at the list and card width. Use it as given; never build photo URLs.
             *
             * @example https://commons.wikimedia.org/wiki/Special:FilePath/Example_avocado_whole.jpg?width=330
             */
            'thumbnail_url' => $this->thumbnail_url,
            /**
             * License short name. Clients must show it wherever they show the photo, linked to `license_url` when set.
             *
             * @example CC BY-SA 4.0
             */
            'license' => $this->license,
            /**
             * Link to the license. `null` only for some `CC0` and `Public domain` photos.
             *
             * @example https://creativecommons.org/licenses/by-sa/4.0
             */
            'license_url' => $this->license_url,
            /**
             * Credit for the photo. Clients must show it when set. `null` for `CC0` and `Public domain` photos.
             *
             * @example Example Author
             */
            'author' => $this->author,
            /**
             * The photo's source page. Clients must link to it wherever they show the photo.
             *
             * @var string|null
             *
             * @example https://commons.wikimedia.org/wiki/File:Example_avocado_whole.jpg
             */
            'credit_url' => $this->credit_url,
        ];
    }
}
