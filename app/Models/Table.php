<?php

// app/Table.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Table extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'restaurant_id', 'number', 'name', 'capacity', 'status', 'rotation',
        'current_order_id', 'x_position', 'y_position', 'qr_code', 'metadata',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function currentOrder()
    {
        return $this->belongsTo(Order::class, 'current_order_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function isFree()
    {
        return $this->status === 'free';
    }

    public function occupy(Order $order)
    {
        $this->update([
            'status' => 'occupied',
            'current_order_id' => $order->id,
        ]);
    }

    public function free()
    {
        $this->update([
            'status' => 'free',
            'current_order_id' => null,
        ]);
    }

    /**
     * Recalcule l'état de la table d'après ses commandes actives.
     * Elle pointe vers la plus ancienne commande active ; s'il n'y en a aucune, elle est libérée.
     */
    public function syncCurrentOrder(): void
    {
        $current = $this->orders()
            ->whereIn('status', Order::ACTIVE_STATUSES)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($current) {
            $this->update(['status' => 'occupied', 'current_order_id' => $current->id]);
        } else {
            $this->update(['status' => 'free', 'current_order_id' => null]);
        }
    }
}
