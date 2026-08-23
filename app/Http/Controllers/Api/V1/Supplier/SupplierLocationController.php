<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierLocationController extends Controller
{
    /**
     * Le fournisseur localise son propre magasin (capture mobile, coordonnées GPS).
     */
    public function store(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $supplier = Supplier::findOrFail($supplierId);

        $supplier->update([
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
        ]);

        return response()->json([
            'message' => 'Votre magasin est localisé.',
            'latitude' => floatval($supplier->latitude),
            'longitude' => floatval($supplier->longitude),
        ]);
    }
}
