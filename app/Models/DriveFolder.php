<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $user_id
 * @property string|null $parent_id
 * @property string $name
 * @property string|null $location
 * @property string|null $color
 */
class DriveFolder extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'parent_id',
        'name',
        'location',
        'color',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<DriveFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(DriveFile::class, 'folder_id');
    }
}
