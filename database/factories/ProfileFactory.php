<?php

namespace Database\Factories;

use App\Models\User;
use App\Modules\Profile\Enums\ActivityLevel;
use App\Modules\Profile\Enums\Sex;
use App\Modules\Profile\Enums\UnitSystem;
use App\Modules\Profile\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
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
            'birth_date' => fake()->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'sex' => fake()->randomElement(Sex::cases()),
            'height_cm' => fake()->randomFloat(1, 150, 200),
            'activity_level' => fake()->randomElement(ActivityLevel::cases()),
            'unit_system' => UnitSystem::Metric,
        ];
    }

    /**
     * Indicate that the profile uses imperial units.
     */
    public function imperial(): static
    {
        return $this->state(fn (array $attributes) => [
            'unit_system' => UnitSystem::Imperial,
        ]);
    }
}
