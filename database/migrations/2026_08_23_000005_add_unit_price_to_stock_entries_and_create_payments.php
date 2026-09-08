<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prix figé au moment de chaque livraison (pour le calcul du crédit client)
        Schema::table('stock_entries', function (Blueprint $table) {
            $table->decimal('unit_price', 10, 2)->nullable()->after('quantity');
        });

        // Encaissements reçus des clients
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('customer_id');
            $table->decimal('amount', 12, 2);
            $table->string('note')->nullable();
            $table->timestamp('paid_at');
            $table->unsignedBigInteger('entered_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('supplier_id', 'pay_supp_fk')->references('id')->on('suppliers')->onDelete('cascade');
            $table->foreign('customer_id', 'pay_cust_fk')->references('id')->on('customers')->onDelete('cascade');
            $table->index('customer_id', 'pay_cust_idx');
            $table->index(['supplier_id', 'customer_id'], 'pay_supp_cust_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::table('stock_entries', function (Blueprint $table) {
            $table->dropColumn('unit_price');
        });
    }
};
