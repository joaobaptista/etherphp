<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Approved Mercado Pago payment that granted a billing period (id = Mercado Pago payment id).
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $checkout_id
 * @property string $kind
 * @property string $amount
 * @property string $plan_id
 * @property string $billing_cycle
 * @property Carbon $period_start
 * @property Carbon $period_end
 */
class SubscriptionPayment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'checkout_id',
        'kind',
        'amount',
        'plan_id',
        'billing_cycle',
        'period_start',
        'period_end',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
        ];
    }
}
