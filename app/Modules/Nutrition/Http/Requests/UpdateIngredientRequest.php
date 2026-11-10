<?php

namespace App\Modules\Nutrition\Http\Requests;

use App\Modules\Nutrition\Enums\IngredientCategory;
use App\Modules\Nutrition\Models\Ingredient;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A partial edit of the caller's own ingredient (SPEC 18). A field that isn't sent keeps its stored value.
 * Authorization runs before validation, so a catalog ingredient is a 403 whatever the body.
 */
class UpdateIngredientRequest extends FormRequest
{
    /**
     * Only the owner may edit (`IngredientPolicy::update`).
     */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->ingredient());
    }

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
             * @example Protein bar, dark chocolate
             */
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            /** @example supplements */
            'category' => ['sometimes', 'required', Rule::enum(IngredientCategory::class)],
            /**
             * Energy per 100 g, in kcal.
             *
             * @example 380
             */
            'kcal' => ['sometimes', 'required', 'numeric', 'min:0', 'max:900'],
            /**
             * Grams per 100 g.
             *
             * @example 33
             */
            'protein' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            /**
             * Grams per 100 g.
             *
             * @example 38
             */
            'carbs' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            /**
             * Grams per 100 g. `protein + carbs + fat`, with stored values filling the fields not sent, may be at most 100.
             *
             * @example 11
             */
            'fat' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            /**
             * Grams per 100 g. `null` clears it.
             *
             * @example 9
             */
            'fiber' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            /**
             * The full new list of portions, in display order, at most 10. Leave it out to keep the portions as they
             * are. An item with a `key` updates that portion, an item without one is a new portion, and a portion
             * left out is removed. `[]` removes them all.
             *
             * @example [{"key": "p_8k2d4x7q", "label": "1 bar", "grams": 45}, {"label": "2 bars", "grams": 90}]
             */
            'portions' => ['sometimes', 'array', 'list', 'max:'.StoreIngredientRequest::MAX_PORTIONS],
            'portions.*' => ['array'],
            /**
             * A current portion of this ingredient, at most once. Leave it out for a new portion.
             *
             * @example p_8k2d4x7q
             */
            'portions.*.key' => ['sometimes', 'string', 'distinct'],
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
                $ingredient = $this->ingredient();

                // Checked here rather than with `Rule::in`, so the contract doesn't list the keys of no ingredient.
                $current = $ingredient->portions()->pluck('key')->all();
                $input = $this->input('portions');
                foreach (is_array($input) ? $input : [] as $i => $item) {
                    $attribute = "portions.{$i}.key";
                    if (is_array($item) && is_string($item['key'] ?? null) && ! $validator->errors()->has($attribute) && ! in_array($item['key'], $current, true)) {
                        $validator->errors()->add($attribute, trans('validation.in', ['attribute' => $attribute]));
                    }
                }

                if ($validator->errors()->hasAny(['protein', 'carbs', 'fat'])) {
                    return;
                }

                $merged = fn (string $field) => $this->has($field) ? $this->input($field) : $ingredient->{$field};

                if (StoreIngredientRequest::exceedsMacroSum($merged('protein'), $merged('carbs'), $merged('fat'))) {
                    $validator->errors()->add('fat', StoreIngredientRequest::macroSumMessage());
                }
            },
        ];
    }

    /**
     * The ingredient being edited, already resolved by the route binding.
     */
    public function ingredient(): Ingredient
    {
        return $this->route('ingredient');
    }
}
