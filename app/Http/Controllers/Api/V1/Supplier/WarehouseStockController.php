<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\WarehouseStockEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseStockController extends Controller
{
    /**
     * Stock entrepôt du fournisseur : produits + résumé + alertes ouvertes.
     */
    public function index(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $products = Product::forSupplier($supplierId)
            ->orderBy('name')
            ->get();

        $openAlerts = StockAlert::forSupplier($supplierId)
            ->where('type', 'warehouse_stock')
            ->unresolved()
            ->with('product:id,name,unit')
            ->orderBy('created_at', 'desc')
            ->get();

        $items = $products->map(function ($p) {
            $stock = floatval($p->stock_quantity);
            $threshold = $p->stock_threshold === null ? null : floatval($p->stock_threshold);
            return [
                'id' => $p->id,
                'name' => $p->name,
                'unit' => $p->unit,
                'sku' => $p->sku,
                'stock_quantity' => $stock,
                'stock_threshold' => $threshold,
                'is_low' => $threshold !== null && $stock <= $threshold,
                'is_negative' => $stock < 0,
            ];
        });

        return response()->json([
            'summary' => [
                'total_products' => $products->count(),
                'low_count' => $items->filter(fn($it) => $it['is_low'])->count(),
                'negative_count' => $items->filter(fn($it) => $it['is_negative'])->count(),
            ],
            'products' => $items,
            'alerts' => $openAlerts->map(fn($a) => [
                'id' => $a->id,
                'severity' => $a->severity,
                'message' => $a->message,
                'product_name' => $a->product?->name,
                'created_at' => $a->created_at?->toISOString(),
            ]),
        ]);
    }

    /**
     * Réception de marchandises : stock += quantité.
     */
    public function receive(Request $request, $productId)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $request->validate([
            'quantity' => 'required|numeric|gt:0|max:99999999.99',
            'note' => 'nullable|string|max:500',
        ]);

        Product::forSupplier($supplierId)->findOrFail($productId);

        $product = DB::transaction(function () use ($supplierId, $productId, $request) {
            return WarehouseStockEntry::applyReceipt(
                $supplierId,
                (int) $productId,
                floatval($request->quantity),
                $request->user()->id,
                $request->note ?: 'Réception manuelle',
            );
        });

        return response()->json([
            'message' => 'Réception enregistrée. Stock entrepôt mis à jour.',
            'product' => $this->formatProduct($product),
        ], 201);
    }

    /**
     * Correction du total : le stock entrepôt est fixé à la valeur saisie.
     */
    public function adjust(Request $request, $productId)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $request->validate([
            'quantity' => 'required|numeric|min:-99999999.99|max:99999999.99',
            'note' => 'required|string|min:3|max:500',
        ]);

        Product::forSupplier($supplierId)->findOrFail($productId);

        $product = DB::transaction(function () use ($supplierId, $productId, $request) {
            return WarehouseStockEntry::applyAdjustment(
                $supplierId,
                (int) $productId,
                floatval($request->quantity),
                $request->user()->id,
                $request->note,
            );
        });

        return response()->json([
            'message' => "Stock entrepôt ajusté à {$this->fmt(floatval($product->stock_quantity))} {$product->unit}.",
            'product' => $this->formatProduct($product),
        ]);
    }

    /**
     * Historique des mouvements d'un produit.
     */
    public function history(Request $request, $productId)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $product = Product::forSupplier($supplierId)->findOrFail($productId);

        $entries = WarehouseStockEntry::forSupplier($supplierId)
            ->forProduct($productId)
            ->with('enteredBy:id,name')
            ->orderBy('entry_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate(50);

        $entries->getCollection()->transform(function ($e) {
            return [
                'id' => $e->id,
                'quantity' => floatval($e->quantity),
                'entry_type' => $e->entry_type,
                'note' => $e->note,
                'entered_by' => $e->enteredBy?->name,
                'entry_date' => $e->entry_date->format('Y-m-d'),
                'created_at' => $e->created_at->format('H:i'),
            ];
        });

        return response()->json([
            'current_stock' => floatval($product->stock_quantity),
            'entries' => $entries,
        ]);
    }

    /**
     * Représentation JSON commune d'un produit (stock entrepôt).
     */
    private function formatProduct(Product $product): array
    {
        $stock = floatval($product->stock_quantity);
        $threshold = $product->stock_threshold === null ? null : floatval($product->stock_threshold);
        return [
            'id' => $product->id,
            'name' => $product->name,
            'unit' => $product->unit,
            'stock_quantity' => $stock,
            'stock_threshold' => $threshold,
            'is_low' => $threshold !== null && $stock <= $threshold,
            'is_negative' => $stock < 0,
        ];
    }

    /**
     * Nombre lisible sans zéros superflus : 5.00 → « 5 ».
     */
    private function fmt(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
