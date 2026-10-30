<?php

namespace App\Modules\Training\Models;

use App\Modules\Training\Enums\ExerciseCategory;
use App\Modules\Training\Enums\ExerciseForce;
use App\Modules\Training\Enums\ExerciseLevel;
use App\Modules\Training\Enums\ExerciseMechanic;
use App\Modules\Training\Enums\MuscleRole;
use App\Modules\Training\Http\Requests\ListExercisesRequest;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
     * The list query: the validated filters, ranked by muscle when one is given, then sorted by name and key.
     *
     * @param  Builder<Exercise>  $query
     * @return Builder<Exercise>
     */
    public function scopeFilter(Builder $query, ListExercisesRequest $filters): Builder
    {
        return $query
            ->when($filters->validated('equipment_group'), fn (Builder $query, array $groups) => $query
                ->whereHas('equipment.group', fn (Builder $query) => $query->whereIn('key', $groups)))
            ->when($filters->validated('equipment'), fn (Builder $query, array $equipment) => $query
                ->whereHas('equipment', fn (Builder $query) => $query->whereIn('key', $equipment)))
            ->when($filters->validated('category'), fn (Builder $query, array $categories) => $query
                ->whereIn('category', $categories))
            ->when($filters->validated('level'), fn (Builder $query, array $levels) => $query
                ->whereIn('level', $levels))
            ->when($filters->validated('search'), fn (Builder $query, string $search) => $query
                ->whereLike('name', '%'.addcslashes($search, '\\%_').'%'))
            ->when($filters->validated('muscle'), fn (Builder $query, string $muscle) => $query
                ->rankedByMuscle($muscle, $filters->validated('role')))
            ->orderBy('name')
            ->orderBy('key');
    }

    /**
     * Only exercises tagged with the muscle: primaries first (by the muscle's position, then fewest primaries), then secondaries.
     *
     * @param  Builder<Exercise>  $query
     * @return Builder<Exercise>
     */
    public function scopeRankedByMuscle(Builder $query, string $muscle, ?string $role = null): Builder
    {
        $primary = MuscleRole::Primary->value;

        return $query
            ->select('exercises.*')
            ->join('exercise_muscle as ranked', 'ranked.exercise_id', '=', 'exercises.id')
            ->where('ranked.muscle_id', Muscle::where('key', $muscle)->select('id'))
            ->when($role, fn (Builder $query, string $role) => $query->where('ranked.role', $role))
            ->withCount('primaryMuscles')
            // Position and count only rank primaries: secondaries go straight to name and key.
            ->orderBy('ranked.role')
            ->orderByRaw('CASE WHEN ranked.role = ? THEN ranked.sort_order END', [$primary])
            ->orderByRaw('CASE WHEN ranked.role = ? THEN primary_muscles_count END', [$primary]);
    }

    /**
     * @return BelongsToMany<Muscle, $this>
     */
    private function musclesWithRole(MuscleRole $role): BelongsToMany
    {
        return $this->muscles()->wherePivot('role', $role->value)->orderByPivot('sort_order');
    }
}
