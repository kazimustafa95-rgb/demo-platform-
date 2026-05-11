<?php

use App\Support\LegislativeChamber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('state_lower_district')->nullable()->after('state_district');
            $table->string('state_upper_district')->nullable()->after('state_lower_district');
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->string('chamber', 32)->nullable()->after('number');
            $table->index('chamber');
        });

        Schema::table('district_populations', function (Blueprint $table) {
            $table->dropUnique('district_populations_scope_unique');
            $table->string('chamber', 32)->default(LegislativeChamber::GENERAL)->after('district');
        });

        DB::table('district_populations')
            ->whereNull('chamber')
            ->update(['chamber' => LegislativeChamber::GENERAL]);

        Schema::table('district_populations', function (Blueprint $table) {
            $table->unique(
                ['jurisdiction_type', 'state_code', 'district', 'chamber'],
                'district_populations_scope_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('district_populations', function (Blueprint $table) {
            $table->dropUnique('district_populations_scope_unique');
            $table->dropColumn('chamber');
            $table->unique(
                ['jurisdiction_type', 'state_code', 'district'],
                'district_populations_scope_unique'
            );
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->dropIndex(['chamber']);
            $table->dropColumn('chamber');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['state_lower_district', 'state_upper_district']);
        });
    }
};
