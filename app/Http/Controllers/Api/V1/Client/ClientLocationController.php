<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\Base64Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClientLocationController extends Controller
{
    /**
     * Le client localise son propre magasin : photo + coordonnées GPS (capture mobile).
     * Le fournisseur n'a alors plus besoin de se déplacer.
     */
    public function store(Request $request)
    {
        $customerId = $request->input('_tenant_customer_id');

        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'photo_base64' => 'nullable|string',
        ]);

        $customer = Customer::findOrFail($customerId);

        $data = [
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
        ];

        if ($request->filled('photo_base64')) {
            if ($customer->shop_image_path) {
                Storage::disk('products')->delete($customer->shop_image_path);
            }
            $data['shop_image_path'] = Base64Image::store($request->input('photo_base64'), 'shops');
        }

        $customer->update($data);

        return response()->json([
            'message' => 'Votre magasin est localisé.',
            'shop_image_path' => $customer->shop_image_path,
            'latitude' => floatval($customer->latitude),
            'longitude' => floatval($customer->longitude),
        ]);
    }
}
