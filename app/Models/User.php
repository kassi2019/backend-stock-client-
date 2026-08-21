<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasRoles;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'is_active',
        'last_login_at',
        'last_login_ip',
        'avatar_path',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    /**
     * Le fournisseur propriétaire de ce compte (si role = supplier_owner)
     */
    public function ownedSupplier()
    {
        return $this->hasOne(Supplier::class, 'owner_user_id');
    }

    /**
     * Les fournisseurs où cet utilisateur est collaborateur
     */
    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class, 'supplier_user')
            ->withPivot('role', 'is_active')
            ->withTimestamps();
    }

    /**
     * Le client final lié à ce compte (si role = client)
     */
    public function customer()
    {
        return $this->hasOne(Customer::class, 'owner_user_id');
    }

    /**
     * Vérifie si l'utilisateur est un admin plateforme
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Vérifie si l'utilisateur est un admin plateforme (principal ou secondaire)
     */
    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->hasRole('admin');
    }

    /**
     * Vérifie si l'utilisateur est un fournisseur (owner ou staff)
     */
    public function isSupplier(): bool
    {
        return $this->hasRole('supplier_owner') || $this->hasRole('supplier_staff');
    }

    /**
     * Vérifie si l'utilisateur est un client final
     */
    public function isClient(): bool
    {
        return $this->hasRole('client');
    }

    /**
     * Retourne le supplier_id de l'utilisateur connecté
     */
    public function getSupplierId(): ?int
    {
        if ($this->isAdmin()) {
            return null; // admin a accès à tout
        }
        if ($this->ownedSupplier) {
            return $this->ownedSupplier->id;
        }
        // Pour un staff, prendre le premier supplier actif
        $supplierUser = $this->suppliers()->wherePivot('is_active', true)->first();
        return $supplierUser ? $supplierUser->id : null;
    }
}
