<?php

// app/Services/KitchenDisplayService.php

namespace App\Services;

use App\Models\KitchenDisplay;
use App\Models\Order;

class KitchenDisplayService
{
    public function addToDisplay(Order $order): void
    {
        foreach ($order->items as $item) {
            KitchenDisplay::updateOrCreate(
                [
                    'restaurant_id' => $order->restaurant_id,
                    'order_id' => $order->id,
                ],
                [
                    'items' => $order->items->toArray(),
                    'status' => 'new',
                    'priority' => $this->calculatePriority($order),
                ]
            );
        }
    }

    private function calculatePriority(Order $order): int
    {
        // Les commandes avec plus d'items ont priorité
        $itemCount = $order->items->count();

        if ($itemCount > 5) {
            return 1;
        }  // Haute priorité
        if ($itemCount > 2) {
            return 2;
        }  // Priorité normale

        return 3;                       // Priorité basse
    }
}
