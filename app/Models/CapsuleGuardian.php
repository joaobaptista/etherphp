<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $capsule_id
 * @property string $guardian_email
 * @property Carbon|null $created_at
 */
class CapsuleGuardian extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'capsule_id',
        'guardian_email',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Capsule, $this> */
    public function capsule(): BelongsTo
    {
        return $this->belongsTo(Capsule::class, 'capsule_id');
    }
}
