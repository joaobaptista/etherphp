<?php

namespace App\Models;

use App\Enums\EncryptionModeEnum;
use App\Enums\MemorySourceEnum;
use App\Enums\MemoryTypeEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $capsule_id
 * @property string $author_id
 * @property MemoryTypeEnum $type
 * @property string|null $title
 * @property string|null $content
 * @property string|null $media_url
 * @property string|null $storage_key
 * @property string|null $mime_type
 * @property int|null $file_size_bytes
 * @property bool $is_encrypted
 * @property EncryptionModeEnum $encryption_mode
 * @property string|null $encryption_iv
 * @property string|null $encryption_tag
 * @property int|null $chunk_size_bytes
 * @property array<string, mixed>|null $variants_json
 * @property int|null $media_duration_seconds
 * @property array<int, float>|string|null $waveform_peaks
 * @property int $dpi
 * @property string $color_profile
 * @property MemorySourceEnum $source
 * @property array<string, mixed>|null $provider_metadata
 * @property Carbon|null $occurred_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Memory extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'capsule_id',
        'author_id',
        'type',
        'title',
        'content',
        'media_url',
        'storage_key',
        'mime_type',
        'file_size_bytes',
        'is_encrypted',
        'encryption_mode',
        'encryption_iv',
        'encryption_tag',
        'chunk_size_bytes',
        'variants_json',
        'media_duration_seconds',
        'waveform_peaks',
        'dpi',
        'color_profile',
        'source',
        'provider_metadata',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => MemoryTypeEnum::class,
            'source' => MemorySourceEnum::class,
            'encryption_mode' => EncryptionModeEnum::class,
            'is_encrypted' => 'boolean',
            'file_size_bytes' => 'integer',
            'chunk_size_bytes' => 'integer',
            'media_duration_seconds' => 'integer',
            'dpi' => 'integer',
            'variants_json' => 'array',
            'waveform_peaks' => 'json',
            'provider_metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<Capsule, $this> */
    public function capsule(): BelongsTo
    {
        return $this->belongsTo(Capsule::class, 'capsule_id');
    }
}
