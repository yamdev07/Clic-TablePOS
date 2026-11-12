<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActivityCreated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public string $restaurantId,
        public string $logId,
        public string $action,
        public string $description,
        public ?string $userName,
        public ?string $entityType,
        public ?string $entityId,
        public string $createdAt,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('restaurant.'.$this->restaurantId)];
    }

    public function broadcastAs(): string
    {
        return 'activity.created';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->logId,
            'action' => $this->action,
            'description' => $this->description,
            'user_name' => $this->userName,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'created_at' => $this->createdAt,
        ];
    }
}
