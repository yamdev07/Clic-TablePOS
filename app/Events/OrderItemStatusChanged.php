<?php

namespace App\Events;

use App\Models\OrderItem;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Diffusé quand le statut d'un item de commande change en cuisine.
 * Canal : private-kitchen.{restaurant_id}
 */
class OrderItemStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly OrderItem $item,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('kitchen.' . $this->item->order->restaurant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'item.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'id'            => $this->item->id,
            'order_id'      => $this->item->order_id,
            'item_name'     => $this->item->item_name,
            'quantity'      => $this->item->quantity,
            'kitchen_status'=> $this->item->kitchen_status,
            'special_instructions' => $this->item->special_instructions,
            'order' => [
                'id'           => $this->item->order->id,
                'order_number' => $this->item->order->order_number,
                'table'        => $this->item->order->table
                    ? ['number' => $this->item->order->table->number]
                    : null,
            ],
        ];
    }
}
