<?php

namespace App\Actions\Auth;

use App\Data\RegisterUserData;
use App\Enums\PlanIdEnum;
use App\Models\User;
use App\Support\RootAdmin;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RegisterUserAction
{
    public function execute(RegisterUserData $data): User
    {
        $email = strtolower(trim($data->email));

        if (User::query()->where('email', $email)->exists()) {
            throw new HttpException(400, 'Email already registered');
        }

        // Root admin e-mails are never claimed with a password (no e-mail check here): only Google login,
        // which proves the e-mail is theirs, can open them. Otherwise anyone could register one and become root.
        if (RootAdmin::isRootEmail($email)) {
            throw new HttpException(403, 'Este e-mail é reservado. Entre com a conta Google dele.');
        }

        return User::query()->create([
            'id' => (string) Str::uuid(),
            'name' => trim($data->name),
            'email' => $email,
            'phone' => trim($data->phone),
            'password_hash' => Hash::make($data->password),
            'plan_id' => PlanIdEnum::Infinity,
            'is_admin' => false,
        ]);
    }
}
