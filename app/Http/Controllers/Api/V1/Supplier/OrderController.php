<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Notifications\OrderNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * Liste des commandes du fournisseur (filtre optionnel par statut).
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_keys(Order::ALLOWED))],
        ]);

        $supplierId = $request->input('_tenant_supplier_id');

        $orders = Order::forSupplier($supplierId)
            ->with(['items', 'customer:id,name,phone'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($orders);
    }

    /**
     * Nombre de commandes en attente (badge de l'onglet).
     * Déclaré avant la route {order} pour éviter le conflit de binding.
     */
    public function pendingCount(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        return response()->json([
            'pending_count' => Order::forSupplier($supplierId)->where('status', Order::PENDING)->count(),
        ]);
    }

    /**
     * Détail d'une commande du fournisseur.
     */
    public function show(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $order = Order::forSupplier($supplierId)
            ->with(['items', 'customer:id,name,phone,address', 'createdBy:id,name'])
            ->findOrFail($id);

        return response()->json(['order' => $this->formatOrder($order)]);
    }

    /**
     * Acceptation par le fournisseur (pending → accepted).
     */
    public function accept(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $order = DB::transaction(function () use ($request, $supplierId, $id) {
            $order = Order::forSupplier($supplierId)->lockForUpdate()->findOrFail($id);

            if ($order->status !== Order::PENDING) {
                return response()->json([
                    'message' => 'Cette commande n\'est plus en attente.',
                ], 422);
            }

            $order->transitionTo(Order::ACCEPTED, $request->user());
            return $order;
        });

        if ($order instanceof \Illuminate\Http\JsonResponse) {
            return $order;
        }

        $order->customer?->owner?->notify(new OrderNotification(
            'order_accepted',
            $order->id,
            "Commande #{$order->id} acceptée",
            'Votre commande a été acceptée par le fournisseur. Confirmez la réception dès réception des produits.',
        ));

        return response()->json([
            'message' => 'Commande acceptée.',
            'order' => $this->formatOrder($order),
        ]);
    }

    /**
     * Refus par le fournisseur (pending → rejected, motif obligatoire).
     */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $supplierId = $request->input('_tenant_supplier_id');

        $order = DB::transaction(function () use ($request, $supplierId, $id) {
            $order = Order::forSupplier($supplierId)->lockForUpdate()->findOrFail($id);

            if ($order->status !== Order::PENDING) {
                return response()->json([
                    'message' => 'Cette commande n\'est plus en attente.',
                ], 422);
            }

            $order->transitionTo(Order::REJECTED, $request->user(), $request->reason);
            return $order;
        });

        if ($order instanceof \Illuminate\Http\JsonResponse) {
            return $order;
        }

        $order->customer?->owner?->notify(new OrderNotification(
            'order_rejected',
            $order->id,
            "Commande #{$order->id} refusée",
            "Votre commande a été refusée par le fournisseur. Motif : {$order->rejection_reason}",
        ));

        return response()->json([
            'message' => 'Commande refusée.',
            'order' => $this->formatOrder($order),
        ]);
    }

    /**
     * Représentation JSON commune d'une commande (vue fournisseur).
     */
    private function formatOrder(Order $order): array
    {
        $items = $order->items->map(fn($item) => $this->formatItem($item));

        return [
            'id' => $order->id,
            'status' => $order->status,
            'note' => $order->note,
            'rejection_reason' => $order->rejection_reason,
            'created_at' => $order->created_at?->toISOString(),
            'accepted_at' => $order->accepted_at?->toISOString(),
            'delivered_at' => $order->delivered_at?->toISOString(),
            'customer' => $order->customer ? [
                'id' => $order->customer->id,
                'name' => $order->customer->name,
                'phone' => $order->customer->phone,
                'address' => $order->customer->address,
            ] : null,
            'created_by' => $order->createdBy?->name,
            'items' => $items,
            'total' => $this->orderTotal($items),
            'unpriced_items_count' => $items->filter(fn($it) => $it['unit_price'] === null)->count(),
        ];
    }

    /**
     * Représentation JSON d'un article : prix figé + sous-total de la ligne.
     */
    private function formatItem(OrderItem $item): array
    {
        $lineTotal = $item->unit_price === null
            ? null
            : round(floatval($item->quantity) * floatval($item->unit_price), 2);

        return [
            'id' => $item->id,
            'product_name' => $item->product_name,
            'unit' => $item->unit,
            'quantity' => floatval($item->quantity),
            'unit_price' => $item->unit_price === null ? null : floatval($item->unit_price),
            'line_total' => $lineTotal,
        ];
    }

    /**
     * Total global d'une commande (null si aucun article n'a de prix).
     */
    private function orderTotal($items): ?float
    {
        $priced = $items->filter(fn($it) => $it['line_total'] !== null);
        if ($priced->isEmpty()) {
            return null;
        }
        return round($priced->sum('line_total'), 2);
    }
}
