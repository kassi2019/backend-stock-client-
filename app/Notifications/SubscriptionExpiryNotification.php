<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class SubscriptionExpiryNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $type,           // 'trial_expiring' | 'subscription_expiring'
        public int $daysRemaining,     // 30 ou 15
        public string $expiresAt,      // date ISO
        public string $planName,       // nom du forfait
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        $typeLabel = $this->type === 'trial_expiring'
            ? "Votre période d'essai"
            : 'Votre abonnement';

        return [
            'type' => $this->type,
            'title' => "{$typeLabel} expire dans {$this->daysRemaining} jours",
            'message' => "{$typeLabel} « {$this->planName} » arrivera à échéance le {$this->expiresAt}. Pensez à renouveler pour éviter toute interruption.",
            'days_remaining' => $this->daysRemaining,
            'expires_at' => $this->expiresAt,
            'plan_name' => $this->planName,
        ];
    }
}
