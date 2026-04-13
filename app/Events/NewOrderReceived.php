<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Diffusé quand une commande est envoyée en cuisine.
 * Canal : private-kitchen.{restaurant_id}  (cuisine)
 *       + private-restaurant.{restaurant_id} (admin / manager)
 */
class NewOrderReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Order $order,
    ) {}

    public function broadcastOn(): array
    {
        $restaurantId = $this->order->restaurant_id;

        return [
            new PrivateChannel('kitchen.' . $restaurantId),
            new PrivateChannel('restaurant.' . $restaurantId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.received';
    }

    public function broadcastWith(): array
    {
        $order = $this->order->load(['table', 'items']);

        return [
            'id'           => $order->id,
            'order_number' => $order->order_number,
            'status'       => $order->status,
            'table'        => $order->table
                ? ['id' => $order->table->id, 'number' => $order->table->number]
                : null,
            'items' => $order->items->map(fn ($i) => [
                'id'                   => $i->id,
                'item_name'            => $i->item_name,
                'quantity'             => $i->quantity,
                'kitchen_status'       => $i->kitchen_status,
                'special_instructions' => $i->special_instructions,
            ]),
        ];
    }
}
