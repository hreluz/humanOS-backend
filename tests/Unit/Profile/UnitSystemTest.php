<?php

namespace Tests\Unit\Profile;

use App\Modules\Profile\Enums\UnitSystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnitSystemTest extends TestCase
{
    public function test_metric_conversions_are_the_identity(): void
    {
        $this->assertSame(180.0, UnitSystem::Metric->toCm(180));
        $this->assertSame(75.5, UnitSystem::Metric->toKg(75.5));
        $this->assertSame(180.0, UnitSystem::Metric->fromCm(180));
        $this->assertSame(75.5, UnitSystem::Metric->fromKg(75.5));
    }

    public function test_metric_conversions_round_to_one_decimal(): void
    {
        $this->assertSame(180.3, UnitSystem::Metric->toCm(180.26));
        $this->assertSame(75.44, UnitSystem::Metric->toKg(75.444));
        $this->assertSame(180.3, UnitSystem::Metric->fromCm(180.26));
        $this->assertSame(75.4, UnitSystem::Metric->fromKg(75.44));
    }

    public function test_imperial_converts_inches_to_cm(): void
    {
        $this->assertSame(165.1, UnitSystem::Imperial->toCm(65));
        $this->assertSame(182.9, UnitSystem::Imperial->toCm(72));
    }

    public function test_imperial_converts_cm_to_inches(): void
    {
        $this->assertSame(65.0, UnitSystem::Imperial->fromCm(165.1));
        $this->assertSame(70.9, UnitSystem::Imperial->fromCm(180));
    }

    public function test_imperial_converts_lb_to_kg(): void
    {
        $this->assertSame(69.99, UnitSystem::Imperial->toKg(154.3));
        $this->assertSame(63.5, UnitSystem::Imperial->toKg(140));
        $this->assertSame(81.65, UnitSystem::Imperial->toKg(180));
    }

    public function test_imperial_converts_kg_to_lb(): void
    {
        $this->assertSame(154.3, UnitSystem::Imperial->fromKg(70.0));
        $this->assertSame(180.0, UnitSystem::Imperial->fromKg(81.65));
        $this->assertSame(220.5, UnitSystem::Imperial->fromKg(100));
    }

    /**
     * @return array<string, array{float}>
     */
    public static function commonImperialHeights(): array
    {
        return [
            '5 ft' => [60.0],
            '5 ft 5 in' => [65.0],
            '5 ft 10 in' => [70.0],
            '6 ft' => [72.0],
            '6 ft 6 in' => [78.0],
        ];
    }

    #[DataProvider('commonImperialHeights')]
    public function test_imperial_height_round_trip_drifts_at_most_a_tenth(float $inches): void
    {
        $roundTrip = UnitSystem::Imperial->fromCm(UnitSystem::Imperial->toCm($inches));

        $this->assertEqualsWithDelta($inches, $roundTrip, 0.1);
    }

    public function test_every_imperial_weight_with_one_decimal_round_trips_exactly(): void
    {
        // 44.1–1102.3 lb in 0.1 steps, built from integers to avoid float accumulation.
        for ($tenths = 441; $tenths <= 11023; $tenths++) {
            $pounds = $tenths / 10;

            $this->assertSame(
                round($pounds, 1),
                UnitSystem::Imperial->fromKg(UnitSystem::Imperial->toKg($pounds)),
                "{$pounds} lb did not round-trip.",
            );
        }
    }

    public function test_every_metric_weight_with_one_decimal_round_trips_exactly(): void
    {
        for ($tenths = 200; $tenths <= 5000; $tenths++) {
            $kilograms = $tenths / 10;

            $this->assertSame(
                round($kilograms, 1),
                UnitSystem::Metric->fromKg(UnitSystem::Metric->toKg($kilograms)),
                "{$kilograms} kg did not round-trip.",
            );
        }
    }
}
