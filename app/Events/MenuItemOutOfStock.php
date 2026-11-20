<?php

namespace App\Events;

use App\Models\MenuItem;
use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MenuItemOutOfStock implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly MenuItem $menuItem,
        public readonly Order $order,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('restaurant.'.$this->order->restaurant_id),
        ];
    }

    public function broadcastAs(): string
    {
              return 'menu.item.out_of_stock';
    }

    public function broadcastWith(): array
    {
        return [
            'menu_item_id' => $this->menuItem->id,
            'menu_item_name' => $this->menuItem->name,
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'table_number' => $this->order->table?->number,
            'message' => "Le plat {$this->menuItem->name} est en rupture. La commande #{$this->order->order_number} doit être modifiée ou annulée.",
        ];
    }
}
