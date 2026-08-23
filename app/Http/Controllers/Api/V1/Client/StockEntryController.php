<?php

namespace App\Http\Controllers\Api\V1\Client;

use App\Http\Controllers\Controller;
use App\Models\CustomerProduct;
use App\Models\StockEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockEntryController extends Controller
{
    /**
     * Saisie de la vente par le client final, selon deux modes :
     * - le client connaît la quantité vendue  → sold_quantity,
     *   le backend calcule le nouveau stock restant ;
     * - le client connaît la quantité restante → remaining_quantity,
     *   le backend calcule le vendu (stock actuel - restant).
     */
    public function store(Request $request)
    {
        $request->validate([
            'customer_product_id' => 'required|integer',
            'sold_quantity' => 'required_without:remaining_quantity|nullable|numeric|min:0',
            'remaining_quantity' => 'required_without:sold_quantity|nullable|numeric|min:0',
            'note' => 'nullable|string|max:255',
        ]);

        $customerId = $request->input('_tenant_customer_id');
        $supplierId = $request->input('_tenant_supplier_id');

        $customerProduct = CustomerProduct::where('id', $request->customer_product_id)
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->first();

        if (!$customerProduct) {
            return response()->json(['message' => 'Produit non trouvé.'], 404);
        }

        if ($request->filled('remaining_quantity')) {
            $remaining = (float) $request->remaining_quantity;

            if ($remaining > $customerProduct->current_stock) {
                throw ValidationException::withMessages([
                    'remaining_quantity' => [
                        'La quantité restante saisie est supérieure au stock actuel (' . $customerProduct->current_stock . ').',
                    ],
                ]);
            }

            $sold = max(0, $customerProduct->current_stock - $remaining);
            if ($sold <= 0) {
                throw ValidationException::withMessages([
                    'remaining_quantity' => [
                        'La quantité restante saisie est égale au stock actuel : aucune vente à enregistrer.',
                    ],
                ]);
            }

            $newStock = $remaining;
        } else {
            $sold = (float) $request->sold_quantity;
            $newStock = max(0, $customerProduct->current_stock - $sold);
        }

        DB::transaction(function () use ($customerProduct, $customerId, $supplierId, $sold, $newStock, $request) {
            // Enregistrer la vente (quantity = quantité vendue)
            StockEntry::create([
                'customer_product_id' => $customerProduct->id,
                'customer_id' => $customerId,
                'supplier_id' => $supplierId,
                'quantity' => $sold,
                'note' => $request->note,
                'entry_type' => 'declared',
                'source' => 'client',
                'entered_by_user_id' => $request->user()->id,
                'entry_date' => now()->toDateString(),
            ]);

            $customerProduct->update([
                'current_stock' => $newStock,
                'last_entry_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Vente enregistrée.',
            'sold' => $sold,
            'current_stock' => $newStock,
        ], 201);
    }

    /**
     * Historique des saisies du client
     */
    public function index(Request $request)
    {
        $customerId = $request->input('_tenant_customer_id');

        $entries = StockEntry::with('customerProduct.product')
            ->forCustomer($customerId)
            ->orderBy('entry_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($entries);
    }

    /**
     * Correction d'une saisie erronée : supprime la vente déclarée par le
     * client et ré-ajoute la quantité au stock restant.
     */
    public function destroy(Request $request, $id)
    {
        $customerId = $request->input('_tenant_customer_id');

        $result = DB::transaction(function () use ($customerId, $id) {
            $entry = StockEntry::where('id', $id)
                ->where('customer_id', $customerId)
                ->lockForUpdate()
                ->first();

            if (!$entry) {
                return response()->json(['message' => 'Saisie non trouvée.'], 404);
            }

            // Seules les ventes déclarées par le client sont corrigeables
            // (pas les livraisons du fournisseur, ni les ajustements).
            if ($entry->source !== 'client' || $entry->entry_type !== 'declared') {
                return response()->json([
                    'message' => 'Seules vos ventes saisies peuvent être corrigées.',
                ], 403);
            }

            $cp = CustomerProduct::find($entry->customer_product_id);
            if ($cp) {
                // Ré-ajouter la quantité, sans dépasser le total reçu
                $cp->current_stock = min(
                    $cp->current_stock + $entry->quantity,
                    $cp->initial_stock,
                );
                $cp->last_entry_at = now();
                $cp->save();
            }

            $entry->delete();

            return [$entry, $cp];
        });

        if ($result instanceof \Illuminate\Http\JsonResponse) {
            return $result;
        }

        [$entry, $cp] = $result;

        return response()->json([
            'message' => 'Vente corrigée : la quantité a été ré-ajoutée au stock.',
            'quantity' => floatval($entry->quantity),
            'product_name' => $entry->customerProduct?->product?->name,
            'current_stock' => $cp?->fresh()?->current_stock,
        ]);
    }
}
