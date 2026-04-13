<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\MobileEmailVerificationCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileAuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.open_states.request_interval_ms', 0);
    }

    public function test_user_can_register_verify_email_and_receive_access_token(): void
    {
        Notification::fake();

        $registerResponse = $this->postJson('/api/auth/register', [
            'full_name' => 'Mobile Tester',
            'email' => 'mobile@example.com',
            'phone_number' => '+1 202 555 0100',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $registerResponse->assertCreated()
            ->assertJsonStructure([
                'message',
                'verification_required',
                'verification_expires_at',
                'resend_available_in',
                'next_step',
                'user' => [
                    'id',
                    'name',
                    'email',
                    'phone_number',
                    'email_verified',
                    'location_completed',
                    'next_step',
                ],
            ])
            ->assertJson([
                'verification_required' => true,
                'next_step' => 'verify_email',
            ]);

        $user = User::where('email', 'mobile@example.com')->firstOrFail();

        $this->assertNull($user->email_verified_at);
        $this->assertSame('+1 202 555 0100', $user->phone_number);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'mobile@example.com',
            'password' => 'password',
            'device_name' => 'ios-simulator',
        ]);

        $loginResponse->assertForbidden()
            ->assertJson([
                'verification_required' => true,
                'next_step' => 'verify_email',
            ]);

        $verificationCode = null;

        Notification::assertSentTo(
            $user,
            MobileEmailVerificationCodeNotification::class,
            function (MobileEmailVerificationCodeNotification $notification) use (&$verificationCode) {
                $verificationCode = $notification->code;

                return true;
            }
        );

        $this->assertNotNull($verificationCode);

        $verifyResponse = $this->postJson('/api/auth/verify-email-code', [
            'email' => 'mobile@example.com',
            'code' => $verificationCode,
            'device_name' => 'ios-simulator',
        ]);

        $verifyResponse->assertOk()
            ->assertJsonStructure([
                'message',
                'token_type',
                'token',
                'next_step',
                'user' => ['id', 'name', 'email', 'email_verified', 'next_step'],
            ])
            ->assertJson([
                'token_type' => 'Bearer',
                'next_step' => 'select_location',
            ]);

        $user->refresh();

        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->email_verification_code);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_user_can_login_and_logout_after_email_verification(): void
    {
        $user = User::factory()->create([
            'email' => 'mobile@example.com',
            'is_verified' => false,
        ]);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'mobile@example.com',
            'password' => 'password',
            'device_name' => 'android-emulator',
        ]);

        $loginResponse->assertOk()
            ->assertJsonStructure([
                'message',
                'token_type',
                'token',
                'next_step',
                'user' => ['id', 'name', 'email'],
            ])
            ->assertJson([
                'token_type' => 'Bearer',
                'next_step' => 'select_location',
            ]);

        $token = $loginResponse->json('token');

        $this->assertNotEmpty($token);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $logoutResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout');

        $logoutResponse->assertOk()
            ->assertJson([
                'message' => 'Logged out successfully.',
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_user_can_resend_verification_code_after_cooldown(): void
    {
        Notification::fake();

        $user = User::factory()->unverified()->create([
            'email' => 'mobile@example.com',
            'phone_number' => '+1 202 555 0100',
        ]);

        $user->forceFill([
            'email_verification_code' => bcrypt('123456'),
            'email_verification_code_expires_at' => now()->addMinutes(10),
            'email_verification_code_sent_at' => now(),
        ])->save();

        $tooEarlyResponse = $this->postJson('/api/auth/resend-verification-code', [
            'email' => 'mobile@example.com',
        ]);

        $tooEarlyResponse->assertStatus(429)
            ->assertJsonStructure([
                'message',
                'resend_available_in',
                'verification_expires_at',
            ]);

        $this->travel(61)->seconds();

        $resendResponse = $this->postJson('/api/auth/resend-verification-code', [
            'email' => 'mobile@example.com',
        ]);

        $resendResponse->assertOk()
            ->assertJsonStructure([
                'message',
                'resend_available_in',
                'verification_expires_at',
            ]);

        Notification::assertSentTo($user, MobileEmailVerificationCodeNotification::class);
    }

    public function test_verified_user_can_complete_location_with_manual_fields(): void
    {
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'geometry' => [
                            'location' => [
                                'lat' => 38.8977,
                                'lng' => -77.0365,
                            ],
                        ],
                    ],
                ],
            ]),
            'https://v3.openstates.org/people.geo*' => Http::response([
                'results' => [
                    [
                        'current_role' => [
                            'org_classification' => 'legislature',
                            'jurisdiction' => 'ocd-jurisdiction/country:us/government',
                            'district' => 'At-Large',
                        ],
                    ],
                    [
                        'current_role' => [
                            'org_classification' => 'legislature',
                            'jurisdiction' => 'ocd-jurisdiction/country:us/state:dc/government',
                            'district' => '1',
                        ],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create([
            'is_verified' => false,
            'verified_at' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/user/location', [
            'country' => 'United States',
            'state' => 'District of Columbia',
            'district' => 'Washington',
            'street_address' => '1600 Pennsylvania Avenue NW',
            'zip_code' => '20500',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'next_step',
                'districts' => ['federal_district', 'state_district'],
                'user' => ['id', 'is_verified', 'location_completed', 'next_step'],
            ])
            ->assertJson([
                'next_step' => 'complete',
                'districts' => [
                    'federal_district' => 'At-Large',
                    'state_district' => 'DC-1',
                ],
            ]);

        $user->refresh();

        $this->assertTrue($user->is_verified);
        $this->assertNotNull($user->verified_at);
        $this->assertSame('United States', $user->country);
        $this->assertSame('District of Columbia', $user->state);
        $this->assertSame('Washington', $user->district);
        $this->assertSame('1600 Pennsylvania Avenue NW', $user->address);
        $this->assertSame('20500', $user->zip_code);
        $this->assertSame('At-Large', $user->federal_district);
        $this->assertSame('DC-1', $user->state_district);
    }

    public function test_verified_user_can_complete_location_with_coordinates(): void
    {
        Http::fake([
            'https://maps.googleapis.com/maps/api/geocode/json*' => Http::response([
                'status' => 'OK',
                'results' => [
                    [
                        'formatted_address' => '1600 Pennsylvania Avenue NW, Washington, DC 20500, USA',
                        'address_components' => [
                            [
                                'long_name' => '1600',
                                'types' => ['street_number'],
                            ],
                            [
                                'long_name' => 'Pennsylvania Avenue NW',
                                'types' => ['route'],
                            ],
                            [
                                'long_name' => 'Washington',
                                'types' => ['locality'],
                            ],
                            [
                                'long_name' => 'District of Columbia',
                                'types' => ['administrative_area_level_1'],
                            ],
                            [
                                'long_name' => 'United States',
                                'types' => ['country'],
                            ],
                            [
                                'long_name' => '20500',
                                'types' => ['postal_code'],
                            ],
                        ],
                    ],
                ],
            ]),
            'https://v3.openstates.org/people.geo*' => Http::response([
                'results' => [
                    [
                        'current_role' => [
                            'org_classification' => 'legislature',
                            'jurisdiction' => 'ocd-jurisdiction/country:us/government',
                            'district' => 'At-Large',
                        ],
                    ],
                    [
                        'current_role' => [
                            'org_classification' => 'legislature',
                            'jurisdiction' => 'ocd-jurisdiction/country:us/state:dc/government',
                            'district' => '1',
                        ],
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create([
            'is_verified' => false,
            'verified_at' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/user/location', [
            'latitude' => 38.8977,
            'longitude' => -77.0365,
        ]);

        $response->assertOk()
            ->assertJson([
                'next_step' => 'complete',
                'districts' => [
                    'federal_district' => 'At-Large',
                    'state_district' => 'DC-1',
                ],
            ]);

        $user->refresh();

        $this->assertTrue($user->is_verified);
        $this->assertSame('United States', $user->country);
        $this->assertSame('District of Columbia', $user->state);
        $this->assertSame('Washington', $user->district);
        $this->assertSame('1600 Pennsylvania Avenue NW', $user->address);
        $this->assertSame('20500', $user->zip_code);
    }
}
