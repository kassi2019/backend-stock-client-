<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'supplier_id',
        'name',
        'sku',
        'unit',
        'category',
        'price',
        'stock_quantity',
        'stock_threshold',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'decimal:2',
        'stock_quantity' => 'decimal:2',
        'stock_threshold' => 'decimal:2',
    ];

    // --- Scopes ---

    public function scopeForSupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    // --- Relations ---

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function customerProducts()
    {
        return $this->hasMany(CustomerProduct::class);
    }

    public function warehouseStockEntries()
    {
        return $this->hasMany(WarehouseStockEntry::class);
    }
}
