<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'planId' => ['required', 'string', 'max:32'],
            'cycle' => ['sometimes', 'string', 'in:monthly,yearly'],
            'method' => ['required', 'string', 'in:card,pix'],
        ];
    }
}
