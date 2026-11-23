<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $minLength = (int) config('ethernity.security.min_password_length', 8);

        return [
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'password' => ['sometimes', 'nullable', 'string', 'min:'.$minLength],
            'photo_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'avatar_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $minLength = (int) config('ethernity.security.min_password_length', 8);

        return [
            'password.min' => "A senha precisa ter pelo menos {$minLength} caracteres.",
        ];
    }
}
