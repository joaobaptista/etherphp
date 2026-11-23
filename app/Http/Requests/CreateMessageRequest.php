<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string'],
            // Capsule of this user the message belongs to; null detaches it
            'capsuleId' => ['sometimes', 'nullable', 'string', Rule::exists('capsules', 'id')->where('user_id', $this->user()?->getAuthIdentifier())],
            'content' => ['sometimes', 'nullable', 'string'],
            'recipientName' => ['sometimes', 'nullable', 'string'],
            'recipientEmail' => ['sometimes', 'nullable', 'string', 'max:255'],
            'recipientPhone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'deliveryType' => ['sometimes', 'nullable', 'string', 'max:32'],
            'deliveryDate' => ['sometimes', 'nullable', 'string'],
            'deliveryTime' => ['sometimes', 'nullable', 'string', 'max:16'],
            'guardianProtected' => ['sometimes', 'boolean'],
            'emailNotification' => ['sometimes', 'boolean'],
            'whatsappNotification' => ['sometimes', 'boolean'],
            'mediaFiles' => ['sometimes', 'nullable', 'array'],
            'category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['capsuleId.exists' => 'Cápsula não encontrada. Abra a cápsula de novo e tente salvar outra vez.'];
    }
}
