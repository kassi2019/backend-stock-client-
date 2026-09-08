<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\WarehouseStockEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalkInCreditSaleController extends Controller
{
    /**
     * Remise de produits à crédit au comptoir : le client se présente chez le
     * fournisseur et prend des produits sans passer de commande.
     *
     * Pour chaque produit :
     * - le stock entrepôt baisse ;
     * - le stock du client monte (comme une livraison) ;
     * - une livraison valorisée (prix figé) est enregistrée → le crédit du
     *   client augmente automatiquement via le module Finances.
     */
    public function store(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|integer',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|numeric|gt:0|max:99999999.99',
        ]);

        $supplierId = $request->input('_tenant_supplier_id');

        $customer = Customer::forSupplier($supplierId)->find($request->customer_id);
        if (!$customer) {
            return response()->json(['message' => 'Client non trouvé.'], 404);
        }

        $ids = collect($request->items)->pluck('product_id');
        $products = Product::forSupplier($supplierId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($products->count() !== $ids->unique()->count()) {
            return response()->json(['message' => 'Certains produits n\'appartiennent pas à votre catalogue.'], 422);
        }

        $result = DB::transaction(function () use ($request, $supplierId, $customer, $products) {
            $lines = [];
            $totalValue = 0.0;

            foreach ($request->items as $item) {
                $product = $products[$item['product_id']];
                $qty = (float) $item['quantity'];

                // 1) Le stock entrepôt baisse (jamais bloquant)
                WarehouseStockEntry::applyWalkInSale(
                    $supplierId,
                    $product->id,
                    $qty,
                    $request->user()->id,
                    "Remise à crédit — {$customer->name}",
                );

                // 2) Le stock du client monte (rattachement automatique si besoin)
                $cp = CustomerProduct::firstOrCreate(
                    ['customer_id' => $customer->id, 'product_id' => $product->id],
                    [
                        'supplier_id' => $supplierId,
                        'initial_stock' => 0,
                        'current_stock' => 0,
                        'is_active' => true,
                    ],
                );

                $cp->initial_stock = bcadd((string) $cp->initial_stock, number_format($qty, 2, '.', ''), 2);
                $cp->current_stock = bcadd((string) $cp->current_stock, number_format($qty, 2, '.', ''), 2);
                $cp->last_entry_at = now();
                $cp->save();

                // 3) Livraison valorisée (prix figé au moment de la remise) → crédit client
                StockEntry::create([
                    'customer_product_id' => $cp->id,
                    'customer_id' => $customer->id,
                    'supplier_id' => $supplierId,
                    'quantity' => $qty,
                    'unit_price' => $product->price,
                    'note' => 'Remise à crédit au comptoir',
                    'entry_type' => 'delivery',
                    'source' => 'supplier',
                    'entered_by_user_id' => $request->user()->id,
                    'entry_date' => now()->toDateString(),
                ]);

                $value = $product->price === null ? 0.0 : $qty * floatval($product->price);
                $totalValue += $value;
                $lines[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $qty,
                    'unit' => $product->unit,
                    'value' => round($value, 2),
                ];
            }

            return [$lines, round($totalValue, 2)];
        });

        return response()->json([
            'message' => "Remise à crédit enregistrée pour « {$customer->name} ».",
            'customer' => ['id' => $customer->id, 'name' => $customer->name],
            'lines' => $result[0],
            'total_value' => $result[1],
        ], 201);
    }
}
