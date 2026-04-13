<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('email_verified_at');
            $table->boolean('is_verified')->default(false)->after('verified_at');
            $table->string('address')->nullable()->after('is_verified');
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('federal_district')->nullable()->after('longitude');
            $table->string('state_district')->nullable()->after('federal_district');
            $table->json('notification_preferences')->nullable()->after('state_district');
            $table->string('subscription_tier')->default('free')->after('notification_preferences');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'verified_at',
                'is_verified',
                'address',
                'latitude',
                'longitude',
                'federal_district',
                'state_district',
                'notification_preferences',
                'subscription_tier',
            ]);
        });
    }
};
