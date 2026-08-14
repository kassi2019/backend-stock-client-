<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerProduct;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $customers = Customer::forSupplier($supplierId)
            ->with('owner:id,name,phone,avatar_path')
            ->orderBy('name')
            ->get();

        return response()->json($customers);
    }

    public function store(Request $request)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $supplier = Supplier::find($supplierId);

        if (!$supplier->canCreateCustomer()) {
            $limit = $supplier->plan->max_customers;
            return response()->json([
                'message' => "Quota atteint : maximum {$limit} clients (plan {$supplier->plan->name})."
            ], 403);
        }

        $request->validate([
            'name' => 'required|string|max:191',
            'contact_name' => 'nullable|string|max:191',
            'phone' => 'required|string|max:30|unique:users,phone',
            'email' => 'nullable|email|max:191',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
            'default_frequency' => 'nullable|in:daily,every_2_days,every_3_days,weekly',
            'password' => 'nullable|string|min:4',
        ]);

        // Créer le compte utilisateur client
        $password = $request->password ?: '0000';
        $user = User::create([
            'name' => $request->contact_name ?: $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($password),
        ]);
        $user->assignRole('client');

        // Créer la fiche client
        $customer = Customer::create([
            'supplier_id' => $supplierId,
            'owner_user_id' => $user->id,
            'name' => $request->name,
            'contact_name' => $request->contact_name,
            'phone' => $request->phone,
            'email' => $request->email,
            'address' => $request->address,
            'notes' => $request->notes,
            'default_frequency' => $request->default_frequency ?? 'daily',
        ]);

        return response()->json([
            'customer' => $customer,
            'login_phone' => $user->phone,
            'password' => $password, // À envoyer par SMS dans la vraie vie
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $customer = Customer::forSupplier($supplierId)
            ->with('owner:id,name,phone,is_active,last_login_at,avatar_path')
            ->findOrFail($id);

        return response()->json($customer);
    }

    public function update(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $customer = Customer::forSupplier($supplierId)->findOrFail($id);

        $request->validate([
            'name' => 'sometimes|string|max:191',
            'contact_name' => 'nullable|string|max:191',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
            'default_frequency' => 'nullable|in:daily,every_2_days,every_3_days,weekly',
            'is_active' => 'sometimes|boolean',
        ]);

        $customer->update($request->only([
            'name', 'contact_name', 'address', 'notes', 'default_frequency', 'is_active'
        ]));

        return response()->json($customer);
    }

    public function destroy(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');
        $customer = Customer::forSupplier($supplierId)->findOrFail($id);
        $customer->delete();

        return response()->json(['message' => 'Client supprimé.']);
    }

    /**
     * Rattacher un produit à un client
     */
    public function attachProduct(Request $request, $customerId)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'initial_stock' => 'nullable|numeric|min:0',
            'frequency' => 'nullable|in:daily,every_2_days,every_3_days,weekly',
            'reorder_point' => 'nullable|numeric|min:0',
        ]);

        // Vérifier que le client appartient au fournisseur
        $customer = Customer::forSupplier($supplierId)->findOrFail($customerId);

        $cp = CustomerProduct::create([
            'customer_id' => $customerId,
            'product_id' => $request->product_id,
            'supplier_id' => $supplierId,
            'initial_stock' => $request->initial_stock ?? 0,
            'current_stock' => $request->initial_stock ?? 0,
            'frequency' => $request->frequency,
            'reorder_point' => $request->reorder_point,
        ]);

        return response()->json($cp, 201);
    }

    /**
     * Détacher un produit d'un client
     */
    public function detachProduct(Request $request, $customerId, $productId)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        CustomerProduct::forSupplier($supplierId)
            ->where('customer_id', $customerId)
            ->where('product_id', $productId)
            ->delete();

        return response()->json(['message' => 'Produit détaché du client.']);
    }

    /**
     * MAJ : ajouter une livraison (augmente le Reçu).
     */
    public function updateProduct(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $request->validate([
            'delivery_qty' => 'required|numeric|min:0.01',
            'reorder_point' => 'nullable|numeric|min:0',
        ]);

        $cp = CustomerProduct::forSupplier($supplierId)->findOrFail($id);

        $qty = $request->delivery_qty;
        $cp->initial_stock += $qty;
        $cp->current_stock += $qty;

        // Logger la livraison
        \App\Models\StockEntry::create([
            'customer_product_id' => $cp->id,
            'customer_id' => $cp->customer_id,
            'supplier_id' => $supplierId,
            'quantity' => $qty,
            'note' => $request->note ?? 'Livraison fournisseur',
            'entry_type' => 'delivery',
            'source' => 'supplier',
            'entered_by_user_id' => $request->user()->id,
            'entry_date' => now()->toDateString(),
        ]);

        if ($request->reorder_point !== null) {
            $cp->reorder_point = $request->reorder_point;
        }

        $cp->last_entry_at = now();
        $cp->save();

        $cp->load('product');

        return response()->json([
            'message' => 'Livraison enregistrée.',
            'customer_product' => [
                'id' => $cp->id,
                'product_name' => $cp->product->name,
                'unit' => $cp->product->unit,
                'recu' => floatval($cp->initial_stock),
                'vendu' => floatval($cp->stockEntries()->where('source', 'client')->sum('quantity')),
                'reste' => floatval($cp->initial_stock) - floatval($cp->stockEntries()->where('source', 'client')->sum('quantity')),
                'reorder_point' => $cp->reorder_point,
                'last_entry_at' => $cp->last_entry_at,
            ],
        ]);
    }

    /**
     * Historique des MAJ pour un produit rattaché.
     */
    public function productHistory(Request $request, $id)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        CustomerProduct::forSupplier($supplierId)->findOrFail($id);

        $entries = \App\Models\StockEntry::with('enteredBy:id,name')
            ->where('customer_product_id', $id)
            ->orderBy('entry_date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($e) {
                return [
                    'id' => $e->id,
                    'quantity' => floatval($e->quantity),
                    'entry_type' => $e->entry_type,
                    'source' => $e->source,
                    'note' => $e->note,
                    'entered_by' => $e->enteredBy?->name,
                    'entry_date' => $e->entry_date->format('Y-m-d'),
                    'created_at' => $e->created_at->format('H:i'),
                ];
            });

        return response()->json($entries);
    }

    /**
     * Voir le stock d'un client spécifique
     */
    public function stock(Request $request, $customerId)
    {
        $supplierId = $request->input('_tenant_supplier_id');

        $customer = Customer::forSupplier($supplierId)
            ->with('owner:id,avatar_path')
            ->findOrFail($customerId);

        $products = CustomerProduct::with('product')
            ->forCustomer($customerId)
            ->forSupplier($supplierId)
            ->where('is_active', true)
            ->get();

        return response()->json([
            'customer' => $customer,
            'products' => $products,
        ]);
    }
}
