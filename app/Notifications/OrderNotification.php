<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class OrderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $type,        // 'order_created' | 'order_accepted' | 'order_rejected' | 'order_delivered'
        public int $orderId,
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
            'order_id' => $this->orderId,
        ];
    }
}
