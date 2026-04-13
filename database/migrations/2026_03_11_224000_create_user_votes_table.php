<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('bill_id')->constrained()->onDelete('cascade');
            $table->enum('vote', ['in_favor', 'against', 'abstain']);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'bill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_votes');
    }
};
