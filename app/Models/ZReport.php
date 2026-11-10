<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ZReport extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'restaurant_id', 'date', 'sequence_number', 'code',
        'snapshot', 'cash_counted', 'cash_difference',
        'closed_by', 'closed_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'closed_at' => 'datetime',
        'cash_counted' => 'integer',
        'cash_difference' => 'integer',
        'sequence_number' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($report) {
            if (empty($report->id)) {
                $report->id = (string) Str::uuid();
            }
        });
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
