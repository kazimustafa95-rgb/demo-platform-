<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\Jurisdiction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillApiFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_bills_endpoint_filters_by_jurisdiction_type_aliases(): void
    {
        $federalJurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'federal',
            'code' => 'US',
        ], [
            'name' => 'Federal',
        ]);

        $californiaJurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'state',
            'code' => 'CA',
        ], [
            'name' => 'California',
        ]);

        $texasJurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'state',
            'code' => 'TX',
        ], [
            'name' => 'Texas',
        ]);

        $federalBill = Bill::create([
            'external_id' => 'federal-1',
            'jurisdiction_id' => $federalJurisdiction->id,
            'number' => 'HR 100',
            'title' => 'Federal Data Access Act',
            'status' => Bill::STATUS_ACTIVE,
        ]);

        Bill::create([
            'external_id' => 'state-ca-1',
            'jurisdiction_id' => $californiaJurisdiction->id,
            'number' => 'AB 200',
            'title' => 'California Grid Upgrade Act',
            'status' => Bill::STATUS_ACTIVE,
        ]);

        Bill::create([
            'external_id' => 'state-tx-1',
            'jurisdiction_id' => $texasJurisdiction->id,
            'number' => 'SB 300',
            'title' => 'Texas Water Modernization Act',
            'status' => Bill::STATUS_ACTIVE,
        ]);

        $federalResponse = $this->getJson('/api/bills?jurisdiction=federal');

        $federalResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $federalBill->id)
            ->assertJsonPath('data.0.jurisdiction.type', 'federal');

        $stateResponse = $this->getJson('/api/bills?jurisdiction_type=state');

        $stateResponse->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['id' => $federalBill->id]);
    }

    public function test_bills_endpoint_keeps_exact_jurisdiction_code_filtering_case_insensitive(): void
    {
        $californiaJurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'state',
            'code' => 'CA',
        ], [
            'name' => 'California',
        ]);

        $texasJurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'state',
            'code' => 'TX',
        ], [
            'name' => 'Texas',
        ]);

        $californiaBill = Bill::create([
            'external_id' => 'state-ca-2',
            'jurisdiction_id' => $californiaJurisdiction->id,
            'number' => 'AB 410',
            'title' => 'California Public Transit Act',
            'status' => Bill::STATUS_ACTIVE,
        ]);

        Bill::create([
            'external_id' => 'state-tx-2',
            'jurisdiction_id' => $texasJurisdiction->id,
            'number' => 'SB 411',
            'title' => 'Texas Transit Expansion Act',
            'status' => Bill::STATUS_ACTIVE,
        ]);

        $response = $this->getJson('/api/bills?jurisdiction=ca');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $californiaBill->id)
            ->assertJsonPath('data.0.jurisdiction.code', 'CA');
    }

    public function test_bills_endpoint_searches_number_title_summary_and_sponsors(): void
    {
        $federalJurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'federal',
            'code' => 'US',
        ], [
            'name' => 'Federal',
        ]);

        Bill::create([
            'external_id' => 'federal-2',
            'jurisdiction_id' => $federalJurisdiction->id,
            'number' => 'HR 512',
            'title' => 'Clean Water Infrastructure Act',
            'summary' => 'Improves regional reservoirs and drinking water systems.',
            'status' => Bill::STATUS_ACTIVE,
            'sponsors' => [
                ['name' => 'Rep. Ada Lovelace'],
            ],
        ]);

        Bill::create([
            'external_id' => 'federal-3',
            'jurisdiction_id' => $federalJurisdiction->id,
            'number' => 'HR 900',
            'title' => 'National Broadband Act',
            'summary' => 'Expands rural internet service and device grants.',
            'status' => Bill::STATUS_ACTIVE,
            'sponsors' => [
                ['name' => 'Rep. Grace Hopper'],
            ],
        ]);

        $response = $this->getJson('/api/bills?search=lovelace');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'HR 512');

        $aliasResponse = $this->getJson('/api/bills?q=broadband');

        $aliasResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 'HR 900');
    }

    public function test_bill_summary_is_serialized_as_plain_text(): void
    {
        $federalJurisdiction = Jurisdiction::firstOrCreate([
            'type' => 'federal',
            'code' => 'US',
        ], [
            'name' => 'Federal',
        ]);

        Bill::create([
            'external_id' => 'federal-4',
            'jurisdiction_id' => $federalJurisdiction->id,
            'number' => 'HR 901',
            'title' => 'Referral Access Act',
            'summary' => "For a logged-in Partner or Sales Rep, go to:\nDashboard -> Settings\nURL is typically:\n/dashboard?section=settings\nThere's a Referral Link box there with the link field, Copy Link, and QR Code button.",
            'status' => Bill::STATUS_ACTIVE,
        ]);

        $response = $this->getJson('/api/bills');

        $response->assertOk()
            ->assertJsonPath(
                'data.0.summary',
                "For a logged-in Partner or Sales Rep, go to: Dashboard -> Settings. URL is typically: /dashboard?section=settings. There's a Referral Link box there with the link field, Copy Link, and QR Code button."
            );
    }
}
