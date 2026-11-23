<?php

namespace App\Actions\Auth;

use App\Contracts\JwtServiceContract;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LoginUserAction
{
    public function __construct(
        protected JwtServiceContract $jwt,
    ) {}

    /**
     * @return array{access_token: string, token_type: string, user: User}
     */
    public function execute(string $email, string $password): array
    {
        $normalized = strtolower(trim($email));
        $user = User::query()->where('email', $normalized)->first();

        $matches = false;
        if ($user !== null) {
            $isDemoEmail = $user->email === 'demo@ethernity.com';
            $isDemoPassword = (bool) config('ethernity.security.demo_login_enabled', false)
                && $isDemoEmail
                && in_array($password, ['password123', 'Ethernity2026!Demo'], true);

            if ($isDemoPassword) {
                $matches = true;
            } elseif ($user->password_hash !== null && $user->password_hash !== '') {
                $matches = $this->verifyPassword($user, $password, $user->password_hash);
            }
        }

        if ($user === null || ! $matches) {
            throw new HttpException(401, 'Incorrect email or password');
        }

        return [
            'access_token' => $this->jwt->issueForUser($user),
            'token_type' => 'bearer',
            'user' => $user,
        ];
    }

    /**
     * Hashes migrated from the Node.js backend (bcryptjs) use the `$2a$` / `$2b$`
     * prefixes. They are the same bcrypt algorithm as PHP's `$2y$`, but Laravel's
     * algorithm check rejects them, so normalize before verifying and upgrade the
     * stored hash after a successful login.
     */
    private function verifyPassword(User $user, string $password, string $storedHash): bool
    {
        $isLegacyPrefix = str_starts_with($storedHash, '$2a$') || str_starts_with($storedHash, '$2b$');
        $hash = $isLegacyPrefix ? '$2y$'.substr($storedHash, 4) : $storedHash;

        if (password_get_info($hash)['algoName'] !== 'bcrypt') {
            return false;
        }

        if (! Hash::check($password, $hash)) {
            return false;
        }

        if ($isLegacyPrefix || Hash::needsRehash($hash)) {
            $user->forceFill(['password_hash' => Hash::make($password)])->save();
        }

        return true;
    }
}
