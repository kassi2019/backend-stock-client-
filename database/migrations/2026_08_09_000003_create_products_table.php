<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->string('name');
            $table->string('sku', 100)->nullable();
            $table->string('unit', 30)->default('piece');
            $table->string('category', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['supplier_id', 'name'], 'prod_supp_name_uq');
            $table->foreign('supplier_id', 'prod_supp_fk')->references('id')->on('suppliers')->onDelete('cascade');
            $table->index('supplier_id', 'prod_supp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
