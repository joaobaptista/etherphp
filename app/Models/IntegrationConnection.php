<?php

namespace App\Models;

use App\Enums\IntegrationStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $user_id
 * @property string $provider
 * @property string $external_account_id
 * @property string|null $display_name
 * @property array<int, string>|null $scopes
 * @property string|null $credentials_encrypted
 * @property string|null $sync_cursor
 * @property IntegrationStatusEnum $status
 * @property Carbon|null $last_synced_at
 * @property string|null $last_error
 */
class IntegrationConnection extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'provider',
        'external_account_id',
        'display_name',
        'scopes',
        'credentials_encrypted',
        'sync_cursor',
        'status',
        'last_synced_at',
        'last_error',
    ];

    /** @var list<string> */
    protected $hidden = [
        'credentials_encrypted',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'status' => IntegrationStatusEnum::class,
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
