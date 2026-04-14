<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_contents', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 64);
            $table->string('audience', 64)->default('global');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('body');
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_published')->default(true);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'audience']);
            $table->index(['is_published', 'published_at']);
            $table->index('display_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_contents');
    }
};
