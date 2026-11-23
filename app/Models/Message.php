<?php

namespace App\Models;

use App\Enums\DeliveryTypeEnum;
use App\Enums\MessageStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $title
 * @property string|null $content
 * @property string|null $recipient_name
 * @property string|null $recipient_email
 * @property string|null $recipient_phone
 * @property DeliveryTypeEnum $delivery_type
 * @property Carbon|null $delivery_date
 * @property string|null $delivery_time
 * @property bool $guardian_protected
 * @property bool $email_notification
 * @property bool $whatsapp_notification
 * @property array<int, mixed>|null $media_files
 * @property string|null $category
 * @property MessageStatusEnum $status
 * @property bool $is_encrypted
 * @property string|null $device_id
 * @property string|null $message_text
 * @property string|null $severity
 * @property string|null $source
 * @property bool $active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Message extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'title',
        'content',
        'recipient_name',
        'recipient_email',
        'recipient_phone',
        'delivery_type',
        'delivery_date',
        'delivery_time',
        'guardian_protected',
        'email_notification',
        'whatsapp_notification',
        'media_files',
        'category',
        'status',
        'is_encrypted',
        'device_id',
        'message_text',
        'severity',
        'source',
        'active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivery_type' => DeliveryTypeEnum::class,
            'status' => MessageStatusEnum::class,
            'delivery_date' => 'datetime',
            'guardian_protected' => 'boolean',
            'email_notification' => 'boolean',
            'whatsapp_notification' => 'boolean',
            'media_files' => 'array',
            'is_encrypted' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
