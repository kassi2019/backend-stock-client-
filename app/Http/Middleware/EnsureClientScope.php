<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Middleware de cloisonnement client : garantit qu'un client final
 * ne voit et ne manipule que ses propres données.
 */
class EnsureClientScope
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Non authentifié.'], 401);
        }

        if (!$user->isClient()) {
            return response()->json(['message' => 'Accès réservé aux clients.'], 403);
        }

        $customer = $user->customer;

        if (!$customer || !$customer->is_active) {
            return response()->json(['message' => 'Aucun compte client associé.'], 403);
        }

        // Injecter les identifiants de cloisonnement
        $request->merge([
            '_tenant_customer_id' => $customer->id,
            '_tenant_supplier_id' => $customer->supplier_id,
        ]);

        return $next($request);
    }
}
