<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('managed_contents', function (Blueprint $table): void {
            $table->string('slug', 160)->nullable()->after('audience');
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('managed_contents', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }
};
