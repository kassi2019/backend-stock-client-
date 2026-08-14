<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_product_id',
        'supplier_id',
        'type',
        'severity',
        'message',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    // --- Scopes ---

    public function scopeForSupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }

    // --- Relations ---

    public function customerProduct()
    {
        return $this->belongsTo(CustomerProduct::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
