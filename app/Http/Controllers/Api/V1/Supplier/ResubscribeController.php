<?php

namespace App\Http\Controllers\Api\V1\Supplier;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Supplier;
use Illuminate\Http\Request;

class ResubscribeController extends Controller
{
    /**
     * Liste des forfaits disponibles pour réabonnement.
     * Accessible sans le middleware tenant (auth:sanctum seulement).
     */
    public function plans()
    {
        $plans = Plan::where('is_active', true)
            ->where('slug', '!=', 'trial')
            ->orderBy('monthly_price')
            ->get()
            ->map(fn($plan) => [
                'id' => $plan->id,
                'name' => $plan->name,
                'slug' => $plan->slug,
                'monthly_price' => $plan->monthly_price,
                'currency_symbol' => $plan->currency_symbol ?? '€',
                'currency_position' => $plan->currency_position ?? 'after',
                'formatted_price' => $plan->formattedPrice(),
                'max_products' => $plan->max_products,
                'max_customers' => $plan->max_customers,
                'max_staff' => $plan->max_staff,
            ]);

        return response()->json(['plans' => $plans]);
    }

    /**
     * Réactiver un abonnement.
     * Accessible sans le middleware tenant (auth:sanctum seulement).
     */
    public function resubscribe(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:plans,id',
        ]);

        $user = $request->user();
        $supplier = $user->ownedSupplier
            ?? $user->suppliers()->wherePivot('is_active', true)->first();

        if (!$supplier) {
            return response()->json(['message' => 'Aucun fournisseur associé à ce compte.'], 403);
        }

        // Accepte aussi un abonnement expiré par date mais encore 'active' en base
        // (la tâche planifiée n'ayant pas encore basculé le statut en 'suspended').
        if (!$supplier->isEffectivelyBlocked()) {
            return response()->json(['message' => 'Votre abonnement est déjà actif.'], 400);
        }

        $plan = Plan::findOrFail($request->plan_id);

        // Réinitialiser les alertes J-30/J-15
        $settings = $supplier->settings ?? [];
        unset($settings['alert_30d_sent'], $settings['alert_15d_sent']);

        $supplier->update([
            'plan_id' => $plan->id,
            'subscription_status' => 'active',
            'subscription_ends_at' => $plan->monthly_price > 0 ? now()->addMonth() : null,
            'trial_ends_at' => null,
            'settings' => $settings,
        ]);

        return response()->json([
            'message' => "Abonnement « {$plan->name} » activé avec succès !",
            'plan_name' => $plan->name,
        ]);
    }
}
