<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\RootAdmin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isRoot = RootAdmin::isRootEmail($this->email);
        $planEnum = $this->plan_id;

        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name !== '' ? $this->name : 'Usuário Sem Nome',
            'phone' => $this->phone,
            'photo_url' => $this->avatar_url,
            'avatar_url' => $this->avatar_url,
            'avatar' => $this->avatar_url,
            'plan_id' => $planEnum->value,
            'plan' => $planEnum->label(),
            'is_admin' => ($isRoot || $this->is_admin) ? 1 : 0,
            'is_root_admin' => $isRoot,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
