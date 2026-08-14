<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Essai',
                'slug' => 'trial',
                'monthly_price' => 0,
                'max_products' => 10,
                'max_customers' => 5,
                'max_staff' => 1,
                'trial_days' => 30,
            ],
            [
                'name' => 'Standard',
                'slug' => 'standard',
                'monthly_price' => 29,
                'max_products' => 50,
                'max_customers' => 25,
                'max_staff' => 2,
                'trial_days' => 0,
            ],
            [
                'name' => 'Premium',
                'slug' => 'premium',
                'monthly_price' => 59,
                'max_products' => 200,
                'max_customers' => 100,
                'max_staff' => 5,
                'trial_days' => 0,
            ],
            [
                'name' => 'Illimité',
                'slug' => 'unlimited',
                'monthly_price' => 99,
                'max_products' => null,
                'max_customers' => null,
                'max_staff' => 10,
                'trial_days' => 0,
            ],
        ];

        foreach ($plans as $plan) {
            $plan['currency'] = $plan['currency'] ?? 'EUR';
            $plan['currency_symbol'] = $plan['currency_symbol'] ?? '€';
            $plan['currency_position'] = $plan['currency_position'] ?? 'after';
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
