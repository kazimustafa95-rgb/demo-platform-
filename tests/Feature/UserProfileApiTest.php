<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_user_endpoint_returns_mobile_profile_information_blocks(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'name' => 'Sarah Johnson',
            'email' => 'sarah.johnson@email.com',
            'phone_number' => '(555) 123-4567',
            'address' => '1234 Democracy Street',
            'district' => 'Capitol City',
            'state' => 'California',
            'zip_code' => '94102',
            'country' => 'United States',
            'email_verified_at' => now(),
            'is_verified' => true,
            'verified_at' => now(),
            'federal_district' => '12',
            'state_district' => 'CA-12',
        ]);
        $user->forceFill([
            'profile_photo_path' => 'profile-photos/sarah.jpg',
        ])->save();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('full_name', 'Sarah Johnson')
            ->assertJsonPath('profile_information.full_name', 'Sarah Johnson')
            ->assertJsonPath('profile_information.email_address', 'sarah.johnson@email.com')
            ->assertJsonPath('profile_information.phone_number', '(555) 123-4567')
            ->assertJsonPath('email_preferences.preferences.account_updates', true)
            ->assertJsonPath('push_notification_preferences.preferences.bill_updates', true)
            ->assertJsonPath('profile_photo_url', Storage::disk('public')->url('profile-photos/sarah.jpg'))
            ->assertJsonPath('profile_image_url', Storage::disk('public')->url('profile-photos/sarah.jpg'))
            ->assertJsonPath('profile_information.profile_photo_url', Storage::disk('public')->url('profile-photos/sarah.jpg'))
            ->assertJsonPath(
                'profile_information.residential_address',
                '1234 Democracy Street, Capitol City, California 94102'
            )
            ->assertJsonPath('verification_status.email_verified', true)
            ->assertJsonPath('verification_status.location_verified', true)
            ->assertJsonPath('verification_status.constituent_verified', true);
    }

    public function test_profile_update_accepts_mobile_fields_and_returns_updated_mobile_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Sarah Johnson',
            'email' => 'sarah.johnson@email.com',
            'phone_number' => '(555) 123-4567',
            'address' => '1234 Democracy Street',
            'district' => 'Capitol City',
            'state' => 'California',
            'zip_code' => '94102',
            'country' => 'United States',
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/user', [
            'full_name' => 'Sarah J. Johnson',
            'email' => 'sarah.updated@email.com',
            'phone' => '(555) 987-6543',
            'street_address' => '900 Civic Center Plaza',
            'district' => 'Metro City',
            'state' => 'California',
            'zip_code' => '90001',
            'notification_preferences' => [
                'push' => true,
                'email' => false,
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('user.full_name', 'Sarah J. Johnson')
            ->assertJsonPath('user.email', 'sarah.updated@email.com')
            ->assertJsonPath('user.phone_number', '(555) 987-6543')
            ->assertJsonPath(
                'user.profile_information.residential_address',
                '900 Civic Center Plaza, Metro City, California 90001'
            )
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonPath('user.verification_status.email_verified', false)
            ->assertJsonPath('user.notification_preferences.push', true)
            ->assertJsonPath('user.notification_preferences.email', false);

        $user->refresh();

        $this->assertSame('Sarah J. Johnson', $user->name);
        $this->assertSame('sarah.updated@email.com', $user->email);
        $this->assertSame('(555) 987-6543', $user->phone_number);
        $this->assertSame('900 Civic Center Plaza', $user->address);
        $this->assertSame('Metro City', $user->district);
        $this->assertSame('California', $user->state);
        $this->assertSame('90001', $user->zip_code);
        $this->assertNull($user->email_verified_at);
    }

    public function test_profile_update_accepts_profile_image_upload_via_post_and_returns_image_url(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'name' => 'Sarah Johnson',
            'email' => 'sarah.johnson@email.com',
            'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->call(
            'POST',
            '/api/user',
            [
                'full_name' => 'Sarah Johnson',
            ],
            [],
            [
                'profile_image' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
            ],
            [
                'HTTP_ACCEPT' => 'application/json',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('user.full_name', 'Sarah Johnson');

        $user->refresh();

        $this->assertNotNull($user->profile_photo_path);
        Storage::disk('public')->assertExists($user->profile_photo_path);
        $this->assertSame(
            Storage::disk('public')->url($user->profile_photo_path),
            $response->json('user.profile_image_url')
        );
        $this->assertSame(
            Storage::disk('public')->url($user->profile_photo_path),
            $response->json('user.profile_information.profile_photo_url')
        );
    }
}
