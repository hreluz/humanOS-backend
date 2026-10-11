<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** @example 8f14e45fceea167a5a36dedd4bea2543a7c6f5b1e2d3c4b5a6978899aabbccdd */
            'token' => ['required', 'string'],
            /** @example ana@example.com */
            'email' => ['required', 'string', 'email'],
            /** @example new-correct-horse-battery-staple */
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }
}
