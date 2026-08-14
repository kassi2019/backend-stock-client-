<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('supplier_id');
            $table->decimal('initial_stock', 10, 2)->default(0);
            $table->decimal('current_stock', 10, 2)->default(0);
            $table->timestamp('last_entry_at')->nullable();
            $table->string('frequency')->nullable();
            $table->decimal('reorder_point', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['customer_id', 'product_id'], 'cp_cust_prod_uq');
            $table->foreign('customer_id', 'cp_cust_fk')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('product_id', 'cp_prod_fk')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('supplier_id', 'cp_supp_fk')->references('id')->on('suppliers')->onDelete('cascade');
            $table->index('customer_id', 'cp_cust_idx');
            $table->index('supplier_id', 'cp_supp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_products');
    }
};
