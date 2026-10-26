<?php

namespace App\Modules\Training\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'label', 'sort_order'])]
class MuscleArea extends Model
{
    /**
     * The muscles in the area, in display order.
     *
     * @return HasMany<Muscle, $this>
     */
    public function muscles(): HasMany
    {
        return $this->hasMany(Muscle::class)->orderBy('sort_order');
    }
}
