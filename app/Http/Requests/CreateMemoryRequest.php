<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateMemoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'capsuleId' => ['sometimes', 'nullable', 'string', 'max:64'],
            'capsule_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'type' => ['sometimes', 'nullable', 'string', 'max:16'],
            'title' => ['sometimes', 'nullable', 'string'],
            'content' => ['sometimes', 'nullable', 'string'],
            'story' => ['sometimes', 'nullable', 'string'],
            'mediaUrl' => ['sometimes', 'nullable', 'string'],
            'media_url' => ['sometimes', 'nullable', 'string'],
            'img' => ['sometimes', 'nullable', 'string'],
            'occurredAt' => ['sometimes', 'nullable', 'string'],
            'occurred_at' => ['sometimes', 'nullable', 'string'],
            'source' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
