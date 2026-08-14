<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Connexion par téléphone + mot de passe
     */
    public function login(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
            'device_name' => 'required|string',
        ]);

        $user = User::where('phone', $request->phone)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['Numéro de téléphone ou mot de passe incorrect.'],
            ]);
        }

        if (!$user->is_active) {
            return response()->json(['message' => 'Compte désactivé.'], 403);
        }

        // Détection de partage de compte client (max 2 tokens)
        if ($user->isClient()) {
            $tokenCount = $user->tokens()->count();
            if ($tokenCount >= 2) {
                // Révoquer les anciens tokens pour n'en garder qu'un actif
                $user->tokens()->delete();
            }
        }

        // Créer le token avec les abilities selon le rôle
        $abilities = $this->getAbilitiesForUser($user);
        $token = $user->createToken($request->device_name, $abilities)->plainTextToken;

        // Mettre à jour les infos de connexion
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ]);
    }

    /**
     * Déconnexion (révoque le token courant)
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté avec succès.']);
    }

    /**
     * Profil de l'utilisateur connecté avec ses modes disponibles
     */
    public function me(Request $request)
    {
        $user = $request->user();

        $data = $this->formatUser($user);

        // Lister tous les modes disponibles
        $modes = [];

        // Mode admin
        if ($user->isSuperAdmin()) {
            $modes[] = [
                'mode' => 'super_admin',
                'label' => 'Administrateur',
                'icon' => '⚙️',
            ];
        }

        // Mode fournisseur (propriétaire)
        if ($user->ownedSupplier) {
            $modes[] = [
                'mode' => 'supplier_owner',
                'label' => $user->ownedSupplier->name,
                'icon' => '🏭',
                'supplier_id' => $user->ownedSupplier->id,
                'supplier_name' => $user->ownedSupplier->name,
                'subscription_status' => $user->ownedSupplier->effectiveSubscriptionStatus(),
                'subscription_ends_at' => $user->ownedSupplier->subscription_ends_at,
                'trial_ends_at' => $user->ownedSupplier->trial_ends_at,
            ];
        }

        // Mode collaborateur
        foreach ($user->suppliers()->wherePivot('is_active', true)->get() as $sup) {
            if (!$user->ownedSupplier || $sup->id !== $user->ownedSupplier->id) {
                $modes[] = [
                    'mode' => 'supplier_staff',
                    'label' => $sup->name . ' (collab.)',
                    'icon' => '🏢',
                    'supplier_id' => $sup->id,
                    'supplier_name' => $sup->name,
                    'subscription_status' => $sup->effectiveSubscriptionStatus(),
                    'subscription_ends_at' => $sup->subscription_ends_at,
                    'trial_ends_at' => $sup->trial_ends_at,
                ];
            }
        }

        // Mode client
        if ($user->customer && $user->customer->is_active) {
            $modes[] = [
                'mode' => 'client',
                'label' => $user->customer->name,
                'icon' => '👤',
                'customer_id' => $user->customer->id,
                'customer_name' => $user->customer->name,
                'supplier_name' => $user->customer->supplier?->name,
            ];
        }

        $data['modes'] = $modes;

        // Mode actif (stocké en session ou premier disponible)
        $activeMode = $request->input('active_mode') ?? session('active_mode');
        if ($activeMode) {
            $data['active_mode'] = $activeMode;
            if ($activeMode === 'client') {
                $data['customer'] = $user->customer;
                $data['supplier_id'] = $user->customer?->supplier_id;
            } else {
                $data['supplier_id'] = $user->getSupplierId();
                $data['supplier'] = $user->ownedSupplier
                    ?? $user->suppliers()->wherePivot('is_active', true)->first();
            }
        } else {
            // Premier mode par défaut
            $first = $modes[0] ?? null;
            if ($first) {
                $data['active_mode'] = $first['mode'];
                if ($first['mode'] === 'client') {
                    $data['customer'] = $user->customer;
                    $data['supplier_id'] = $user->customer?->supplier_id;
                } else {
                    $data['supplier_id'] = $first['supplier_id'] ?? null;
                    $data['supplier'] = $user->ownedSupplier
                        ?? $user->suppliers()->wherePivot('is_active', true)->first();
                }
            }
        }

        // Si un seul mode, pas besoin de sélecteur
        $data['show_mode_selector'] = count($modes) > 1;

        return response()->json($data);
    }

    /**
     * Changer de mode actif
     */
    public function switchMode(Request $request)
    {
        $request->validate([
            'mode' => 'required|string',
            'supplier_id' => 'nullable|integer',
            'customer_id' => 'nullable|integer',
        ]);

        $user = $request->user();
        $mode = $request->mode;

        // Vérifier que l'utilisateur a bien accès à ce mode
        if ($mode === 'super_admin' && !$user->isSuperAdmin()) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if (in_array($mode, ['supplier_owner', 'supplier_staff'])) {
            $sid = $request->supplier_id;
            $hasAccess = $user->ownedSupplier?->id === $sid
                || $user->suppliers()->where('suppliers.id', $sid)->wherePivot('is_active', true)->exists();
            if (!$hasAccess) {
                return response()->json(['message' => 'Accès refusé à ce fournisseur.'], 403);
            }
        }

        if ($mode === 'client' && (!$user->customer || $user->customer->id != $request->customer_id)) {
            return response()->json(['message' => 'Accès refusé à ce compte client.'], 403);
        }

        session(['active_mode' => $mode]);

        return response()->json([
            'message' => 'Mode changé.',
            'active_mode' => $mode,
        ]);
    }

    private function getAbilitiesForUser(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return ['admin', 'supplier:read', 'supplier:write', 'client:read', 'client:write'];
        }

        if ($user->isSupplier()) {
            return ['supplier:read', 'supplier:write'];
        }

        return ['client:read', 'client:write'];
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'roles' => $user->getRoleNames(),
            'is_active' => $user->is_active,
            'avatar_path' => $user->avatar_path,
        ];
    }
}
