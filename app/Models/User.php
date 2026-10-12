<?php

namespace App\Models;

use App\Modules\Auth\Enums\UserRole;
use App\Modules\Profile\Models\Profile;
use App\Modules\Profile\Models\TargetWeightEntry;
use App\Modules\Profile\Models\WeightEntry;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'user',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    /**
     * Determine whether the user has the admin role.
     */
    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /**
     * The user's body profile.
     *
     * @return HasOne<Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * Every weight the user has recorded.
     *
     * @return HasMany<WeightEntry, $this>
     */
    public function weightEntries(): HasMany
    {
        return $this->hasMany(WeightEntry::class);
    }

    /**
     * The user's most recent weight entry.
     *
     * @return HasOne<WeightEntry, $this>
     */
    public function latestWeightEntry(): HasOne
    {
        return $this->hasOne(WeightEntry::class)->latestOfMany('recorded_at');
    }

    /**
     * Every target weight the user has set.
     *
     * @return HasMany<TargetWeightEntry, $this>
     */
    public function targetWeightEntries(): HasMany
    {
        return $this->hasMany(TargetWeightEntry::class);
    }

    /**
     * The user's most recent target weight entry.
     *
     * @return HasOne<TargetWeightEntry, $this>
     */
    public function latestTargetWeightEntry(): HasOne
    {
        return $this->hasOne(TargetWeightEntry::class)->latestOfMany('recorded_at');
    }
}
