<?php

// app/Models/Order.php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Order extends Model
{
    use BelongsToRestaurant, HasFactory;

    /** Une commande open est un brouillon : elle n'est pas active. */
    public const ACTIVE_STATUSES = ['in_progress', 'ready', 'served'];

    /** Un plat servi n'apparaît plus à l'écran de la cuisine, sauf s'il est signalé en rupture (out_of_stock_at). */
    public const KITCHEN_VISIBLE_ITEM_STATUSES = ['pending', 'cooking', 'ready'];

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
        'completed_at' => 'datetime',
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

    /**
     * Fait avancer la commande selon l'état de ses plats en cuisine :
     *   open → in_progress (dès qu'un plat est lancé par le cuisinier)
     *   → ready (tous les plats prêts ou servis)
     *   → served (tous les plats servis).
     * Un plat ajouté à une commande prête/servie la renvoie « en préparation ».
     * Ne revient jamais à « open » et ne touche pas aux commandes payées / annulées.
     * Retourne l'ancien statut si la commande a changé, sinon null.
     */
    public function syncStatusFromItems(): ?string
    {
        if (! in_array($this->status, ['open', 'in_progress', 'ready', 'served'], true)) {
            return null;
        }

        $statuses = $this->items()->pluck('kitchen_status');
        if ($statuses->isEmpty()) {
            return null;
        }

        $target = match (true) {
            $statuses->every(fn ($s) => $s === 'served') => 'served',
            $statuses->every(fn ($s) => in_array($s, ['ready', 'served'], true)) => 'ready',
            $statuses->contains(fn ($s) => $s !== 'pending') => 'in_progress',
            $this->status === 'open' => 'open',
            default => 'in_progress', // nouveau plat sur une commande prête/servie
        };

        if ($target === $this->status) {
            return null;
        }

        $old = $this->status;
        $this->update(['status' => $target]);

        return $old;
    }

        /** Étape à l'écran de la cuisine : pending, preparing, ready, ou null si la commande n'y figure pas. */
    public function kitchenStage(): ?string
    {
        // Sans plat à montrer à la cuisine (commande vidée par le serveur, plats tous servis),
        // pas de carte. Même règle que toKitchenArray() et que /kitchen/pending.
        $hasDishForKitchen = $this->items->contains(
            fn ($item) => $item->out_of_stock_at !== null
                || in_array($item->kitchen_status, self::KITCHEN_VISIBLE_ITEM_STATUSES, true)
        );

        if (! $hasDishForKitchen) {
            return null;
        }

        if ($this->status === 'ready') {
            return 'ready';
        }

        // Envoyée en cuisine mais pas encore lancée : elle reste « open » avec confirmed_at.
        // Un brouillon (open sans confirmed_at) n'apparaît pas à l'écran de la cuisine.
        if ($this->status === 'open') {
            return $this->confirmed_at !== null ? 'pending' : null;
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
        $this->loadMissing(['items.menuItem', 'items.options', 'table']);

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
            'type' => $this->type,
                        'guest_count' => $this->guest_count !== null ? (int) $this->guest_count : null,
            'confirmed_at' => $this->confirmed_at?->toISOString(),
            'table' => $table ? ['number' => $table->number] : null,
            'kitchen_stage' => $this->kitchenStage(),
            'estimated_prep_time' => (int) $items
                ->filter(fn ($item) => $item->out_of_stock_at === null)
                ->sum(fn ($item) => $item->menuItem?->preparation_time ?? 0),
            'items' => $items->map(fn ($item) => [
                'id' => $item->id,
                'item_name' => $item->item_name,
                'quantity' => $item->quantity,
                'kitchen_status' => $item->kitchen_status,
                'out_of_stock' => $item->out_of_stock_at !== null,
                'special_instructions' => $item->special_instructions,
                'started_at' => $item->started_at?->toISOString(),
                'ready_at' => $item->ready_at?->toISOString(),
                'preparation_time' => $item->menuItem?->preparation_time,
                'options' => $item->options->map(fn ($opt) => [
                    'name' => $opt->name,
                    'price_delta' => $opt->price_delta,
                    'source_type' => $opt->source_type,
                ])->all(),
            ])->all(),
        ];
    }

    public function recalculate(): void
    {
        $subtotal = $this->items()
            ->whereNull('out_of_stock_at')
            ->sum('total_price');

        $restaurant = $this->restaurant()->first();
        $taxEnabled = $restaurant?->taxEnabled() ?? true;
        $serviceEnabled = $restaurant?->serviceChargeEnabled() ?? true;

        $tax = $taxEnabled ? (int) ($subtotal * 0.18) : 0;
        $serviceCharge = $serviceEnabled ? (int) ($subtotal * 0.05) : 0;
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
            $updates = ['status' => 'paid'];
            if ($this->completed_at === null) {
                $updates['completed_at'] = now();
            }
            $this->update($updates);
            $this->table()->first()?->syncCurrentOrder();
        }
    }
}
