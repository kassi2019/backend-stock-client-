<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Le téléphone devient l'identifiant de connexion principal
            $table->string('phone', 30)->nullable(false)->unique()->change();
            // L'email devient optionnel
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->change();
            $table->dropUnique('users_phone_unique');
            $table->string('email')->nullable(false)->unique()->change();
        });
    }
};
