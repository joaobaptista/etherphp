<?php

namespace App\Http\Controllers;

use App\Contracts\VaultStorageServiceContract;
use App\Models\Guardian;
use App\Models\User;
use App\Support\RootAdmin;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SpaController extends Controller
{
    public function index(): View
    {
        return view('app');
    }

    public function serveUpload(Request $request, string $path, VaultStorageServiceContract $storage): BinaryFileResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_if(! $user instanceof User, 403, 'Not authenticated');

        $clean = ltrim(str_replace('\\', '/', $path), '/');
        abort_if($clean === '' || str_contains($clean, '..') || str_contains($clean, "\0"), 404, 'Arquivo não encontrado.');

        abort_unless($this->canAccessUpload($user, $clean), 403, 'Acesso não autorizado a este arquivo.');

        $resolved = $storage->uploadFilePath($clean);
        abort_if($resolved === null, 404, 'Arquivo não encontrado.');

        return response()->file($resolved, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    private function canAccessUpload(User $user, string $cleanRelativePath): bool
    {
        $url = '/uploads/'.$cleanRelativePath;

        // 1. User's own scoped upload directory (generic uploads + guardian photos)
        if (str_starts_with($cleanRelativePath, $user->id.'/')) {
            return true;
        }

        // 2. User's own profile avatar (local or Google-cached)
        if (
            str_starts_with($cleanRelativePath, "avatar-{$user->id}-")
            || str_starts_with($cleanRelativePath, "avatar-google-{$user->id}.")
            || $user->avatar_url === $url
        ) {
            return true;
        }

        // 3. Guardian avatar explicitly linked to this user
        if (Guardian::query()->where('user_id', $user->id)->where('avatar_url', $url)->exists()) {
            return true;
        }

        // 4. Admins may view user profile avatars in the admin panel
        if (
            ($user->is_admin || RootAdmin::isRootEmail($user->email))
            && (str_starts_with($cleanRelativePath, 'avatar-') || str_starts_with($cleanRelativePath, 'avatar-google-'))
        ) {
            return true;
        }

        return false;
    }
}
