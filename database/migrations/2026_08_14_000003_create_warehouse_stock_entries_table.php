<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stock_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('supplier_id');
            $table->decimal('quantity', 10, 2); // delta signé : +réception, -livraison, ±ajustement
            $table->string('note')->nullable();
            $table->string('entry_type')->default('receipt'); // receipt|delivery|adjustment
            $table->unsignedBigInteger('entered_by_user_id')->nullable();
            $table->date('entry_date');
            $table->timestamps();

            $table->foreign('product_id', 'wse_prod_fk')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('supplier_id', 'wse_supp_fk')->references('id')->on('suppliers')->onDelete('cascade');
            $table->foreign('entered_by_user_id', 'wse_user_fk')->references('id')->on('users')->onDelete('set null');
            $table->index('product_id', 'wse_prod_idx');
            $table->index('supplier_id', 'wse_supp_idx');
            $table->index(['product_id', 'entry_date'], 'wse_prod_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_stock_entries');
    }
};
