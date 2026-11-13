<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CashSession extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'cash_register_id', 'status', 'opening_balance', 'opened_by', 'opened_at',
        'expected_balance', 'closing_balance', 'difference', 'report_snapshot', 'closed_by', 'closed_at',
    ];

    protected $casts = [
        'opening_balance' => 'integer',
        'expected_balance' => 'integer',
        'closing_balance' => 'integer',
        'report_snapshot' => 'array',
        'difference' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($session) {
            if (empty($session->id)) {
                $session->id = (string) Str::uuid();
            }
        });
    }

    public function cashRegister()
    {
        return $this->belongsTo(CashRegister::class);
    }

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function movements()
    {
        return $this->hasMany(CashMovement::class);
    }

    /**
     * Calcule le solde théorique d'une session.
     * = opening_balance + paiements cash completed + mouvements in - mouvements out.
     */
    public function expectedBalance(): int
    {
        $cashCollected = $this->payments()
            ->where('method', 'cash')
            ->where('status', 'completed')
            ->sum('amount');

        $in = $this->movements()->where('type', 'in')->sum('amount');
        $out = $this->movements()->where('type', 'out')->sum('amount');

        return (int) ($this->opening_balance + $cashCollected + $in - $out);
    }
}
