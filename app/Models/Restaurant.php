<?php

// app/Restaurant.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Restaurant extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'name', 'slug', 'email', 'phone', 'address', 'logo', 'ifu', 'rccm',
        'currency', 'timezone', 'settings', 'payment_gateways', 'status', 'trial_ends_at',
    ];

    protected $casts = [
        'settings' => 'array',
        'payment_gateways' => 'array',
        'trial_ends_at' => 'datetime',
    ];

    public const TRIAL_DAYS = 14;

    public function isTrialExpired(): bool
    {
        return $this->status === 'trial'
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isPast();
    }

    /** Slug unique dérivé du nom (« Chez Fatou » → chez-fatou, chez-fatou-2…). */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'restaurant';
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

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

    public function taxEnabled(): bool
    {
        return (bool) ($this->settings['tax_enabled'] ?? true);
    }

    public function serviceChargeEnabled(): bool
    {
        return (bool) ($this->settings['service_charge_enabled'] ?? true);
    }
}
