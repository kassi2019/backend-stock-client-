<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Middleware de cloisonnement : garantit qu'un fournisseur
 * ne voit et ne manipule que ses propres données.
 */
class EnsureTenantScope
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        // L'admin a une vue globale (sauf s'il est en mode client/fournisseur)
        if ($user->isSuperAdmin() && !session('active_mode')) {
            return $next($request);
        }

        // Mode actif depuis la session
        $activeMode = session('active_mode');

        // Si mode client actif, rediriger vers le middleware client
        if ($activeMode === 'client') {
            return response()->json(['message' => 'Mode client actif, utilisez /client.'], 403);
        }

        if ($activeMode && !in_array($activeMode, ['supplier_owner', 'supplier_staff'])) {
            return response()->json(['message' => 'Accès fournisseur requis.'], 403);
        }

        $supplierId = $user->getSupplierId();

        if (!$supplierId) {
            return response()->json(['message' => 'Aucun fournisseur associé à ce compte.'], 403);
        }

        $request->merge(['_tenant_supplier_id' => $supplierId]);

        // Vérifier le statut de l'abonnement (suspendu/annulé OU expiré par date,
        // sans dépendre de la tâche planifiée subscription:check-expiry)
        $supplier = \App\Models\Supplier::find($supplierId);
        if ($supplier && $supplier->isEffectivelyBlocked()) {
            return response()->json([
                'message' => 'Votre abonnement a expiré. Veuillez vous réabonner pour continuer.',
                'reason' => 'subscription_expired',
            ], 403);
        }

        return $next($request);
    }
}
