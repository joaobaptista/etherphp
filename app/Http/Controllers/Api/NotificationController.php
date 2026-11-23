<?php

namespace App\Http\Controllers\Api;

use App\Actions\Notifications\BuildNotificationsAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Notificações', weight: 110)]
class NotificationController extends Controller
{
    /**
     * Listar notificações
     */
    public function index(Request $request, BuildNotificationsAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($action->execute($user));
    }

    /**
     * Marcar notificações como lidas
     */
    public function markRead(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->update(['notifications_read_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
