<?php

namespace App\Support;

final class RootAdmin
{
    /**
     * @return list<string>
     */
    public static function emails(): array
    {
        /** @var list<string> $emails */
        $emails = config('ethernity.security.root_admin_emails', []);

        return $emails;
    }

    public static function isRootEmail(?string $email): bool
    {
        if ($email === null || trim($email) === '') {
            return false;
        }

        return in_array(strtolower(trim($email)), self::emails(), true);
    }
}
