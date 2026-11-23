<?php

namespace App\Actions\Admin;

use App\Models\Memory;
use App\Models\User;
use App\Support\RootAdmin;

class GetAdminMetricsAction
{
    /**
     * @return array{totalUsers: int, totalAdmins: int, totalMemories: int, systemStatus: string}
     */
    public function execute(): array
    {
        $rootEmails = RootAdmin::emails();

        $totalAdmins = User::query()
            ->where(function ($sub) use ($rootEmails): void {
                $sub->where('is_admin', true);
                if ($rootEmails !== []) {
                    $sub->orWhereIn('email', $rootEmails);
                }
            })
            ->count();

        return [
            'totalUsers' => User::query()->count(),
            'totalAdmins' => $totalAdmins,
            'totalMemories' => Memory::query()->count(),
            'systemStatus' => 'healthy',
        ];
    }
}
