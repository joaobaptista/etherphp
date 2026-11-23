<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCapsuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'nullable', 'string'],
            'recipient' => ['sometimes', 'nullable'],
            'deliveryType' => ['sometimes', 'nullable', 'string', 'max:32'],
            'delivery_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'scheduledDate' => ['sometimes', 'nullable', 'string'],
            'scheduled_date' => ['sometimes', 'nullable', 'string'],
            'emotion' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', 'nullable', 'string', 'max:16'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'guardians' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
