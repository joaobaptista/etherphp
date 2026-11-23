<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $user_id
 * @property int $version
 * @property string $kdf_name
 * @property int $kdf_iterations
 * @property string $kdf_salt
 * @property string $password_wrapped_key
 * @property string $password_verifier_hash
 * @property string $recovery_salt
 * @property string $recovery_wrapped_key
 * @property string $recovery_verifier_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UserVaultKey extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'version',
        'kdf_name',
        'kdf_iterations',
        'kdf_salt',
        'password_wrapped_key',
        'password_verifier_hash',
        'recovery_salt',
        'recovery_wrapped_key',
        'recovery_verifier_hash',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password_verifier_hash',
        'recovery_verifier_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'kdf_iterations' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
