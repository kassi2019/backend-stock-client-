<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\CustomerProduct;
use App\Models\StockAlert;
use App\Models\Supplier;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Dashboard temps réel du fournisseur
     */
    public function index(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        // Infos abonnement
        $supplier = Supplier::with('plan')->find($supplierId);

        // Tous les produits-clients actifs, groupés par client
        $stocks = CustomerProduct::with(['customer', 'product'])
            ->forSupplier($supplierId)
            ->where('is_active', true)
            ->get()
            ->groupBy('customer_id')
            ->map(function ($items) {
                $customer = $items->first()->customer;
                return [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'products' => $items->map(function ($cp) {
                        $recu = floatval($cp->initial_stock);
                        $vendu = floatval($cp->stockEntries()->where('source', 'client')->sum('quantity'));
                        $reste = $recu - $vendu;
                        return [
                            'id' => $cp->id,
                            'product_name' => $cp->product->name,
                            'unit' => $cp->product->unit,
                            'recu' => $recu,
                            'vendu' => max(0, $vendu),
                            'reste' => max(0, $reste),
                            'reorder_point' => $cp->reorder_point,
                            'last_entry_at' => $cp->last_entry_at,
                            'is_low' => $cp->reorder_point && $reste <= $cp->reorder_point,
                        ];
                    }),
                ];
            })->values();

        // Alertes non résolues
        $alerts = StockAlert::with('customerProduct.product', 'customerProduct.customer')
            ->forSupplier($supplierId)
            ->unresolved()
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        // Compteurs
        $totalCustomers = \App\Models\Customer::forSupplier($supplierId)->where('is_active', true)->count();
        $totalProducts = \App\Models\Product::forSupplier($supplierId)->where('is_active', true)->count();
        $lowStockCount = CustomerProduct::forSupplier($supplierId)
            ->where('is_active', true)
            ->whereNotNull('reorder_point')
            ->whereRaw('current_stock <= reorder_point')
            ->count();

        return response()->json([
            'summary' => [
                'total_customers' => $totalCustomers,
                'total_products' => $totalProducts,
                'low_stock_count' => $lowStockCount,
            ],
            'subscription' => $supplier ? $supplier->quotaInfo() : null,
            'stocks' => $stocks,
            'alerts' => $alerts,
        ]);
    }
}
