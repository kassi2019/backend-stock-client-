<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseStockEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'supplier_id',
        'quantity',
        'note',
        'entry_type',
        'entered_by_user_id',
        'entry_date',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'entry_date' => 'date',
    ];

    // --- Scopes ---

    public function scopeForSupplier($query, $supplierId)
    {
        return $query->where('supplier_id', $supplierId);
    }

    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    // --- Relations ---

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by_user_id');
    }

    // --- Helpers de mouvement (un seul lieu d'écriture du stock entrepôt) ---
    //
    // À appeler DANS une DB::transaction déjà ouverte par l'appelant : le
    // lockForUpdate est acquis sur la même connexion, donc le verrou tient.
    // L'écriture passe par bcadd/bcsub (string) pour éviter la dérive float.

    /**
     * Livraison à un client : déduit la quantité du stock entrepôt.
     * withTrashed : une commande passée doit toujours déduire, même si le
     * produit a été archivé entre-temps. Le stock peut devenir négatif.
     */
    public static function applyDelivery(int $supplierId, int $productId, float $quantity, ?int $userId, string $note): Product
    {
        $product = Product::withTrashed()
            ->where('id', $productId)
            ->where('supplier_id', $supplierId)
            ->lockForUpdate()
            ->firstOrFail();

        $product->stock_quantity = bcsub((string) $product->stock_quantity, self::num($quantity), 2);
        $product->save();

        self::create([
            'product_id' => $product->id,
            'supplier_id' => $supplierId,
            'quantity' => '-' . self::num($quantity),
            'note' => $note,
            'entry_type' => 'delivery',
            'entered_by_user_id' => $userId,
            'entry_date' => now()->toDateString(),
        ]);

        StockAlert::syncWarehouseAlert($product);

        return $product;
    }

    /**
     * Réception de marchandises : ajoute la quantité au stock entrepôt.
     */
    public static function applyReceipt(int $supplierId, int $productId, float $quantity, ?int $userId, ?string $note): Product
    {
        $product = Product::where('id', $productId)
            ->where('supplier_id', $supplierId)
            ->lockForUpdate()
            ->firstOrFail();

        $product->stock_quantity = bcadd((string) $product->stock_quantity, self::num($quantity), 2);
        $product->save();

        self::create([
            'product_id' => $product->id,
            'supplier_id' => $supplierId,
            'quantity' => self::num($quantity),
            'note' => $note,
            'entry_type' => 'receipt',
            'entered_by_user_id' => $userId,
            'entry_date' => now()->toDateString(),
        ]);

        StockAlert::syncWarehouseAlert($product);

        return $product;
    }

    /**
     * Correction du total : fixe le stock à la valeur indiquée.
     * L'entrée enregistre le delta signé pour garder l'invariant
     * stock_quantity = SUM(quantity).
     */
    public static function applyAdjustment(int $supplierId, int $productId, float $newTotal, int $userId, string $note): Product
    {
        $product = Product::where('id', $productId)
            ->where('supplier_id', $supplierId)
            ->lockForUpdate()
            ->firstOrFail();

        $delta = bcsub(self::num($newTotal), (string) $product->stock_quantity, 2);
        $product->stock_quantity = self::num($newTotal);
        $product->save();

        self::create([
            'product_id' => $product->id,
            'supplier_id' => $supplierId,
            'quantity' => $delta,
            'note' => $note,
            'entry_type' => 'adjustment',
            'entered_by_user_id' => $userId,
            'entry_date' => now()->toDateString(),
        ]);

        StockAlert::syncWarehouseAlert($product);

        return $product;
    }

    /**
     * Nombre flottant au format string 2 décimales pour bcmath.
     */
    private static function num(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
