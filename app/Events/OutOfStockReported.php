<?php

namespace App\Events;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OutOfStockReported implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Order $order,
        public readonly OrderItem $item,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('restaurant.'.$this->order->restaurant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'order.item.out_of_stock';
    }

    public function broadcastWith(): array
    {
        $table = $this->order->table;

        return [
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'table_id' => $this->order->table_id,
            'table_number' => $table?->number,
            'item_id' => $this->item->id,
            'item_name' => $this->item->item_name,
            'message' => "Le plat {$this->item->item_name} est en rupture de stock.",
        ];
    }
}
