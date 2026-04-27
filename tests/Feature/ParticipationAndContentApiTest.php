<?php

namespace Tests\Feature;

use App\Models\Amendment;
use App\Models\Bill;
use App\Models\Jurisdiction;
use App\Models\ManagedContent;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParticipationAndContentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_content_endpoint_groups_items_and_filters_by_audience(): void
    {
        ManagedContent::create([
            'type' => ManagedContent::TYPE_FAQ,
            'audience' => ManagedContent::AUDIENCE_GLOBAL,
            'title' => 'How does DEMOS work?',
            'summary' => 'Overview of the platform.',
            'body' => 'Users can vote on real bills, support amendments, and submit citizen proposals.',
            'display_order' => 1,
            'is_published' => true,
            'published_at' => now()->subHour(),
        ]);

        ManagedContent::create([
            'type' => ManagedContent::TYPE_GUIDELINE,
            'audience' => ManagedContent::AUDIENCE_CITIZEN_PROPOSALS,
            'title' => 'Citizen proposal writing guide',
            'body' => 'Explain the problem, proposed solution, affected groups, and expected outcomes.',
            'display_order' => 1,
            'is_published' => true,
            'published_at' => now()->subHour(),
        ]);

        ManagedContent::create([
            'type' => ManagedContent::TYPE_ANNOUNCEMENT,
            'audience' => ManagedContent::AUDIENCE_GLOBAL,
            'title' => 'Upcoming maintenance window',
            'body' => 'This draft announcement should stay hidden until it is published.',
            'display_order' => 1,
            'is_published' => false,
        ]);

        $response = $this->getJson('/api/content');

        $response->assertOk()
            ->assertJsonCount(1, 'faqs')
            ->assertJsonPath('guidelines.0.title', 'Privacy Policy')
            ->assertJsonPath('guidelines.1.title', 'Citizen proposal writing guide')
            ->assertJsonCount(0, 'announcements')
            ->assertJsonPath('faqs.0.title', 'How does DEMOS work?')
            ->assertJsonPath('guidelines.1.audience', ManagedContent::AUDIENCE_CITIZEN_PROPOSALS);

        $filteredResponse = $this->getJson('/api/content?type=guideline&audience=citizen_proposals');

        $filteredResponse->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.audience', ManagedContent::AUDIENCE_CITIZEN_PROPOSALS);
    }

    public function test_content_summary_is_serialized_as_plain_text(): void
    {
        ManagedContent::create([
            'type' => ManagedContent::TYPE_FAQ,
            'audience' => ManagedContent::AUDIENCE_GLOBAL,
            'title' => 'Referral QR code help',
            'summary' => "For a logged-in Partner or Sales Rep, go to:\nDashboard -> Settings\nURL is typically:\n/dashboard?section=settings\nThere's a Referral Link box there with the link field, Copy Link, and QR Code button.\n\nFor an admin viewing a specific partner or sales rep, go to:\nDashboard -> Partners or Dashboard -> Sales Representatives -> View Details\nOn that details page, the QR Code button is in the top-right, and the modal shows the referral URL plus the QR code.",
            'body' => 'Referral QR instructions.',
            'display_order' => 1,
            'is_published' => true,
            'published_at' => now()->subHour(),
        ]);

        $response = $this->getJson('/api/content');

        $response->assertOk()
            ->assertJsonPath(
                'faqs.0.summary',
                "For a logged-in Partner or Sales Rep, go to: Dashboard -> Settings. URL is typically: /dashboard?section=settings. There's a Referral Link box there with the link field, Copy Link, and QR Code button. For an admin viewing a specific partner or sales rep, go to: Dashboard -> Partners or Dashboard -> Sales Representatives -> View Details. On that details page, the QR Code button is in the top-right, and the modal shows the referral URL plus the QR code."
            );
    }

    public function test_managed_content_page_can_be_fetched_by_slug(): void
    {
        ManagedContent::query()->updateOrCreate(
            ['slug' => 'privacy-policy'],
            [
                'type' => ManagedContent::TYPE_GUIDELINE,
                'audience' => ManagedContent::AUDIENCE_PRIVACY,
                'title' => 'Privacy Policy',
                'summary' => 'How DEMOS handles your data and account activity.',
                'body' => 'This page explains what information is collected, how it is used, and how users can request account deletion.',
                'display_order' => 1,
                'is_published' => true,
                'published_at' => now()->subHour(),
            ]
        );

        $response = $this->getJson('/api/content/pages/privacy-policy');

        $response->assertOk()
            ->assertJsonPath('item.slug', 'privacy-policy')
            ->assertJsonPath('item.title', 'Privacy Policy')
            ->assertJsonPath('item.audience', ManagedContent::AUDIENCE_PRIVACY);
    }

    public function test_mobile_login_blocks_suspended_users(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended@example.com',
            'suspended_at' => now(),
            'suspension_reason' => 'Repeated guideline violations.',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'suspended@example.com',
            'password' => 'password',
            'device_name' => 'ios-simulator',
        ]);

        $response->assertStatus(423)
            ->assertJson([
                'message' => 'Your account is currently suspended.',
                'suspension' => [
                    'active' => true,
                    'reason' => 'Repeated guideline violations.',
                ],
            ]);
    }

    public function test_suspended_users_cannot_vote_on_bills(): void
    {
        $jurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'federal',
            'code' => 'US',
        ], [
            'name' => 'Federal',
        ]);

        $bill = Bill::create([
            'external_id' => 'HR-109-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => 'HR 109',
            'title' => 'Suspended User Vote Test Bill',
            'status' => 'active',
            'voting_deadline' => now()->addDay(),
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_verified' => true,
            'verified_at' => now(),
            'federal_district' => '12',
            'state_district' => 'CA-12',
            'suspended_at' => now(),
            'suspension_reason' => 'Spam voting activity.',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/bills/{$bill->id}/vote", [
            'vote' => 'in_favor',
        ]);

        $response->assertStatus(423)
            ->assertJson([
                'message' => 'Your account is suspended from participation.',
                'suspension' => [
                    'active' => true,
                    'reason' => 'Spam voting activity.',
                ],
            ]);
    }

    public function test_constituent_verification_is_required_for_amendment_and_citizen_proposal_submission(): void
    {
        $jurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'federal',
            'code' => 'US',
        ], [
            'name' => 'Federal',
        ]);

        $bill = Bill::create([
            'external_id' => 'HR-110-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => 'HR 110',
            'title' => 'Verification Rules Test Bill',
            'status' => 'active',
            'voting_deadline' => now()->addDay(),
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_verified' => false,
            'verified_at' => null,
            'federal_district' => null,
            'state_district' => null,
        ]);

        Sanctum::actingAs($user);

        $amendmentResponse = $this->postJson("/api/bills/{$bill->id}/amendments", [
            'title' => 'Open budget reporting requirement',
            'amendment_text' => implode(' ', array_fill(0, 55, 'change')),
            'category' => 'Budget',
        ]);

        $amendmentResponse->assertForbidden()
            ->assertJson([
                'message' => 'You must complete constituent verification before proposing amendments.',
            ]);

        $proposalResponse = $this->postJson('/api/citizen-proposals', [
            'title' => 'Renewable Energy Workforce Development Act',
            'content' => 'This proposal would expand apprenticeships and fund state-level clean energy workforce pathways.',
            'category' => 'Economy',
            'jurisdiction_focus' => 'federal',
        ]);

        $proposalResponse->assertForbidden()
            ->assertJson([
                'message' => 'You must complete constituent verification before submitting proposals.',
            ]);
    }

    public function test_bill_details_include_admin_configured_amendment_support_threshold(): void
    {
        $jurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'federal',
            'code' => 'US',
        ], [
            'name' => 'Federal',
        ]);

        Setting::updateOrCreate(
            ['key' => 'amendment_threshold'],
            ['value' => 25]
        );

        $bill = Bill::create([
            'external_id' => 'HR-111-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => 'HR 111',
            'title' => 'Amendment Threshold Display Bill',
            'status' => 'active',
        ]);

        Amendment::create([
            'source' => Amendment::SOURCE_USER,
            'user_id' => User::factory()->create()->id,
            'bill_id' => $bill->id,
            'title' => 'Monthly transparency updates',
            'amendment_text' => implode(' ', array_fill(0, 55, 'transparency')),
            'category' => 'transparency',
            'support_count' => 3,
            'threshold_reached' => false,
            'hidden' => false,
        ]);

        $response = $this->getJson("/api/bills/{$bill->id}");

        $response->assertOk()
            ->assertJsonPath('bill.amendments.0.support_threshold', 25)
            ->assertJsonPath('bill.amendments.0.support_count', 3)
            ->assertJsonPath('bill.amendments.0.threshold_reached', false);
    }

    public function test_amendment_creation_returns_title_username_and_submitted_date(): void
    {
        $jurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'federal',
            'code' => 'US',
        ], [
            'name' => 'Federal',
        ]);

        $bill = Bill::create([
            'external_id' => 'HR-112-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => 'HR 112',
            'title' => 'Community Amendment Metadata Bill',
            'status' => 'active',
            'voting_deadline' => now()->addDay(),
        ]);

        $user = User::factory()->create([
            'name' => 'Baker Davis',
            'email_verified_at' => now(),
            'is_verified' => true,
            'verified_at' => now(),
            'federal_district' => '12',
            'state_district' => 'CA-12',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/bills/{$bill->id}/amendments", [
            'title' => 'Monthly transparency updates',
            'amendment_text' => implode(' ', array_fill(0, 55, 'transparency')),
            'category' => 'transparency',
        ]);

        $response->assertCreated()
            ->assertJsonPath('amendment.title', 'Monthly transparency updates')
            ->assertJsonPath('amendment.username', 'Baker Davis')
            ->assertJsonPath('amendment.submitted_date', now()->toDateString())
            ->assertJsonPath('amendment.category', 'transparency');

        $this->assertDatabaseHas('amendments', [
            'bill_id' => $bill->id,
            'user_id' => $user->id,
            'title' => 'Monthly transparency updates',
            'category' => 'transparency',
        ]);
    }
}
