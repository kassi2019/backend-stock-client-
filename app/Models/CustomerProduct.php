<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomerProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'product_id',
        'supplier_id',
        'initial_stock',
        'current_stock',
        'last_entry_at',
        'frequency',
        'reorder_point',
        'is_active',
    ];

    protected $casts = [
        'initial_stock' => 'decimal:2',
        'current_stock' => 'decimal:2',
        'reorder_point' => 'decimal:2',
        'last_entry_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    // --- Scopes ---

    public function scopeForSupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeForCustomer($query, $customerId)
    {
        return $query->where('customer_id', $customerId);
    }

    // --- Relations ---

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function stockEntries()
    {
        return $this->hasMany(StockEntry::class);
    }

    public function stockAlerts()
    {
        return $this->hasMany(StockAlert::class);
    }
}
