<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->string('ai_summary_plain', 500)->nullable();
            $table->string('ai_bill_impact', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropColumn([
                'ai_summary_plain',
                'ai_bill_impact',
            ]);
        });
    }
};
