<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('identity_verification_provider')->nullable()->after('is_verified');
            $table->string('identity_verification_status')->nullable()->after('identity_verification_provider');
            $table->string('identity_verification_reference')->nullable()->after('identity_verification_status');
            $table->timestamp('identity_verified_at')->nullable()->after('identity_verification_reference');
            $table->json('identity_verification_meta')->nullable()->after('identity_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'identity_verification_provider',
                'identity_verification_status',
                'identity_verification_reference',
                'identity_verified_at',
                'identity_verification_meta',
            ]);
        });
    }
};
