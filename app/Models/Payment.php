<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id',
        'customer_id',
        'amount',
        'note',
        'paid_at',
        'entered_by_user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    // --- Scopes ---

    public function scopeForSupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    // --- Relations ---

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by_user_id');
    }
}
