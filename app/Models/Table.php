<?php

// app/Table.php

namespace App\Models;

use App\Events\TableStatusChanged;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Table extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'restaurant_id', 'number', 'name', 'capacity', 'status', 'rotation', 'shape', 'size_label',
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
        $oldStatus = $this->status;
        $oldOrderId = $this->current_order_id;

        $this->update([
            'status' => 'occupied',
            'current_order_id' => $order->id,
        ]);

        $this->dispatchStatusChangedIfNeeded($oldStatus, $oldOrderId);
    }

    public function free()
    {
        $oldStatus = $this->status;
        $oldOrderId = $this->current_order_id;

        $this->update([
            'status' => 'free',
            'current_order_id' => null,
        ]);

        $this->dispatchStatusChangedIfNeeded($oldStatus, $oldOrderId);
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

        $oldStatus = $this->status;
        $oldOrderId = $this->current_order_id;

        if ($current) {
            $this->update(['status' => 'occupied', 'current_order_id' => $current->id]);
        } else {
            $this->update(['status' => 'free', 'current_order_id' => null]);
        }

        $this->dispatchStatusChangedIfNeeded($oldStatus, $oldOrderId);
    }

    private function dispatchStatusChangedIfNeeded(string $oldStatus, ?string $oldOrderId): void
    {
        $this->refresh();

        if ($oldStatus === $this->status && $oldOrderId === $this->current_order_id) {
            return;
        }

        $event = new TableStatusChanged(
            restaurantId: $this->restaurant_id,
            tableId: $this->id,
            number: $this->number,
            status: $this->status,
            currentOrderId: $this->current_order_id,
        );

        $dispatch = function () use ($event) {
            try {
                event($event);
            } catch (\Throwable) {
                // Reverb indisponible — le polling frontend compensera
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($dispatch);
        } else {
            $dispatch();
        }
    }
}
