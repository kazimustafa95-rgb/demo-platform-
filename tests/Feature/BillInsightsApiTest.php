<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\DistrictPopulation;
use App\Models\Jurisdiction;
use App\Models\User;
use App\Models\UserVote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BillInsightsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_fetch_district_scoped_bill_insights(): void
    {
        $jurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'federal',
            'code' => 'US',
        ], [
            'name' => 'Federal',
        ]);

        $bill = Bill::create([
            'external_id' => 'HR-77-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => 'HR 77',
            'title' => 'Climate Action and Clean Energy Investment Act',
            'status' => 'active',
        ]);

        DistrictPopulation::create([
            'jurisdiction_type' => 'federal',
            'state_code' => 'CA',
            'district' => '12',
            'registered_voter_count' => 750000,
            'provider' => 'targetsmart',
            'source_reference' => 'district:CA-12',
            'last_synced_at' => now(),
        ]);

        $viewer = User::factory()->create([
            'email_verified_at' => now(),
            'is_verified' => true,
            'verified_at' => now(),
            'state' => 'California',
            'federal_district' => '12',
            'state_district' => 'CA-14',
        ]);

        $sameDistrictSupporter = User::factory()->create([
            'email_verified_at' => now(),
            'is_verified' => true,
            'verified_at' => now(),
            'state' => 'California',
            'federal_district' => '12',
            'state_district' => 'CA-33',
        ]);

        $sameDistrictOpponent = User::factory()->create([
            'email_verified_at' => now(),
            'is_verified' => true,
            'verified_at' => now(),
            'state' => 'California',
            'federal_district' => '12',
            'state_district' => 'CA-22',
        ]);

        $differentDistrictUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_verified' => true,
            'verified_at' => now(),
            'state' => 'California',
            'federal_district' => '9',
            'state_district' => 'CA-9',
        ]);

        $unverifiedDistrictUser = User::factory()->create([
            'email_verified_at' => now(),
            'is_verified' => false,
            'verified_at' => null,
            'state' => 'California',
            'federal_district' => '12',
            'state_district' => 'CA-10',
        ]);

        UserVote::create([
            'user_id' => $viewer->id,
            'bill_id' => $bill->id,
            'vote' => 'in_favor',
        ]);

        UserVote::create([
            'user_id' => $sameDistrictSupporter->id,
            'bill_id' => $bill->id,
            'vote' => 'in_favor',
        ]);

        UserVote::create([
            'user_id' => $sameDistrictOpponent->id,
            'bill_id' => $bill->id,
            'vote' => 'against',
        ]);

        UserVote::create([
            'user_id' => $differentDistrictUser->id,
            'bill_id' => $bill->id,
            'vote' => 'against',
        ]);

        UserVote::create([
            'user_id' => $unverifiedDistrictUser->id,
            'bill_id' => $bill->id,
            'vote' => 'against',
        ]);

        Sanctum::actingAs($viewer);

        $response = $this->getJson("/api/bills/{$bill->id}/insights");

        $response->assertOk()
            ->assertJsonPath('bill.title', 'Climate Action and Clean Energy Investment Act')
            ->assertJsonPath('district.state_code', 'CA')
            ->assertJsonPath('district.district', '12')
            ->assertJsonPath('district.registered_voter_count', 750000)
            ->assertJsonPath('participation.verified_participant_count', 3)
            ->assertJsonPath('vote_totals.in_favor', 2)
            ->assertJsonPath('vote_totals.against', 1)
            ->assertJsonPath('vote_totals.total', 3)
            ->assertJsonPath('vote_proportions.in_favor_percent', 66.67)
            ->assertJsonPath('vote_proportions.against_percent', 33.33)
            ->assertJsonPath('statistical_validity.formula_inputs.n', 3)
            ->assertJsonPath('statistical_validity.formula_inputs.N', 750000);

        $payload = $response->json();

        $expectedMargin = 1.96 * sqrt(((2 / 3) * (1 - (2 / 3)) / 3) * ((750000 - 3) / (750000 - 1)));

        $this->assertEqualsWithDelta(
            $expectedMargin,
            $payload['statistical_validity']['margin_of_error'],
            0.000001
        );
    }

    public function test_vote_endpoint_requires_constituent_verification(): void
    {
        $jurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'federal',
            'code' => 'US',
        ], [
            'name' => 'Federal',
        ]);

        $bill = Bill::create([
            'external_id' => 'HR-88-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => 'HR 88',
            'title' => 'Voting Test Bill',
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

        $response = $this->postJson("/api/bills/{$bill->id}/vote", [
            'vote' => 'in_favor',
        ]);

        $response->assertForbidden()
            ->assertJson([
                'message' => 'You must complete constituent verification before voting.',
            ]);
    }

    public function test_bill_list_includes_card_stats_with_static_population_fallback(): void
    {
        $jurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'federal',
            'code' => 'US',
        ], [
            'name' => 'Federal',
        ]);

        $bill = Bill::create([
            'external_id' => 'HR-99-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => 'HR 99',
            'title' => 'Energy Grid Reliability Act',
            'status' => 'active',
        ]);

        $viewer = User::factory()->create([
            'email_verified_at' => now(),
            'is_verified' => true,
            'verified_at' => now(),
            'state' => 'California',
            'federal_district' => '12',
            'state_district' => 'CA-14',
        ]);

        $sameDistrictSupporter = User::factory()->create([
            'email_verified_at' => now(),
            'is_verified' => true,
            'verified_at' => now(),
            'state' => 'California',
            'federal_district' => '12',
            'state_district' => 'CA-22',
        ]);

        UserVote::create([
            'user_id' => $viewer->id,
            'bill_id' => $bill->id,
            'vote' => 'in_favor',
        ]);

        UserVote::create([
            'user_id' => $sameDistrictSupporter->id,
            'bill_id' => $bill->id,
            'vote' => 'against',
        ]);

        Sanctum::actingAs($viewer);

        $response = $this->getJson('/api/bills');

        $response->assertOk()
            ->assertJsonPath('data.0.card_stats.scope_type', 'district')
            ->assertJsonPath('data.0.card_stats.verified_vote_count', 2)
            ->assertJsonPath('data.0.card_stats.registered_voter_count', 750000)
            ->assertJsonPath('data.0.card_stats.population_source.provider', 'static_demo')
            ->assertJsonPath('data.0.card_stats.population_source.is_fallback', true)
            ->assertJsonPath('data.0.card_stats.vote_distribution.total', 2)
            ->assertJsonPath('data.0.card_stats.in_favor_percent', 50);
    }

    public function test_user_votes_list_includes_bill_card_stats(): void
    {
        $jurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'federal',
            'code' => 'US',
        ], [
            'name' => 'Federal',
        ]);

        DistrictPopulation::create([
            'jurisdiction_type' => 'federal',
            'state_code' => 'CA',
            'district' => '12',
            'registered_voter_count' => 750000,
            'provider' => 'targetsmart',
            'source_reference' => 'district:CA-12',
            'last_synced_at' => now(),
        ]);

        $bill = Bill::create([
            'external_id' => 'HR-100-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => 'HR 100',
            'title' => 'Climate Resilience Act',
            'status' => 'active',
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'is_verified' => true,
            'verified_at' => now(),
            'state' => 'California',
            'federal_district' => '12',
            'state_district' => 'CA-14',
        ]);

        UserVote::create([
            'user_id' => $user->id,
            'bill_id' => $bill->id,
            'vote' => 'in_favor',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/user/votes');

        $response->assertOk()
            ->assertJsonPath('data.0.bill.card_stats.scope_type', 'district')
            ->assertJsonPath('data.0.bill.card_stats.registered_voter_count', 750000)
            ->assertJsonPath('data.0.bill.card_stats.population_source.provider', 'targetsmart');
    }
}
