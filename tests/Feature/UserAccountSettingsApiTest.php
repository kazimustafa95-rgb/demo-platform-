<?php

namespace Tests\Feature;

use App\Models\ManagedContent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAccountSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_preferences_endpoint_returns_mobile_ready_preferences_payload(): void
    {
        $user = User::factory()->create([
            'notification_preferences' => [
                'newsletter' => true,
            ],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user/email-preferences');

        $response->assertOk()
            ->assertJsonPath('summary', 'Manage how you receive email notifications from us.')
            ->assertJsonPath('preferences.account_updates', true)
            ->assertJsonPath('preferences.security_alerts', true)
            ->assertJsonPath('preferences.reminders', true)
            ->assertJsonPath('preferences.promotions', false)
            ->assertJsonPath('preferences.newsletter', true)
            ->assertJsonFragment([
                'key' => 'marketing',
                'title' => 'Marketing',
            ]);
    }

    public function test_email_preferences_update_persists_supported_toggles_and_preserves_other_notification_settings(): void
    {
        $user = User::factory()->create([
            'notification_preferences' => [
                'push' => true,
                'email' => false,
                'newsletter' => false,
            ],
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user/email-preferences', [
            'account_updates' => false,
            'promotions' => true,
            'newsletter' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('email_preferences.preferences.account_updates', false)
            ->assertJsonPath('email_preferences.preferences.promotions', true)
            ->assertJsonPath('email_preferences.preferences.newsletter', true)
            ->assertJsonPath('email_preferences.preferences.security_alerts', true);

        $user->refresh();

        $this->assertSame(true, $user->notification_preferences['push']);
        $this->assertSame(false, $user->notification_preferences['email']);
        $this->assertSame(false, $user->notification_preferences['account_updates']);
        $this->assertSame(true, $user->notification_preferences['promotions']);
        $this->assertSame(true, $user->notification_preferences['newsletter']);
    }

    public function test_delete_user_endpoint_soft_deletes_account_and_revokes_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('iphone')->plainTextToken;

        $response = $this->withToken($token)->deleteJson('/api/user', [
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Account deleted successfully.');

        $this->assertSoftDeleted('users', [
            'id' => $user->id,
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_delete_user_endpoint_requires_the_current_password(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('iphone')->plainTextToken;

        $response = $this->withToken($token)->deleteJson('/api/user', [
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('password.0', 'The provided password is incorrect.');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_privacy_settings_endpoint_returns_policy_pages_and_control_actions(): void
    {
        ManagedContent::query()->updateOrCreate(
            ['slug' => 'privacy-policy'],
            [
                'type' => ManagedContent::TYPE_GUIDELINE,
                'audience' => ManagedContent::AUDIENCE_PRIVACY,
                'title' => 'Privacy Policy',
                'summary' => 'How DEMOS handles user data.',
                'body' => 'Privacy policy body content for the mobile privacy page.',
                'display_order' => 1,
                'is_published' => true,
                'published_at' => now()->subHour(),
            ]
        );

        ManagedContent::query()->updateOrCreate(
            ['slug' => 'terms-of-service'],
            [
                'type' => ManagedContent::TYPE_GUIDELINE,
                'audience' => ManagedContent::AUDIENCE_PRIVACY,
                'title' => 'Terms of Service',
                'summary' => 'Rules for using the DEMOS platform.',
                'body' => 'Terms of service body content for the mobile privacy page.',
                'display_order' => 2,
                'is_published' => true,
                'published_at' => now()->subHour(),
            ]
        );

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user/privacy-settings');

        $response->assertOk()
            ->assertJsonPath('title', 'Privacy Settings')
            ->assertJsonPath('summary', 'Control your data and privacy preferences.')
            ->assertJsonPath('sections.0.key', 'policies')
            ->assertJsonPath('sections.0.items.0.slug', 'privacy-policy')
            ->assertJsonPath('sections.0.items.1.slug', 'terms-of-service')
            ->assertJsonPath('sections.1.key', 'controls')
            ->assertJsonPath('sections.1.items.0.endpoint', '/api/user/email-preferences')
            ->assertJsonPath('sections.1.items.1.endpoint', '/api/user/notification-preferences')
            ->assertJsonPath('sections.1.items.2.endpoint', '/api/user')
            ->assertJsonPath('sections.1.items.2.requires_password', true)
            ->assertJsonPath('status.email_verified', true);
    }
}
