<?php

// app/Models/Order.php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    use BelongsToRestaurant, HasFactory;

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

    /**
     * Fait avancer la commande selon l'état de ses plats en cuisine :
     *   open → in_progress (dès qu'un plat est lancé par le cuisinier)
     *   → ready (tous les plats prêts ou servis)
     *   → served (tous les plats servis).
     * Un plat ajouté à une commande prête/servie la renvoie « en préparation ».
     * Ne revient jamais à « open » et ne touche pas aux commandes payées / annulées.
     * Retourne l'ancien statut si la commande a changé, sinon null.
     */
    public function syncStatusFromItems(): ?string
    {
        if (! in_array($this->status, ['open', 'in_progress', 'ready', 'served'], true)) {
            return null;
        }

        $statuses = $this->items()->pluck('kitchen_status');
        if ($statuses->isEmpty()) {
            return null;
        }

        $target = match (true) {
            $statuses->every(fn ($s) => $s === 'served') => 'served',
            $statuses->every(fn ($s) => in_array($s, ['ready', 'served'], true)) => 'ready',
            $statuses->contains(fn ($s) => $s !== 'pending') => 'in_progress',
            $this->status === 'open' => 'open',
            default => 'in_progress', // nouveau plat sur une commande prête/servie
        };

        if ($target === $this->status) {
            return null;
        }

        $old = $this->status;
        $this->update(['status' => $target]);

        return $old;
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

        // Une commande vide (total 0) n'est pas « payée »
        if ($total > 0 && $due <= 0 && $this->status !== 'paid') {
            $this->update(['status' => 'paid']);
            $table = $this->table()->first();
            if ($table) {
                $table->update(['status' => 'free', 'current_order_id' => null]);
            }
        }
    }
}
