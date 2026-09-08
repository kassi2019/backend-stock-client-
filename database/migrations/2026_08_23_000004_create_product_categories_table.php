<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['supplier_id', 'name'], 'pcat_supp_name_uq');
            $table->foreign('supplier_id', 'pcat_supp_fk')->references('id')->on('suppliers')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_categories');
    }
};
