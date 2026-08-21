<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $units = Unit::forSupplier($supplierId)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['units' => $units]);
    }

    public function store(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $request->validate([
            'name' => 'required|string|max:30',
        ]);

        $unit = Unit::firstOrCreate([
            'supplier_id' => $supplierId,
            'name' => trim($request->name),
        ]);

        return response()->json($unit, 201);
    }

    public function destroy(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $unit = Unit::forSupplier($supplierId)->findOrFail($id);

        // On refuse la suppression si des produits utilisent encore cette unité
        $usedCount = Product::forSupplier($supplierId)
            ->where('unit', $unit->name)
            ->count();

        if ($usedCount > 0) {
            return response()->json([
                'message' => "Impossible : {$usedCount} produit(s) utilisent encore l'unité « {$unit->name} ».",
            ], 422);
        }

        $unit->delete();

        return response()->json(['message' => 'Unité supprimée.']);
    }
}
