<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Profile\Models\TargetWeightEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TargetWeightEntry>
 */
class TargetWeightEntryFactory extends Factory
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
            'target_weight_kg' => fake()->randomFloat(1, 50, 120),
            'recorded_at' => now(),
        ];
    }
}
