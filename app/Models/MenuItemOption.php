<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class MenuItemOption extends Model
{
    use HasFactory;

    public const TYPES = ['required_single', 'optional_single', 'optional_multi'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'menu_item_id', 'type', 'name', 'price_delta',
        'linked_menu_item_id', 'display_order', 'is_available',
    ];

    protected $casts = [
        'price_delta' => 'integer',
        'is_available' => 'boolean',
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

    public function menuItem()
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function linkedMenuItem()
    {
        return $this->belongsTo(MenuItem::class, 'linked_menu_item_id');
    }
}
