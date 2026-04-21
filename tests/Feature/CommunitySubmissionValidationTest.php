<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\CitizenProposal;
use App\Models\Jurisdiction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommunitySubmissionValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_bill_vote_comment_cannot_exceed_fifty_words(): void
    {
        $user = $this->verifiedUser();
        $bill = $this->openFederalBill();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/bills/{$bill->id}/vote", [
            'vote' => 'in_favor',
            'comment' => implode(' ', array_fill(0, 51, 'comment')),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('comment.0', 'Comment cannot exceed 50 words.');
    }

    public function test_amendment_submission_rejects_short_categories_and_invalid_word_counts(): void
    {
        $user = $this->verifiedUser();
        $bill = $this->openFederalBill();

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/bills/{$bill->id}/amendments", [
            'amendment_text' => implode(' ', array_fill(0, 12, 'change')),
            'category' => 'A',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('amendment_text.0', 'Amendment text must be between 50 and 70 words.')
            ->assertJsonPath('category.0', 'The category field must be at least 2 characters.');
    }

    public function test_citizen_proposal_submission_requires_a_valid_jurisdiction_focus(): void
    {
        $user = $this->verifiedUser();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/citizen-proposals', [
            'title' => 'Modernize apprenticeship pathways',
            'content' => 'This proposal would expand training pathways, employer partnerships, and state grants for workforce development.',
            'category' => 'Economy',
            'jurisdiction_focus' => 'moon',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('jurisdiction_focus.0', 'Jurisdiction focus must be federal, state, or a valid two-letter state code.');
    }

    public function test_reports_with_other_reason_require_additional_detail(): void
    {
        $reporter = $this->verifiedUser('reporter@example.com');
        $author = $this->verifiedUser('author@example.com');

        $proposal = CitizenProposal::create([
            'user_id' => $author->id,
            'title' => 'Community broadband expansion',
            'content' => 'This proposal would improve broadband access across underserved neighborhoods.',
            'category' => 'Infrastructure',
            'jurisdiction_focus' => 'federal',
            'support_count' => 0,
            'threshold_reached' => false,
            'hidden' => false,
        ]);

        Sanctum::actingAs($reporter);

        $response = $this->postJson('/api/report', [
            'reportable_type' => 'proposal',
            'reportable_id' => $proposal->id,
            'reason' => 'other',
            'description' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('description.0', 'Please provide at least 10 characters of additional detail when selecting "other".');
    }

    private function verifiedUser(string $email = 'verified@example.com'): User
    {
        return User::factory()->create([
            'email' => $email,
            'email_verified_at' => now(),
            'is_verified' => true,
            'verified_at' => now(),
            'federal_district' => '12',
            'state_district' => 'CA-12',
        ]);
    }

    private function openFederalBill(): Bill
    {
        $jurisdiction = Jurisdiction::firstOrCreate(
            ['type' => 'federal', 'code' => 'US'],
            ['name' => 'Federal'],
        );

        return Bill::create([
            'external_id' => 'HR-555-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => 'HR 555',
            'title' => 'Validation Test Bill',
            'status' => Bill::STATUS_ACTIVE,
            'voting_deadline' => now()->addDay(),
        ]);
    }
}
