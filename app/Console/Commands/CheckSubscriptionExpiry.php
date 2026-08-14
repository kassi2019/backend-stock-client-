<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Notifications\SubscriptionExpiryNotification;
use Illuminate\Console\Command;

class CheckSubscriptionExpiry extends Command
{
    protected $signature = 'subscription:check-expiry';
    protected $description = 'Vérifie les abonnements expirés et envoie les notifications J-30/J-15';

    public function handle(): int
    {
        $now = now();

        // 1. Suspension automatique des abonnements expirés
        $expired = Supplier::where(function ($q) use ($now) {
                $q->where('subscription_status', 'trial')
                  ->whereNotNull('trial_ends_at')
                  ->where('trial_ends_at', '<', $now);
            })->orWhere(function ($q) use ($now) {
                $q->where('subscription_status', 'active')
                  ->whereNotNull('subscription_ends_at')
                  ->where('subscription_ends_at', '<', $now);
            })->get();

        foreach ($expired as $supplier) {
            $supplier->update(['subscription_status' => 'suspended']);
            $this->info("Suspendu : {$supplier->name} (ID: {$supplier->id})");
        }

        // 2. Notifications J-30 et J-15
        $active = Supplier::whereIn('subscription_status', ['trial', 'active'])->with('owner')->get();

        foreach ($active as $supplier) {
            if (!$supplier->owner) continue;

            $endDate = $supplier->subscription_status === 'trial'
                ? $supplier->trial_ends_at
                : $supplier->subscription_ends_at;

            if (!$endDate) continue;

            $daysRemaining = (int) $now->diffInDays($endDate, false);

            $settings = $supplier->settings ?? [];
            $planName = $supplier->plan?->name ?? 'Actuel';
            $expiresAt = $endDate->format('d/m/Y');
            $type = $supplier->subscription_status === 'trial' ? 'trial_expiring' : 'subscription_expiring';

            // J-30
            if ($daysRemaining <= 30 && $daysRemaining > 15 && empty($settings['alert_30d_sent'])) {
                $supplier->owner->notify(new SubscriptionExpiryNotification($type, 30, $expiresAt, $planName));
                $settings['alert_30d_sent'] = true;
                $supplier->settings = $settings;
                $supplier->save();
                $this->info("Notif J-30 envoyée à : {$supplier->name}");
            }

            // J-15
            if ($daysRemaining <= 15 && $daysRemaining > 0 && empty($settings['alert_15d_sent'])) {
                $supplier->owner->notify(new SubscriptionExpiryNotification($type, 15, $expiresAt, $planName));
                $settings['alert_15d_sent'] = true;
                $supplier->settings = $settings;
                $supplier->save();
                $this->info("Notif J-15 envoyée à : {$supplier->name}");
            }
        }

        $this->info('Vérification terminée.');
        return self::SUCCESS;
    }
}
