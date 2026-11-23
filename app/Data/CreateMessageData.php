<?php

namespace App\Data;

use Spatie\LaravelData\Data;

class CreateMessageData extends Data
{
    /**
     * @param  list<mixed>  $mediaFiles
     */
    public function __construct(
        public string $title,
        public string $content = '',
        public ?string $recipientName = null,
        public ?string $recipientEmail = null,
        public ?string $recipientPhone = null,
        public string $deliveryType = 'date',
        public ?string $deliveryDate = null,
        public string $deliveryTime = '09:00',
        public bool $guardianProtected = true,
        public bool $emailNotification = true,
        public bool $whatsappNotification = false,
        public array $mediaFiles = [],
        public string $category = 'Cartas de Amor',
        public string $status = 'scheduled',
        public ?string $capsuleId = null,
    ) {}
}
