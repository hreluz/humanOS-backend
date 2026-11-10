<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Training\Models\WorkoutLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkoutLocation>
 */
class WorkoutLocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => WorkoutLocation::generateKey(),
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
