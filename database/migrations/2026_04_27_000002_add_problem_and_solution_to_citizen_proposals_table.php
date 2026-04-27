<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('citizen_proposals', function (Blueprint $table): void {
            $table->text('problem_statement')->nullable()->after('content');
            $table->text('proposed_solution')->nullable()->after('problem_statement');
        });
    }

    public function down(): void
    {
        Schema::table('citizen_proposals', function (Blueprint $table): void {
            $table->dropColumn([
                'problem_statement',
                'proposed_solution',
            ]);
        });
    }
};
