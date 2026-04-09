<?php
// app/KitchenDisplay.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class KitchenDisplay extends Model
{
    use HasFactory;
    
    protected $table = 'kitchen_display';
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'id', 'restaurant_id', 'order_id', 'items', 'status', 'priority',
        'started_at', 'completed_at'
    ];
    
    protected $casts = [
        'items' => 'array',
    ];
    
    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($display) {
            if (empty($display->id)) {
                $display->id = (string) Str::uuid();
            }
        });
    }
    
    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
    
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}