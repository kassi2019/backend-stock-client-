<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_product_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('type');
            $table->string('severity')->default('info');
            $table->string('message')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('customer_product_id', 'sa_cp_fk')->references('id')->on('customer_products')->onDelete('cascade');
            $table->foreign('supplier_id', 'sa_supp_fk')->references('id')->on('suppliers')->onDelete('cascade');
            $table->index('supplier_id', 'sa_supp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
    }
};
