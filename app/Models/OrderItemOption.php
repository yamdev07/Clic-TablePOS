<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class OrderItemOption extends Model
{
    use HasFactory;

    public const SOURCE_MENU_OPTION = 'menu_option';

    public const SOURCE_SUPPLEMENT = 'supplement';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'order_item_id', 'source_type', 'source_id',
        'name', 'price_delta', 'quantity',
    ];

    protected $casts = [
        'price_delta' => 'integer',
        'quantity' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($option) {
            if (empty($option->id)) {
                $option->id = (string) Str::uuid();
            }
        });
    }

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}
