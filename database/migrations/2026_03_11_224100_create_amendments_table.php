<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('bill_id')->constrained()->onDelete('cascade');
            $table->text('amendment_text');
            $table->string('category');
            $table->integer('support_count')->default(0);
            $table->boolean('threshold_reached')->default(false);
            $table->timestamp('threshold_reached_at')->nullable();
            $table->boolean('hidden')->default(false);
            $table->timestamps();

            $table->index('bill_id');
            $table->index('support_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amendments');
    }
};
