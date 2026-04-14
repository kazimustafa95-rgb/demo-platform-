<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('district_populations', function (Blueprint $table) {
            $table->id();
            $table->string('jurisdiction_type', 32);
            $table->string('state_code', 2)->nullable();
            $table->string('district', 64);
            $table->unsignedBigInteger('registered_voter_count');
            $table->string('provider')->nullable();
            $table->string('source_reference')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['jurisdiction_type', 'state_code', 'district'],
                'district_populations_scope_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('district_populations');
    }
};
