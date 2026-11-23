<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'capsuleId' => ['sometimes', 'string', 'max:64'],
            'title' => ['sometimes', 'nullable', 'string'],
            'content' => ['sometimes', 'nullable', 'string'],
            'story' => ['sometimes', 'nullable', 'string'],
            'occurredAt' => ['sometimes', 'nullable', 'string'],
            'occurred_at' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
