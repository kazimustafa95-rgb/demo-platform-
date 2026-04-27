<?php

namespace Tests\Feature;

use App\Models\NotificationDevice;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FakeFirebaseMessagingService extends FirebaseMessagingService
{
    public array $sent = [];

    public function sendToDevice(string $deviceToken, string $title, string $body, array $data = []): array
    {
        $this->sent[] = compact('deviceToken', 'title', 'body', 'data');

        return [
            'name' => 'projects/demo/messages/fake-message-id',
        ];
    }
}

class UserNotificationPreferencesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_preferences_endpoint_returns_mobile_ready_payload(): void
    {
        $user = User::factory()->create([
            'notification_preferences' => [
                'weekly_digest' => true,
            ],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user/notification-preferences');

        $response->assertOk()
            ->assertJsonPath('title', 'Notification Preferences')
            ->assertJsonPath('summary', 'Customize how and when you receive alerts.')
            ->assertJsonPath('preferences.bill_updates', true)
            ->assertJsonPath('preferences.significance_alerts', true)
            ->assertJsonPath('preferences.weekly_digest', true)
            ->assertJsonPath('preferences.proposal_activity', true)
            ->assertJsonPath('preferences.representative_updates', false);
    }

    public function test_notification_preferences_update_persists_supported_toggles(): void
    {
        $user = User::factory()->create([
            'notification_preferences' => [
                'newsletter' => true,
            ],
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user/notification-preferences', [
            'bill_updates' => false,
            'weekly_digest' => true,
            'representative_updates' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('notification_preferences.preferences.bill_updates', false)
            ->assertJsonPath('notification_preferences.preferences.weekly_digest', true)
            ->assertJsonPath('notification_preferences.preferences.representative_updates', true);

        $user->refresh();

        $this->assertSame(true, $user->notification_preferences['newsletter']);
        $this->assertSame(false, $user->notification_preferences['bill_updates']);
        $this->assertSame(true, $user->notification_preferences['weekly_digest']);
        $this->assertSame(true, $user->notification_preferences['representative_updates']);
    }

    public function test_register_notification_device_stores_token_and_metadata(): void
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/user/notification-devices', [
            'device_token' => 'fcm-test-token',
            'platform' => 'ios',
            'device_name' => 'iPhone 15',
            'app_version' => '1.0.0',
            'notifications_enabled' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('device.device_token', 'fcm-test-token')
            ->assertJsonPath('device.platform', 'ios')
            ->assertJsonPath('device.device_name', 'iPhone 15')
            ->assertJsonPath('device.app_version', '1.0.0')
            ->assertJsonPath('device.notifications_enabled', true);

        $this->assertDatabaseHas('notification_devices', [
            'user_id' => $user->id,
            'device_token' => 'fcm-test-token',
            'platform' => 'ios',
        ]);
    }

    public function test_send_test_notification_uses_registered_device_and_enabled_preference(): void
    {
        $user = User::factory()->create([
            'notification_preferences' => [
                'bill_updates' => true,
            ],
        ]);

        NotificationDevice::create([
            'user_id' => $user->id,
            'device_token' => 'fcm-test-token',
            'platform' => 'ios',
            'notifications_enabled' => true,
            'last_seen_at' => now(),
        ]);

        $fakeService = new FakeFirebaseMessagingService();
        $this->app->instance(FirebaseMessagingService::class, $fakeService);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/user/notification-test', [
            'type' => 'bill_updates',
            'title' => 'Bill status changed',
            'body' => 'A bill you follow has moved forward.',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Test notification sent.')
            ->assertJsonPath('sent_count', 1)
            ->assertJsonPath('failed_count', 0);

        $this->assertCount(1, $fakeService->sent);
        $this->assertSame('fcm-test-token', $fakeService->sent[0]['deviceToken']);
        $this->assertSame('bill_updates', $fakeService->sent[0]['data']['type']);
    }

    public function test_unregister_notification_device_removes_the_registered_token(): void
    {
        $user = User::factory()->create();

        NotificationDevice::create([
            'user_id' => $user->id,
            'device_token' => 'fcm-test-token',
            'platform' => 'android',
            'notifications_enabled' => true,
            'last_seen_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/user/notification-devices', [
            'device_token' => 'fcm-test-token',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Notification device removed.');

        $this->assertDatabaseMissing('notification_devices', [
            'user_id' => $user->id,
            'device_token' => 'fcm-test-token',
        ]);
    }
}
