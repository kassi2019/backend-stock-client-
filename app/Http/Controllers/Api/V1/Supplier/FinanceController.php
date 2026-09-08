<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\StockEntry;
use Illuminate\Http\Request;

class FinanceController extends Controller
{
    /**
     * Finances du fournisseur : crédit total + détail par client
     * (livré en valeur − encaissements = solde restant).
     */
    public function index(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        return response()->json($this->summary($supplierId));
    }

    /**
     * Données financières partagées (écran Finances + tableau de bord).
     */
    public function summary(int $supplierId): array
    {
        // Valeur livrée par client : quantité × prix (prix figé à la livraison,
        // sinon prix actuel du produit)
        $delivered = StockEntry::query()
            ->join('customer_products as cp', 'cp.id', '=', 'stock_entries.customer_product_id')
            ->join('products as p', 'p.id', '=', 'cp.product_id')
            ->where('stock_entries.supplier_id', $supplierId)
            ->where('stock_entries.entry_type', 'delivery')
            ->selectRaw('stock_entries.customer_id as cid, COALESCE(SUM(stock_entries.quantity * COALESCE(stock_entries.unit_price, p.price)), 0) as total')
            ->groupBy('stock_entries.customer_id')
            ->pluck('total', 'cid');

        $paid = Payment::where('supplier_id', $supplierId)
            ->selectRaw('customer_id as cid, COALESCE(SUM(amount), 0) as total')
            ->groupBy('customer_id')
            ->pluck('total', 'cid');

        $customers = Customer::forSupplier($supplierId)
            ->orderBy('name')
            ->get(['id', 'name', 'phone']);

        $items = $customers->map(function ($c) use ($delivered, $paid) {
            $deliveredTotal = round(floatval($delivered[$c->id] ?? 0), 2);
            $paidTotal = round(floatval($paid[$c->id] ?? 0), 2);
            $balance = round($deliveredTotal - $paidTotal, 2);

            return [
                'customer_id' => $c->id,
                'customer_name' => $c->name,
                'phone' => $c->phone,
                'delivered_total' => $deliveredTotal,
                'paid_total' => $paidTotal,
                'balance' => $balance,
                'credit' => max(0, $balance),
            ];
        })->values();

        return [
            'total_credit' => round($items->sum('credit'), 2),
            'customers' => $items,
        ];
    }
}
