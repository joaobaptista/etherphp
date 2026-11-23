<?php

namespace App\Models;

use App\Enums\PlanIdEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property PlanIdEnum $plan_id
 * @property string $status
 * @property string $price_formatted
 * @property string $billing_cycle
 * @property string|null $payment_method
 * @property Carbon $current_period_start
 * @property Carbon $current_period_end
 * @property string|null $gateway_subscription_id
 * @property string|null $gateway_status
 * @property bool $auto_renew
 * @property Carbon|null $canceled_at
 */
class Subscription extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'plan_id',
        'status',
        'price_formatted',
        'billing_cycle',
        'payment_method',
        'current_period_start',
        'current_period_end',
        'gateway_subscription_id',
        'gateway_status',
        'auto_renew',
        'canceled_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'plan_id' => PlanIdEnum::class,
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'auto_renew' => 'boolean',
            'canceled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
