<?php

namespace App\Modules\Profile\Models;

use App\Models\User;
use Database\Factories\TargetWeightEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['target_weight_kg', 'recorded_at'])]
#[UseFactory(TargetWeightEntryFactory::class)]
class TargetWeightEntry extends Model
{
    /** @use HasFactory<TargetWeightEntryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_weight_kg' => 'decimal:2',
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
