<?php

namespace App\Jobs;

use App\Models\Jurisdiction;
use App\Models\Representative;
use App\Services\CongressGovApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncFederalRepresentatives implements ShouldQueue
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
        $jurisdiction = Jurisdiction::where('type', 'federal')->first();
        if (!$jurisdiction) {
            return;
        }

        $offset = 0;
        $pageLimit = 250;
        $processed = 0;

        do {
            $requestLimit = $this->remainingLimit($processed, $pageLimit);
            $response = $api->getMembers(null, null, $offset, $requestLimit);
            if (!$response || !isset($response['members'])) {
                break;
            }

            foreach ($response['members'] as $member) {
                $externalId = $member['bioguideId'] ?? null;
                if (!$externalId) {
                    continue;
                }

                $terms = $this->extractTerms($member['terms'] ?? []);
                $currentTerm = $this->selectCurrentTerm($terms);
                [$lastName, $firstName] = $this->resolveName($member);

                $representative = Representative::firstOrNew(['external_id' => $externalId]);
                $needsDetailSync = $representative->contact_info === null || $representative->committee_assignments === null;

                $contactInfo = is_array($representative->contact_info) ? $representative->contact_info : [];
                if (!blank($member['url'] ?? null)) {
                    $contactInfo['source_url'] = $member['url'];
                }

                $committeeAssignments = is_array($representative->committee_assignments)
                    ? $representative->committee_assignments
                    : [];

                $representative->fill([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'party' => $member['partyName'] ?? $member['party'] ?? $representative->party,
                    'chamber' => $this->normalizeChamber($currentTerm['chamber'] ?? $member['chamber'] ?? $member['memberType'] ?? 'house'),
                    'district' => $currentTerm['district'] ?? $member['district'] ?? $member['stateDistrict'] ?? null,
                    'jurisdiction_id' => $jurisdiction->id,
                    'years_in_office_start' => $this->extractServiceStartYear($terms),
                    'years_in_office_end' => $this->extractServiceEndYear($terms),
                    'photo_url' => data_get($member, 'depiction.imageUrl'),
                    'contact_info' => $contactInfo,
                    'committee_assignments' => $committeeAssignments,
                ]);
                $representative->save();
                $processed++;

                if ($needsDetailSync) {
                    SyncFederalRepresentativeDetails::dispatch((string) $externalId, $representative->id);
                }

                if ($this->limitReached($processed)) {
                    return;
                }
            }

            $offset += $requestLimit;
            $pagination = $response['pagination'] ?? [];
        } while ($offset < ($pagination['count'] ?? 0));
    }

    private function extractTerms(mixed $terms): array
    {
        if (!is_array($terms)) {
            return [];
        }

        if (array_is_list($terms)) {
            return $terms;
        }

        $items = $terms['item'] ?? null;

        if (is_array($items) && array_is_list($items)) {
            return $items;
        }

        return is_array($items) ? [$items] : [];
    }

    private function selectCurrentTerm(array $terms): ?array
    {
        $currentTerm = null;

        foreach ($terms as $term) {
            if (!is_array($term)) {
                continue;
            }

            if ($currentTerm === null || (int) ($term['startYear'] ?? 0) > (int) ($currentTerm['startYear'] ?? 0)) {
                $currentTerm = $term;
            }
        }

        return $currentTerm;
    }

    private function extractServiceStartYear(array $terms): ?int
    {
        $years = array_values(array_filter(array_map(
            fn ($term) => is_array($term) && isset($term['startYear']) ? (int) $term['startYear'] : null,
            $terms
        )));

        return $years === [] ? null : min($years);
    }

    private function extractServiceEndYear(array $terms): ?int
    {
        $years = array_values(array_filter(array_map(
            fn ($term) => is_array($term) && isset($term['endYear']) ? (int) $term['endYear'] : null,
            $terms
        )));

        return $years === [] ? null : max($years);
    }

    private function resolveName(array $member): array
    {
        $firstName = trim((string) ($member['firstName'] ?? ''));
        $lastName = trim((string) ($member['lastName'] ?? ''));

        if ($firstName !== '' || $lastName !== '') {
            return [$lastName, $firstName];
        }

        return $this->splitName((string) ($member['name'] ?? ''));
    }

    private function normalizeChamber(string $value): string
    {
        $normalized = strtolower(trim($value));

        return match (true) {
            str_contains($normalized, 'senate') => 'senate',
            str_contains($normalized, 'house') => 'house',
            default => $normalized !== '' ? $normalized : 'house',
        };
    }

    private function splitName(string $fullName): array
    {
        $fullName = trim($fullName);

        if (str_contains($fullName, ',')) {
            [$last, $first] = array_map('trim', explode(',', $fullName, 2));
            return [$last, $first];
        }

        $parts = preg_split('/\s+/', $fullName) ?: [];
        $last = array_pop($parts) ?? '';
        $first = implode(' ', $parts);

        return [$last, $first];
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
