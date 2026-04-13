<?php

namespace App\Jobs;

use App\Models\Representative;
use App\Services\CongressGovApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncFederalRepresentativeDetails implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        protected string $bioguideId,
        protected int $representativeId,
    ) {
    }

    public function handle(CongressGovApi $api): void
    {
        $representative = Representative::find($this->representativeId);
        if (!$representative) {
            return;
        }

        $response = $api->getMemberDetails($this->bioguideId);
        $data = $response['member'] ?? $response;

        if (!is_array($data) || $data === []) {
            return;
        }

        $terms = $this->extractTerms($data['terms'] ?? []);
        $currentTerm = $this->selectCurrentTerm($terms);
        [$lastName, $firstName] = $this->resolveName($data, $representative);

        $contactInfo = array_merge(
            is_array($representative->contact_info) ? $representative->contact_info : [],
            $this->extractContactInfo($data)
        );

        $representative->update([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'party' => $this->extractParty($data) ?? $representative->party,
            'chamber' => $this->normalizeChamber($currentTerm['chamber'] ?? $representative->chamber ?? 'house'),
            'district' => $currentTerm['district'] ?? $representative->district,
            'years_in_office_start' => $this->extractServiceStartYear($terms) ?? $representative->years_in_office_start,
            'years_in_office_end' => $this->extractServiceEndYear($terms) ?? $representative->years_in_office_end,
            'photo_url' => data_get($data, 'depiction.imageUrl') ?? $representative->photo_url,
            'contact_info' => $contactInfo,
            'committee_assignments' => $this->extractCommitteeAssignments($data),
        ]);
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

    private function resolveName(array $data, Representative $representative): array
    {
        $firstName = trim((string) ($data['firstName'] ?? $representative->first_name ?? ''));
        $middleName = trim((string) ($data['middleName'] ?? ''));
        $lastName = trim((string) ($data['lastName'] ?? $data['lastname'] ?? $representative->last_name ?? ''));

        if ($middleName !== '' && !str_contains($firstName, $middleName)) {
            $firstName = trim($firstName . ' ' . $middleName);
        }

        if ($firstName !== '' || $lastName !== '') {
            return [$lastName, $firstName];
        }

        return $this->splitName((string) ($data['directOrderName'] ?? $data['invertedOrderName'] ?? ''));
    }

    private function extractParty(array $data): ?string
    {
        $partyHistory = $this->normalizeCollection($data['partyHistory'] ?? []);
        if ($partyHistory !== []) {
            usort($partyHistory, fn (array $a, array $b) => (int) ($b['startYear'] ?? 0) <=> (int) ($a['startYear'] ?? 0));
            return $partyHistory[0]['partyName'] ?? $partyHistory[0]['partyAbbreviation'] ?? null;
        }

        return $data['partyName'] ?? $data['party'] ?? null;
    }

    private function extractContactInfo(array $data): array
    {
        $address = is_array($data['addressInformation'] ?? null) ? $data['addressInformation'] : [];

        $contactInfo = [
            'source_url' => $data['url'] ?? null,
            'website' => $data['officialUrl'] ?? $data['officialWebsiteUrl'] ?? $data['websiteUrl'] ?? $data['website'] ?? null,
            'contact_form' => $data['contactFormUrl'] ?? $data['contactForm'] ?? null,
            'office_address' => $address['officeAddress'] ?? $data['officeAddress'] ?? null,
            'phone' => $address['phoneNumber'] ?? $data['phoneNumber'] ?? $data['phone'] ?? null,
            'fax' => $data['faxNumber'] ?? $data['fax'] ?? null,
            'city' => $address['city'] ?? $data['city'] ?? null,
            'state' => $address['district'] ?? $data['state'] ?? null,
            'zip_code' => $address['zipCode'] ?? null,
        ];

        return array_filter($contactInfo, fn ($value) => !blank($value));
    }

    private function extractCommitteeAssignments(array $data): array
    {
        $assignments = [];

        foreach (['committeeAssignments', 'committees', 'committeeHistory'] as $key) {
            foreach ($this->normalizeCollection($data[$key] ?? []) as $committee) {
                if (!is_array($committee)) {
                    continue;
                }

                $name = $committee['name']
                    ?? $committee['officialName']
                    ?? $committee['libraryOfCongressName']
                    ?? $committee['committeeName']
                    ?? $committee['systemCode']
                    ?? null;

                $name = trim((string) $name);
                if ($name !== '') {
                    $assignments[$name] = $name;
                }
            }
        }

        return array_values($assignments);
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
}

