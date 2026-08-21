<?php

namespace App\Services;

use App\Models\PushToken;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Envoi de notifications push via Firebase Cloud Messaging (API HTTP v1).
 *
 * L'authentification utilise le compte de service Firebase
 * (storage/keys/firebase-service-account.json) avec un JWT RS256
 * signé localement via OpenSSL — aucune dépendance externe.
 *
 * Si Firebase n'est pas configuré (fichier absent), tout est silencieux :
 * l'application continue de fonctionner normalement.
 */
class FcmPushService
{
    /**
     * Envoie une notification push à tous les appareils d'un utilisateur.
     * Ne lève jamais d'exception : une panne push ne doit pas casser le reste.
     */
    public static function sendToUser(User $user, string $title, string $body, array $data = [], ?int $badge = null): void
    {
        $tokens = PushToken::where('user_id', $user->id)->pluck('token');
        if ($tokens->isEmpty()) {
            return;
        }

        foreach ($tokens as $token) {
            try {
                static::send($token, $title, $body, $data, $badge);
            } catch (\Throwable $e) {
                // Token invalide (app désinstallée / réinstallée) : on le retire
                $msg = $e->getMessage();
                if (str_contains($msg, 'UNREGISTERED') || str_contains($msg, 'NOT_FOUND')) {
                    PushToken::where('token', $token)->delete();
                }
            }
        }
    }

    public static function send(string $token, string $title, string $body, array $data = [], ?int $badge = null): void
    {
        $projectId = static::projectId();
        if (!$projectId) {
            return; // Firebase non configuré : on ignore silencieusement
        }

        $message = [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            // data : valeurs en chaînes (exigé par FCM), ex. type, notification_id
            'data' => array_map(fn ($v) => (string) $v, $data),
            'android' => [
                'priority' => 'high',
                'notification' => ['sound' => 'default'],
            ],
        ];

        // Badge iOS (nombre de notifications non lues) : géré nativement par APNs
        if ($badge !== null) {
            $message['apns'] = [
                'payload' => [
                    'aps' => [
                        'badge' => $badge,
                        'sound' => 'default',
                    ],
                ],
            ];
        }

        $res = Http::timeout(10)
            ->withToken(static::accessToken(), 'Bearer')
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => $message,
            ]);

        if ($res->failed()) {
            $err = $res->json();
            throw new \RuntimeException(
                $err['error']['status'] ?? $err['error']['message'] ?? $res->body(),
                $res->status()
            );
        }
    }

    // --- Authentification OAuth2 par compte de service ---

    private static ?array $account = null;

    private static function account(): ?array
    {
        if (static::$account === null) {
            $path = storage_path('keys/firebase-service-account.json');
            static::$account = is_file($path) ? json_decode(file_get_contents($path), true) : null;
        }
        return static::$account;
    }

    private static function projectId(): ?string
    {
        return static::account()['project_id'] ?? null;
    }

    /** Jeton d'accès OAuth2, mis en cache ~50 minutes (valide 60 min). */
    private static function accessToken(): string
    {
        return cache()->remember('fcm_access_token', 3000, function () {
            $account = static::account();

            $header = static::b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $now = time();
            $claims = static::b64(json_encode([
                'iss' => $account['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ]));

            $unsigned = "{$header}.{$claims}";
            openssl_sign($unsigned, $signature, $account['private_key'], OPENSSL_ALGO_SHA256);
            $assertion = $unsigned . '.' . static::b64($signature);

            $res = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

            if ($res->failed()) {
                throw new \RuntimeException('Échec d\'authentification FCM : ' . $res->body());
            }

            return $res->json()['access_token'];
        });
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
