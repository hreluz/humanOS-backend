<?php

namespace App\Modules\Training\Http\Resources;

use App\Modules\Training\Models\ExercisePhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExercisePhoto
 */
class ExercisePhotoResource extends JsonResource
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
             * Full URL of the photo.
             *
             * @example https://raw.githubusercontent.com/yuhonas/free-exercise-db/f00c92c7dcf1216a928a52c3706c7ce8e2f71ed5/exercises/Barbell_Bench_Press_-_Medium_Grip/0.jpg
             */
            'url' => $this->url,
            /** @example Start */
            'caption' => $this->caption,
            /**
             * Where the photo comes from.
             *
             * @example free_exercise_db
             */
            'source' => $this->source,
            /** @example Unlicense */
            'license' => $this->license,
            /**
             * Photo author, only set for wger photos. Clients must show it when set.
             *
             * @example null
             */
            'author' => $this->author,
            /**
             * Link to the photo's source, only set for wger photos. Clients must show it when set.
             *
             * @example null
             */
            'credit_url' => $this->credit_url,
        ];
    }
}
