<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 191)->unique();
            $table->foreignId('jurisdiction_id')->constrained();
            $table->string('number');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('status', 64);
            $table->dateTime('introduced_date')->nullable();
            $table->dateTime('official_vote_date')->nullable();
            $table->dateTime('voting_deadline')->nullable();
            $table->string('bill_text_url')->nullable();
            $table->json('sponsors')->nullable();
            $table->json('committees')->nullable();
            $table->json('amendments_history')->nullable();
            $table->json('related_documents')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('voting_deadline');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
