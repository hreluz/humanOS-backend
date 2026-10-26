<?php

namespace App\Modules\Training\Models;

use App\Modules\Training\Enums\ExerciseCategory;
use App\Modules\Training\Enums\ExerciseForce;
use App\Modules\Training\Enums\ExerciseLevel;
use App\Modules\Training\Enums\ExerciseMechanic;
use App\Modules\Training\Enums\MuscleRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name', 'equipment_id', 'category', 'level', 'mechanic', 'force', 'cue', 'instructions', 'source_id'])]
class Exercise extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => ExerciseCategory::class,
            'level' => ExerciseLevel::class,
            'mechanic' => ExerciseMechanic::class,
            'force' => ExerciseForce::class,
            'instructions' => 'array',
        ];
    }

    /**
     * The exact piece of equipment the exercise needs.
     *
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * Every muscle the exercise trains, with its role and order.
     *
     * @return BelongsToMany<Muscle, $this>
     */
    public function muscles(): BelongsToMany
    {
        return $this->belongsToMany(Muscle::class)->withPivot('role', 'sort_order')->withTimestamps();
    }

    /**
     * The muscles the exercise mainly trains, most direct first.
     *
     * @return BelongsToMany<Muscle, $this>
     */
    public function primaryMuscles(): BelongsToMany
    {
        return $this->musclesWithRole(MuscleRole::Primary);
    }

    /**
     * The muscles that assist.
     *
     * @return BelongsToMany<Muscle, $this>
     */
    public function secondaryMuscles(): BelongsToMany
    {
        return $this->musclesWithRole(MuscleRole::Secondary);
    }

    /**
     * The exercise's photos in display order: its own first, then close variants.
     *
     * @return HasMany<ExercisePhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ExercisePhoto::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Muscle, $this>
     */
    private function musclesWithRole(MuscleRole $role): BelongsToMany
    {
        return $this->muscles()->wherePivot('role', $role->value)->orderByPivot('sort_order');
    }
}
