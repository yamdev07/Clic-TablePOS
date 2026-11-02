<?php

// app/ActivityLog.php

namespace App\Models;

use App\Events\DashboardActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ActivityLog extends Model
{
    use HasFactory;

    protected $table = 'activity_logs';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'restaurant_id', 'user_id', 'user_name', 'action', 'description',
        'entity_type', 'entity_id', 'old_values', 'new_values', 'ip_address', 'user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($log) {
            if (empty($log->id)) {
                $log->id = (string) Str::uuid();
            }
        });

        // Diffuse chaque nouvelle ligne au tableau de bord. Un échec ne doit jamais bloquer le journal
        static::created(function ($log) {
            try {
                broadcast(new DashboardActivity($log->restaurant_id, $log->toFeedEntry()));
            } catch (\Throwable) {
                // Reverb indisponible : le frontend se rafraîchira à l'actualisation suivante
            }
        });
    }

    /** Les seuls champs du journal exposés au tableau de bord (jamais l'IP ni le navigateur). */
    public function toFeedEntry(): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'description' => $this->description,
            'user_name' => $this->user_name,
            'entity_type' => $this->entity_type,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
