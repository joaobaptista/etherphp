<?php

namespace App\Models;

use App\Enums\PlanIdEnum;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $password_hash
 * @property string|null $phone
 * @property string|null $avatar_url
 * @property PlanIdEnum $plan_id
 * @property bool $is_admin
 * @property Carbon|null $notifications_read_at
 * @property string|null $encryption_public_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'name',
        'email',
        'password_hash',
        'phone',
        'avatar_url',
        'plan_id',
        'is_admin',
        'notifications_read_at',
        'encryption_public_key',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'plan_id' => PlanIdEnum::class,
            'is_admin' => 'boolean',
            'notifications_read_at' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return (string) ($this->password_hash ?? '');
    }

    /** @return HasMany<Capsule, $this> */
    public function capsules(): HasMany
    {
        return $this->hasMany(Capsule::class, 'user_id');
    }

    /** @return HasMany<Guardian, $this> */
    public function guardians(): HasMany
    {
        return $this->hasMany(Guardian::class, 'user_id');
    }

    /** @return HasMany<Memory, $this> */
    public function memories(): HasMany
    {
        return $this->hasMany(Memory::class, 'author_id');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'user_id');
    }

    /** @return HasOne<Subscription, $this> */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class, 'user_id');
    }

    /** @return HasOne<UserVaultKey, $this> */
    public function vaultKey(): HasOne
    {
        return $this->hasOne(UserVaultKey::class, 'user_id');
    }

    /** @return HasMany<IntegrationConnection, $this> */
    public function integrationConnections(): HasMany
    {
        return $this->hasMany(IntegrationConnection::class, 'user_id');
    }

    /** @return HasMany<GooglePhotosPickerSession, $this> */
    public function pickerSessions(): HasMany
    {
        return $this->hasMany(GooglePhotosPickerSession::class, 'user_id');
    }

    /** @return HasMany<DeviceToken, $this> */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class, 'user_id');
    }
}
