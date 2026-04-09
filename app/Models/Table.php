<?php
// app/Table.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Table extends Model
{
    use HasFactory;
    
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'id', 'restaurant_id', 'number', 'name', 'capacity', 'status',
        'current_order_id', 'x_position', 'y_position', 'qr_code', 'metadata'
    ];
    
    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }
    
    public function currentOrder()
    {
        return $this->belongsTo(Order::class, 'current_order_id');
    }
    
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
    
    public function isFree()
    {
        return $this->status === 'free';
    }
    
    public function occupy(Order $order)
    {
        $this->update([
            'status' => 'occupied',
            'current_order_id' => $order->id
        ]);
    }
    
    public function free()
    {
        $this->update([
            'status' => 'free',
            'current_order_id' => null
        ]);
    }
}