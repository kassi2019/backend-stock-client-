<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_product_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('supplier_id');
            $table->decimal('quantity', 10, 2);
            $table->string('note')->nullable();
            $table->string('entry_type')->default('declared');
            $table->string('source')->default('client');
            $table->unsignedBigInteger('entered_by_user_id')->nullable();
            $table->date('entry_date');
            $table->timestamps();

            $table->foreign('customer_product_id', 'se_cp_fk')->references('id')->on('customer_products')->onDelete('cascade');
            $table->foreign('customer_id', 'se_cust_fk')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('supplier_id', 'se_supp_fk')->references('id')->on('suppliers')->onDelete('cascade');
            $table->foreign('entered_by_user_id', 'se_user_fk')->references('id')->on('users')->onDelete('set null');
            $table->index('customer_product_id', 'se_cp_idx');
            $table->index('customer_id', 'se_cust_idx');
            $table->index('supplier_id', 'se_supp_idx');
            $table->index(['customer_product_id', 'entry_date'], 'se_cp_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_entries');
    }
};
