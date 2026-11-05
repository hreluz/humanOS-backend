<?php

namespace App\Modules\Nutrition\Http\Requests;

use App\Modules\Nutrition\Enums\IngredientCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new private ingredient, typed in from a food label (SPEC 18). Strings are trimmed by the global middleware.
 */
class StoreIngredientRequest extends FormRequest
{
    /**
     * The most portions an ingredient may have.
     */
    public const MAX_PORTIONS = 10;

    /**
     * The most `protein + carbs + fat` may add up to, in grams per 100 g. Fiber isn't in the sum: US labels count
     * it inside carbs and EU labels separately.
     */
    public const MAX_MACRO_SUM = 100;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * 1–100 characters after trimming, in any language. Not unique.
             *
             * @example Protein bar, chocolate
             */
            'name' => ['required', 'string', 'max:100'],
            /** @example supplements */
            'category' => ['required', Rule::enum(IngredientCategory::class)],
            /**
             * Energy per 100 g, in kcal. Not checked against the macros: labels round, and alcohol or sugar
             * alcohols add calories the macros don't explain.
             *
             * @example 380
             */
            'kcal' => ['required', 'numeric', 'min:0', 'max:900'],
            /**
             * Grams per 100 g.
             *
             * @example 33
             */
            'protein' => ['required', 'numeric', 'min:0', 'max:100'],
            /**
             * Grams per 100 g.
             *
             * @example 38
             */
            'carbs' => ['required', 'numeric', 'min:0', 'max:100'],
            /**
             * Grams per 100 g. `protein + carbs + fat` may be at most 100.
             *
             * @example 11
             */
            'fat' => ['required', 'numeric', 'min:0', 'max:100'],
            /**
             * Grams per 100 g. Leave it out or send `null` when the label doesn't list it. Not in the sum rule.
             *
             * @example 9
             */
            'fiber' => ['nullable', 'numeric', 'min:0', 'max:100'],
            /**
             * Measures in display order, at most 10. Keys are generated.
             *
             * @example [{"label": "1 bar", "grams": 45}]
             */
            'portions' => ['sometimes', 'array', 'list', 'max:'.self::MAX_PORTIONS],
            'portions.*' => ['array'],
            /**
             * 1–50 characters after trimming.
             *
             * @example 1 bar
             */
            'portions.*.label' => ['required', 'string', 'max:50'],
            /**
             * Weight of the measure in grams: more than 0, at most 5000.
             *
             * @example 45
             */
            'portions.*.grams' => ['required', 'numeric', 'gt:0', 'max:5000'],
            // `portions.*.key` is prohibited, checked in after() so the contract doesn't list it as a field.
        ];
    }

    /**
     * Get the "after" validation callables for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ($this->portionItems() as $i => $item) {
                    if (array_key_exists('key', $item)) {
                        $attribute = "portions.{$i}.key";
                        $validator->errors()->add($attribute, trans('validation.prohibited', ['attribute' => $attribute]));
                    }
                }

                if ($validator->errors()->hasAny(['protein', 'carbs', 'fat'])) {
                    return;
                }

                if (self::exceedsMacroSum($this->input('protein'), $this->input('carbs'), $this->input('fat'))) {
                    $validator->errors()->add('fat', self::macroSumMessage());
                }
            },
        ];
    }

    /**
     * The `portions` items as sent, skipping anything that isn't an array (the rules report those).
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function portionItems(): array
    {
        $portions = $this->input('portions');

        return is_array($portions) ? array_filter($portions, is_array(...)) : [];
    }

    /**
     * Whether `protein + carbs + fat` is over `MAX_MACRO_SUM`, compared at the columns' 3 decimals.
     */
    public static function exceedsMacroSum(mixed $protein, mixed $carbs, mixed $fat): bool
    {
        return round((float) $protein + (float) $carbs + (float) $fat, 3) > self::MAX_MACRO_SUM;
    }

    public static function macroSumMessage(): string
    {
        return 'The protein, carbs and fat together may not be more than '.self::MAX_MACRO_SUM.' g per 100 g.';
    }
}
