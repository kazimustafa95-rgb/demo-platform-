<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('amendments', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('id');
            $table->string('source')->default('user')->after('external_id');
            $table->unsignedInteger('congress')->nullable()->after('bill_id');
            $table->string('amendment_type')->nullable()->after('congress');
            $table->string('amendment_number')->nullable()->after('amendment_type');
            $table->string('chamber')->nullable()->after('amendment_number');
            $table->json('sponsors')->nullable()->after('chamber');
            $table->json('latest_action')->nullable()->after('sponsors');
            $table->dateTime('proposed_at')->nullable()->after('latest_action');
            $table->dateTime('submitted_at')->nullable()->after('proposed_at');
            $table->string('text_url')->nullable()->after('submitted_at');
            $table->string('congress_gov_url')->nullable()->after('text_url');
            $table->json('metadata')->nullable()->after('congress_gov_url');

            $table->unique('external_id');
            $table->index(['source', 'bill_id']);
        });
    }

    public function down(): void
    {
        Schema::table('amendments', function (Blueprint $table) {
            $table->dropUnique(['external_id']);
            $table->dropIndex(['source', 'bill_id']);
            $table->dropColumn([
                'external_id',
                'source',
                'congress',
                'amendment_type',
                'amendment_number',
                'chamber',
                'sponsors',
                'latest_action',
                'proposed_at',
                'submitted_at',
                'text_url',
                'congress_gov_url',
                'metadata',
            ]);
        });
    }
};
