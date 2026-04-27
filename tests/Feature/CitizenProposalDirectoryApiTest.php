<?php

namespace Tests\Feature;

use App\Models\CitizenProposal;
use App\Models\ProposalSupport;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CitizenProposalDirectoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_citizen_proposals_endpoint_supports_filters_pagination_and_enriched_response(): void
    {
        Setting::updateOrCreate(['key' => 'proposal_threshold'], ['value' => 5000]);
        Setting::updateOrCreate(['key' => 'proposal_active_days'], ['value' => 14]);

        $activeAuthor = User::factory()->create(['name' => 'District 7 Transit Coalition']);
        $passedAuthor = User::factory()->create(['name' => 'Green Future Network']);
        $failedAuthor = User::factory()->create(['name' => 'Health Access Group']);
        $viewer = User::factory()->create();

        $activeProposal = CitizenProposal::create([
            'user_id' => $activeAuthor->id,
            'title' => 'Expand Public Transit Routes to Underserved Neighborhoods',
            'content' => 'Problem: District 7 neighborhoods lack reliable transit links. Solution: fund new bus routes and improve daily service frequency.',
            'problem_statement' => 'District 7 neighborhoods lack reliable transit links into downtown employment centers.',
            'proposed_solution' => 'Fund new bus routes, improve service frequency, and coordinate last-mile transit stops across underserved areas.',
            'category' => 'Infrastructure',
            'jurisdiction_focus' => 'federal',
            'support_count' => 1247,
            'hidden' => false,
        ]);
        $activeProposal->forceFill([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ])->saveQuietly();

        $passedProposal = CitizenProposal::create([
            'user_id' => $passedAuthor->id,
            'title' => 'Clean Energy Workforce Accelerator',
            'content' => 'Problem: apprenticeship access is uneven. Solution: create grants for clean energy workforce pipelines.',
            'problem_statement' => 'Apprenticeship access is uneven across districts entering the clean energy economy.',
            'proposed_solution' => 'Create grants for training providers and employers that expand local clean energy workforce pipelines.',
            'category' => 'Environment',
            'jurisdiction_focus' => 'federal',
            'support_count' => 6200,
            'threshold_reached' => true,
            'threshold_reached_at' => now()->subDay(),
            'hidden' => false,
        ]);
        $passedProposal->forceFill([
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDay(),
        ])->saveQuietly();

        $failedProposal = CitizenProposal::create([
            'user_id' => $failedAuthor->id,
            'title' => 'Hospital Staffing Transparency',
            'content' => 'Problem: staffing data is inconsistent. Solution: require quarterly state hospital staffing disclosures.',
            'problem_statement' => 'Residents do not have consistent visibility into hospital staffing shortages and service pressure.',
            'proposed_solution' => 'Require quarterly state hospital staffing disclosures and public service access dashboards.',
            'category' => 'Healthcare',
            'jurisdiction_focus' => 'CA',
            'support_count' => 89,
            'hidden' => false,
        ]);
        $failedProposal->forceFill([
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ])->saveQuietly();

        $hiddenProposal = CitizenProposal::create([
            'user_id' => $activeAuthor->id,
            'title' => 'Hidden Proposal Draft',
            'content' => 'Problem: draft. Solution: draft.',
            'problem_statement' => 'Draft problem statement for a hidden proposal.',
            'proposed_solution' => 'Draft proposed solution for a hidden proposal that should stay excluded.',
            'category' => 'Infrastructure',
            'jurisdiction_focus' => 'federal',
            'support_count' => 1,
            'hidden' => true,
        ]);
        $hiddenProposal->forceFill([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ])->saveQuietly();

        ProposalSupport::create([
            'user_id' => $viewer->id,
            'citizen_proposal_id' => $activeProposal->id,
        ]);

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/citizen-proposals?jurisdiction=federal&status=active&category=infrastructure&per_page=1');

        $response->assertOk()
            ->assertJsonPath('per_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activeProposal->id)
            ->assertJsonPath('data.0.title', 'Expand Public Transit Routes to Underserved Neighborhoods')
            ->assertJsonPath('data.0.username', 'District 7 Transit Coalition')
            ->assertJsonPath('data.0.submitted_date', now()->subDays(2)->toDateString())
            ->assertJsonPath('data.0.support_threshold', 5000)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.jurisdiction_type', 'federal')
            ->assertJsonPath('data.0.days_left', 12)
            ->assertJsonPath('data.0.user_supported', true)
            ->assertJsonPath('data.0.problem_statement', 'District 7 neighborhoods lack reliable transit links into downtown employment centers.')
            ->assertJsonPath('data.0.proposed_solution', 'Fund new bus routes, improve service frequency, and coordinate last-mile transit stops across underserved areas.');
    }

    public function test_citizen_proposals_endpoint_supports_multiple_status_values_and_state_filters(): void
    {
        Setting::updateOrCreate(['key' => 'proposal_active_days'], ['value' => 14]);

        $author = User::factory()->create(['name' => 'Community Author']);

        $activeProposal = CitizenProposal::create([
            'user_id' => $author->id,
            'title' => 'Neighborhood Literacy Hubs',
            'content' => 'Problem: literacy resources vary. Solution: fund local literacy hubs.',
            'problem_statement' => 'Literacy resources vary sharply between neighborhoods.',
            'proposed_solution' => 'Fund local literacy hubs with evening tutoring and digital access support.',
            'category' => 'Education',
            'jurisdiction_focus' => 'state',
            'support_count' => 220,
            'hidden' => false,
        ]);
        $activeProposal->forceFill([
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ])->saveQuietly();

        $failedProposal = CitizenProposal::create([
            'user_id' => $author->id,
            'title' => 'Statewide Air Quality Sensors',
            'content' => 'Problem: communities lack local air data. Solution: deploy neighborhood sensors.',
            'problem_statement' => 'Communities lack local air quality data in areas near major traffic corridors.',
            'proposed_solution' => 'Deploy neighborhood air quality sensors and publish state dashboards with alerts.',
            'category' => 'Environment',
            'jurisdiction_focus' => 'CA',
            'support_count' => 120,
            'hidden' => false,
        ]);
        $failedProposal->forceFill([
            'created_at' => now()->subDays(20),
            'updated_at' => now()->subDays(20),
        ])->saveQuietly();

        $response = $this->getJson('/api/citizen-proposals?jurisdiction=state&status=active,failed&category=education,environment');

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $payload = collect($response->json('data'))->keyBy('id');

        $this->assertSame('active', $payload[$activeProposal->id]['status']);
        $this->assertSame('failed', $payload[$failedProposal->id]['status']);
        $this->assertSame('state', $payload[$activeProposal->id]['jurisdiction_type']);
        $this->assertSame('state', $payload[$failedProposal->id]['jurisdiction_type']);
    }

    public function test_citizen_proposal_creation_accepts_problem_and_solution_and_returns_enriched_response(): void
    {
        Setting::updateOrCreate(['key' => 'proposal_threshold'], ['value' => 5000]);
        Setting::updateOrCreate(['key' => 'proposal_active_days'], ['value' => 14]);

        $user = User::factory()->create([
            'name' => 'Baker Davis',
            'email_verified_at' => now(),
            'is_verified' => true,
            'verified_at' => now(),
            'federal_district' => '12',
            'state_district' => 'CA-12',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/citizen-proposals', [
            'title' => 'Expand digital civic access',
            'problem_statement' => 'Residents in underserved neighborhoods have limited access to reliable internet, devices, and multilingual civic information.',
            'proposed_solution' => 'Create a public digital access program that funds community internet hubs, device lending, and multilingual civic outreach through libraries and schools.',
            'category' => 'Education',
            'jurisdiction_focus' => 'federal',
        ]);

        $response->assertCreated()
            ->assertJsonPath('proposal.title', 'Expand digital civic access')
            ->assertJsonPath('proposal.username', 'Baker Davis')
            ->assertJsonPath('proposal.submitted_date', now()->toDateString())
            ->assertJsonPath('proposal.status', 'active')
            ->assertJsonPath('proposal.support_threshold', 5000)
            ->assertJsonPath('proposal.days_left', 14)
            ->assertJsonPath('proposal.problem_statement', 'Residents in underserved neighborhoods have limited access to reliable internet, devices, and multilingual civic information.')
            ->assertJsonPath('proposal.proposed_solution', 'Create a public digital access program that funds community internet hubs, device lending, and multilingual civic outreach through libraries and schools.')
            ->assertJsonPath('proposal.user_supported', false);

        $this->assertDatabaseHas('citizen_proposals', [
            'user_id' => $user->id,
            'title' => 'Expand digital civic access',
            'category' => 'Education',
            'jurisdiction_focus' => 'federal',
        ]);
    }
}
