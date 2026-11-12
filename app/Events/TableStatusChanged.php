<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TableStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $restaurantId,
        public string $tableId,
        public string $number,
        public string $status,
        public ?string $currentOrderId,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('restaurant.'.$this->restaurantId)];
    }

    public function broadcastAs(): string
    {
        return 'table.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'table_id' => $this->tableId,
            'number' => $this->number,
            'status' => $this->status,
            'current_order_id' => $this->currentOrderId,
        ];
    }
}
