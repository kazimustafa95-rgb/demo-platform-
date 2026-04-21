<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE representatives MODIFY years_in_office_start SMALLINT UNSIGNED NULL');
        DB::statement('ALTER TABLE representatives MODIFY years_in_office_end SMALLINT UNSIGNED NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE representatives MODIFY years_in_office_start YEAR NULL');
        DB::statement('ALTER TABLE representatives MODIFY years_in_office_end YEAR NULL');
    }
};
