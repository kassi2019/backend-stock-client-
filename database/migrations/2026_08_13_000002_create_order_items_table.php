<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            // set null : les customer_products sont supprimés en dur (detachProduct)
            $table->unsignedBigInteger('customer_product_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name');    // instantané — visible même si le produit est supprimé
            $table->string('unit')->nullable(); // instantané
            $table->decimal('quantity', 10, 2);
            $table->timestamps();

            $table->foreign('order_id', 'oi_order_fk')->references('id')->on('orders')->onDelete('cascade');
            $table->foreign('customer_product_id', 'oi_cp_fk')->references('id')->on('customer_products')->onDelete('set null');
            $table->foreign('product_id', 'oi_prod_fk')->references('id')->on('products')->onDelete('set null');
            $table->index('order_id', 'oi_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
