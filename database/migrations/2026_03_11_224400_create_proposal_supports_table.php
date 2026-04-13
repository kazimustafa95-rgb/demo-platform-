<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_supports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('citizen_proposal_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'citizen_proposal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_supports');
    }
};
