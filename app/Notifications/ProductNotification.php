<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProductNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $type,        // 'product_created' | 'price_updated'
        public int $productId,
        public string $title,
        public string $message,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'product_id' => $this->productId,
        ];
    }

    /**
     * Formatage d'un montant selon la monnaie du fournisseur (ex. « 12,50 € »).
     */
    public static function formatMoney(?float $price, ?string $symbol, string $position): string
    {
        $symbol = $symbol ?: '€';
        $position = $position ?: 'after';
        $formatted = number_format($price, 2, ',', ' ');

        return $position === 'before'
            ? "{$symbol} {$formatted}"
            : "{$formatted} {$symbol}";
    }
}
