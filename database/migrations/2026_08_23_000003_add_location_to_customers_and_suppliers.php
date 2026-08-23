<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Magasin du client : photo + coordonnées GPS (capture mobile)
            $table->string('shop_image_path')->nullable()->after('notes');
            $table->decimal('latitude', 10, 7)->nullable()->after('shop_image_path');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            // Magasin du fournisseur : coordonnées GPS (capture mobile)
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['shop_image_path', 'latitude', 'longitude']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
