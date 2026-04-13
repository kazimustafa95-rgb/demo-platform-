<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $supportsFullText = Schema::getConnection()->getDriverName() !== 'sqlite';

        Schema::create('citizen_proposals', function (Blueprint $table) use ($supportsFullText) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->text('content');
            $table->string('category');
            $table->string('jurisdiction_focus');
            $table->integer('support_count')->default(0);
            $table->boolean('threshold_reached')->default(false);
            $table->timestamp('threshold_reached_at')->nullable();
            $table->boolean('is_duplicate')->default(false);
            $table->boolean('hidden')->default(false);
            $table->timestamps();

            if ($supportsFullText) {
                $table->fullText('content');
            }

            $table->index('support_count');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citizen_proposals');
    }
};
