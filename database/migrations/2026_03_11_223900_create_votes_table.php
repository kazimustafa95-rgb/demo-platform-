<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->onDelete('cascade');
            $table->foreignId('representative_id')->constrained()->onDelete('cascade');
            $table->string('vote');
            $table->string('roll_call_id')->nullable();
            $table->dateTime('vote_date')->nullable();
            $table->timestamps();

            $table->unique(['bill_id', 'representative_id', 'roll_call_id'], 'unique_rep_vote');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};
