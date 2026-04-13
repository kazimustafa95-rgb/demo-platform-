<?php

namespace Tests\Feature;

use App\Jobs\MaintainCommunityEngagement;
use App\Jobs\SyncFederalAmendmentDetails;
use App\Jobs\SyncFederalBillDetails;
use App\Jobs\SyncFederalBills;
use App\Jobs\SyncFederalRepresentativeDetails;
use App\Jobs\SyncVotingResults;
use App\Models\Amendment;
use App\Models\AmendmentSupport;
use App\Models\Bill;
use App\Models\CitizenProposal;
use App\Models\Jurisdiction;
use App\Models\ProposalSupport;
use App\Models\Report;
use App\Models\Representative;
use App\Models\Setting;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
class FederalSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_federal_bill_detail_sync_populates_bill_fields_from_congress_data(): void
    {
        $jurisdiction = $this->federalJurisdiction();
        $this->setVotingDeadlineHours();

        $bill = Bill::create([
            'external_id' => 'HR-1-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => '1',
            'title' => 'Placeholder',
            'status' => 'active',
        ]);

        config(['queue.default' => 'sync']);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.congress.gov/v3/bill/119/hr/1?*' => Http::response([
                'bill' => [
                    'title' => 'Test Bill',
                    'introducedDate' => '2025-01-10',
                    'latestAction' => [
                        'actionDate' => '2025-02-01',
                        'text' => 'Passed House.',
                    ],
                    'legislationUrl' => 'https://www.congress.gov/bill/119th-congress/house-bill/1',
                    'summaries' => ['url' => 'https://api.congress.gov/v3/bill/119/hr/1/summaries'],
                    'textVersions' => ['url' => 'https://api.congress.gov/v3/bill/119/hr/1/text'],
                    'committees' => ['url' => 'https://api.congress.gov/v3/bill/119/hr/1/committees'],
                    'actions' => ['url' => 'https://api.congress.gov/v3/bill/119/hr/1/actions'],
                    'amendments' => ['url' => 'https://api.congress.gov/v3/bill/119/hr/1/amendments'],
                    'relatedBills' => 'https://api.congress.gov/v3/bill/119/hr/1/relatedbills',
                    'subjects' => ['url' => 'https://api.congress.gov/v3/bill/119/hr/1/subjects'],
                    'titles' => ['url' => 'https://api.congress.gov/v3/bill/119/hr/1/titles'],
                    'cosponsors' => ['url' => 'https://api.congress.gov/v3/bill/119/hr/1/cosponsors'],
                    'sponsors' => [[
                        'bioguideId' => 'A000001',
                        'firstName' => 'Ada',
                        'lastName' => 'Lovelace',
                        'fullName' => 'Rep. Ada Lovelace [D-CA-12]',
                        'party' => 'D',
                        'state' => 'CA',
                        'url' => 'https://api.congress.gov/v3/member/A000001?format=json',
                    ]],
                ],
            ], 200),
            'https://api.congress.gov/v3/bill/119/hr/1/summaries*' => Http::response([
                [
                    'text' => '<p>Official summary text.</p>',
                    'updateDate' => '2025-01-12T00:00:00Z',
                ],
            ], 200),
            'https://api.congress.gov/v3/bill/119/hr/1/text*' => Http::response([
                [
                    'date' => '2025-01-12T00:00:00Z',
                    'type' => 'Introduced in House',
                    'formats' => [
                        [
                            'type' => 'Formatted Text',
                            'url' => 'https://www.congress.gov/119/bills/hr1/BILLS-119hr1ih.htm',
                        ],
                    ],
                ],
            ], 200),
            'https://api.congress.gov/v3/bill/119/hr/1/committees*' => Http::response([
                'committees' => [
                    ['name' => 'House Committee on Rules'],
                ],
                'pagination' => ['count' => 1],
            ], 200),
            'https://api.congress.gov/v3/bill/119/hr/1/actions*' => Http::response([
                'actions' => [
                    [
                        'actionDate' => '2025-02-01',
                        'actionTime' => '13:00:00',
                        'type' => 'Vote',
                        'text' => 'On passage Passed by Yea-Nay Vote.',
                    ],
                ],
                'pagination' => ['count' => 1],
            ], 200),
            'https://api.congress.gov/v3/bill/119/hr/1/amendments*' => Http::response([
                'amendments' => [
                    [
                        'congress' => 119,
                        'type' => 'HAMDT',
                        'number' => '12',
                        'description' => 'Manager amendment.',
                        'latestAction' => ['actionDate' => '2025-01-20', 'text' => 'Agreed to.'],
                        'updateDate' => '2025-01-20T12:00:00Z',
                        'url' => 'https://api.congress.gov/v3/amendment/119/hamdt/12?format=json',
                    ],
                ],
                'pagination' => ['count' => 1],
            ], 200),
            'https://api.congress.gov/v3/amendment/119/hamdt/12?*' => Http::response([
                'amendment' => [
                    'congress' => 119,
                    'type' => 'HAMDT',
                    'number' => '12',
                    'description' => 'Manager amendment.',
                    'latestAction' => ['actionDate' => '2025-01-20', 'text' => 'Agreed to.'],
                    'amendedBill' => [
                        'congress' => 119,
                        'type' => 'HR',
                        'number' => '1',
                        'title' => 'Test Bill',
                    ],
                ],
            ], 200),
            'https://api.congress.gov/v3/bill/119/hr/1/relatedbills*' => Http::response([
                'relatedBills' => [
                    [
                        'type' => 'S',
                        'number' => 2,
                        'url' => 'https://api.congress.gov/v3/bill/119/s/2?format=json',
                        'relationshipDetails' => [
                            ['type' => 'Related Bill'],
                        ],
                    ],
                ],
                'pagination' => ['count' => 1],
            ], 200),
            'https://api.congress.gov/v3/bill/119/hr/1/subjects*' => Http::response([
                'policyArea' => ['name' => 'Government Operations and Politics'],
                'legislativeSubjects' => [
                    ['name' => 'Congressional oversight'],
                ],
            ], 200),
            'https://api.congress.gov/v3/bill/119/hr/1/titles*' => Http::response([
                'titles' => [
                    ['title' => 'Test Bill', 'titleType' => 'Display Title'],
                ],
                'pagination' => ['count' => 1],
            ], 200),
            'https://api.congress.gov/v3/bill/119/hr/1/cosponsors*' => Http::response([
                'cosponsors' => [
                    ['bioguideId' => 'B000002', 'fullName' => 'Rep. Grace Hopper [D-NY-8]'],
                ],
                'pagination' => ['count' => 1],
            ], 200),
        ]);

        (new SyncFederalBillDetails(119, 'hr', '1', $bill->id))->handle(app(\App\Services\CongressGovApi::class));

        $bill->refresh();

        $this->assertSame('Test Bill', $bill->title);
        $this->assertSame('2025-01-10', $bill->introduced_date?->toDateString());
        $this->assertSame('passed', $bill->status);
        $this->assertStringContainsString('Official summary text', $bill->summary);
        $this->assertSame('https://www.congress.gov/119/bills/hr1/BILLS-119hr1ih.htm', $bill->bill_text_url);
        $this->assertSame(['House Committee on Rules'], $bill->committees);
        $this->assertCount(1, $bill->sponsors);
        $this->assertSame('A000001', $bill->sponsors[0]['bioguide_id']);
        $this->assertSame('2025-02-01 13:00:00', $bill->official_vote_date?->format('Y-m-d H:i:s'));
        $this->assertCount(1, $bill->amendments_history);
        $this->assertNotEmpty($bill->related_documents);
        $this->assertDatabaseHas('amendments', [
            'external_id' => 'HAMDT-12-119',
            'source' => Amendment::SOURCE_CONGRESS_GOV,
            'bill_id' => $bill->id,
        ]);
    }

    public function test_federal_bill_sync_honors_record_limit_for_small_test_runs(): void
    {
        $this->federalJurisdiction();
        Bus::fake();

        Http::preventStrayRequests();
        Http::fake([
            'https://api.congress.gov/v3/bill/119?*limit=2*' => Http::response([
                'bills' => [
                    ['congress' => 119, 'type' => 'HR', 'number' => '1', 'title' => 'Bill One'],
                    ['congress' => 119, 'type' => 'HR', 'number' => '2', 'title' => 'Bill Two'],
                ],
                'pagination' => ['count' => 3],
            ], 200),
        ]);

        (new SyncFederalBills(2))->handle(app(\App\Services\CongressGovApi::class));

        $this->assertSame(2, Bill::count());
        $this->assertDatabaseHas('bills', ['external_id' => 'HR-1-119']);
        $this->assertDatabaseHas('bills', ['external_id' => 'HR-2-119']);
    }

    public function test_federal_amendment_detail_sync_persists_official_amendment_data(): void
    {
        $jurisdiction = $this->federalJurisdiction();

        $bill = Bill::create([
            'external_id' => 'HR-1-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => '1',
            'title' => 'Test Bill',
            'status' => 'active',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.congress.gov/v3/amendment/119/hamdt/12?*' => Http::response([
                'amendment' => [
                    'congress' => 119,
                    'type' => 'HAMDT',
                    'number' => '12',
                    'chamber' => 'House',
                    'description' => 'Manager amendment.',
                    'purpose' => 'Improve implementation language.',
                    'latestAction' => [
                        'actionDate' => '2025-01-20',
                        'text' => 'Agreed to in House.',
                    ],
                    'submittedDate' => '2025-01-18T12:00:00Z',
                    'amendedBill' => [
                        'congress' => 119,
                        'type' => 'HR',
                        'number' => '1',
                        'title' => 'Test Bill',
                    ],
                    'actions' => ['url' => 'https://api.congress.gov/v3/amendment/119/hamdt/12/actions'],
                    'textVersions' => ['url' => 'https://api.congress.gov/v3/amendment/119/hamdt/12/text'],
                    'cosponsors' => ['url' => 'https://api.congress.gov/v3/amendment/119/hamdt/12/cosponsors'],
                    'sponsors' => [[
                        'bioguideId' => 'T000001',
                        'firstName' => 'Rashida',
                        'lastName' => 'Tlaib',
                        'fullName' => 'Rep. Tlaib, Rashida [D-MI-12]',
                        'party' => 'D',
                        'state' => 'MI',
                    ]],
                ],
            ], 200),
            'https://api.congress.gov/v3/amendment/119/hamdt/12/actions*' => Http::response([
                'actions' => [
                    ['actionDate' => '2025-01-20', 'text' => 'Agreed to in House.', 'type' => 'Floor'],
                ],
                'pagination' => ['count' => 1],
            ], 200),
            'https://api.congress.gov/v3/amendment/119/hamdt/12/text*' => Http::response([
                'textVersions' => [
                    [
                        'type' => 'Amendment Text',
                        'formats' => [
                            ['type' => 'Formatted Text', 'url' => 'https://www.congress.gov/amendment-text.htm'],
                        ],
                    ],
                ],
                'pagination' => ['count' => 1],
            ], 200),
            'https://api.congress.gov/v3/amendment/119/hamdt/12/cosponsors*' => Http::response([
                'cosponsors' => [
                    ['bioguideId' => 'A000002', 'fullName' => 'Rep. Example'],
                ],
                'pagination' => ['count' => 1],
            ], 200),
        ]);

        (new SyncFederalAmendmentDetails(119, 'hamdt', '12', $bill->id))->handle(app(\App\Services\CongressGovApi::class));

        $amendment = Amendment::where('external_id', 'HAMDT-12-119')->first();

        $this->assertNotNull($amendment);
        $this->assertSame(Amendment::SOURCE_CONGRESS_GOV, $amendment->source);
        $this->assertSame($bill->id, $amendment->bill_id);
        $this->assertSame('official', $amendment->category);
        $this->assertSame('House', $amendment->chamber);
        $this->assertSame('https://www.congress.gov/amendment-text.htm', $amendment->text_url);
        $this->assertSame('T000001', $amendment->sponsors[0]['bioguide_id']);
        $this->assertSame('Manager amendment.', $amendment->metadata['description']);
    }

    public function test_federal_representative_detail_sync_stores_contact_info_and_committees(): void
    {
        $jurisdiction = $this->federalJurisdiction();

        $representative = Representative::create([
            'external_id' => 'A000001',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'party' => 'D',
            'chamber' => 'house',
            'district' => '12',
            'jurisdiction_id' => $jurisdiction->id,
            'contact_info' => ['source_url' => 'https://api.congress.gov/v3/member/A000001?format=json'],
            'committee_assignments' => [],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.congress.gov/v3/member/A000001*' => Http::response([
                'member' => [
                    'firstName' => 'Ada',
                    'middleName' => 'Byron',
                    'lastName' => 'Lovelace',
                    'url' => 'https://api.congress.gov/v3/member/A000001?format=json',
                    'officialUrl' => 'https://lovelace.house.gov',
                    'addressInformation' => [
                        'officeAddress' => '123 Cannon House Office Building',
                        'city' => 'Washington',
                        'district' => 'DC',
                        'zipCode' => '20515',
                        'phoneNumber' => '202-555-0100',
                    ],
                    'partyHistory' => [
                        ['partyName' => 'Democratic', 'startYear' => 2021],
                    ],
                    'terms' => [
                        [
                            'chamber' => 'House of Representatives',
                            'startYear' => 2025,
                            'endYear' => 2027,
                            'district' => '12',
                        ],
                    ],
                    'committees' => [
                        ['name' => 'House Committee on Science, Space, and Technology'],
                    ],
                ],
            ], 200),
        ]);

        (new SyncFederalRepresentativeDetails('A000001', $representative->id))->handle(app(\App\Services\CongressGovApi::class));

        $representative->refresh();

        $this->assertSame('Ada Byron', $representative->first_name);
        $this->assertSame('Lovelace', $representative->last_name);
        $this->assertSame('Democratic', $representative->party);
        $this->assertSame('house', $representative->chamber);
        $this->assertSame('12', $representative->district);
        $this->assertSame('https://lovelace.house.gov', $representative->contact_info['website']);
        $this->assertSame('202-555-0100', $representative->contact_info['phone']);
        $this->assertSame('20515', $representative->contact_info['zip_code']);
        $this->assertSame(
            ['House Committee on Science, Space, and Technology'],
            $representative->committee_assignments
        );
    }

    public function test_house_vote_sync_persists_bill_votes_without_overwriting_existing_representative_metadata(): void
    {
        $jurisdiction = $this->federalJurisdiction();
        $this->setVotingDeadlineHours();

        $bill = Bill::create([
            'external_id' => 'HR-1-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => '1',
            'title' => 'Vote Bill',
            'status' => 'active',
        ]);

        $representative = Representative::create([
            'external_id' => 'A000055',
            'first_name' => 'Robert',
            'last_name' => 'Aderholt',
            'party' => 'R',
            'chamber' => 'house',
            'district' => '4',
            'jurisdiction_id' => $jurisdiction->id,
            'contact_info' => ['website' => 'https://aderholt.house.gov'],
            'committee_assignments' => ['Appropriations'],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.congress.gov/v3/house-vote/119/1?*' => Http::response([
                'houseRollCallVotes' => [
                    [
                        'congress' => 119,
                        'identifier' => 1191202517,
                        'legislationNumber' => '1',
                        'legislationType' => 'HR',
                        'rollCallNumber' => 17,
                        'startDate' => '2025-01-16T11:00:00-05:00',
                    ],
                ],
                'pagination' => ['count' => 1],
            ], 200),
            'https://api.congress.gov/v3/house-vote/119/2?*' => Http::response([
                'houseRollCallVotes' => [],
                'pagination' => ['count' => 0],
            ], 200),
            'https://api.congress.gov/v3/house-vote/119/1/17/members*' => Http::response([
                'results' => [
                    [
                        'bioguideID' => 'A000055',
                        'firstName' => 'Robert',
                        'lastName' => 'Aderholt',
                        'voteCast' => 'Yea',
                        'voteParty' => 'R',
                    ],
                ],
                'pagination' => ['count' => 1],
            ], 200),
        ]);

        (new SyncVotingResults())->handle(app(\App\Services\CongressGovApi::class));

        $bill->refresh();
        $representative->refresh();

        $this->assertSame('2025-01-16 11:00:00', $bill->official_vote_date?->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('votes', [
            'bill_id' => $bill->id,
            'amendment_id' => null,
            'representative_id' => $representative->id,
            'roll_call_id' => '1191202517',
            'vote' => 'Yea',
        ]);
        $this->assertSame('https://aderholt.house.gov', $representative->contact_info['website']);
        $this->assertSame(['Appropriations'], $representative->committee_assignments);
        $this->assertSame(1, Vote::count());
    }

    public function test_house_vote_sync_persists_official_amendment_votes_separately_from_bill_votes(): void
    {
        $jurisdiction = $this->federalJurisdiction();
        $creator = $this->makeUser('creator@example.com');

        $bill = Bill::create([
            'external_id' => 'HR-1-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => '1',
            'title' => 'Vote Bill',
            'status' => 'active',
        ]);

        $amendment = Amendment::create([
            'external_id' => 'HAMDT-12-119',
            'source' => Amendment::SOURCE_CONGRESS_GOV,
            'user_id' => $creator->id,
            'bill_id' => $bill->id,
            'congress' => 119,
            'amendment_type' => 'HAMDT',
            'amendment_number' => '12',
            'amendment_text' => 'Manager amendment.',
            'category' => 'official',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.congress.gov/v3/house-vote/119/1?*' => Http::response([
                'houseRollCallVotes' => [
                    [
                        'congress' => 119,
                        'identifier' => 1191202599,
                        'amendmentType' => 'HAMDT',
                        'amendmentNumber' => '12',
                        'rollCallNumber' => 99,
                        'startDate' => '2025-01-17T11:00:00-05:00',
                    ],
                ],
                'pagination' => ['count' => 1],
            ], 200),
            'https://api.congress.gov/v3/house-vote/119/2?*' => Http::response([
                'houseRollCallVotes' => [],
                'pagination' => ['count' => 0],
            ], 200),
            'https://api.congress.gov/v3/house-vote/119/1/99/members*' => Http::response([
                'results' => [
                    [
                        'bioguideId' => 'A000055',
                        'firstName' => 'Robert',
                        'lastName' => 'Aderholt',
                        'voteCast' => 'Aye',
                        'voteParty' => 'R',
                    ],
                ],
                'pagination' => ['count' => 1],
            ], 200),
        ]);

        (new SyncVotingResults())->handle(app(\App\Services\CongressGovApi::class));

        $this->assertDatabaseHas('votes', [
            'bill_id' => $bill->id,
            'amendment_id' => $amendment->id,
            'roll_call_id' => '1191202599',
            'vote' => 'Aye',
        ]);
        $this->assertSame(0, $bill->votes()->count());
    }

    public function test_community_maintenance_reconciles_supports_thresholds_and_report_hiding(): void
    {
        $creator = $this->makeUser('author@example.com');
        $supporterA = $this->makeUser('supporter-a@example.com');
        $supporterB = $this->makeUser('supporter-b@example.com');
        $reporter = $this->makeUser('reporter@example.com');
        $jurisdiction = $this->federalJurisdiction();

        Setting::updateOrCreate(['key' => 'amendment_threshold'], ['value' => 2]);
        Setting::updateOrCreate(['key' => 'proposal_threshold'], ['value' => 2]);
        Setting::updateOrCreate(['key' => 'auto_hide_report_count'], ['value' => 1]);

        $bill = Bill::create([
            'external_id' => 'HR-99-119',
            'jurisdiction_id' => $jurisdiction->id,
            'number' => '99',
            'title' => 'Community Bill',
            'status' => 'active',
        ]);

        $amendment = Amendment::create([
            'source' => Amendment::SOURCE_USER,
            'user_id' => $creator->id,
            'bill_id' => $bill->id,
            'amendment_text' => str_repeat('Citizen amendment text ', 4),
            'category' => 'budget',
            'support_count' => 0,
            'threshold_reached' => false,
            'hidden' => false,
        ]);

        AmendmentSupport::create(['user_id' => $supporterA->id, 'amendment_id' => $amendment->id]);
        AmendmentSupport::create(['user_id' => $supporterB->id, 'amendment_id' => $amendment->id]);
        Report::create([
            'user_id' => $reporter->id,
            'reportable_type' => Amendment::class,
            'reportable_id' => $amendment->id,
            'reason' => 'spam',
            'status' => 'pending',
        ]);

        $proposal = CitizenProposal::create([
            'user_id' => $creator->id,
            'title' => 'Community Proposal',
            'content' => 'Proposal body',
            'category' => 'governance',
            'jurisdiction_focus' => 'federal',
            'support_count' => 0,
            'threshold_reached' => false,
            'hidden' => false,
        ]);

        ProposalSupport::create(['user_id' => $supporterA->id, 'citizen_proposal_id' => $proposal->id]);
        ProposalSupport::create(['user_id' => $supporterB->id, 'citizen_proposal_id' => $proposal->id]);
        Report::create([
            'user_id' => $reporter->id,
            'reportable_type' => CitizenProposal::class,
            'reportable_id' => $proposal->id,
            'reason' => 'duplicate',
            'status' => 'pending',
        ]);

        (new MaintainCommunityEngagement())->handle();

        $amendment->refresh();
        $proposal->refresh();

        $this->assertSame(2, $amendment->support_count);
        $this->assertTrue($amendment->threshold_reached);
        $this->assertTrue($amendment->hidden);
        $this->assertNotNull($amendment->threshold_reached_at);

        $this->assertSame(2, $proposal->support_count);
        $this->assertTrue($proposal->threshold_reached);
        $this->assertTrue($proposal->hidden);
        $this->assertNotNull($proposal->threshold_reached_at);
    }

    private function federalJurisdiction(): Jurisdiction
    {
        return Jurisdiction::firstOrCreate(
            ['type' => 'federal', 'code' => 'US'],
            ['name' => 'Federal']
        );
    }

    private function setVotingDeadlineHours(): void
    {
        Setting::updateOrCreate(
            ['key' => 'voting_deadline_hours'],
            ['value' => 48]
        );
    }

    private function makeUser(string $email): User
    {
        return User::create([
            'name' => strtok($email, '@'),
            'email' => $email,
            'password' => 'password123',
            'verified_at' => now(),
            'is_verified' => true,
        ]);
    }
}





