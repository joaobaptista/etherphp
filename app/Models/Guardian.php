<?php

namespace App\Models;

use App\Enums\GuardianStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $email
 * @property string $phone
 * @property string $relation
 * @property string|null $avatar_url
 * @property GuardianStatusEnum $status
 * @property bool $can_trigger_delivery
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Guardian extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'name',
        'email',
        'phone',
        'relation',
        'avatar_url',
        'status',
        'can_trigger_delivery',
        'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => GuardianStatusEnum::class,
            'can_trigger_delivery' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
