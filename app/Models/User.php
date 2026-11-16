<?php

// app/Models/User.php

namespace App\Models;

use App\Models\Concerns\BelongsToRestaurant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use BelongsToRestaurant, HasApiTokens, HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'restaurant_id', 'name', 'email', 'password',
        'role', 'permissions', 'is_active', 'avatar', 'phone', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'permissions' => 'array',
        'is_active' => 'boolean',
    ];

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    /** Raison pour laquelle ce compte ne peut pas utiliser l'application, ou null s'il le peut. */
    public function accessDenialReason(): ?string
    {
        if (! $this->is_active) {
            return 'Votre compte a été désactivé. Contactez votre administrateur.';
        }

        if ($this->restaurant?->status === 'suspended') {
            return 'Ce restaurant est suspendu. Contactez le support Clic&Table.';
        }

        if ($this->restaurant?->isTrialExpired()) {
            return "La période d'essai de ce restaurant est terminée. Contactez le support Clic&Table pour activer votre abonnement.";
        }

        return null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'super_admin';
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
