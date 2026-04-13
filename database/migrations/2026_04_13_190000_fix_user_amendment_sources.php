<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('amendments')
            ->where('source', 'congress_gov')
            ->whereNull('external_id')
            ->update(['source' => 'user']);
    }

    public function down(): void
    {
        DB::table('amendments')
            ->where('source', 'user')
            ->whereNull('external_id')
            ->update(['source' => 'congress_gov']);
    }
};
