<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    public function index(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $categories = ProductCategory::forSupplier($supplierId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['categories' => $categories]);
    }

    public function store(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $category = ProductCategory::firstOrCreate([
            'supplier_id' => $supplierId,
            'name' => trim($request->name),
        ]);

        return response()->json($category, 201);
    }

    public function destroy(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $category = ProductCategory::forSupplier($supplierId)->findOrFail($id);

        // On refuse la suppression si des produits utilisent encore cette catégorie
        $usedCount = Product::forSupplier($supplierId)
            ->where('category', $category->name)
            ->count();

        if ($usedCount > 0) {
            return response()->json([
                'message' => "Impossible : {$usedCount} produit(s) utilisent encore la catégorie « {$category->name} ».",
            ], 422);
        }

        $category->delete();

        return response()->json(['message' => 'Catégorie supprimée.']);
    }
}
