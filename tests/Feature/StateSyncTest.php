<?php

namespace Tests\Feature;

use App\Jobs\SyncStateBillDetails;
use App\Jobs\SyncStateBills;
use App\Jobs\SyncStateRepresentatives;
use App\Models\Amendment;
use App\Models\Bill;
use App\Models\Jurisdiction;
use App\Models\Representative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class StateSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.open_states.request_interval_ms', 0);
    }

    public function test_sync_command_dispatches_state_jobs_when_requested(): void
    {
        Bus::fake();

        $this->artisan('demos:sync-federal --with-state')
            ->assertExitCode(0);

        Bus::assertDispatched(SyncStateBills::class);
        Bus::assertDispatched(SyncStateRepresentatives::class);
    }

    public function test_state_bill_sync_uses_openstates_pagination_and_dispatches_detail_jobs(): void
    {
        $jurisdiction = $this->stateJurisdiction('AL', 'Alabama');

        Bus::fake();

        Http::preventStrayRequests();
        Http::fake([
            'https://v3.openstates.org/bills*jurisdiction=AL*page=1*' => Http::response([
                'results' => [
                    [
                        'id' => 'ocd-bill/al-1',
                        'identifier' => 'HB 1',
                        'title' => 'First state bill',
                        'first_action_date' => '2025-01-01',
                        'latest_action_description' => 'Introduced',
                    ],
                ],
                'pagination' => [
                    'page' => 1,
                    'per_page' => 20,
                    'max_page' => 2,
                    'total_items' => 2,
                ],
            ], 200),
            'https://v3.openstates.org/bills*jurisdiction=AL*page=2*' => Http::response([
                'results' => [
                    [
                        'id' => 'ocd-bill/al-2',
                        'identifier' => 'HB 2',
                        'title' => 'Second state bill',
                        'first_action_date' => '2025-01-02',
                        'latest_action_description' => 'Introduced',
                    ],
                ],
                'pagination' => [
                    'page' => 2,
                    'per_page' => 20,
                    'max_page' => 2,
                    'total_items' => 2,
                ],
            ], 200),
        ]);

        (new SyncStateBills())->handle(app(\App\Services\OpenStatesApi::class));

        $this->assertSame(2, Bill::count());
        $this->assertDatabaseHas('bills', [
            'external_id' => 'ocd-bill/al-1',
            'jurisdiction_id' => $jurisdiction->id,
        ]);
        $this->assertDatabaseHas('bills', [
            'external_id' => 'ocd-bill/al-2',
            'jurisdiction_id' => $jurisdiction->id,
        ]);
        Bus::assertDispatchedTimes(SyncStateBillDetails::class, 2);
    }

    public function test_state_bill_sync_honors_record_limit_for_small_runs(): void
    {
        $jurisdiction = $this->stateJurisdiction('AL', 'Alabama');

        Bus::fake();

        Http::preventStrayRequests();
        Http::fake([
            'https://v3.openstates.org/bills*jurisdiction=AL*page=1*' => Http::response([
                'results' => [
                    [
                        'id' => 'ocd-bill/al-1',
                        'identifier' => 'HB 1',
                        'title' => 'First state bill',
                        'first_action_date' => '2025-01-01',
                        'latest_action_description' => 'Introduced',
                    ],
                    [
                        'id' => 'ocd-bill/al-2',
                        'identifier' => 'HB 2',
                        'title' => 'Second state bill',
                        'first_action_date' => '2025-01-02',
                        'latest_action_description' => 'Introduced',
                    ],
                ],
                'pagination' => [
                    'page' => 1,
                    'per_page' => 20,
                    'max_page' => 2,
                    'total_items' => 3,
                ],
            ], 200),
        ]);

        (new SyncStateBills(1))->handle(app(\App\Services\OpenStatesApi::class));

        $this->assertSame(1, Bill::count());
        $this->assertDatabaseHas('bills', [
            'external_id' => 'ocd-bill/al-1',
            'jurisdiction_id' => $jurisdiction->id,
        ]);
        $this->assertDatabaseMissing('bills', [
            'external_id' => 'ocd-bill/al-2',
        ]);
        Bus::assertDispatchedTimes(SyncStateBillDetails::class, 1);
    }

    public function test_state_bill_detail_sync_persists_summary_related_documents_and_official_amendments(): void
    {
        $jurisdiction = $this->stateJurisdiction('AL', 'Alabama');
        $externalId = 'ocd-bill/country:us/state:al/government/2025/hb:1';

        $bill = Bill::create([
            'external_id' => $externalId,
            'jurisdiction_id' => $jurisdiction->id,
            'number' => 'HB 1',
            'title' => 'Placeholder',
            'status' => 'active',
        ]);

        $encodedExternalId = rawurlencode($externalId);

        Http::preventStrayRequests();
        Http::fake([
            "https://v3.openstates.org/bills/{$encodedExternalId}*" => Http::response([
                'id' => $externalId,
                'title' => 'Education Reform Act',
                'openstates_url' => 'https://openstates.org/al/bills/2025/HB1/',
                'latest_action_description' => 'Passed Senate',
                'abstracts' => [
                    [
                        'abstract' => 'Plain-language summary for the public.',
                        'note' => 'official summary',
                    ],
                ],
                'sponsorships' => [
                    [
                        'name' => 'Jane Doe',
                        'entity_type' => 'person',
                        'primary' => true,
                        'classification' => 'primary',
                        'person' => [
                            'id' => 'ocd-person/jane-doe',
                            'party' => 'Democratic',
                            'current_role' => [
                                'district' => '5',
                            ],
                        ],
                    ],
                ],
                'actions' => [
                    [
                        'description' => 'Referred to House Committee on Education',
                        'date' => '2025-01-10',
                        'classification' => ['referral'],
                        'organization' => [
                            'name' => 'Alabama House',
                            'classification' => 'lower',
                        ],
                        'related_entities' => [
                            [
                                'name' => 'House Committee on Education',
                                'entity_type' => 'organization',
                                'organization' => [
                                    'name' => 'House Committee on Education',
                                    'classification' => 'committee',
                                ],
                            ],
                        ],
                    ],
                    [
                        'description' => 'House adopted amendment 1',
                        'date' => '2025-01-15',
                        'classification' => ['amendment'],
                        'organization' => [
                            'name' => 'Alabama House',
                            'classification' => 'lower',
                        ],
                    ],
                ],
                'versions' => [
                    [
                        'note' => 'Introduced version',
                        'date' => '2025-01-01',
                        'classification' => 'bill',
                        'links' => [
                            [
                                'url' => 'https://legis.alabama.gov/bills/HB1.html',
                                'media_type' => 'text/html',
                            ],
                        ],
                    ],
                    [
                        'note' => 'House Amendment 1',
                        'date' => '2025-01-15',
                        'classification' => 'amendment',
                        'links' => [
                            [
                                'url' => 'https://legis.alabama.gov/bills/HB1-amdt1.pdf',
                                'media_type' => 'application/pdf',
                            ],
                        ],
                    ],
                ],
                'documents' => [
                    [
                        'note' => 'Fiscal note',
                        'date' => '2025-01-16',
                        'classification' => 'fiscal_note',
                        'links' => [
                            [
                                'url' => 'https://legis.alabama.gov/bills/HB1-fiscal.pdf',
                                'media_type' => 'application/pdf',
                            ],
                        ],
                    ],
                ],
                'related_bills' => [
                    [
                        'identifier' => 'SB 2',
                        'legislative_session' => '2025',
                        'relation_type' => 'companion',
                        'openstates_url' => 'https://openstates.org/al/bills/2025/SB2/',
                    ],
                ],
                'sources' => [
                    [
                        'url' => 'https://legis.alabama.gov',
                        'note' => 'homepage',
                    ],
                ],
            ], 200),
        ]);

        (new SyncStateBillDetails($externalId))->handle(app(\App\Services\OpenStatesApi::class));

        Http::assertSent(function ($request) {
            $url = $request->url();

            return Str::contains($url, 'include=abstracts')
                && Str::contains($url, 'include=actions')
                && Str::contains($url, 'include=sponsorships')
                && !Str::contains($url, 'include=abstracts%2Cactions');
        });

        $bill->refresh();

        $this->assertSame('Education Reform Act', $bill->title);
        $this->assertStringContainsString('Plain-language summary', $bill->summary);
        $this->assertSame('https://legis.alabama.gov/bills/HB1.html', $bill->bill_text_url);
        $this->assertSame(['House Committee on Education'], $bill->committees);
        $this->assertCount(1, $bill->sponsors);
        $this->assertCount(6, $bill->related_documents);
        $this->assertCount(2, $bill->amendments_history);
        $this->assertTrue(User::where('email', 'openstates-import@system.local')->exists());
        $this->assertDatabaseHas('amendments', [
            'bill_id' => $bill->id,
            'source' => Amendment::SOURCE_OPENSTATES,
            'category' => 'official',
        ]);
    }

    public function test_state_bill_detail_sync_treats_blank_amendment_dates_as_null(): void
    {
        $jurisdiction = $this->stateJurisdiction('AL', 'Alabama');
        $externalId = 'ocd-bill/country:us/state:al/government/2025/hb:9';

        $bill = Bill::create([
            'external_id' => $externalId,
            'jurisdiction_id' => $jurisdiction->id,
            'number' => 'HB 9',
            'title' => 'Placeholder',
            'status' => 'active',
        ]);

        $encodedExternalId = rawurlencode($externalId);

        Http::preventStrayRequests();
        Http::fake([
            "https://v3.openstates.org/bills/{$encodedExternalId}*" => Http::response([
                'id' => $externalId,
                'title' => 'Blank Date Bill',
                'sponsorships' => [],
                'actions' => [],
                'versions' => [
                    [
                        'note' => 'Chesteen 1st Amendment Offered',
                        'date' => '',
                        'classification' => '',
                        'links' => [
                            [
                                'url' => 'https://example.com/amendment.pdf',
                                'media_type' => 'application/pdf',
                            ],
                        ],
                    ],
                ],
                'documents' => [],
                'sources' => [],
                'related_bills' => [],
            ], 200),
        ]);

        (new SyncStateBillDetails($externalId))->handle(app(\App\Services\OpenStatesApi::class));

        $imported = Amendment::where('bill_id', $bill->id)
            ->where('source', Amendment::SOURCE_OPENSTATES)
            ->first();

        $this->assertNotNull($imported);
        $this->assertNull($imported->proposed_at);
        $this->assertNull($imported->submitted_at);
        $this->assertNull($imported->latest_action['date'] ?? null);
    }

    public function test_state_representative_sync_persists_people_into_representatives_table(): void
    {
        $jurisdiction = $this->stateJurisdiction('AL', 'Alabama');

        Http::preventStrayRequests();
        Http::fake([
            'https://v3.openstates.org/people*jurisdiction=AL*page=1*' => Http::response([
                'results' => [
                    [
                        'id' => 'ocd-person/alice-senate',
                        'name' => 'Alice Senate',
                        'party' => 'Democratic',
                        'openstates_url' => 'https://openstates.org/person/alice-senate/',
                        'email' => 'alice@example.com',
                        'contact_details' => [
                            [
                                'type' => 'voice',
                                'value' => '202-555-0100',
                            ],
                        ],
                        'links' => [
                            [
                                'url' => 'https://alice.example.com',
                                'note' => 'website',
                            ],
                        ],
                        'memberships' => [
                            [
                                'start_date' => '2021-01-01',
                                'end_date' => '2026-12-31',
                                'organization' => [
                                    'classification' => 'committee',
                                    'name' => 'Education Committee',
                                ],
                            ],
                        ],
                        'current_role' => [
                            'org_classification' => 'upper',
                            'district' => '4',
                            'title' => 'Senator',
                        ],
                    ],
                    [
                        'id' => 'ocd-person/bob-house',
                        'name' => 'Bob House',
                        'party' => 'Republican',
                        'openstates_url' => 'https://openstates.org/person/bob-house/',
                        'current_role' => [
                            'org_classification' => 'lower',
                            'division_id' => 'ocd-division/country:us/state:al/sldl:12',
                            'title' => 'Representative',
                        ],
                    ],
                ],
                'pagination' => [
                    'page' => 1,
                    'per_page' => 20,
                    'max_page' => 1,
                    'total_items' => 2,
                ],
            ], 200),
        ]);

        (new SyncStateRepresentatives())->handle(app(\App\Services\OpenStatesApi::class));

        $this->assertSame(2, Representative::count());
        $this->assertDatabaseHas('representatives', [
            'external_id' => 'ocd-person/alice-senate',
            'jurisdiction_id' => $jurisdiction->id,
            'party' => 'Democratic',
            'chamber' => 'senate',
            'district' => '4',
        ]);
        $this->assertDatabaseHas('representatives', [
            'external_id' => 'ocd-person/bob-house',
            'jurisdiction_id' => $jurisdiction->id,
            'party' => 'Republican',
            'chamber' => 'house',
            'district' => '12',
        ]);

        $alice = Representative::where('external_id', 'ocd-person/alice-senate')->firstOrFail();
        $this->assertSame('https://alice.example.com', $alice->contact_info['website']);
        $this->assertSame('alice@example.com', $alice->contact_info['email']);
        $this->assertSame('202-555-0100', $alice->contact_info['phone']);
        $this->assertSame(['Education Committee'], $alice->committee_assignments);
        $this->assertSame(2021, $alice->years_in_office_start);
        $this->assertSame(2026, $alice->years_in_office_end);
    }

    public function test_state_representative_sync_honors_record_limit_for_small_runs(): void
    {
        $jurisdiction = $this->stateJurisdiction('AL', 'Alabama');

        Http::preventStrayRequests();
        Http::fake([
            'https://v3.openstates.org/people*jurisdiction=AL*page=1*' => Http::response([
                'results' => [
                    [
                        'id' => 'ocd-person/alice-senate',
                        'name' => 'Alice Senate',
                        'party' => 'Democratic',
                        'current_role' => [
                            'org_classification' => 'upper',
                            'district' => '4',
                            'title' => 'Senator',
                        ],
                    ],
                    [
                        'id' => 'ocd-person/bob-house',
                        'name' => 'Bob House',
                        'party' => 'Republican',
                        'current_role' => [
                            'org_classification' => 'lower',
                            'district' => '12',
                            'title' => 'Representative',
                        ],
                    ],
                ],
                'pagination' => [
                    'page' => 1,
                    'per_page' => 20,
                    'max_page' => 2,
                    'total_items' => 3,
                ],
            ], 200),
        ]);

        (new SyncStateRepresentatives(1))->handle(app(\App\Services\OpenStatesApi::class));

        $this->assertSame(1, Representative::count());
        $this->assertDatabaseHas('representatives', [
            'external_id' => 'ocd-person/alice-senate',
            'jurisdiction_id' => $jurisdiction->id,
        ]);
        $this->assertDatabaseMissing('representatives', [
            'external_id' => 'ocd-person/bob-house',
            'jurisdiction_id' => $jurisdiction->id,
        ]);
    }

    private function stateJurisdiction(string $code, string $name): Jurisdiction
    {
        Jurisdiction::where('type', 'state')->delete();

        return Jurisdiction::firstOrCreate(
            ['type' => 'state', 'code' => $code],
            ['name' => $name]
        );
    }
}
