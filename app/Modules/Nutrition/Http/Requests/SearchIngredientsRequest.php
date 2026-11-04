<?php

namespace App\Modules\Nutrition\Http\Requests;

use App\Modules\Nutrition\Enums\IngredientCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchIngredientsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * Matched anywhere in the English name or an English alias, ignoring case and accents.
             *
             * @example rice
             */
            'search' => ['required', 'string', 'min:3', 'max:100'],
            'category' => ['sometimes', 'array'],
            /**
             * Only these categories.
             *
             * @example ["grains", "vegetables"]
             */
            'category.*' => [Rule::enum(IngredientCategory::class)],
        ];
    }
}
