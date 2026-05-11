<?php

namespace App\Services;

use App\Support\LegislativeChamber;
use RuntimeException;

class AtlasDistrictPopulationSyncService
{
    public function __construct(
        private readonly AtlasDataMappingClient $atlasClient,
        private readonly DistrictPopulationImportService $importService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function sync(array $options = []): array
    {
        $requestedStateCode = $this->normalizeStateCode(
            $options['atlas_state_code']
                ?? $options['state_code']
                ?? $options['default_state']
                ?? null
        );
        $application = $this->atlasClient->resolveApplication(
            $this->filledString($options['customer_code'] ?? null),
            $this->filledString($options['atlas_dataset'] ?? null),
            $requestedStateCode
        );
        $stateCode = $requestedStateCode
            ?? $this->normalizeStateCode($application['state_code'] ?? null)
            ?? $this->atlasClient->datasetStateCode($application['dataset_id'] ?? null);

        if ($stateCode === null) {
            throw new RuntimeException('Unable to determine the state code for the selected Atlas application.');
        }

        $stats = $this->atlasClient->fetchDatasetStats($application);
        $rows = $this->buildAggregateRows($application, $stats, $stateCode);

        return $this->importService->importRows($rows, [
            'mode' => 'aggregate',
            'provider' => $this->filledString($options['provider'] ?? null) ?? 'atlas_stats',
            'source_reference' => $this->filledString($options['source_reference'] ?? null)
                ?? ('atlas_stats:' . $application['dataset_id']),
            'source_payload' => [
                'atlas_customer_code' => $application['customer_code'],
                'atlas_customer_name' => $application['customer_name'],
                'atlas_dataset_id' => $application['dataset_id'],
                'atlas_application_label' => $application['application_label'],
                'atlas_pick_url' => $application['pick_url'],
                'atlas_state_code' => $stateCode,
                'atlas_stats_fields' => [
                    'federal' => $this->firstAvailableField($stats, $this->federalFieldCandidates()),
                    'state_house' => $this->firstAvailableField($stats, $this->stateHouseFieldCandidates()),
                    'state_senate' => $this->firstAvailableField($stats, $this->stateSenateFieldCandidates()),
                    'state_general' => $this->firstAvailableField($stats, $this->stateGeneralFieldCandidates()),
                ],
            ],
            'default_state' => $stateCode,
            'dry_run' => (bool) ($options['dry_run'] ?? false),
        ]);
    }

    /**
     * @param  array<string, mixed>  $application
     * @param  array<string, mixed>  $stats
     * @return array<int, array{jurisdiction_type:string, state_code:string, district:string, chamber:string, registered_voter_count:int}>
     */
    private function buildAggregateRows(array $application, array $stats, string $stateCode): array
    {
        $rows = [];
        $federalField = $this->firstAvailableField($stats, $this->federalFieldCandidates());
        $stateHouseField = $this->firstAvailableField($stats, $this->stateHouseFieldCandidates());
        $stateSenateField = $this->firstAvailableField($stats, $this->stateSenateFieldCandidates());
        $stateGeneralField = $this->firstAvailableField($stats, $this->stateGeneralFieldCandidates());

        if ($federalField !== null) {
            $rows = array_merge($rows, $this->rowsFromStatsField(
                $stats,
                $federalField,
                'federal',
                $stateCode,
                LegislativeChamber::GENERAL
            ));
        }

        if ($stateHouseField !== null) {
            $rows = array_merge($rows, $this->rowsFromStatsField(
                $stats,
                $stateHouseField,
                'state',
                $stateCode,
                LegislativeChamber::HOUSE
            ));
        }

        if ($stateSenateField !== null) {
            $rows = array_merge($rows, $this->rowsFromStatsField(
                $stats,
                $stateSenateField,
                'state',
                $stateCode,
                LegislativeChamber::SENATE
            ));
        }

        if ($stateGeneralField !== null) {
            $rows = array_merge($rows, $this->rowsFromStatsField(
                $stats,
                $stateGeneralField,
                'state',
                $stateCode,
                LegislativeChamber::GENERAL
            ));
        }

        if ($rows === []) {
            throw new RuntimeException(sprintf(
                'Atlas stats for dataset [%s] did not include usable district counts.',
                (string) ($application['dataset_id'] ?? 'unknown')
            ));
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $stats
     * @return array<int, array{jurisdiction_type:string, state_code:string, district:string, chamber:string, registered_voter_count:int}>
     */
    private function rowsFromStatsField(
        array $stats,
        string $fieldName,
        string $jurisdictionType,
        string $stateCode,
        string $chamber
    ): array {
        $field = is_array($stats[$fieldName] ?? null) ? $stats[$fieldName] : [];
        $counts = is_array($field['_counts'] ?? null) ? $field['_counts'] : [];
        $dict = is_array($field['_dict'] ?? null) ? $field['_dict'] : [];
        $rows = [];

        foreach ($counts as $valueKey => $count) {
            $registeredVoterCount = $this->normalizeCount($count);

            if ($registeredVoterCount === null || $registeredVoterCount <= 0) {
                continue;
            }

            $label = trim((string) ($dict[(string) $valueKey] ?? ''));
            $district = $this->normalizeDistrictLabel($jurisdictionType, $label, $stateCode);

            if ($district === null) {
                continue;
            }

            $rows[] = [
                'jurisdiction_type' => $jurisdictionType,
                'state_code' => $stateCode,
                'district' => $district,
                'chamber' => $chamber,
                'registered_voter_count' => $registeredVoterCount,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  array<int, string>  $candidates
     */
    private function firstAvailableField(array $stats, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_array($stats[$candidate] ?? null)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function federalFieldCandidates(): array
    {
        return [
            'US_Congressional_District',
            'Congressional_District',
            'Federal_District',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stateHouseFieldCandidates(): array
    {
        return [
            'State_House_District',
            'State_Assembly_District',
            'State_Lower_District',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stateSenateFieldCandidates(): array
    {
        return [
            'State_Senate_District',
            'State_Upper_District',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function stateGeneralFieldCandidates(): array
    {
        return [
            'State_Legislative_District',
        ];
    }

    private function normalizeDistrictLabel(string $jurisdictionType, string $label, string $stateCode): ?string
    {
        $label = strtoupper(trim($label));
        $stateCode = strtoupper(trim($stateCode));

        if ($label === '' || $label === $stateCode . '##') {
            return null;
        }

        $district = $label;

        if (str_contains($district, '##')) {
            [$prefix, $suffix] = explode('##', $district, 2);

            if ($prefix !== '' && $prefix !== $stateCode) {
                return null;
            }

            $district = $suffix;
        } elseif (str_starts_with($district, $stateCode . '-')) {
            $district = substr($district, strlen($stateCode) + 1);
        }

        $district = trim($district);

        if ($district === '' || $district === '00') {
            return null;
        }

        if ($jurisdictionType === 'federal' && in_array($district, ['AL', 'AT-LARGE'], true)) {
            return 'At-Large';
        }

        $district = preg_replace('/^(DISTRICT|CD)\s+/i', '', $district) ?: $district;
        $district = ltrim($district, '0');

        if ($district === '') {
            return $jurisdictionType === 'federal' ? 'At-Large' : null;
        }

        if ($jurisdictionType === 'federal' && $district === '1' && $this->isAtLargeState($stateCode)) {
            return 'At-Large';
        }

        return $district;
    }

    private function normalizeCount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeStateCode(mixed $value): ?string
    {
        $stateCode = strtoupper(trim((string) $value));

        return preg_match('/^[A-Z]{2}$/', $stateCode) === 1 ? $stateCode : null;
    }

    private function filledString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function isAtLargeState(string $stateCode): bool
    {
        return in_array(strtoupper(trim($stateCode)), ['AK', 'DE', 'DC', 'ND', 'SD', 'VT', 'WY'], true);
    }
}
