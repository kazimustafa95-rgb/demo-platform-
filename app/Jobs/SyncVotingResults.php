<?php

namespace App\Jobs;

use App\Models\Amendment;
use App\Models\Bill;
use App\Models\Jurisdiction;
use App\Models\Representative;
use App\Models\Setting;
use App\Models\Vote;
use App\Services\CongressGovApi;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class SyncVotingResults implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public function __construct(
        protected ?int $maxRecords = null,
    ) {
        $this->maxRecords = $this->normalizeLimit($this->maxRecords);
    }

    public function handle(CongressGovApi $api): void
    {
        $federalJurisdiction = Jurisdiction::where('type', 'federal')->first();

        if (!$federalJurisdiction) {
            return;
        }

        $billColumns = ['id', 'external_id', 'status', 'official_vote_date', 'voting_deadline'];
        $bills = Bill::query()
            ->where('jurisdiction_id', $federalJurisdiction->id)
            ->get($billColumns);

        if ($bills->isEmpty()) {
            return;
        }

        $billMap = $bills->keyBy('external_id');
        $billById = $bills->keyBy('id');
        $amendmentMap = Amendment::query()
            ->whereNotNull('external_id')
            ->get(['id', 'bill_id', 'external_id'])
            ->keyBy('external_id');

        $votingDeadlineHours = (int) Setting::get('voting_deadline_hours', 48);
        $pageLimit = 250;
        $processed = 0;

        foreach ($this->extractCongresses($billMap->keys()->all()) as $congress) {
            foreach ([1, 2] as $session) {
                $offset = 0;

                do {
                    $requestLimit = $this->remainingLimit($processed, $pageLimit);
                    $response = $api->getHouseVotes($congress, $session, $offset, $requestLimit);
                    
                    $houseVotes = is_array($response)
                        ? ($response['houseRollCallVotes'] ?? $response['houseVotes'] ?? $response['rollCallVotes'] ?? [])
                        : [];
                    
                    if (!is_array($houseVotes) || $houseVotes === []) {
                        break;
                    }
                    
                    foreach ($houseVotes as $houseVote) {
                        
                        if (!is_array($houseVote)) {
                            continue;
                        }

                        $voteTarget = $this->resolveVoteTarget($houseVote, $billMap, $billById, $amendmentMap, $billColumns);
                        
                        if ($voteTarget === null) {
                            continue;
                        }

                        $voteNumber = $houseVote['rollCallNumber'] ?? null;
                        if ($voteNumber === null) {
                            continue;
                        }

                        $voteSession = (int) ($houseVote['sessionNumber'] ?? $session);
                        $memberVotes = $api->getAllHouseVoteMembers($congress, $voteSession, (int) $voteNumber);
                        if ($memberVotes === []) {
                            continue;
                        }
                        if ($voteTarget['type'] === 'bill') {
                            $this->updateBillVoteMetadata($voteTarget['bill'], $houseVote, $votingDeadlineHours);
                        }

                        $rollCallId = (string) ($houseVote['identifier'] ?? ($congress . '-' . $voteSession . '-' . $voteNumber));
                        $voteDate = $houseVote['startDate'] ?? null;
                        foreach ($memberVotes as $memberVote) {
                            $bioguideId = trim((string) ($memberVote['bioguideID'] ?? $memberVote['bioguideId'] ?? ''));
                            if ($bioguideId === '') {
                                continue;
                            }

                            $representative = $this->findOrCreateRepresentative($bioguideId, $memberVote, $federalJurisdiction->id);

                            Vote::updateOrCreate(
                                [
                                    'bill_id' => $voteTarget['bill']->id,
                                    'amendment_id' => $voteTarget['amendment_id'],
                                    'representative_id' => $representative->id,
                                    'roll_call_id' => $rollCallId,
                                ],
                                [
                                    'vote' => $this->normalizeVoteValue($memberVote['voteCast'] ?? null),
                                    'vote_date' => $voteDate,
                                ]
                            );
                        }

                        $processed++;

                        if ($this->limitReached($processed)) {
                            return;
                        }
                    }

                    $offset += $requestLimit;
                    $pagination = is_array($response) ? ($response['pagination'] ?? []) : [];
                } while ($offset < ($pagination['count'] ?? 0));
            }
        }
    }

    private function resolveVoteTarget(
        array $houseVote,
        Collection &$billMap,
        Collection &$billById,
        Collection &$amendmentMap,
        array $billColumns,
    ): ?array {
        $billExternalId = $this->buildBillExternalId($houseVote);
        if ($billExternalId && $billMap->has($billExternalId)) {
            return [
                'type' => 'bill',
                'bill' => $billMap->get($billExternalId),
                'amendment_id' => null,
            ];
        }

        $amendmentExternalId = $this->buildAmendmentExternalId($houseVote);
        if (!$amendmentExternalId) {
            return null;
        }

        $amendment = $amendmentMap->get($amendmentExternalId);

        if (!$amendment) {
            [$type, $number, $congress] = $this->parseExternalId($amendmentExternalId);

            if ($type && $number && $congress) {
                if (config('queue.default') === 'sync') {
                    SyncFederalAmendmentDetails::dispatchSync((int) $congress, $type, $number);
                } else {
                    SyncFederalAmendmentDetails::dispatch((int) $congress, $type, $number);
                }
            }

            if (config('queue.default') !== 'sync') {
                return null;
            }

            $amendment = Amendment::query()
                ->where('external_id', $amendmentExternalId)
                ->first(['id', 'bill_id', 'external_id']);

            if (!$amendment) {
                return null;
            }

            $amendmentMap->put($amendmentExternalId, $amendment);
        }

        $bill = $billById->get($amendment->bill_id);
        if (!$bill) {
            $bill = Bill::query()->find($amendment->bill_id, $billColumns);
            if (!$bill) {
                return null;
            }

            $billById->put($bill->id, $bill);

            if (!blank($bill->external_id)) {
                $billMap->put($bill->external_id, $bill);
            }
        }

        return [
            'type' => 'amendment',
            'bill' => $bill,
            'amendment_id' => $amendment->id,
        ];
    }

    private function extractCongresses(array $externalIds): array
    {
        $congresses = [];

        foreach ($externalIds as $externalId) {
            $parts = explode('-', (string) $externalId);
            $congress = array_pop($parts);

            if (is_numeric($congress)) {
                $congresses[(int) $congress] = (int) $congress;
            }
        }

        sort($congresses);

        return array_values($congresses);
    }

    private function buildBillExternalId(array $houseVote): ?string
    {
        $type = strtoupper(trim((string) ($houseVote['legislationType'] ?? '')));
        $number = trim((string) ($houseVote['legislationNumber'] ?? ''));
        $congress = $houseVote['congress'] ?? null;

        if ($type !== '' && $number !== '' && is_numeric($congress)) {
            return $type . '-' . $number . '-' . $congress;
        }

        return $this->buildExternalIdFromLegislationUrl($houseVote['legislationUrl'] ?? null, [
            'house-bill' => 'HR',
            'house-resolution' => 'HRES',
            'house-joint-resolution' => 'HJRES',
            'house-concurrent-resolution' => 'HCONRES',
            'senate-bill' => 'S',
            'senate-resolution' => 'SRES',
            'senate-joint-resolution' => 'SJRES',
            'senate-concurrent-resolution' => 'SCONRES',
        ], 'bill');
    }

    private function buildAmendmentExternalId(array $houseVote): ?string
    {
        $type = strtoupper(trim((string) ($houseVote['amendmentType'] ?? '')));
        $number = trim((string) ($houseVote['amendmentNumber'] ?? ''));
        $congress = $houseVote['congress'] ?? null;

        if ($type !== '' && $number !== '' && is_numeric($congress)) {
            return $type . '-' . $number . '-' . $congress;
        }

        return $this->buildExternalIdFromLegislationUrl($houseVote['legislationUrl'] ?? null, [
            'house-amendment' => 'HAMDT',
            'senate-amendment' => 'SAMDT',
        ], 'amendment');
    }

    private function buildExternalIdFromLegislationUrl(?string $url, array $slugMap, string $resourceType): ?string
    {
        $path = trim((string) parse_url((string) $url, PHP_URL_PATH), '/');
        if ($path === '') {
            return null;
        }

        $segments = explode('/', $path);
        if (count($segments) < 4 || $segments[0] !== $resourceType) {
            return null;
        }

        [, $congress, $slug, $number] = array_slice($segments, 0, 4);
        $type = $slugMap[$slug] ?? null;

        if (!$type || !is_numeric($congress) || trim($number) === '') {
            return null;
        }

        return $type . '-' . trim($number) . '-' . $congress;
    }

    private function parseExternalId(string $externalId): array
    {
        $parts = explode('-', $externalId);

        if (count($parts) !== 3) {
            return [null, null, null];
        }

        return [$parts[0], $parts[1], $parts[2]];
    }

    private function updateBillVoteMetadata(Bill $bill, array $houseVote, int $votingDeadlineHours): void
    {
        $voteDate = $houseVote['startDate'] ?? null;
        if (blank($voteDate)) {
            return;
        }

        $shouldUpdate = blank($bill->official_vote_date)
            || Carbon::parse($voteDate)->gt(Carbon::parse($bill->official_vote_date));

        if (!$shouldUpdate) {
            return;
        }

        $bill->update([
            'official_vote_date' => $voteDate,
            'voting_deadline' => Carbon::parse($voteDate)->subHours($votingDeadlineHours),
        ]);
    }

    private function findOrCreateRepresentative(string $bioguideId, array $memberVote, int $jurisdictionId): Representative
    {
        $representative = Representative::firstOrNew(['external_id' => $bioguideId]);

        $representative->fill([
            'first_name' => $representative->first_name ?: ($memberVote['firstName'] ?? 'Unknown'),
            'last_name' => $representative->last_name ?: ($memberVote['lastName'] ?? 'Member'),
            'party' => $memberVote['voteParty'] ?? $representative->party,
            'chamber' => $representative->chamber ?: 'house',
            'district' => $representative->district,
            'jurisdiction_id' => $representative->jurisdiction_id ?: $jurisdictionId,
            'contact_info' => is_array($representative->contact_info) ? $representative->contact_info : [],
            'committee_assignments' => is_array($representative->committee_assignments) ? $representative->committee_assignments : [],
        ]);

        $representative->save();

        return $representative;
    }

    private function normalizeVoteValue(?string $voteCast): string
    {
        $voteCast = trim((string) $voteCast);

        return $voteCast !== '' ? $voteCast : 'Unknown';
    }

    private function normalizeLimit(?int $limit): ?int
    {
        return $limit && $limit > 0 ? $limit : null;
    }

    private function remainingLimit(int $processed, int $pageLimit): int
    {
        if ($this->maxRecords === null) {
            return $pageLimit;
        }

        return max(1, min($pageLimit, $this->maxRecords - $processed));
    }

    private function limitReached(int $processed): bool
    {
        return $this->maxRecords !== null && $processed >= $this->maxRecords;
    }
}
