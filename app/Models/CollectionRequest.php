<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CollectionRequest extends Model
{
    use HasFactory;

    public const STATUSES = ['pending', 'validated', 'cancelled'];

    public const METHODS = ['cash', 'mobile_money'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'restaurant_id', 'table_id', 'requested_by',
        'method', 'operator', 'phone_number', 'beneficiary_name',
        'status', 'validated_by', 'validated_at', 'cancelled_at',
    ];

    protected $casts = [
        'validated_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($request) {
            if (empty($request->id)) {
                $request->id = (string) Str::uuid();
            }
        });
    }

    /** Restriction au restaurant de l'utilisateur connecté (route model binding). */
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

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function validator()
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function orders()
    {
        return $this->belongsToMany(Order::class, 'collection_request_order');
    }

    /** Somme informative des due_amount — jamais stockée, recalculée à chaque lecture. */
    public function totalAmount(): int
    {
        return (int) $this->orders()->sum('due_amount');
    }
}
