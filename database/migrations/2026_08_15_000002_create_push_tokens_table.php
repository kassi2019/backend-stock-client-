<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token', 512);
            $table->string('platform', 20)->default('android'); // android | ios
            $table->timestamps();

            $table->unique('token', 'push_token_uq');
            $table->foreign('user_id', 'push_token_user_fk')->references('id')->on('users')->onDelete('cascade');
            $table->index('user_id', 'push_token_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
