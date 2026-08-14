<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('monthly_price', 10, 2)->default(0);
            $table->integer('max_products')->nullable();
            $table->integer('max_customers')->nullable();
            $table->integer('max_staff')->default(1);
            $table->integer('trial_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Ajouter colonnes abonnement à suppliers
        Schema::table('suppliers', function (Blueprint $table) {
            if (!Schema::hasColumn('suppliers', 'trial_ends_at')) {
                $table->timestamp('trial_ends_at')->nullable();
            }
            if (!Schema::hasColumn('suppliers', 'subscription_ends_at')) {
                $table->timestamp('subscription_ends_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['trial_ends_at', 'subscription_ends_at']);
        });
        Schema::dropIfExists('plans');
    }
};
