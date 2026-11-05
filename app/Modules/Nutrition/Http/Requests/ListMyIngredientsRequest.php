<?php

namespace App\Modules\Nutrition\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListMyIngredientsRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 50;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * Page number. Defaults to 1.
             *
             * @example 1
             */
            'page' => ['sometimes', 'integer', 'min:1'],
            /**
             * Page size. Defaults to 50.
             *
             * @example 50
             */
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * The validated page size.
     */
    public function perPage(): int
    {
        return (int) $this->validated('per_page', self::DEFAULT_PER_PAGE);
    }
}
