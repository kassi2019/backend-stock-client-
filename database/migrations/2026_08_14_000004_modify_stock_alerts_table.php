<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_alerts', function (Blueprint $table) {
            // Les alertes entrepôt n'ont pas de produit-client
            $table->unsignedBigInteger('customer_product_id')->nullable()->change();
            $table->unsignedBigInteger('product_id')->nullable()->after('customer_product_id');

            $table->foreign('product_id', 'sa_prod_fk')->references('id')->on('products')->onDelete('cascade');
            $table->index('product_id', 'sa_prod_idx');
        });
    }

    public function down(): void
    {
        Schema::table('stock_alerts', function (Blueprint $table) {
            $table->dropForeign('sa_prod_fk');
            $table->dropIndex('sa_prod_idx');
            $table->dropColumn('product_id');
            $table->unsignedBigInteger('customer_product_id')->nullable(false)->change();
        });
    }
};
