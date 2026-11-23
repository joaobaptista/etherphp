<?php

namespace App\Contracts;

interface RealtimeBroadcasterContract
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function broadcastToUser(string $userId, string $event, array $payload): void;
}
