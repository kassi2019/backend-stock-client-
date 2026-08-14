<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use App\Notifications\ProductNotification;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $supplier = Supplier::find($supplierId);

        $products = Product::forSupplier($supplierId)
            ->orderBy('name')
            ->get();

        return response()->json([
            'products' => $products,
            // Même devise que les forfaits (affichage des prix)
            'currency' => [
                'code' => $supplier?->plan?->currency ?? 'EUR',
                'symbol' => $supplier?->plan?->currency_symbol ?? '€',
                'position' => $supplier?->plan?->currency_position ?? 'after',
            ],
        ]);
    }

    public function store(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $supplier = \App\Models\Supplier::find($supplierId);

        if (!$supplier->canCreateProduct()) {
            $limit = $supplier->plan->max_products;
            return response()->json([
                'message' => "Quota atteint : maximum {$limit} produits (plan {$supplier->plan->name})."
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:191',
            'unit' => 'required|string|max:30',
            'sku' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'price' => 'nullable|numeric|min:0|max:99999999.99',
        ]);

        $product = Product::create([
            'supplier_id' => $supplierId,
            'name' => $request->name,
            'unit' => $request->unit,
            'sku' => $request->sku,
            'category' => $request->category,
            'price' => $request->price,
        ]);

        // Nouveau produit au catalogue (avec prix) : notifier tous les clients
        if ($request->price !== null) {
            Supplier::notifyClients($supplier, new ProductNotification(
                'product_created',
                $product->id,
                'Nouveau produit au catalogue',
                "Le produit « {$product->name} » est disponible au prix de {$this->formatPrice($supplier, $product->price)}.",
            ));
        }

        return response()->json($product, 201);
    }

    public function show(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $product = Product::forSupplier($supplierId)->findOrFail($id);

        return response()->json($product);
    }

    public function update(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $supplier = \App\Models\Supplier::find($supplierId);
        $product = Product::forSupplier($supplierId)->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:191',
            'unit' => 'sometimes|string|max:30',
            'sku' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:100',
            'price' => 'nullable|numeric|min:0|max:99999999.99',
            'is_active' => 'sometimes|boolean',
        ]);

        // Comparaison stricte : null distinct de 0, et 5 == 5.00 (floatval)
        $oldPrice = $product->price === null ? null : floatval($product->price);
        $newPrice = $request->input('price') === null ? null : floatval($request->input('price'));
        $priceChanged = $oldPrice !== $newPrice;

        $product->update($request->only(['name', 'unit', 'sku', 'category', 'price', 'is_active']));

        // Changement de prix sur un produit visible : notifier tous les clients
        if ($priceChanged && $product->is_active) {
            $symbol = $supplier?->plan?->currency_symbol ?? '€';
            $position = $supplier?->plan?->currency_position ?? 'after';

            if ($newPrice === null) {
                $message = "Le prix de « {$product->name} » a été retiré du catalogue.";
            } elseif ($oldPrice === null) {
                $message = "Le prix de « {$product->name} » est maintenant de {$this->formatPrice($supplier, $newPrice)}.";
            } else {
                $message = "Le prix de « {$product->name} » passe de "
                    . ProductNotification::formatMoney($oldPrice, $symbol, $position)
                    . ' à ' . ProductNotification::formatMoney($newPrice, $symbol, $position) . '.';
            }

            Supplier::notifyClients($supplier, new ProductNotification(
                'price_updated',
                $product->id,
                "Prix mis à jour : {$product->name}",
                $message,
            ));
        }

        return response()->json($product);
    }

    /**
     * Formatage du prix avec la monnaie du forfait fournisseur.
     */
    private function formatPrice(?Supplier $supplier, $price): string
    {
        return ProductNotification::formatMoney(
            $price === null ? null : floatval($price),
            $supplier?->plan?->currency_symbol ?? '€',
            $supplier?->plan?->currency_position ?? 'after',
        );
    }

    public function destroy(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $product = Product::forSupplier($supplierId)->findOrFail($id);
        $product->delete();

        return response()->json(['message' => 'Produit supprimé.']);
    }
}
