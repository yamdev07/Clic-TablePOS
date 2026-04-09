<?php
// app/MenuItem.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MenuItem extends Model
{
    use HasFactory;
    
    public $incrementing = false;
   protected $keyType = 'string';
    
    protected $fillable = [
        'id', 'restaurant_id', 'category_id', 'name', 'description', 'price', 'cost',
        'image', 'preparation_time', 'is_available', 'is_active', 'is_recommended',
        'display_order', 'modifiers', 'taxes'
    ];
    
    protected $casts = [
        'price' => 'integer',
        'is_available' => 'boolean',
        'modifiers' => 'array',
    ];
    
    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
    
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    
    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
}