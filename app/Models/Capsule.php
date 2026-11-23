<?php

namespace App\Models;

use App\Enums\CapsuleStatusEnum;
use App\Enums\DeliveryTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $title
 * @property array<string, mixed>|null $recipient
 * @property DeliveryTypeEnum $delivery_type
 * @property Carbon|null $scheduled_date
 * @property string $emotion
 * @property CapsuleStatusEnum $status
 * @property int $video_duration_seconds
 * @property string|null $video_blob_url
 * @property float|null $video_size_mb
 * @property bool $is_encrypted
 * @property string|null $encryption_hash
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Capsule extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'title',
        'recipient',
        'delivery_type',
        'scheduled_date',
        'emotion',
        'status',
        'video_duration_seconds',
        'video_blob_url',
        'video_size_mb',
        'is_encrypted',
        'encryption_hash',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recipient' => 'array',
            'metadata' => 'array',
            'delivery_type' => DeliveryTypeEnum::class,
            'status' => CapsuleStatusEnum::class,
            'scheduled_date' => 'datetime',
            'video_duration_seconds' => 'integer',
            'video_size_mb' => 'float',
            'is_encrypted' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<CapsuleGuardian, $this> */
    public function capsuleGuardians(): HasMany
    {
        return $this->hasMany(CapsuleGuardian::class, 'capsule_id');
    }

    /** @return HasMany<Memory, $this> */
    public function memories(): HasMany
    {
        return $this->hasMany(Memory::class, 'capsule_id');
    }
}
