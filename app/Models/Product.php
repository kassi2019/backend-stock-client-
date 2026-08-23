<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'supplier_id',
        'name',
        'sku',
        'unit',
        'category',
        'image_path',
        'price',
        'stock_quantity',
        'stock_threshold',
        'pack_size',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'price' => 'decimal:2',
        'stock_quantity' => 'decimal:2',
        'stock_threshold' => 'decimal:2',
        'pack_size' => 'integer',
    ];

    // --- Helpers ---

    /**
     * Convertit une quantité saisie en paquets vers l'unité de base (ex. 2 paquets × 6 → 12).
     * Refuse si le produit n'a pas de conditionnement défini.
     */
    public function toBaseUnits(float $quantity): float
    {
        if (!$this->pack_size) {
            throw ValidationException::withMessages([
                'quantity' => ["Ce produit n'a pas de conditionnement en paquets défini."],
            ]);
        }
        return $quantity * $this->pack_size;
    }

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
