<?php

namespace App\Models;

use App\Enums\PickerSessionStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $user_id
 * @property string $capsule_id
 * @property string $google_session_id
 * @property string|null $picker_uri
 * @property PickerSessionStatusEnum $status
 * @property int $selected_count
 * @property int $imported_count
 * @property array<int, string>|null $imported_item_ids
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $expires_at
 * @property Carbon|null $last_polled_at
 * @property Carbon|null $selection_ready_at
 * @property Carbon|null $import_started_at
 * @property Carbon|null $import_completed_at
 * @property string|null $last_error
 */
class GooglePhotosPickerSession extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'capsule_id',
        'google_session_id',
        'picker_uri',
        'status',
        'selected_count',
        'imported_count',
        'imported_item_ids',
        'metadata',
        'expires_at',
        'last_polled_at',
        'selection_ready_at',
        'import_started_at',
        'import_completed_at',
        'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PickerSessionStatusEnum::class,
            'selected_count' => 'integer',
            'imported_count' => 'integer',
            'imported_item_ids' => 'array',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'selection_ready_at' => 'datetime',
            'import_started_at' => 'datetime',
            'import_completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
