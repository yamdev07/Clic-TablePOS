<?php

// app/Models/Order.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'restaurant_id', 'table_id', 'user_id', 'order_number', 'status', 'type',
        'subtotal', 'tax', 'service_charge', 'discount', 'total',
        'paid_amount', 'due_amount', 'notes', 'metadata',
        'confirmed_at', 'completed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'confirmed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->id)) {
                $order->id = (string) Str::uuid();
            }
            if (empty($order->order_number)) {
                $order->order_number = 'ORD-'.date('Ymd').'-'.strtoupper(Str::random(6));
            }
        });
    }

    /**
     * Restreindre le route model binding au restaurant de l'utilisateur connecté.
     * Retourne 404 si l'order appartient à un autre restaurant.
     */
    public function resolveRouteBinding($value, $field = null): static
    {
        $query = $this->where($field ?? $this->getRouteKeyName(), $value);

        if (auth()->check()) {
            $query->where('restaurant_id', auth()->user()->restaurant_id);
        }

        return $query->firstOrFail();
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function recalculate(): void
    {
        $subtotal = $this->items()->sum('total_price');
        $tax = (int) ($subtotal * 0.18);
        $serviceCharge = (int) ($subtotal * 0.05);
        $total = $subtotal + $tax + $serviceCharge;

        $paid = $this->payments()->where('status', 'completed')->sum('amount');
        $due = $total - $paid;

        $this->update([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'service_charge' => $serviceCharge,
            'total' => $total,
            'paid_amount' => $paid,
            'due_amount' => $due,
        ]);

        if ($due <= 0 && $this->status !== 'paid') {
            $this->update(['status' => 'paid']);
            $table = $this->table()->first();
            if ($table) {
                $table->update(['status' => 'free', 'current_order_id' => null]);
            }
        }
    }
}
