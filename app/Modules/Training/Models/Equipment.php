<?php

namespace App\Modules\Training\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['equipment_group_id', 'key', 'label', 'source_key', 'sort_order'])]
class Equipment extends Model
{
    /**
     * "Equipment" is uncountable, so the table keeps the singular name.
     *
     * @var string
     */
    protected $table = 'equipment';

    /**
     * The group the equipment belongs to.
     *
     * @return BelongsTo<EquipmentGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(EquipmentGroup::class, 'equipment_group_id');
    }

    /**
     * The exercises that use this piece of equipment.
     *
     * @return HasMany<Exercise, $this>
     */
    public function exercises(): HasMany
    {
        return $this->hasMany(Exercise::class);
    }
}
