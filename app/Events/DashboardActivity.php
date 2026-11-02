<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Nouvelle ligne du journal d'activité.
 * Canal : private-dashboard.{restaurant_id} (admin et gérant uniquement).
 */
class DashboardActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $restaurantId,
        public array $entry,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('dashboard.'.$this->restaurantId)];
    }

    public function broadcastAs(): string
    {
        return 'activity.logged';
    }

    public function broadcastWith(): array
    {
        return $this->entry;
    }
}
