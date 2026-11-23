<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $minLength = (int) config('ethernity.security.min_password_length', 8);

        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'min:'.$minLength],
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
