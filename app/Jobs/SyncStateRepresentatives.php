<?php

namespace App\Jobs;

use App\Models\Jurisdiction;
use App\Models\Representative;
use App\Services\OpenStatesApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncStateRepresentatives implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    public function __construct(
        protected ?int $maxRecords = null,
    ) {
        $this->maxRecords = $this->normalizeLimit($this->maxRecords);
    }

    public function handle(OpenStatesApi $api): void
    {
        $states = Jurisdiction::where('type', 'state')->get();
        $perPage = max(1, (int) config('services.open_states.max_per_page', 20));
        $processed = 0;

        foreach ($states as $state) {
            if ($api->isQuotaExceeded()) {
                return;
            }

            $page = 1;

            do {
                $response = $api->getLegislators($state->code, null, $page, $perPage, [
                    'memberships',
                    'links',
                    'sources',
                ]);

                if (!$response || !isset($response['results'])) {
                    if ($api->isQuotaExceeded()) {
                        return;
                    }

                    break;
                }

                foreach ($response['results'] as $person) {
                    $externalId = trim((string) ($person['id'] ?? ''));
                    if ($externalId === '') {
                        continue;
                    }

                    [$lastName, $firstName] = $this->splitName((string) ($person['name'] ?? ''));

                    $representative = Representative::firstOrNew(['external_id' => $externalId]);
                    $representative->fill([
                        'first_name' => $firstName !== '' ? $firstName : ($representative->first_name ?: 'Unknown'),
                        'last_name' => $lastName !== '' ? $lastName : ($representative->last_name ?: 'Member'),
                        'party' => $person['party'] ?? $representative->party,
                        'chamber' => $this->normalizeChamber($person, $representative->chamber),
                        'district' => $this->extractDistrict($person) ?? $representative->district,
                        'jurisdiction_id' => $state->id,
                        'photo_url' => data_get($person, 'image') ?? data_get($person, 'photo_url') ?? $representative->photo_url,
                        'contact_info' => $this->extractContactInfo($person, $representative),
                        'committee_assignments' => $this->extractCommitteeAssignments($person, $representative),
                        'years_in_office_start' => $this->extractServiceStartYear($person) ?? $representative->years_in_office_start,
                        'years_in_office_end' => $this->extractServiceEndYear($person) ?? $representative->years_in_office_end,
                    ]);
                    $representative->save();

                    $processed++;

                    if ($this->limitReached($processed)) {
                        return;
                    }
                }

                $hasMorePages = $this->hasMorePages($response, $page);
                $page++;
            } while ($hasMorePages);
        }
    }

    private function extractDistrict(array $person): ?string
    {
        $district = data_get($person, 'current_role.district');
        if (!blank($district)) {
            return (string) $district;
        }

        $divisionId = trim((string) data_get($person, 'current_role.division_id', ''));
        if ($divisionId !== '' && preg_match('/:([^:]+)$/', $divisionId, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function normalizeChamber(array $person, ?string $currentValue = null): string
    {
        $classification = strtolower(trim((string) data_get($person, 'current_role.org_classification', '')));
        $title = strtolower(trim((string) data_get($person, 'current_role.title', '')));

        return match (true) {
            $classification === 'upper' || str_contains($title, 'senate') || str_contains($title, 'senator') => 'senate',
            str_contains($title, 'assembly') => 'assembly',
            $classification === 'lower' || str_contains($title, 'house') || str_contains($title, 'representative') => 'house',
            !blank($currentValue) => (string) $currentValue,
            default => 'house',
        };
    }

    private function extractContactInfo(array $person, Representative $representative): array
    {
        $contactInfo = is_array($representative->contact_info) ? $representative->contact_info : [];

        if (!blank($person['openstates_url'] ?? null)) {
            $contactInfo['source_url'] = $person['openstates_url'];
        }

        if (!blank($person['email'] ?? null)) {
            $contactInfo['email'] = $person['email'];
        }

        foreach ($this->normalizeCollection($person['contact_details'] ?? []) as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $type = strtolower(trim((string) ($detail['type'] ?? '')));
            $value = trim((string) ($detail['value'] ?? ''));
            $note = strtolower(trim((string) ($detail['note'] ?? '')));

            if ($value === '') {
                continue;
            }

            if (in_array($type, ['email', 'mailto'], true) && !isset($contactInfo['email'])) {
                $contactInfo['email'] = $value;
                continue;
            }

            if (in_array($type, ['voice', 'phone', 'tel'], true) && !isset($contactInfo['phone'])) {
                $contactInfo['phone'] = $value;
                continue;
            }

            if ($type === 'fax' && !isset($contactInfo['fax'])) {
                $contactInfo['fax'] = $value;
                continue;
            }

            if (in_array($type, ['address', 'office'], true) && !isset($contactInfo['office_address'])) {
                $contactInfo['office_address'] = $value;
                continue;
            }

            if ($type === 'url' && !isset($contactInfo['website'])) {
                $contactInfo['website'] = $value;
                continue;
            }

            if ($note !== '' && !isset($contactInfo[$note])) {
                $contactInfo[$note] = $value;
            }
        }

        foreach ($this->normalizeCollection($person['links'] ?? []) as $link) {
            if (!is_array($link)) {
                continue;
            }

            $url = trim((string) ($link['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $note = strtolower(trim((string) ($link['note'] ?? '')));
            if (!isset($contactInfo['website']) && !str_starts_with($url, 'mailto:')) {
                $contactInfo['website'] = $url;
            }

            if ($note !== '' && !isset($contactInfo[$note])) {
                $contactInfo[$note] = $url;
            }
        }

        foreach ($this->normalizeCollection($person['offices'] ?? []) as $office) {
            if (!is_array($office)) {
                continue;
            }

            if (!blank($office['voice'] ?? null) && !isset($contactInfo['phone'])) {
                $contactInfo['phone'] = $office['voice'];
            }

            if (!blank($office['fax'] ?? null) && !isset($contactInfo['fax'])) {
                $contactInfo['fax'] = $office['fax'];
            }

            if (!blank($office['address'] ?? null) && !isset($contactInfo['office_address'])) {
                $contactInfo['office_address'] = $office['address'];
            }

            if (!blank($office['name'] ?? null) && !isset($contactInfo['office_name'])) {
                $contactInfo['office_name'] = $office['name'];
            }
        }

        return array_filter($contactInfo, fn (mixed $value) => !blank($value));
    }

    private function extractCommitteeAssignments(array $person, Representative $representative): array
    {
        $assignments = is_array($representative->committee_assignments)
            ? array_fill_keys($representative->committee_assignments, true)
            : [];

        foreach ($this->normalizeCollection($person['memberships'] ?? []) as $membership) {
            if (!is_array($membership)) {
                continue;
            }

            $classification = strtolower(trim((string) data_get($membership, 'organization.classification', '')));
            if (!in_array($classification, ['committee', 'subcommittee'], true)) {
                continue;
            }

            $name = trim((string) data_get($membership, 'organization.name', ''));
            if ($name !== '') {
                $assignments[$name] = true;
            }
        }

        return array_keys($assignments);
    }

    private function extractServiceStartYear(array $person): ?int
    {
        $years = [];

        foreach ($this->extractRoleDates($person) as $date) {
            if (!blank($date)) {
                $years[] = (int) date('Y', strtotime((string) $date));
            }
        }

        return $years === [] ? null : min($years);
    }

    private function extractServiceEndYear(array $person): ?int
    {
        $years = [];

        foreach ($this->extractRoleEndDates($person) as $date) {
            if (!blank($date)) {
                $years[] = (int) date('Y', strtotime((string) $date));
            }
        }

        return $years === [] ? null : max($years);
    }

    private function extractRoleDates(array $person): array
    {
        $dates = [];

        foreach ($this->normalizeCollection($person['memberships'] ?? []) as $membership) {
            if (!is_array($membership)) {
                continue;
            }

            foreach (['start_date', 'startDate'] as $key) {
                if (!blank($membership[$key] ?? null)) {
                    $dates[] = $membership[$key];
                }
            }
        }

        foreach (['start_date', 'startDate'] as $key) {
            if (!blank(data_get($person, 'current_role.' . $key))) {
                $dates[] = data_get($person, 'current_role.' . $key);
            }
        }

        return $dates;
    }

    private function extractRoleEndDates(array $person): array
    {
        $dates = [];

        foreach ($this->normalizeCollection($person['memberships'] ?? []) as $membership) {
            if (!is_array($membership)) {
                continue;
            }

            foreach (['end_date', 'endDate'] as $key) {
                if (!blank($membership[$key] ?? null)) {
                    $dates[] = $membership[$key];
                }
            }
        }

        foreach (['end_date', 'endDate'] as $key) {
            if (!blank(data_get($person, 'current_role.' . $key))) {
                $dates[] = data_get($person, 'current_role.' . $key);
            }
        }

        return $dates;
    }

    private function splitName(string $fullName): array
    {
        $fullName = trim($fullName);

        if ($fullName === '') {
            return ['', ''];
        }

        if (str_contains($fullName, ',')) {
            [$last, $first] = array_map('trim', explode(',', $fullName, 2));

            return [$last, $first];
        }

        $parts = preg_split('/\s+/', $fullName) ?: [];
        $last = array_pop($parts) ?? '';
        $first = implode(' ', $parts);

        return [$last, $first];
    }

    private function normalizeCollection(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            return $value;
        }

        if (array_key_exists('item', $value)) {
            return $this->normalizeCollection($value['item']);
        }

        return [$value];
    }

    private function hasMorePages(array $response, int $currentPage): bool
    {
        $pagination = is_array($response['pagination'] ?? null) ? $response['pagination'] : [];

        if (array_key_exists('next_page', $pagination)) {
            return !blank($pagination['next_page']);
        }

        $maxPage = (int) ($pagination['max_page'] ?? 0);
        if ($maxPage > 0) {
            return $currentPage < $maxPage;
        }

        $perPage = (int) ($pagination['per_page'] ?? 0);
        $totalItems = (int) ($pagination['total_items'] ?? 0);

        return $perPage > 0 && $totalItems > ($currentPage * $perPage);
    }

    private function normalizeLimit(?int $limit): ?int
    {
        return $limit && $limit > 0 ? $limit : null;
    }

    private function limitReached(int $processed): bool
    {
        return $this->maxRecords !== null && $processed >= $this->maxRecords;
    }
}
