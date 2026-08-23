<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'owner_user_id',
        'name',
        'legal_name',
        'email',
        'phone',
        'address',
        'subscription_status',
        'plan_id',
        'trial_ends_at',
        'subscription_ends_at',
        'settings',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'settings' => 'array',
        'latitude' => 'float',
        'longitude' => 'float',
        'trial_ends_at' => 'datetime',
        'subscription_ends_at' => 'datetime',
    ];

    // --- Relations ---

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    // --- Helpers abonnement ---

    public function isOnTrial(): bool
    {
        return $this->subscription_status === 'trial'
            && $this->trial_ends_at
            && now()->lt($this->trial_ends_at);
    }

    public function isActive(): bool
    {
        return $this->subscription_status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->subscription_status === 'suspended';
    }

    /**
     * Le fournisseur est bloqué (suspendu ou annulé).
     */
    public function isBlocked(): bool
    {
        return in_array($this->subscription_status, ['suspended', 'cancelled']);
    }

    /**
     * Bloqué de fait : statut suspendu/annulé OU abonnement dont la date
     * d'expiration est dépassée (indépendamment de la tâche planifiée).
     */
    public function isEffectivelyBlocked(): bool
    {
        return $this->isBlocked() || $this->isSubscriptionExpired();
    }

    /**
     * Statut effectif exposé à l'API : 'suspended' si l'abonnement est expiré
     * par date même si le statut en base est encore 'active'/'trial'.
     */
    public function effectiveSubscriptionStatus(): string
    {
        if ($this->isBlocked()) {
            return $this->subscription_status;
        }
        return $this->isSubscriptionExpired() ? 'suspended' : $this->subscription_status;
    }

    /**
     * Vérifie si l'abonnement est expiré selon les dates, quel que soit le statut.
     */
    public function isSubscriptionExpired(): bool
    {
        if ($this->subscription_status === 'trial') {
            return $this->trial_ends_at && now()->gte($this->trial_ends_at);
        }
        if ($this->subscription_status === 'active') {
            return $this->subscription_ends_at && now()->gte($this->subscription_ends_at);
        }
        return false;
    }

    /**
     * Jours restants avant expiration (négatif si dépassé), null si pas de date.
     */
    public function daysUntilExpiration(): ?int
    {
        $end = $this->subscription_status === 'trial'
            ? $this->trial_ends_at
            : $this->subscription_ends_at;

        return $end ? (int) now()->diffInDays($end, false) : null;
    }

    public function canCreateProduct(): bool
    {
        if (!$this->plan || !$this->plan->max_products) return true;
        return $this->products()->count() < $this->plan->max_products;
    }

    public function canCreateCustomer(): bool
    {
        if (!$this->plan || !$this->plan->max_customers) return true;
        return $this->customers()->count() < $this->plan->max_customers;
    }

    /**
     * Notifie tous les clients actifs du fournisseur (leurs comptes utilisateurs).
     */
    public static function notifyClients(Supplier $supplier, $notification): void
    {
        $recipients = \App\Models\User::query()
            ->where('is_active', true)
            ->whereHas('customer', fn ($q) => $q
                ->where('supplier_id', $supplier->id)
                ->where('is_active', true))
            ->get();

        \Illuminate\Support\Facades\Notification::send($recipients, $notification);
    }

    public function quotaInfo(): array
    {
        $plan = $this->plan;
        return [
            'plan_name' => $plan?->name ?? 'Aucun',
            'plan_price' => $plan?->monthly_price ?? 0,
            'currency_symbol' => $plan?->currency_symbol ?? '€',
            'currency_position' => $plan?->currency_position ?? 'after',
            'status' => $this->subscription_status,
            'trial_ends_at' => $this->trial_ends_at,
            'subscription_ends_at' => $this->subscription_ends_at,
            'products_used' => $this->products()->count(),
            'products_max' => $plan?->max_products,
            'customers_used' => $this->customers()->count(),
            'customers_max' => $plan?->max_customers,
        ];
    }

    // --- Relations (suite) ---

    public function users()
    {
        return $this->belongsToMany(User::class, 'supplier_user')
            ->withPivot('role', 'is_active')
            ->withTimestamps();
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function customerProducts()
    {
        return $this->hasMany(CustomerProduct::class);
    }

    public function stockEntries()
    {
        return $this->hasMany(StockEntry::class);
    }

    public function stockAlerts()
    {
        return $this->hasMany(StockAlert::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}
