<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('representatives', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 191)->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('party')->nullable();
            $table->string('chamber');
            $table->string('district')->nullable();
            $table->foreignId('jurisdiction_id')->constrained();
            $table->string('photo_url')->nullable();
            $table->json('contact_info')->nullable();
            $table->json('committee_assignments')->nullable();
            $table->year('years_in_office_start')->nullable();
            $table->year('years_in_office_end')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('representatives');
    }
};
