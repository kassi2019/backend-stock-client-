<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('status')->default('pending'); // pending|accepted|rejected|cancelled|delivered
            $table->text('note')->nullable();              // note du client
            $table->string('rejection_reason')->nullable(); // motif si refusée
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('accepted_by_user_id')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->unsignedBigInteger('rejected_by_user_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('delivered_by_user_id')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->foreign('supplier_id', 'ord_supp_fk')->references('id')->on('suppliers')->onDelete('cascade');
            $table->foreign('customer_id', 'ord_cust_fk')->references('id')->on('customers')->onDelete('cascade');
            $table->foreign('created_by_user_id', 'ord_created_fk')->references('id')->on('users')->onDelete('set null');
            $table->foreign('accepted_by_user_id', 'ord_accepted_fk')->references('id')->on('users')->onDelete('set null');
            $table->foreign('rejected_by_user_id', 'ord_rejected_fk')->references('id')->on('users')->onDelete('set null');
            $table->foreign('cancelled_by_user_id', 'ord_cancelled_fk')->references('id')->on('users')->onDelete('set null');
            $table->foreign('delivered_by_user_id', 'ord_delivered_fk')->references('id')->on('users')->onDelete('set null');
            $table->index(['supplier_id', 'status'], 'ord_supp_status_idx');
            $table->index(['customer_id', 'status'], 'ord_cust_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
