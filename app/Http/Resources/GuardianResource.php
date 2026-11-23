<?php

namespace App\Http\Resources;

use App\Models\Guardian;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Guardian
 */
class GuardianResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'relation' => $this->relation,
            'relationship' => $this->relation,
            'avatar' => $this->avatar_url,
            'avatar_url' => $this->avatar_url,
            'status' => $this->status->value,
            'canTriggerDelivery' => (bool) $this->can_trigger_delivery,
            'can_trigger_delivery' => (bool) $this->can_trigger_delivery,
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
