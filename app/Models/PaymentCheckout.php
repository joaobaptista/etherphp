<?php

namespace App\Models;

use App\Enums\CheckoutMethodEnum;
use App\Enums\CheckoutStatusEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string $plan_id
 * @property string $billing_cycle
 * @property CheckoutMethodEnum $method
 * @property string $amount
 * @property CheckoutStatusEnum $status
 * @property string|null $gateway_id
 * @property string|null $gateway_status
 * @property string|null $init_point
 * @property string|null $pix_qr_code
 * @property string|null $pix_qr_code_base64
 * @property string|null $pix_ticket_url
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PaymentCheckout extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'user_id',
        'plan_id',
        'billing_cycle',
        'method',
        'amount',
        'status',
        'gateway_id',
        'gateway_status',
        'init_point',
        'pix_qr_code',
        'pix_qr_code_base64',
        'pix_ticket_url',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'method' => CheckoutMethodEnum::class,
            'status' => CheckoutStatusEnum::class,
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
