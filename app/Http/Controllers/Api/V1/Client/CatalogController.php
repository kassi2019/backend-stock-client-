<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    /**
     * Catalogue du fournisseur : produits actifs avec prix + monnaie.
     */
    public function index(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $supplier = Supplier::find($supplierId);

        $products = Product::forSupplier($supplierId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'category', 'sku', 'price'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'unit' => $p->unit,
                'category' => $p->category,
                'sku' => $p->sku,
                'price' => $p->price === null ? null : floatval($p->price),
            ]);

        return response()->json([
            'products' => $products,
            'currency' => [
                'code' => $supplier?->plan?->currency ?? 'EUR',
                'symbol' => $supplier?->plan?->currency_symbol ?? '€',
                'position' => $supplier?->plan?->currency_position ?? 'after',
            ],
        ]);
    }
}
