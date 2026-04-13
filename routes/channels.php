<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Tous les canaux sont privés (nécessitent un token Sanctum valide).
|
| private-restaurant.{restaurantId}
|   → admins, managers et serveurs du restaurant (données commandes / paiements)
|
| private-kitchen.{restaurantId}
|   → cuisiniers et admins du restaurant (flux KDS)
|
*/

Broadcast::channel('restaurant.{restaurantId}', function ($user, string $restaurantId) {
    return $user->restaurant_id === $restaurantId
        && in_array($user->role, ['admin', 'manager', 'waiter']);
});

Broadcast::channel('kitchen.{restaurantId}', function ($user, string $restaurantId) {
    return $user->restaurant_id === $restaurantId
        && in_array($user->role, ['admin', 'kitchen']);
});
