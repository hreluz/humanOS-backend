<?php

namespace App\Modules\Profile\Models;

use App\Models\User;
use Database\Factories\WeightEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['weight_kg', 'recorded_at'])]
#[UseFactory(WeightEntryFactory::class)]
class WeightEntry extends Model
{
    /** @use HasFactory<WeightEntryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weight_kg' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * The user the entry belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
