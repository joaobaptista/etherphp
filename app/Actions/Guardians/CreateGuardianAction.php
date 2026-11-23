<?php

namespace App\Actions\Guardians;

use App\Data\CreateGuardianData;
use App\Enums\GuardianStatusEnum;
use App\Models\Guardian;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CreateGuardianAction
{
    public function execute(User $user, CreateGuardianData $data): Guardian
    {
        $name = trim($data->name);
        $email = strtolower(trim($data->email));

        if ($name === '') {
            throw new HttpException(400, 'Nome do guardião é obrigatório.');
        }

        if ($email === '' || ! str_contains($email, '@')) {
            throw new HttpException(400, 'E-mail válido do guardião é obrigatório.');
        }

        $duplicate = Guardian::query()
            ->where('user_id', $user->id)
            ->where('email', $email)
            ->exists();

        if ($duplicate) {
            throw new HttpException(400, 'Já existe um guardião cadastrado com este e-mail.');
        }

        $id = $data->id !== null && str_starts_with($data->id, 'g-')
            ? $data->id
            : 'g-'.substr((string) Str::uuid(), 0, 8);

        if (Guardian::query()->whereKey($id)->exists()) {
            throw new HttpException(409, 'Identificador de guardião já utilizado.');
        }

        return Guardian::query()->create([
            'id' => $id,
            'user_id' => $user->id,
            'name' => $name,
            'email' => $email,
            'phone' => trim($data->phone),
            'relation' => trim($data->relation),
            'status' => GuardianStatusEnum::Active,
            'can_trigger_delivery' => $data->canTriggerDelivery,
            'notes' => $data->notes !== null ? trim($data->notes) : null,
        ]);
    }
}
