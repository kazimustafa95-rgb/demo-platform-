<?php

namespace Tests\Feature;

use App\Models\Amendment;
use App\Models\Bill;
use App\Models\Jurisdiction;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AmendmentDirectoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_amendments_directory_endpoint_supports_filters_pagination_and_related_data(): void
    {
        Setting::updateOrCreate(
            ['key' => 'amendment_threshold'],
            ['value' => 5000]
        );

        [$federalBill, $stateBill] = $this->makeBills();

        $activeAuthor = User::factory()->create(['name' => 'Transit Coalition']);
        $passedAuthor = User::factory()->create(['name' => 'Green Future Alliance']);
        $failedAuthor = User::factory()->create(['name' => 'Health Access Group']);
        $viewer = User::factory()->create();

        $activeAmendment = Amendment::create([
            'source' => Amendment::SOURCE_USER,
            'user_id' => $activeAuthor->id,
            'bill_id' => $federalBill->id,
            'title' => 'Expand Public Transit Routes to Underserved Neighborhoods',
            'amendment_text' => implode(' ', array_fill(0, 55, 'transit')),
            'category' => 'Infrastructure',
            'support_count' => 1247,
            'submitted_at' => now()->subDay(),
            'hidden' => false,
        ]);

        Amendment::create([
            'source' => Amendment::SOURCE_USER,
            'user_id' => $passedAuthor->id,
            'bill_id' => $federalBill->id,
            'title' => 'Clean Energy Workforce Reporting',
            'amendment_text' => implode(' ', array_fill(0, 55, 'energy')),
            'category' => 'Environment',
            'support_count' => 6200,
            'threshold_reached' => true,
            'threshold_reached_at' => now()->subHours(4),
            'submitted_at' => now()->subDays(2),
            'hidden' => false,
        ]);

        Amendment::create([
            'source' => Amendment::SOURCE_USER,
            'user_id' => $failedAuthor->id,
            'bill_id' => $stateBill->id,
            'title' => 'Hospital Staffing Transparency',
            'amendment_text' => implode(' ', array_fill(0, 55, 'healthcare')),
            'category' => 'Healthcare',
            'support_count' => 89,
            'submitted_at' => now()->subDays(3),
            'hidden' => false,
        ]);

        Amendment::create([
            'source' => Amendment::SOURCE_USER,
            'user_id' => $activeAuthor->id,
            'bill_id' => $federalBill->id,
            'title' => 'Hidden Draft Amendment',
            'amendment_text' => implode(' ', array_fill(0, 55, 'draft')),
            'category' => 'Infrastructure',
            'support_count' => 4,
            'submitted_at' => now()->subHours(5),
            'hidden' => true,
        ]);

        Sanctum::actingAs($viewer);
        $activeAmendment->supports()->create(['user_id' => $viewer->id]);

        $response = $this->getJson('/api/amendments?jurisdiction=federal&status=active&category=infrastructure&per_page=1');

        $response->assertOk()
            ->assertJsonPath('per_page', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $activeAmendment->id)
            ->assertJsonPath('data.0.title', 'Expand Public Transit Routes to Underserved Neighborhoods')
            ->assertJsonPath('data.0.username', 'Transit Coalition')
            ->assertJsonPath('data.0.submitted_date', now()->subDay()->toDateString())
            ->assertJsonPath('data.0.support_threshold', 5000)
            ->assertJsonPath('data.0.status', 'active')
            ->assertJsonPath('data.0.user_supported', true)
            ->assertJsonPath('data.0.bill.id', $federalBill->id)
            ->assertJsonPath('data.0.bill.jurisdiction.type', 'federal');
    }

    public function test_amendments_directory_endpoint_supports_multiple_status_and_category_filters(): void
    {
        [$federalBill, $stateBill] = $this->makeBills();

        $author = User::factory()->create(['name' => 'Community Author']);

        $activeAmendment = Amendment::create([
            'source' => Amendment::SOURCE_USER,
            'user_id' => $author->id,
            'bill_id' => $federalBill->id,
            'title' => 'Local Transit Safety Updates',
            'amendment_text' => implode(' ', array_fill(0, 55, 'safety')),
            'category' => 'Infrastructure',
            'support_count' => 140,
            'submitted_at' => now()->subDay(),
            'hidden' => false,
        ]);

        $passedAmendment = Amendment::create([
            'source' => Amendment::SOURCE_USER,
            'user_id' => $author->id,
            'bill_id' => $federalBill->id,
            'title' => 'Clean River Restoration Goals',
            'amendment_text' => implode(' ', array_fill(0, 55, 'river')),
            'category' => 'Environment',
            'support_count' => 2100,
            'threshold_reached' => true,
            'threshold_reached_at' => now()->subHours(2),
            'submitted_at' => now()->subDays(2),
            'hidden' => false,
        ]);

        Amendment::create([
            'source' => Amendment::SOURCE_USER,
            'user_id' => $author->id,
            'bill_id' => $stateBill->id,
            'title' => 'State Hospital Reporting',
            'amendment_text' => implode(' ', array_fill(0, 55, 'hospital')),
            'category' => 'Healthcare',
            'support_count' => 75,
            'submitted_at' => now()->subDays(3),
            'hidden' => false,
        ]);

        $response = $this->getJson('/api/amendments?jurisdiction_type=federal&status=active,passed&category=infrastructure,environment');

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        $payload = collect($response->json('data'))->keyBy('id');

        $this->assertSame('active', $payload[$activeAmendment->id]['status']);
        $this->assertSame('passed', $payload[$passedAmendment->id]['status']);
        $this->assertSame('federal', $payload[$activeAmendment->id]['bill']['jurisdiction']['type']);
        $this->assertSame('federal', $payload[$passedAmendment->id]['bill']['jurisdiction']['type']);
    }

    private function makeBills(): array
    {
        $federalJurisdiction = Jurisdiction::firstOrCreate(
            ['type' => 'federal', 'code' => 'US'],
            ['name' => 'Federal']
        );

        $stateJurisdiction = Jurisdiction::firstOrCreate(
            ['type' => 'state', 'code' => 'CA'],
            ['name' => 'California']
        );

        $federalBill = Bill::create([
            'external_id' => 'HR-700-119',
            'jurisdiction_id' => $federalJurisdiction->id,
            'number' => 'HR 700',
            'title' => 'Federal Infrastructure Bill',
            'status' => Bill::STATUS_ACTIVE,
            'official_vote_date' => now()->addDays(10),
        ]);

        $stateBill = Bill::create([
            'external_id' => 'AB-700-CA',
            'jurisdiction_id' => $stateJurisdiction->id,
            'number' => 'AB 700',
            'title' => 'State Healthcare Bill',
            'status' => Bill::STATUS_FAILED,
            'official_vote_date' => now()->subDay(),
        ]);

        return [$federalBill, $stateBill];
    }
}
