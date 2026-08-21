<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_product_id',
        'product_id',
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

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    // --- Alertes entrepôt ---

    /**
     * Crée ou résout l'alerte de stock entrepôt d'un produit.
     *
     * IMPORTANT : doit être appelé sous le verrou de la ligne products
     * (lockForUpdate dans une DB::transaction) — c'est ce verrou qui rend
     * l'anti-doublon fiable : deux écritures concurrentes se sérialisent.
     */
    public static function syncWarehouseAlert(Product $product): void
    {
        $stock = floatval($product->stock_quantity);
        $threshold = $product->stock_threshold;
        $isBelow = $threshold !== null && $stock <= floatval($threshold);
        $isNegative = $stock < 0;
        $active = $isBelow || $isNegative;

        if (!$active) {
            // Retour au-dessus du seuil : l'alerte n'a plus lieu d'être
            self::where('product_id', $product->id)
                ->where('type', 'warehouse_stock')
                ->whereNull('resolved_at')
                ->update(['resolved_at' => now()]);
            return;
        }

        $exists = self::where('product_id', $product->id)
            ->where('type', 'warehouse_stock')
            ->whereNull('resolved_at')
            ->exists();
        if ($exists) {
            return;
        }

        self::create([
            'product_id' => $product->id,
            'supplier_id' => $product->supplier_id,
            'customer_product_id' => null,
            'type' => 'warehouse_stock',
            'severity' => $isNegative ? 'critical' : 'warning',
            'message' => $isNegative
                ? "Le stock entrepôt de « {$product->name} » est négatif : " . self::fmt($stock) . " {$product->unit}."
                : "Le stock entrepôt de « {$product->name} » est sous le seuil : " . self::fmt($stock) . " {$product->unit} (seuil " . self::fmt(floatval($threshold)) . ").",
        ]);
    }

    /**
     * Nombre lisible sans zéros superflus : 5.00 → « 5 », 5.50 → « 5.5 ».
     */
    private static function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
