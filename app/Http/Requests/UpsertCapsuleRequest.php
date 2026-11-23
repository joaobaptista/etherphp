<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertCapsuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'title' => ['required', 'string'],
            'recipient' => ['sometimes', 'nullable'],
            'deliveryType' => ['sometimes', 'nullable', 'string'],
            'delivery_type' => ['sometimes', 'nullable', 'string'],
            'scheduledDate' => ['sometimes', 'nullable', 'string'],
            'scheduled_date' => ['sometimes', 'nullable', 'string'],
            'emotion' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', 'nullable', 'string', 'max:16'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'guardians' => ['sometimes', 'nullable', 'array'],
            'memories' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
