<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\CustomerProduct;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockEntry;
use App\Models\Supplier;
use App\Notifications\OrderNotification;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    /**
     * Création d'une commande par le client final.
     * Chaque article référence soit un produit assigné (customer_product_id),
     * soit un produit du catalogue (product_id) — dans ce cas le produit est
     * automatiquement rattaché au compte du client (stock à 0).
     */
    public function store(Request $request)
    {
        $request->validate([
            'items' => 'required|array|min:1',
            'items.*.customer_product_id' => 'nullable|integer',
            'items.*.product_id' => 'nullable|integer',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'note' => 'nullable|string|max:1000',
        ]);

        $customerId = $request->input('_tenant_customer_id');
        $supplierId = $request->input('_tenant_supplier_id');

        $order = DB::transaction(function () use ($request, $customerId, $supplierId) {
            $items = [];
            $seenCpIds = [];

            foreach ($request->items as $item) {
                // Exactement un des deux identifiants par article
                $hasCpId = !empty($item['customer_product_id']);
                $hasProductId = !empty($item['product_id']);
                if ($hasCpId === $hasProductId) {
                    return response()->json([
                        'message' => 'Indiquez soit un produit du catalogue, soit un produit assigné à votre compte.',
                    ], 422);
                }

                if ($hasCpId) {
                    // Produit déjà assigné au client
                    $cp = CustomerProduct::where('id', $item['customer_product_id'])
                        ->where('customer_id', $customerId)
                        ->where('is_active', true)
                        ->first();
                    if (!$cp) {
                        return response()->json(['message' => 'Produit non trouvé.'], 404);
                    }
                } else {
                    // Produit du catalogue : vérifier puis rattacher au client
                    $product = Product::forSupplier($supplierId)
                        ->where('is_active', true)
                        ->find($item['product_id']);
                    if (!$product) {
                        return response()->json(['message' => 'Produit non trouvé.'], 404);
                    }
                    $cp = $this->resolveCustomerProduct($customerId, $product);
                }

                if (in_array($cp->id, $seenCpIds, true)) {
                    return response()->json([
                        'message' => 'Un produit ne peut apparaître qu\'une seule fois par commande.',
                    ], 422);
                }
                $seenCpIds[] = $cp->id;

                // Verrou pour la cohérence du stock à la livraison
                $cp = CustomerProduct::where('id', $cp->id)->lockForUpdate()->first();

                $items[] = [
                    'customer_product_id' => $cp->id,
                    'product_id' => $cp->product_id,
                    'product_name' => $cp->product?->name ?? 'Produit',
                    'unit' => $cp->product?->unit,
                    'quantity' => $item['quantity'],
                    // Prix figé au moment de la commande (null = prix sur demande)
                    'unit_price' => $cp->product?->price,
                ];
            }

            $order = Order::create([
                'supplier_id' => $supplierId,
                'customer_id' => $customerId,
                'status' => Order::PENDING,
                'note' => $request->note,
                'created_by_user_id' => $request->user()->id,
            ]);

            foreach ($items as $item) {
                $item['order_id'] = $order->id;
                OrderItem::create($item);
            }

            return $order;
        });

        // Si un produit était invalide, la transaction a renvoyé une réponse d'erreur
        if ($order instanceof \Illuminate\Http\JsonResponse) {
            return $order;
        }

        // Notifier l'équipe fournisseur
        $supplier = Supplier::find($supplierId);
        if ($supplier) {
            $customerName = $order->customer?->name ?? 'Un client';
            $count = $order->items()->count();
            Order::notifySupplierTeam($supplier, new OrderNotification(
                'order_created',
                $order->id,
                "Nouvelle commande #{$order->id}",
                "Le client « {$customerName} » a passé une commande de {$count} produit(s). Consultez vos commandes.",
            ));
        }

        return response()->json([
            'message' => 'Commande envoyée.',
            'order' => $this->formatOrder($order),
        ], 201);
    }

    /**
     * Rattache un produit du catalogue au client (stock à 0), en gérant la
     * course sur la contrainte unique (customer_id, product_id).
     */
    private function resolveCustomerProduct(int $customerId, Product $product): CustomerProduct
    {
        try {
            return CustomerProduct::firstOrCreate(
                ['customer_id' => $customerId, 'product_id' => $product->id],
                [
                    'supplier_id' => $product->supplier_id,
                    'initial_stock' => 0,
                    'current_stock' => 0,
                    'is_active' => true,
                ],
            );
        } catch (QueryException $e) {
            // Duplicate key (1062 / SQLSTATE 23000) : une commande concurrente
            // vient de créer le rattachement — on le récupère.
            $isDuplicate = ($e->errorInfo[1] ?? null) === 1062 || $e->getCode() === '23000';
            if (!$isDuplicate) {
                throw $e;
            }
            $cp = CustomerProduct::where('customer_id', $customerId)
                ->where('product_id', $product->id)
                ->first();
            if (!$cp) {
                throw $e;
            }
            return $cp;
        }
    }

    /**
     * Historique des commandes du client.
     */
    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_keys(Order::ALLOWED))],
        ]);

        $customerId = $request->input('_tenant_customer_id');

        $orders = Order::forCustomer($customerId)
            ->with(['items', 'supplier:id,name'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($orders);
    }

    /**
     * Détail d'une commande du client.
     */
    public function show(Request $request, $id)
    {
        $customerId = $request->input('_tenant_customer_id');

        $order = Order::forCustomer($customerId)
            ->with(['items', 'supplier:id,name'])
            ->findOrFail($id);

        return response()->json(['order' => $this->formatOrder($order)]);
    }

    /**
     * Annulation par le client (uniquement si encore en attente).
     */
    public function cancel(Request $request, $id)
    {
        $customerId = $request->input('_tenant_customer_id');

        $order = DB::transaction(function () use ($request, $customerId, $id) {
            $order = Order::forCustomer($customerId)->lockForUpdate()->findOrFail($id);

            if (!in_array(Order::CANCELLED, Order::ALLOWED[$order->status] ?? [], true)) {
                return response()->json([
                    'message' => 'Seules les commandes en attente peuvent être annulées.',
                ], 422);
            }

            $order->transitionTo(Order::CANCELLED, $request->user());
            return $order;
        });

        if ($order instanceof \Illuminate\Http\JsonResponse) {
            return $order;
        }

        return response()->json([
            'message' => 'Commande annulée.',
            'order' => $this->formatOrder($order),
        ]);
    }

    /**
     * Confirmation de réception par le client : la commande est livrée et
     * le stock est mis à jour automatiquement (comme une livraison classique).
     */
    public function confirmDelivery(Request $request, $id)
    {
        $customerId = $request->input('_tenant_customer_id');
        $supplierId = $request->input('_tenant_supplier_id');

        $order = DB::transaction(function () use ($request, $customerId, $supplierId, $id) {
            $order = Order::forCustomer($customerId)->with('items')->lockForUpdate()->findOrFail($id);

            if ($order->status !== Order::ACCEPTED) {
                return response()->json([
                    'message' => 'Cette commande n\'est pas en attente de livraison.',
                ], 422);
            }

            foreach ($order->items as $item) {
                $cp = CustomerProduct::where('id', $item->customer_product_id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (!$cp) {
                    return response()->json([
                        'message' => "Impossible de confirmer la réception : le produit « {$item->product_name} » n'est plus disponible.",
                    ], 422);
                }

                $cp->initial_stock += $item->quantity;
                $cp->current_stock += $item->quantity;
                $cp->last_entry_at = now();
                $cp->save();

                StockEntry::create([
                    'customer_product_id' => $cp->id,
                    'customer_id' => $customerId,
                    'supplier_id' => $supplierId,
                    'quantity' => $item->quantity,
                    'note' => "Commande #{$order->id}",
                    'entry_type' => 'delivery',
                    'source' => 'supplier',
                    'entered_by_user_id' => $request->user()->id,
                    'entry_date' => now()->toDateString(),
                ]);
            }

            $order->transitionTo(Order::DELIVERED, $request->user());
            return $order;
        });

        if ($order instanceof \Illuminate\Http\JsonResponse) {
            return $order;
        }

        // Notifier l'équipe fournisseur que le client a confirmé la réception
        $supplier = Supplier::find($supplierId);
        if ($supplier) {
            $customerName = $order->customer?->name ?? 'Un client';
            Order::notifySupplierTeam($supplier, new OrderNotification(
                'order_delivered',
                $order->id,
                "Commande #{$order->id} livrée",
                "Le client « {$customerName} » a confirmé la réception de la commande #{$order->id}. Le stock a été mis à jour.",
            ));
        }

        return response()->json([
            'message' => 'Livraison confirmée. Le stock a été mis à jour.',
            'order' => $this->formatOrder($order),
        ]);
    }

    /**
     * Représentation JSON commune d'une commande (vue client).
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
            'supplier_name' => $order->supplier?->name,
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
