<?php

// app/Payment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Payment extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'order_id', 'user_id', 'payment_group_id', 'cash_session_id', 'amount', 'method', 'operator',
        'reference', 'transaction_data', 'status',
    ];

    protected $casts = [
        'transaction_data' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($payment) {
            if (empty($payment->id)) {
                $payment->id = (string) Str::uuid();
            }
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Une même référence (restaurant + opérateur) est autorisée pour plusieurs paiements
     * si et seulement s'ils partagent le même payment_group_id.
     * Les paiements directs (payment_group_id null) restent soumis à l'unicité stricte.
     */
    public static function referenceAlreadyUsed(
        string $restaurantId,
        string $operator,
        string $reference,
        ?string $exceptGroupId = null
    ): bool {
        return self::where('operator', $operator)
            ->where('reference', $reference)
            ->whereIn('status', ['pending', 'completed'])
            ->whereHas('order', fn ($q) => $q->where('restaurant_id', $restaurantId))
            ->when($exceptGroupId === null,
                // paiement direct : interdit si une autre utilisation existe, groupe ou pas
                fn ($q) => $q,
                // dans un groupe : interdit seulement si un autre groupe (ou direct) l'utilise
                fn ($q) => $q->where(function ($q) use ($exceptGroupId) {
                    $q->whereNull('payment_group_id')
                        ->orWhere('payment_group_id', '!=', $exceptGroupId);
                })
            )
            ->exists();
    }
}
