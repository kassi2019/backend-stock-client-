<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\CustomerProduct;
use App\Models\StockEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockEntryController extends Controller
{
    /**
     * Saisie par le fournisseur au nom d'un client
     */
    public function store(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $request->validate([
            'customer_product_id' => 'required|integer',
            'quantity' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:255',
            'entry_type' => 'nullable|in:declared,delivery,adjustment',
        ]);

        $customerProduct = CustomerProduct::forSupplier($supplierId)
            ->where('id', $request->customer_product_id)
            ->where('is_active', true)
            ->first();

        if (!$customerProduct) {
            return response()->json(['message' => 'Produit non trouvé.'], 404);
        }

        DB::transaction(function () use ($request, $customerProduct, $supplierId) {
            StockEntry::create([
                'customer_product_id' => $customerProduct->id,
                'customer_id' => $customerProduct->customer_id,
                'supplier_id' => $supplierId,
                'quantity' => $request->quantity,
                'note' => $request->note,
                'entry_type' => $request->entry_type ?? 'declared',
                'source' => 'supplier',
                'entered_by_user_id' => $request->user()->id,
                'entry_date' => now()->toDateString(),
            ]);

            $customerProduct->update([
                'current_stock' => $request->quantity,
                'last_entry_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Stock mis à jour avec succès.',
            'current_stock' => $request->quantity,
        ], 201);
    }

    /**
     * Historique des saisies pour un produit-client
     */
    public function index(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $entries = StockEntry::with('customerProduct.product', 'customer')
            ->forSupplier($supplierId)
            ->orderBy('entry_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($entries);
    }
}
