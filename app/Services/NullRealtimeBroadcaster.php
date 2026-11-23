<?php

namespace App\Services;

use App\Contracts\RealtimeBroadcasterContract;

class NullRealtimeBroadcaster implements RealtimeBroadcasterContract
{
    /**
     * Shared-hosting friendly no-op broadcaster; notifications are fetched via HTTP polling/SSE.
     * Swap this binding for a Laravel Reverb broadcaster when running persistent WebSocket workers.
     *
     * @param  array<string, mixed>  $payload
     */
    public function broadcastToUser(string $userId, string $event, array $payload): void
    {
        // Intentionally no-op until Reverb/Echo is enabled.
    }
}
