<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\CustomerProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockEntry;
use App\Models\WarehouseStockEntry;
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
            ->with(['items.product', 'customer:id,name,phone'])
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
            ->with(['items.product', 'customer:id,name,phone,address', 'createdBy:id,name'])
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
     * Livraison (partielle ou totale) saisie par le fournisseur : met à jour
     * le stock client et l'entrepôt, et suit le reste à livrer par article.
     * Quand tout est livré, la commande passe à « livrée ».
     */
    public function deliver(Request $request, $id)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        $supplierId = $request->input('_tenant_supplier_id');

        $result = DB::transaction(function () use ($request, $supplierId, $id) {
            $order = Order::forSupplier($supplierId)
                ->with(['items', 'customer:id,name,owner_user_id'])
                ->lockForUpdate()
                ->findOrFail($id);

            if ($order->status !== Order::ACCEPTED) {
                return response()->json([
                    'message' => 'La commande doit être acceptée avant toute livraison.',
                ], 422);
            }

            $orderItems = $order->items->keyBy('id');
            $deliveredLines = [];

            foreach ($request->items as $line) {
                $item = $orderItems->get($line['order_item_id']);
                if (!$item) {
                    return response()->json(['message' => 'Article non trouvé dans cette commande.'], 422);
                }

                $qty = floatval($line['quantity']);
                $remaining = $item->remainingQuantity();
                if ($qty > $remaining) {
                    return response()->json([
                        'message' => "Quantité trop grande pour « {$item->product_name} » : il reste {$remaining} {$item->unit} à livrer.",
                    ], 422);
                }

                // Verrou pour la cohérence du stock client
                $cp = CustomerProduct::where('id', $item->customer_product_id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();
                if (!$cp) {
                    return response()->json([
                        'message' => "Impossible de livrer : le produit « {$item->product_name} » n'est plus disponible.",
                    ], 422);
                }

                $cp->initial_stock += $qty;
                $cp->current_stock += $qty;
                $cp->last_entry_at = now();
                $cp->save();

                StockEntry::create([
                    'customer_product_id' => $cp->id,
                    'customer_id' => $cp->customer_id,
                    'supplier_id' => $supplierId,
                    'quantity' => $qty,
                    'note' => "Commande #{$order->id} (livraison partielle)",
                    'entry_type' => 'delivery',
                    'source' => 'supplier',
                    'entered_by_user_id' => $request->user()->id,
                    'entry_date' => now()->toDateString(),
                ]);

                // Déduire l'entrepôt (jamais bloquant)
                WarehouseStockEntry::applyDelivery(
                    $supplierId,
                    $item->product_id,
                    $qty,
                    $request->user()->id,
                    "Livraison commande #{$order->id} (partielle)",
                );

                $item->delivered_quantity = bcadd(
                    (string) $item->delivered_quantity,
                    number_format($qty, 2, '.', ''),
                    2,
                );
                $item->save();

                $deliveredLines[] = ['item' => $item->fresh(), 'qty' => $qty];
            }

            // Tout livré → commande terminée
            if ($order->items->every(fn($it) => $it->remainingQuantity() <= 0)) {
                $order->transitionTo(Order::DELIVERED, $request->user());
            }

            return ['order' => $order, 'lines' => $deliveredLines];
        });

        if ($result instanceof \Illuminate\Http\JsonResponse) {
            return $result;
        }

        ['order' => $order, 'lines' => $lines] = $result;

        // Notifier le client
        $owner = $order->customer?->owner;
        if ($owner) {
            if ($order->status === Order::DELIVERED) {
                $owner->notify(new OrderNotification(
                    'order_delivered',
                    $order->id,
                    "Commande #{$order->id} livrée",
                    'Votre commande a été entièrement livrée par le fournisseur.',
                ));
            } else {
                $summary = implode(', ', array_map(
                    fn($l) => "{$l['qty']} {$l['item']->unit} de « {$l['item']->product_name} »",
                    $lines,
                ));
                $remainingTotal = $order->items->sum(fn($it) => $it->remainingQuantity());
                $owner->notify(new OrderNotification(
                    'order_partial_delivery',
                    $order->id,
                    "Livraison partielle — Commande #{$order->id}",
                    "Le fournisseur vous a livré : {$summary}. Il reste {$remainingTotal} unité(s) à livrer.",
                ));
            }
        }

        return response()->json([
            'message' => 'Livraison enregistrée.',
            'order' => $this->formatOrder($order),
        ]);
    }

    /**
     * Restes à livrer : commandes acceptées ayant des livraisons partielles
     * en cours (au moins un article partiellement livré).
     * Déclaré avant la route {order} pour éviter le conflit de binding.
     */
    public function pendingDeliveries(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $orders = Order::forSupplier($supplierId)
            ->where('status', Order::ACCEPTED)
            ->with(['items.product', 'customer:id,name,phone'])
            ->whereHas('items', function ($q) {
                $q->whereRaw('delivered_quantity > 0')
                    ->whereRaw('quantity > delivered_quantity');
            })
            ->orderBy('created_at', 'desc')
            ->get();

        $pending = $orders->map(function ($order) {
            $items = $order->items
                ->filter(fn($it) => $it->remainingQuantity() > 0)
                ->map(fn($it) => [
                    'order_item_id' => $it->id,
                    'product_name' => $it->product_name,
                    'unit' => $it->unit,
                    'image_path' => $it->product?->image_path,
                    'ordered' => floatval($it->quantity),
                    'delivered' => floatval($it->delivered_quantity),
                    'remaining' => $it->remainingQuantity(),
                ])
                ->values();

            return [
                'order_id' => $order->id,
                'customer_name' => $order->customer?->name,
                'created_at' => $order->created_at?->toISOString(),
                'items' => $items,
            ];
        })->values();

        return response()->json([
            'pending' => $pending,
            'total' => $pending->sum(fn($o) => $o['items']->count()),
        ]);
    }

    /**
     * Message du fournisseur au client sur une commande (ex. : stock
     * insuffisant, délai de réapprovisionnement). La réponse du client se
     * fait par téléphone.
     */
    public function message(Request $request, $id)
    {
        $request->validate([
            'message' => 'required|string|min:3|max:1000',
        ]);

        $supplierId = $request->input('_tenant_supplier_id');

        $order = Order::forSupplier($supplierId)->findOrFail($id);

        if (in_array($order->status, [Order::REJECTED, Order::CANCELLED], true)) {
            return response()->json([
                'message' => 'Cette commande ne peut plus recevoir de message.',
            ], 422);
        }

        $order->customer?->owner?->notify(new OrderNotification(
            'order_message',
            $order->id,
            "Message du fournisseur — Commande #{$order->id}",
            $request->message,
        ));

        return response()->json([
            'message' => 'Message envoyé au client.',
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
            'image_path' => $item->product?->image_path,
            'quantity' => floatval($item->quantity),
            'unit_price' => $item->unit_price === null ? null : floatval($item->unit_price),
            'line_total' => $lineTotal,
            'warehouse_stock' => $item->product ? floatval($item->product->stock_quantity) : null,
            'warehouse_insufficient' => $item->product
                && floatval($item->quantity) > floatval($item->product->stock_quantity),
            'delivered_quantity' => floatval($item->delivered_quantity),
            'remaining' => $item->remainingQuantity(),
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
