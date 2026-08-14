<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    /**
     * Met à jour la photo de profil de l'utilisateur connecté.
     */
    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,webp|max:2048',
        ]);

        $user = $request->user();

        // Supprimer l'ancienne photo
        if ($user->avatar_path) {
            Storage::disk('avatars')->delete($user->avatar_path);
        }

        // putFile : nom aléatoire → l'URL change à chaque upload (pas de cache)
        $path = Storage::disk('avatars')->putFile('avatars', $request->file('avatar'));

        $user->update(['avatar_path' => $path]);

        return response()->json([
            'message' => 'Photo de profil mise à jour.',
            'avatar_path' => $user->avatar_path,
        ]);
    }

    /**
     * Change le mot de passe de l'utilisateur connecté.
     * Révoque les autres sessions (tokens) mais garde la session courante.
     */
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Mot de passe actuel incorrect.'],
            ]);
        }

        // Le cast 'hashed' du modèle User hache automatiquement
        $user->update(['password' => $request->password]);

        // Déconnecter les autres appareils, garder la session courante
        $user->tokens()
            ->where('id', '!=', $user->currentAccessToken()->id)
            ->delete();

        return response()->json(['message' => 'Mot de passe modifié avec succès.']);
    }
}
