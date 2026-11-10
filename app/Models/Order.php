<?php

// app/Models/Order.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    /** Une commande open est un brouillon : elle n'est pas active. */
    public const ACTIVE_STATUSES = ['in_progress', 'ready', 'served'];

    /** Un plat servi n'apparaît plus à l'écran de la cuisine, sauf s'il est signalé en rupture (out_of_stock_at). */
    public const KITCHEN_VISIBLE_ITEM_STATUSES = ['pending', 'cooking', 'ready'];

    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'restaurant_id', 'table_id', 'pending_collection_request_id', 'user_id', 'order_number', 'status', 'type', 'guest_count',
        'subtotal', 'tax', 'service_charge', 'discount', 'total',
        'paid_amount', 'due_amount', 'notes', 'metadata',
        'confirmed_at', 'completed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'confirmed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->id)) {
                $order->id = (string) Str::uuid();
            }
            if (empty($order->order_number)) {
                $order->order_number = 'ORD-'.date('Ymd').'-'.strtoupper(Str::random(6));
            }
        });
    }

    /**
     * Restreindre le route model binding au restaurant de l'utilisateur connecté.
     * Retourne 404 si l'order appartient à un autre restaurant.
     */
    public function resolveRouteBinding($value, $field = null): static
    {
        $query = $this->where($field ?? $this->getRouteKeyName(), $value);

        if (auth()->check()) {
            $query->where('restaurant_id', auth()->user()->restaurant_id);
        }

        return $query->firstOrFail();
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function table()
    {
        return $this->belongsTo(Table::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function collectionRequests()
    {
        return $this->belongsToMany(CollectionRequest::class, 'collection_request_order');
    }

    public function pendingCollectionRequest()
    {
        return $this->belongsTo(CollectionRequest::class, 'pending_collection_request_id');
    }

    /** Étape à l'écran de la cuisine : pending, preparing, ready, ou null si la commande n'y figure pas. */
    public function kitchenStage(): ?string
    {
        if ($this->status === 'ready') {
            return 'ready';
        }

        if ($this->status !== 'in_progress') {
            return null;
        }

        $started = $this->items->contains(
            fn ($item) => in_array($item->kitchen_status, ['cooking', 'ready'], true)
        );

        return $started ? 'preparing' : 'pending';
    }

    /** Description d'une commande pour l'écran de la cuisine (API et temps réel). Aucun montant. */
    public function toKitchenArray(): array
    {
        $this->loadMissing(['items', 'table']);

        // Dans le modèle, $this->table serait le nom de la table SQL : on lit la relation chargée
        $table = $this->getRelation('table');

        $items = $this->items
            ->filter(fn ($item) => $item->out_of_stock_at !== null
                || in_array($item->kitchen_status, self::KITCHEN_VISIBLE_ITEM_STATUSES, true)
            )
            ->sortBy(fn ($item) => $item->created_at->format('Y-m-d H:i:s').'|'.$item->id)
            ->values();

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'table' => $table ? ['number' => $table->number] : null,
            'kitchen_stage' => $this->kitchenStage(),
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'item_name' => $item->item_name,
                'quantity' => $item->quantity,
                'kitchen_status' => $item->kitchen_status,
                'out_of_stock' => $item->out_of_stock_at !== null,
                'special_instructions' => $item->special_instructions,
                'started_at' => $item->started_at?->toISOString(),
                'ready_at' => $item->ready_at?->toISOString(),
            ])->all(),
        ];
    }

    public function recalculate(): void
    {
        // Les plats signalés en rupture ne sont plus facturés.
        $subtotal = $this->items()
            ->whereNull('out_of_stock_at')
            ->sum('total_price');

        $tax = (int) ($subtotal * 0.18);
        $serviceCharge = (int) ($subtotal * 0.05);
        $total = $subtotal + $tax + $serviceCharge;

        $paid = $this->payments()
            ->where('status', 'completed')
            ->sum('amount');

        $due = $total - $paid;

        $this->update([
            'subtotal' => $subtotal,
            'tax' => $tax,
            'service_charge' => $serviceCharge,
            'total' => $total,
            'paid_amount' => $paid,
            'due_amount' => $due,
        ]);

        if ($total > 0 && $due <= 0 && $this->status === 'served') {
            $this->update(['status' => 'paid']);
            $this->table()->first()?->syncCurrentOrder();
        }
    }
}
