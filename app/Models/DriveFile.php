<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $user_id
 * @property string|null $folder_id
 * @property string $title
 * @property string $type
 * @property string|null $type_label
 * @property string|null $icon_color
 * @property string|null $preview_type
 * @property string|null $preview_url
 * @property string|null $content
 * @property string|null $description
 * @property string|null $size
 * @property string|null $duration
 * @property string $status
 * @property string|null $capsule_id
 * @property string|null $capsule_recipient
 */
class DriveFile extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'folder_id',
        'title',
        'type',
        'type_label',
        'icon_color',
        'preview_type',
        'preview_url',
        'content',
        'description',
        'size',
        'duration',
        'status',
        'capsule_id',
        'capsule_recipient',
        'moved_to_capsule_at',
        'moved_to_vault_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'moved_to_capsule_at' => 'datetime',
            'moved_to_vault_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<DriveFolder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DriveFolder::class, 'folder_id');
    }

    /** @return BelongsTo<Capsule, $this> */
    public function capsule(): BelongsTo
    {
        return $this->belongsTo(Capsule::class, 'capsule_id');
    }
}
