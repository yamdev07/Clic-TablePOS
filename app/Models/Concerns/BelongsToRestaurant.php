<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Isolation multi-tenant : le route model binding ne résout que les
 * enregistrements du restaurant de l'utilisateur connecté (404 sinon).
 *
 * Pas de global scope volontairement : il casserait l'authentification
 * Sanctum (User), les seeders et les jobs sans utilisateur connecté.
 */
trait BelongsToRestaurant
{
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->scopeForCurrentRestaurant(
            $this->where($field ?? $this->getRouteKeyName(), $value)
        )->firstOrFail();
    }

    /** Filtre une requête sur le restaurant de l'utilisateur connecté. */
    public function scopeForCurrentRestaurant(Builder $query): Builder
    {
        if (auth()->check()) {
            $query->where($this->getTable().'.restaurant_id', auth()->user()->restaurant_id);
        }

        return $query;
    }
}
