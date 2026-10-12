<?php

namespace App\Modules\Profile\Models;

use App\Models\User;
use App\Modules\Profile\Enums\ActivityLevel;
use App\Modules\Profile\Enums\Sex;
use App\Modules\Profile\Enums\UnitSystem;
use Database\Factories\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['birth_date', 'sex', 'height_cm', 'activity_level', 'unit_system'])]
#[UseFactory(ProfileFactory::class)]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'sex' => Sex::class,
            'height_cm' => 'decimal:1',
            'activity_level' => ActivityLevel::class,
            'unit_system' => UnitSystem::class,
        ];
    }

    /**
     * The user the profile belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The user's age in whole years, computed from the birth date.
     *
     * @return Attribute<int, never>
     */
    protected function age(): Attribute
    {
        return Attribute::get(fn (): int => $this->birth_date->age);
    }
}
