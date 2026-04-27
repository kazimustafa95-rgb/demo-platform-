<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notification_devices')) {
            Schema::create('notification_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('device_token', 255);
                $table->string('platform', 20)->nullable();
                $table->string('device_name', 100)->nullable();
                $table->string('app_version', 50)->nullable();
                $table->boolean('notifications_enabled')->default(true);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->index('user_id');
                $table->unique('device_token');
            });

            return;
        }

        if (!$this->indexExists('notification_devices', 'notification_devices_user_id_index')) {
            DB::statement('ALTER TABLE `notification_devices` ADD INDEX `notification_devices_user_id_index` (`user_id`)');
        }

        if (!$this->indexExists('notification_devices', 'notification_devices_device_token_unique')) {
            DB::statement('ALTER TABLE `notification_devices` ADD UNIQUE `notification_devices_device_token_unique` (`device_token`)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_devices');
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return !empty(DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName]
        ));
    }
};
