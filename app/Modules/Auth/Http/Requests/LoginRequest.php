<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** @example ana@example.com */
            'email' => ['required', 'string', 'email'],
            /** @example correct-horse-battery-staple */
            'password' => ['required', 'string'],
            /** @example iPhone 15 */
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The token name: device_name, else the User-Agent, else a placeholder.
     */
    public function deviceName(): string
    {
        return $this->input('device_name')
            ?: Str::substr((string) $this->userAgent(), 0, 255)
            ?: 'Unknown device';
    }
}
