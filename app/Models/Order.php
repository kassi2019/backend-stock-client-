<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'customer_id',
        'status',
        'note',
        'rejection_reason',
        'created_by_user_id',
        'accepted_by_user_id',
        'accepted_at',
        'rejected_by_user_id',
        'rejected_at',
        'cancelled_by_user_id',
        'cancelled_at',
        'delivered_by_user_id',
        'delivered_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    // --- Statuts ---

    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';
    public const DELIVERED = 'delivered';

    /**
     * Transitions autorisées par statut courant.
     */
    public const ALLOWED = [
        self::PENDING => [self::ACCEPTED, self::REJECTED, self::CANCELLED],
        self::ACCEPTED => [self::DELIVERED],
        self::REJECTED => [],
        self::CANCELLED => [],
        self::DELIVERED => [],
    ];

    // --- Scopes (cloisonnement par colonne, comme les autres modèles) ---

    public function scopeForSupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    // --- Relations ---

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function acceptedBy()
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function deliveredBy()
    {
        return $this->belongsTo(User::class, 'delivered_by_user_id');
    }

    // --- Helpers ---

    /**
     * Transition atomique : doit être appelée dans une DB::transaction
     * avec verrouillage (lockForUpdate) sur la commande.
     */
    public function transitionTo(string $to, ?User $actor, ?string $reason = null): bool
    {
        if (!in_array($to, self::ALLOWED[$this->status] ?? [], true)) {
            return false;
        }

        $this->status = $to;
        $this->rejection_reason = $to === self::REJECTED ? $reason : null;

        $now = now();
        match ($to) {
            self::ACCEPTED => $this->fill(['accepted_by_user_id' => $actor?->id, 'accepted_at' => $now]),
            self::REJECTED => $this->fill(['rejected_by_user_id' => $actor?->id, 'rejected_at' => $now]),
            self::CANCELLED => $this->fill(['cancelled_by_user_id' => $actor?->id, 'cancelled_at' => $now]),
            self::DELIVERED => $this->fill(['delivered_by_user_id' => $actor?->id, 'delivered_at' => $now]),
        };

        return $this->save();
    }

    /**
     * Notifie l'équipe fournisseur (propriétaire + collaborateurs actifs), sans doublon.
     */
    public static function notifySupplierTeam(Supplier $supplier, $notification): void
    {
        $recipients = collect([$supplier->owner])
            ->concat($supplier->users()->wherePivot('is_active', true)->get())
            ->filter()
            ->unique('id');

        Notification::send($recipients, $notification);
    }
}
