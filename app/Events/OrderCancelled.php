<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Diffusé quand une commande est annulée avant le début de la préparation.
 * Canal : private-kitchen.{restaurant_id}
 * Le contenu ne porte aucun montant : la cuisine n'en a pas besoin.
 */
class OrderCancelled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Order $order,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('kitchen.' . $this->order->restaurant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.cancelled';
    }

    public function broadcastWith(): array
    {
        return [
            'id'           => $this->order->id,
            'order_number' => $this->order->order_number,
            'table'        => $this->order->table
                ? ['id' => $this->order->table->id, 'number' => $this->order->table->number]
                : null,
        ];
    }
}
