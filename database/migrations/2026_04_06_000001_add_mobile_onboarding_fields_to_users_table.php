<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->after('email');
            $table->string('email_verification_code')->nullable()->after('email_verified_at');
            $table->timestamp('email_verification_code_expires_at')->nullable()->after('email_verification_code');
            $table->timestamp('email_verification_code_sent_at')->nullable()->after('email_verification_code_expires_at');
            $table->string('country')->nullable()->after('address');
            $table->string('state')->nullable()->after('country');
            $table->string('district')->nullable()->after('state');
            $table->string('zip_code')->nullable()->after('district');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone_number',
                'email_verification_code',
                'email_verification_code_expires_at',
                'email_verification_code_sent_at',
                'country',
                'state',
                'district',
                'zip_code',
            ]);
        });
    }
};
