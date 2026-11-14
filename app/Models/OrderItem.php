<?php

// app/OrderItem.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OrderItem extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'order_id', 'menu_item_id', 'item_name', 'item_description',
        'quantity', 'unit_price', 'total_price', 'kitchen_status',
        'special_instructions', 'modifiers',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($item) {
            if (empty($item->id)) {
                $item->id = (string) Str::uuid();
            }
        });
    }

    /** Isolation multi-tenant via la commande parente (pas de restaurant_id ici). */
    public function resolveRouteBinding($value, $field = null)
    {
        $query = $this->where($field ?? $this->getRouteKeyName(), $value);

        if (auth()->check()) {
            $restaurantId = auth()->user()->restaurant_id;
            $query->whereHas('order', fn ($q) => $q->where('restaurant_id', $restaurantId));
        }

        return $query->firstOrFail();
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function markAsCooking()
    {
        $this->update(['kitchen_status' => 'cooking']);
    }

    public function markAsReady()
    {
        $this->update(['kitchen_status' => 'ready']);
    }

    public function markAsServed()
    {
        $this->update(['kitchen_status' => 'served']);
    }
}
