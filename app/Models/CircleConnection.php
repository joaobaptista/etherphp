<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $requester_id
 * @property string|null $requester_name
 * @property string|null $requester_email
 * @property string|null $target_user_id
 * @property string $target_email
 * @property string $target_name
 * @property string $relation
 * @property string $role
 * @property string $status
 * @property bool $can_trigger_delivery
 * @property string|null $notes
 * @property string|null $invite_code
 */
class CircleConnection extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'requester_id',
        'requester_name',
        'requester_email',
        'target_user_id',
        'target_email',
        'target_name',
        'relation',
        'role',
        'status',
        'can_trigger_delivery',
        'notes',
        'invite_code',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'can_trigger_delivery' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }
}
