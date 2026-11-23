<?php

namespace App\Actions\Admin;

use App\Models\User;
use App\Support\RootAdmin;
use Illuminate\Database\Eloquent\Collection;

class ListUsersAction
{
    /**
     * @return Collection<int, User>
     */
    public function execute(string $search = '', string $role = 'all'): Collection
    {
        $query = User::query();

        $trimmed = strtolower(trim($search));
        if ($trimmed !== '') {
            $like = "%{$trimmed}%";
            $query->where(function ($sub) use ($like): void {
                $sub->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$like])
                    ->orWhere('phone', 'LIKE', $like);
            });
        }

        $rootEmails = RootAdmin::emails();
        if ($role === 'admin') {
            $query->where(function ($sub) use ($rootEmails): void {
                $sub->where('is_admin', true);
                if ($rootEmails !== []) {
                    $sub->orWhereIn('email', $rootEmails);
                }
            });
        } elseif ($role === 'user') {
            $query->where('is_admin', false);
            if ($rootEmails !== []) {
                $query->whereNotIn('email', $rootEmails);
            }
        }

        return $query
            ->orderByDesc('is_admin')
            ->orderByDesc('created_at')
            ->get();
    }
}
