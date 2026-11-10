<?php

namespace App\Modules\Training\Models;

use App\Models\User;
use Database\Factories\WorkoutLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A named place where a user trains, with the exercises they can do there (SPEC 19).
 */
#[Fillable(['user_id', 'key', 'name'])]
#[UseFactory(WorkoutLocationFactory::class)]
class WorkoutLocation extends Model
{
    /** @use HasFactory<WorkoutLocationFactory> */
    use HasFactory;

    /**
     * The most locations a user may have.
     */
    public const MAX_PER_USER = 20;

    /**
     * The user who owns the location.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The exercises the user can do at the location, sorted by name, then key.
     *
     * @return BelongsToMany<Exercise, $this>
     */
    public function exercises(): BelongsToMany
    {
        return $this->belongsToMany(Exercise::class, 'workout_location_exercise')
            ->orderBy('exercises.name')
            ->orderBy('exercises.key');
    }

    /**
     * Only the user's own locations.
     *
     * @param  Builder<WorkoutLocation>  $query
     * @return Builder<WorkoutLocation>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->getKey());
    }

    /**
     * The ids of the exercises saved at the user's location with this key, read in one query.
     *
     * @return list<int>
     */
    public static function exerciseIds(string $key, User $user): array
    {
        return DB::table('workout_location_exercise')
            ->whereIn('workout_location_id', self::ownedBy($user)->where('key', $key)->select('id'))
            ->pluck('exercise_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * A new location key: `l_` plus a ULID.
     */
    public static function generateKey(): string
    {
        return 'l_'.Str::ulid();
    }
}
