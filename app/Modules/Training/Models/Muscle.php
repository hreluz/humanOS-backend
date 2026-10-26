<?php

namespace App\Modules\Training\Models;

use App\Modules\Training\Enums\BodyView;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['muscle_area_id', 'key', 'label', 'body_view', 'sort_order'])]
class Muscle extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'body_view' => BodyView::class,
        ];
    }

    /**
     * The area the muscle belongs to.
     *
     * @return BelongsTo<MuscleArea, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(MuscleArea::class, 'muscle_area_id');
    }
}
