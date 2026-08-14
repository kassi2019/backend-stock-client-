<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_user', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('manager');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->primary(['supplier_id', 'user_id'], 'sup_usr_pk');
            $table->foreign('supplier_id', 'sup_usr_supp_fk')->references('id')->on('suppliers')->onDelete('cascade');
            $table->foreign('user_id', 'sup_usr_user_fk')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_user');
    }
};
