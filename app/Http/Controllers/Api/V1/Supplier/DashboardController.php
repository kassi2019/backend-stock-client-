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
                            'image_path' => $cp->product->image_path,
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

        // Alertes non résolues (stock entrepôt + stock clients)
        $alerts = StockAlert::with('product', 'customerProduct.product', 'customerProduct.customer')
            ->forSupplier($supplierId)
            ->unresolved()
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->map(function ($a) {
                return [
                    'id' => $a->id,
                    'type' => $a->type,
                    'severity' => $a->severity,
                    'message' => $a->message,
                    'kind' => $a->product_id ? 'warehouse' : 'customer',
                    'product_name' => $a->product?->name ?? $a->customerProduct?->product?->name,
                    'image_path' => $a->product?->image_path ?? $a->customerProduct?->product?->image_path,
                    'created_at' => $a->created_at?->toISOString(),
                ];
            });

        // Compteurs
        $totalCustomers = \App\Models\Customer::forSupplier($supplierId)->where('is_active', true)->count();
        $totalProducts = \App\Models\Product::forSupplier($supplierId)->where('is_active', true)->count();
        $lowStockCount = CustomerProduct::forSupplier($supplierId)
            ->where('is_active', true)
            ->whereNotNull('reorder_point')
            ->whereRaw('current_stock <= reorder_point')
            ->count();
        $warehouseLowCount = \App\Models\Product::forSupplier($supplierId)
            ->where('is_active', true)
            ->whereNotNull('stock_threshold')
            ->whereRaw('stock_quantity <= stock_threshold')
            ->count();
        $warehouseNegativeCount = \App\Models\Product::forSupplier($supplierId)
            ->where('is_active', true)
            ->whereRaw('stock_quantity < 0')
            ->count();
        $pendingDeliveriesCount = \App\Models\Order::forSupplier($supplierId)
            ->where('status', \App\Models\Order::ACCEPTED)
            ->whereHas('items', function ($q) {
                $q->whereRaw('delivered_quantity > 0')
                    ->whereRaw('quantity > delivered_quantity');
            })
            ->count();

        // Crédit total (valeur livrée − encaissements, tous clients confondus)
        $financeSummary = (new FinanceController())->summary($supplierId);

        return response()->json([
            'summary' => [
                'total_credit' => $financeSummary['total_credit'],
                'total_customers' => $totalCustomers,
                'total_products' => $totalProducts,
                'low_stock_count' => $lowStockCount,
                'warehouse_low_count' => $warehouseLowCount,
                'warehouse_negative_count' => $warehouseNegativeCount,
                'warehouse_products_count' => $totalProducts,
                'pending_deliveries_count' => $pendingDeliveriesCount,
            ],
            'subscription' => $supplier ? $supplier->quotaInfo() : null,
            'stocks' => $stocks,
            'alerts' => $alerts,
        ]);
    }
}
