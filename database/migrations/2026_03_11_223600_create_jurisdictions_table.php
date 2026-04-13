<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jurisdictions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // federal or state
            $table->string('code')->nullable(); // e.g. TX
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jurisdictions');
    }
};
