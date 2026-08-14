<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Supplier;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    // --- CRUD Plans ---

    public function index()
    {
        return response()->json(Plan::orderBy('monthly_price')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'slug' => 'required|string|max:191|unique:plans',
            'monthly_price' => 'required|numeric|min:0',
            'currency' => 'nullable|string|max:5',
            'currency_symbol' => 'nullable|string|max:5',
            'currency_position' => 'nullable|in:before,after',
            'max_products' => 'nullable|integer|min:1',
            'max_customers' => 'nullable|integer|min:1',
            'max_staff' => 'nullable|integer|min:1',
            'trial_days' => 'nullable|integer|min:0',
        ]);

        return response()->json(Plan::create($data), 201);
    }

    public function update(Request $request, $id)
    {
        $plan = Plan::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:191',
            'monthly_price' => 'sometimes|numeric|min:0',
            'currency' => 'nullable|string|max:5',
            'currency_symbol' => 'nullable|string|max:5',
            'currency_position' => 'nullable|in:before,after',
            'max_products' => 'nullable|integer|min:1',
            'max_customers' => 'nullable|integer|min:1',
            'max_staff' => 'nullable|integer|min:1',
            'trial_days' => 'nullable|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $plan->update($data);
        return response()->json($plan);
    }

    // --- Assigner un plan à un fournisseur ---

    public function assignPlan(Request $request, $supplierId)
    {
        $request->validate(['plan_id' => 'required|exists:plans,id']);

        $supplier = Supplier::findOrFail($supplierId);
        $plan = Plan::findOrFail($request->plan_id);

        $supplier->update([
            'plan_id' => $plan->id,
            'subscription_status' => 'active',
            'subscription_ends_at' => $plan->monthly_price > 0 ? now()->addMonth() : null,
            'trial_ends_at' => null,
        ]);

        return response()->json([
            'message' => "Plan {$plan->name} assigné à {$supplier->name}.",
            'supplier' => $supplier->fresh()->load('plan'),
        ]);
    }

    // --- Stats abonnements ---

    public function stats()
    {
        return response()->json([
            'total' => Supplier::count(),
            'trial' => Supplier::where('subscription_status', 'trial')->count(),
            'active' => Supplier::where('subscription_status', 'active')->count(),
            'suspended' => Supplier::where('subscription_status', 'suspended')->count(),
            'cancelled' => Supplier::where('subscription_status', 'cancelled')->count(),
        ]);
    }
}
