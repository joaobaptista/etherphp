<?php

namespace App\Actions\Messages;

use App\Models\Message;

class DeleteMessageAction
{
    public function execute(Message $message): void
    {
        $message->delete();
    }
}
