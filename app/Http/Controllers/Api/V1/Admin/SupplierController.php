<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SupplierController extends Controller
{
    /**
     * Liste des fournisseurs (admin)
     */
    public function index()
    {
        $suppliers = Supplier::with(['owner:id,name,phone', 'plan'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($s) {
                return array_merge($s->toArray(), ['quota' => $s->quotaInfo()]);
            });

        return response()->json($suppliers);
    }

    /**
     * Créer un compte fournisseur
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:191',
            'owner_name' => 'required|string|max:191',
            'phone' => 'required|string|max:30|unique:users,phone',
            'email' => 'nullable|email|max:191',
            'password' => 'required|string|min:6',
        ]);

        // Créer le compte utilisateur propriétaire
        $password = $request->password;
        $user = User::create([
            'name' => $request->owner_name,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($password),
        ]);
        $user->assignRole('supplier_owner');

        // Trouver le plan d'essai par défaut
        $trialPlan = \App\Models\Plan::defaultTrial();

        // Créer le fournisseur
        $supplier = Supplier::create([
            'owner_user_id' => $user->id,
            'name' => $request->name,
            'legal_name' => $request->legal_name ?? null,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address ?? null,
            'subscription_status' => 'trial',
            'plan_id' => $trialPlan?->id,
            'trial_ends_at' => now()->addDays($trialPlan?->trial_days ?? 30),
        ]);

        return response()->json([
            'supplier' => $supplier,
            'owner_login' => $user->phone,
            'password' => $password,
        ], 201);
    }

    /**
     * Suspendre / activer un fournisseur
     */
    public function toggleStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:active,suspended,cancelled',
        ]);

        $supplier = Supplier::findOrFail($id);
        $supplier->update(['subscription_status' => $request->status]);

        return response()->json($supplier);
    }

    /**
     * Réinitialiser le mot de passe du propriétaire d'un fournisseur.
     */
    public function resetPassword(Request $request, $id)
    {
        $request->validate([
            'password' => 'required|string|min:6',
        ]);

        $supplier = Supplier::with('owner')->findOrFail($id);
        $owner = $supplier->owner;

        if (!$owner) {
            return response()->json(['message' => 'Ce fournisseur n\'a pas de compte propriétaire.'], 422);
        }

        // Le cast 'hashed' du modèle User hache automatiquement
        $owner->update(['password' => $request->password]);
        $owner->tokens()->delete(); // déconnecte tous ses appareils

        return response()->json([
            'message' => 'Mot de passe réinitialisé.',
            'login' => $owner->phone,
            'password' => $request->password,
        ]);
    }
}
