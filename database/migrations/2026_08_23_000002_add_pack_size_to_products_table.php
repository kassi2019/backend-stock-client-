<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Nombre d'unités par paquet (ex. 6 bouteilles/paquet). Null = pas de paquet.
            $table->unsignedInteger('pack_size')->nullable()->after('stock_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('pack_size');
        });
    }
};
