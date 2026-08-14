<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('currency', 5)->default('EUR')->after('monthly_price');
            $table->string('currency_symbol', 5)->default('€')->after('currency');
            $table->string('currency_position', 10)->default('after')->after('currency_symbol');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['currency', 'currency_symbol', 'currency_position']);
        });
    }
};
