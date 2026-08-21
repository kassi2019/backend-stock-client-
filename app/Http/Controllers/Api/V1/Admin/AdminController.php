<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    /**
     * Liste des administrateurs (principal + secondaires).
     * Réservé à l'admin principal (middleware role:super_admin).
     */
    public function index()
    {
        $admins = User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['super_admin', 'admin']);
        })
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'phone' => $u->phone,
                    'email' => $u->email,
                    'is_primary' => $u->isSuperAdmin(),
                    'created_at' => $u->created_at?->toISOString(),
                ];
            });

        return response()->json($admins);
    }

    /**
     * Créer un administrateur secondaire (réservé au principal).
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:191',
            'phone' => 'required|string|max:30|unique:users,phone',
            'email' => 'nullable|email|max:191',
            'password' => 'required|string|min:6',
        ]);

        $password = $request->password;
        $admin = User::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'password' => Hash::make($password),
        ]);
        $admin->assignRole('admin');

        return response()->json([
            'admin' => ['id' => $admin->id, 'name' => $admin->name, 'phone' => $admin->phone],
            'login' => $admin->phone,
            'password' => $password,
        ], 201);
    }

    /**
     * Supprimer un administrateur secondaire (réservé au principal).
     */
    public function destroy(Request $request, $id)
    {
        $target = User::findOrFail($id);

        if ($target->id === $request->user()->id) {
            return response()->json([
                'message' => 'Vous ne pouvez pas supprimer votre propre compte.',
            ], 403);
        }

        if ($target->isSuperAdmin()) {
            return response()->json([
                'message' => 'Le compte administrateur principal ne peut pas être supprimé.',
            ], 403);
        }

        if (!$target->hasRole('admin')) {
            return response()->json(['message' => 'Administrateur non trouvé.'], 404);
        }

        // Suppression définitive : libère le téléphone (contrainte unique)
        $target->forceDelete();

        return response()->json(['message' => 'Administrateur supprimé.']);
    }
}
