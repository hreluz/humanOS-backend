<?php

namespace App\Modules\Training\Models;

use App\Modules\Training\Enums\PhotoSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['exercise_id', 'source', 'path', 'caption', 'position', 'license', 'author', 'credit_url'])]
class ExercisePhoto extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => PhotoSource::class,
        ];
    }

    /**
     * The exercise the photo belongs to.
     *
     * @return BelongsTo<Exercise, $this>
     */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * The photo's full URL: the source's configured base followed by the stored path.
     *
     * @return Attribute<string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn (): string => rtrim((string) config("services.exercise_photos.{$this->source->value}"), '/').'/'.$this->path);
    }
}
