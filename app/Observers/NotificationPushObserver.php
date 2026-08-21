<?php

namespace App\Observers;

use App\Models\User;
use App\Services\FcmPushService;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Chaque notification enregistrée en base est aussi envoyée en push
 * aux appareils de l'utilisateur (si Firebase est configuré).
 * Un seul point de branchement : tous les types de notifications
 * (produits, commandes, abonnement…) sont couverts automatiquement.
 */
class NotificationPushObserver
{
    public function created(DatabaseNotification $notification): void
    {
        if ($notification->notifiable_type !== User::class) {
            return;
        }

        $user = User::find($notification->notifiable_id);
        if (!$user) {
            return;
        }

        $data = $notification->data ?? [];
        $title = $data['title'] ?? 'STOCK360';
        $body = $data['message'] ?? '';

        // Badge : nombre de notifications non lues (géré nativement par iOS)
        $badge = $user->unreadNotifications()->count();

        FcmPushService::sendToUser($user, $title, $body, [
            'type' => $data['type'] ?? '',
            'notification_id' => (string) $notification->id,
        ], $badge);
    }
}
