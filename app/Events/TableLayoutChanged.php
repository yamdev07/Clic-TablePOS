<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TableLayoutChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $restaurantId,
        public string $action,           // 'moved' | 'rotated' | 'model_changed'
        public ?string $tableId,
        public array $data,
        public ?string $triggeredBy = null,
        public ?string $triggeredByName = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('restaurant.'.$this->restaurantId)];
    }

    public function broadcastAs(): string
    {
        return 'table.layout.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'table_id' => $this->tableId,
            'data' => $this->data,
            'triggered_by' => $this->triggeredBy,
            'triggered_by_name' => $this->triggeredByName,
        ];
    }
}
