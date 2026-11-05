<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class KitchenOrderUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Order $order,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('kitchen.'.$this->order->restaurant_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'kitchen.order.updated';
    }

    public function broadcastWith(): array
    {
        $this->order->loadMissing(['items', 'table']);

        $items = $this->order->items;

        $kitchenStage = match ($this->order->status) {
            'ready' => 'ready',

            'in_progress' => (
                $items->contains(fn ($item) => in_array($item->kitchen_status, ['cooking', 'ready'], true)
                )
            ) ? 'preparing' : 'pending',

            default => null,
        };

        return [
            'id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'status' => $this->order->status,
            'table' => $this->order->table
                ? ['number' => $this->order->table->number]
                : null,
            'kitchen_stage' => $kitchenStage,

            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'item_name' => $item->item_name,
                'quantity' => $item->quantity,
                'kitchen_status' => $item->kitchen_status,
                'special_instructions' => $item->special_instructions,
                'started_at' => $item->started_at?->toISOString(),
                'ready_at' => $item->ready_at?->toISOString(),
            ])->values()->all(),
        ];
    }
}
