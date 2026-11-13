<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CashMovement extends Model
{
    use HasFactory;

    public const TYPES = ['in', 'out'];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'cash_session_id', 'type', 'amount', 'reason', 'created_by',
    ];

    protected $casts = [
        'amount' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($movement) {
            if (empty($movement->id)) {
                $movement->id = (string) Str::uuid();
            }
        });
    }

    public function cashSession()
    {
        return $this->belongsTo(CashSession::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
