<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\WarehouseStockEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalkInSaleController extends Controller
{
    /**
     * Vente comptoir (client sans compte) : le fournisseur saisit la vente,
     * le stock entrepôt diminue directement. Deux modes, comme côté client :
     * - sold_quantity      : quantité vendue
     * - remaining_quantity : quantité restante → vendu = stock actuel - restant
     */
    public function store(Request $request)
    {
        $request->validate([
            'product_id' => 'required|integer',
            'sold_quantity' => 'required_without:remaining_quantity|nullable|numeric|min:0',
            'remaining_quantity' => 'required_without:sold_quantity|nullable|numeric|min:0',
            'in_packs' => 'nullable|boolean',
        ]);

        $supplierId = $request->input('_tenant_supplier_id');

        $product = Product::where('id', $request->product_id)
            ->where('supplier_id', $supplierId)
            ->first();

        if (!$product) {
            return response()->json(['message' => 'Produit non trouvé.'], 404);
        }

        $currentStock = floatval($product->stock_quantity);
        $inPacks = $request->boolean('in_packs');

        if ($request->filled('remaining_quantity')) {
            if ($inPacks) {
                throw ValidationException::withMessages([
                    'in_packs' => [
                        'La saisie en paquets ne fonctionne qu\'avec le mode « Vendu ».',
                    ],
                ]);
            }

            $remaining = (float) $request->remaining_quantity;

            if ($remaining > $currentStock) {
                throw ValidationException::withMessages([
                    'remaining_quantity' => [
                        'La quantité restante saisie est supérieure au stock entrepôt (' . $currentStock . ').',
                    ],
                ]);
            }

            $sold = max(0, $currentStock - $remaining);
            if ($sold <= 0) {
                throw ValidationException::withMessages([
                    'remaining_quantity' => [
                        'La quantité restante saisie est égale au stock entrepôt : aucune vente à enregistrer.',
                    ],
                ]);
            }
        } else {
            $sold = (float) $request->sold_quantity;
            if ($sold <= 0) {
                throw ValidationException::withMessages([
                    'sold_quantity' => ['La quantité vendue doit être supérieure à 0.'],
                ]);
            }
            // Vente en paquets : conversion vers l'unité de base (ex. 1 paquet × 6 → 6)
            if ($inPacks) {
                $sold = $product->toBaseUnits($sold);
            }
        }

        $product = DB::transaction(function () use ($supplierId, $request, $sold) {
            return WarehouseStockEntry::applyWalkInSale(
                $supplierId,
                (int) $request->product_id,
                $sold,
                $request->user()->id,
                'Vente comptoir',
            );
        });

        return response()->json([
            'message' => 'Vente comptoir enregistrée.',
            'sold' => $sold,
            'stock_quantity' => floatval($product->stock_quantity),
        ], 201);
    }
}
