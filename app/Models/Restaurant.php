<?php
// app/Restaurant.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Restaurant extends Model
{
    use HasFactory;
    
    public $incrementing = false;
    protected $keyType = 'string';
    
    protected $fillable = [
        'id', 'name', 'slug', 'email', 'phone', 'address', 'logo',
        'currency', 'timezone', 'settings', 'payment_gateways', 'status', 'trial_ends_at'
    ];
    
    protected $casts = [
        'settings' => 'array',
        'payment_gateways' => 'array',
    ];
    
    public function users()
    {
        return $this->hasMany(User::class);
    }
    
    public function tables()
    {
        return $this->hasMany(Table::class);
    }
    
    public function categories()
    {
        return $this->hasMany(Category::class);
    }
    
    public function menuItems()
    {
        return $this->hasMany(MenuItem::class);
    }
    
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}