<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Jurisdiction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PersonaIdentityVerificationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.identity_verification.provider', 'persona');
        config()->set('services.identity_verification.persona_api_key', 'persona_test_key');
        config()->set('services.identity_verification.persona_template_id', 'itmpl_test_template');
        config()->set('services.identity_verification.persona_base_url', 'https://api.withpersona.com/api/v1');
        config()->set('services.identity_verification.persona_timeout_seconds', 20);
    }

    public function test_start_identity_verification_creates_persona_inquiry_and_returns_one_time_link(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.withpersona.com/api/v1/inquiries' => Http::response([
                'data' => [
                    'id' => 'inq_persona_start',
                    'attributes' => [
                        'status' => 'created',
                        'reference-id' => 'user:1',
                        'created-at' => now()->toISOString(),
                    ],
                ],
            ], 201),
            'https://api.withpersona.com/api/v1/inquiries/inq_persona_start/generate-one-time-link' => Http::response([
                'meta' => [
                    'one-time-link' => 'https://withpersona.com/verify?code=otp_persona_start',
                ],
            ]),
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_verified' => false,
            'verified_at' => null,
            'federal_district' => '12',
            'state_district' => 'CA-12',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/user/identity/start');

        $response->assertOk()
            ->assertJsonPath('identity_verification.provider', 'persona')
            ->assertJsonPath('identity_verification.inquiry_id', 'inq_persona_start')
            ->assertJsonPath('identity_verification.status', 'created')
            ->assertJsonPath('identity_verification.verified', false)
            ->assertJsonPath('identity_verification.verification_url', 'https://withpersona.com/verify?code=otp_persona_start')
            ->assertJsonPath('identity_verification.next_step', 'verify_identity')
            ->assertJsonPath('user.next_step', 'verify_identity')
            ->assertJsonPath('user.verification_status.identity_verified', false);

        $user->refresh();

        $this->assertSame('persona', $user->identity_verification_provider);
        $this->assertSame('created', $user->identity_verification_status);
        $this->assertSame('inq_persona_start', $user->identity_verification_reference);
        $this->assertNull($user->identity_verified_at);
        $this->assertSame('verify_identity', $user->nextOnboardingStep());

        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($user) {
            if ($request->method() !== 'POST' || $request->url() !== 'https://api.withpersona.com/api/v1/inquiries') {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer persona_test_key')
                && data_get($request->data(), 'data.attributes.inquiry-template-id') === 'itmpl_test_template'
                && data_get($request->data(), 'meta.auto-create-account-reference-id') === 'user:' . $user->id;
        });
    }

    public function test_complete_identity_verification_syncs_persona_status_and_allows_voting(): void
    {
        Http::preventStrayRequests();

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_verified' => false,
            'verified_at' => null,
            'federal_district' => '12',
            'state_district' => 'CA-12',
            'identity_verification_provider' => 'persona',
            'identity_verification_status' => 'pending',
            'identity_verification_reference' => 'inq_persona_complete',
        ]);

        Http::fake([
            'https://api.withpersona.com/api/v1/inquiries/inq_persona_complete' => Http::response([
                'data' => [
                    'id' => 'inq_persona_complete',
                    'attributes' => [
                        'status' => 'completed',
                        'reference-id' => 'user:' . $user->id,
                        'completed-at' => now()->toISOString(),
                        'updated-at' => now()->toISOString(),
                    ],
                ],
            ]),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/user/identity/complete', [
            'inquiry_id' => 'inq_persona_complete',
        ]);

        $response->assertOk()
            ->assertJsonPath('identity_verification.status', 'completed')
            ->assertJsonPath('identity_verification.verified', true)
            ->assertJsonPath('identity_verification.verification_url', null)
            ->assertJsonPath('user.verification_status.identity_verified', true)
            ->assertJsonPath('user.verification_status.constituent_verified', true)
            ->assertJsonPath('user.next_step', 'complete');

        $user->refresh();

        $this->assertNotNull($user->identity_verified_at);
        $this->assertTrue($user->is_verified);
        $this->assertNotNull($user->verified_at);
        $this->assertTrue($user->isVerifiedConstituent());

        $jurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'federal',
            'code' => 'US',
        ], [
            'name' => 'Federal',
        ]);

        $bill = Bill::create([
            'external_id' => 'HR-500-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => 'HR 500',
            'title' => 'Persona Verified Voting Test Bill',
            'status' => 'active',
            'voting_deadline' => now()->addDay(),
        ]);

        Sanctum::actingAs($user->fresh());

        $voteResponse = $this->postJson("/api/bills/{$bill->id}/vote", [
            'vote' => 'in_favor',
        ]);

        $voteResponse->assertOk()
            ->assertJsonPath('message', 'Vote recorded.');
    }

    public function test_persona_provider_ignores_legacy_is_verified_without_identity_approval(): void
    {
        $jurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'federal',
            'code' => 'US',
        ], [
            'name' => 'Federal',
        ]);

        $bill = Bill::create([
            'external_id' => 'HR-501-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => 'HR 501',
            'title' => 'Persona Gate Enforcement Bill',
            'status' => 'active',
            'voting_deadline' => now()->addDay(),
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_verified' => true,
            'verified_at' => now(),
            'federal_district' => '12',
            'state_district' => 'CA-12',
            'identity_verification_provider' => 'persona',
            'identity_verification_status' => null,
            'identity_verified_at' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/bills/{$bill->id}/vote", [
            'vote' => 'in_favor',
        ]);

        $response->assertForbidden()
            ->assertJson([
                'message' => 'You must complete constituent verification before voting.',
            ]);
    }
}
