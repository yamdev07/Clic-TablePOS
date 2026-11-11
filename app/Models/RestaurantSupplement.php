<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RestaurantSupplement extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'restaurant_id', 'name', 'price_delta', 'is_available', 'display_order',
    ];

    protected $casts = [
        'price_delta' => 'integer',
        'is_available' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($supplement) {
            if (empty($supplement->id)) {
                $supplement->id = (string) Str::uuid();
            }
        });
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
}
