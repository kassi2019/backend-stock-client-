<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\Request;

class PushTokenController extends Controller
{
    /**
     * Enregistre le token push de l'appareil pour l'utilisateur connecté.
     */
    public function store(Request $request)
    {
        $request->validate([
            'token' => 'required|string|max:512',
            'platform' => 'nullable|in:android,ios',
        ]);

        // Un token est lié à un seul appareil : s'il appartenait à un autre
        // compte (changement d'utilisateur sur le même téléphone), on le déplace.
        PushToken::updateOrCreate(
            ['token' => $request->token],
            [
                'user_id' => $request->user()->id,
                'platform' => $request->platform ?? 'android',
            ],
        );

        return response()->json(['message' => 'Token enregistré.'], 201);
    }

    /**
     * Retire le token push (déconnexion).
     */
    public function destroy(Request $request)
    {
        $request->validate(['token' => 'required|string|max:512']);

        PushToken::where('token', $request->token)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['message' => 'Token supprimé.']);
    }
}
