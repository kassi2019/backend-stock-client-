<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\CustomerProduct;
use App\Models\StockEntry;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Dashboard du client final : ses produits avec stock actuel
     */
    public function index(Request $request)
    {
        $customerId = $request->input('_tenant_customer_id');

        $products = CustomerProduct::with('product')
            ->forCustomer($customerId)
            ->where('is_active', true)
            ->get()
            ->map(function ($cp) {
                return [
                    'id' => $cp->id,
                    'product_name' => $cp->product->name,
                    'unit' => $cp->product->unit,
                    'initial_stock' => $cp->initial_stock,
                    'current_stock' => $cp->current_stock,
                    'last_entry_at' => $cp->last_entry_at,
                    'frequency' => $cp->frequency ?? 'daily',
                ];
            });

        return response()->json([
            'products' => $products,
        ]);
    }
}
