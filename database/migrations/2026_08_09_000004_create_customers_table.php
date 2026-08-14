<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('owner_user_id')->nullable();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->string('default_frequency')->default('daily');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['supplier_id', 'email'], 'cust_supp_email_uq');
            $table->unique('owner_user_id', 'cust_owner_uq');
            $table->foreign('supplier_id', 'cust_supp_fk')->references('id')->on('suppliers')->onDelete('cascade');
            $table->foreign('owner_user_id', 'cust_owner_fk')->references('id')->on('users')->onDelete('set null');
            $table->index('supplier_id', 'cust_supp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
