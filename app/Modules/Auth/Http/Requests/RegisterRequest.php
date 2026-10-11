<?php

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /** @example Ana Torres */
            'name' => ['required', 'string', 'max:255'],
            /** @example ana@example.com */
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            /** @example correct-horse-battery-staple */
            'password' => ['required', 'confirmed', Password::defaults()],
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
