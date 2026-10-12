<?php

namespace App\Modules\Profile\Enums;

enum UnitSystem: string
{
    case Metric = 'metric';
    case Imperial = 'imperial';

    private const CM_PER_INCH = 2.54;

    private const KG_PER_POUND = 0.45359237;

    /**
     * Convert a height in this unit (cm or inches) to centimetres, rounded to 1 decimal.
     */
    public function toCm(float $height): float
    {
        return round($this === self::Imperial ? $height * self::CM_PER_INCH : $height, 1);
    }

    /**
     * Convert a weight in this unit (kg or lb) to kilograms, rounded to 2 decimals.
     *
     * Two decimals keep the error under 0.011 lb, so any input with 1 decimal comes back
     * unchanged through fromKg(). With 1 decimal, 180 lb would be stored as 81.6 kg and read
     * back as 179.9 lb.
     */
    public function toKg(float $weight): float
    {
        return round($this === self::Imperial ? $weight * self::KG_PER_POUND : $weight, 2);
    }

    /**
     * Convert centimetres to this unit (cm or inches), rounded to 1 decimal.
     */
    public function fromCm(float $cm): float
    {
        return round($this === self::Imperial ? $cm / self::CM_PER_INCH : $cm, 1);
    }

    /**
     * Convert kilograms to this unit (kg or lb), rounded to 1 decimal.
     */
    public function fromKg(float $kg): float
    {
        return round($this === self::Imperial ? $kg / self::KG_PER_POUND : $kg, 1);
    }
}
