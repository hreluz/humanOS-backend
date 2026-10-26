<?php

namespace App\Modules\Training\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'label', 'sort_order'])]
class EquipmentGroup extends Model
{
    /**
     * The equipment in the group, in display order.
     *
     * @return HasMany<Equipment, $this>
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class)->orderBy('sort_order');
    }
}
