<?php

namespace App\Modules\Profile\Http\Requests;

use App\Modules\Profile\Enums\ActivityLevel;
use App\Modules\Profile\Enums\Sex;
use App\Modules\Profile\Enums\UnitSystem;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public const MIN_AGE = 13;

    public const MAX_AGE = 120;

    public const MIN_HEIGHT_CM = 50;

    public const MAX_HEIGHT_CM = 272;

    public const MIN_WEIGHT_KG = 20;

    public const MAX_WEIGHT_KG = 500;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $units = $this->unitSystem();

        return [
            /**
             * Units of `height`, `weight` and `target_weight`: cm/kg (metric) or inches/lb
             * (imperial). Also saved as the user's display preference.
             *
             * @example imperial
             */
            'unit_system' => ['required', Rule::enum(UnitSystem::class)],
            /**
             * The user must be 13 to 120 years old today.
             *
             * @example 1990-04-15
             */
            'birth_date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:'.today()->subYears(self::MIN_AGE)->toDateString(),
                'after:'.today()->subYears(self::MAX_AGE + 1)->toDateString(),
            ],
            /** @example female */
            'sex' => ['required', Rule::enum(Sex::class)],
            /**
             * Height in centimetres (50–272) or total inches (19.7–107.1).
             *
             * @example 65
             */
            'height' => [
                'required',
                'numeric',
                $this->between($units->fromCm(self::MIN_HEIGHT_CM), $units->fromCm(self::MAX_HEIGHT_CM)),
            ],
            /**
             * Current weight in kilograms (20–500) or pounds (44.1–1102.3), at most 1 decimal.
             *
             * @example 154.3
             */
            'weight' => [
                'required',
                'numeric',
                'decimal:0,1',
                $this->between($units->fromKg(self::MIN_WEIGHT_KG), $units->fromKg(self::MAX_WEIGHT_KG)),
            ],
            /**
             * Target weight in kilograms (20–500) or pounds (44.1–1102.3), at most 1 decimal.
             *
             * @example 140
             */
            'target_weight' => [
                'required',
                'numeric',
                'decimal:0,1',
                $this->between($units->fromKg(self::MIN_WEIGHT_KG), $units->fromKg(self::MAX_WEIGHT_KG)),
            ],
            /** @example moderately_active */
            'activity_level' => ['required', Rule::enum(ActivityLevel::class)],
        ];
    }

    /**
     * Same check and message as the `between` rule. A closure, because Scramble would publish a
     * `between` rule's bounds as the schema's minimum/maximum, and those depend on `unit_system`:
     * the document would show only the metric range and reject valid imperial values. The ranges
     * are stated in each field's description instead.
     */
    private function between(float $min, float $max): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($min, $max): void {
            if (is_numeric($value) && ($value < $min || $value > $max)) {
                $fail('validation.between.numeric')->translate(['min' => $min, 'max' => $max]);
            }
        };
    }

    /**
     * The unit system the body is written in. Falls back to metric while the
     * value is missing or invalid, so the bounds can still be built.
     */
    public function unitSystem(): UnitSystem
    {
        return UnitSystem::tryFrom((string) $this->input('unit_system')) ?? UnitSystem::Metric;
    }

    /**
     * The validated height in centimetres.
     */
    public function heightCm(): float
    {
        return $this->unitSystem()->toCm((float) $this->validated('height'));
    }

    /**
     * The validated weight in kilograms.
     */
    public function weightKg(): float
    {
        return $this->unitSystem()->toKg((float) $this->validated('weight'));
    }

    /**
     * The validated target weight in kilograms.
     */
    public function targetWeightKg(): float
    {
        return $this->unitSystem()->toKg((float) $this->validated('target_weight'));
    }
}
