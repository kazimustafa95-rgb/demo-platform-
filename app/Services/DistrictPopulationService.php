<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\DistrictPopulation;
use App\Models\Jurisdiction;
use App\Models\User;
use App\Support\LegislativeChamber;

class DistrictPopulationService
{
    public function __construct(
        private readonly AtlasDistrictPopulationSyncService $atlasDistrictPopulationSyncService,
    ) {
    }

    public function resolveForBillAndUser(Bill $bill, User $user): array
    {
        $bill->loadMissing('jurisdiction');

        $context = $this->resolveDistrictContext($bill, $user);

        if ($context === null) {
            return [
                'context' => null,
                'population' => null,
                'source' => null,
            ];
        }

        $population = $this->findPopulationRecord($context);

        if (!$population) {
            $this->syncAtlasPopulationIfConfigured($context);
            $population = $this->findPopulationRecord($context);
        }

        if ($population) {
            return [
                'context' => $context,
                'population' => $population->registered_voter_count,
                'source' => [
                    'provider' => $population->provider ?: config('services.district_population.provider', 'manual'),
                    'reference' => $population->source_reference,
                    'last_synced_at' => $population->last_synced_at?->toISOString(),
                    'is_fallback' => false,
                ],
            ];
        }

        return [
            'context' => $context,
            'population' => $this->staticPopulationCount($context['jurisdiction_type']),
            'source' => [
                'provider' => 'static_demo',
                'reference' => 'static:' . $context['district_key'],
                'last_synced_at' => null,
                'is_fallback' => true,
            ],
        ];
    }

    public function resolveFallbackForBill(Bill $bill): array
    {
        $bill->loadMissing('jurisdiction');

        $jurisdictionType = strtolower(trim((string) $bill->jurisdiction?->type));

        return [
            'context' => null,
            'population' => $this->staticPopulationCount($jurisdictionType),
            'source' => [
                'provider' => 'static_demo',
                'reference' => 'static:' . ($jurisdictionType !== '' ? $jurisdictionType : 'default'),
                'last_synced_at' => null,
                'is_fallback' => true,
            ],
        ];
    }

    private function findPopulationRecord(array $context): ?DistrictPopulation
    {
        $baseQuery = DistrictPopulation::query()
            ->where('jurisdiction_type', $context['jurisdiction_type'])
            ->where('district', $context['district'])
            ->when(
                $context['state_code'] !== null,
                fn ($query) => $query->where('state_code', $context['state_code']),
                fn ($query) => $query->whereNull('state_code')
            );

        $requestedChamber = LegislativeChamber::normalize($context['population_chamber'] ?? null)
            ?? LegislativeChamber::GENERAL;

        if ($context['jurisdiction_type'] === 'state' && in_array($requestedChamber, [
            LegislativeChamber::HOUSE,
            LegislativeChamber::SENATE,
            LegislativeChamber::LEGISLATURE,
        ], true)) {
            $specificRecord = (clone $baseQuery)
                ->where('chamber', $requestedChamber)
                ->first();

            if ($specificRecord) {
                return $specificRecord;
            }
        }

        $generalRecord = (clone $baseQuery)
            ->where('chamber', LegislativeChamber::GENERAL)
            ->first();

        if ($generalRecord) {
            return $generalRecord;
        }

        if ($context['jurisdiction_type'] !== 'state' || $requestedChamber !== LegislativeChamber::GENERAL) {
            return null;
        }

        $fallbackMatches = (clone $baseQuery)->get();

        return $fallbackMatches->count() === 1 ? $fallbackMatches->first() : null;
    }

    public function resolveDistrictContext(Bill $bill, User $user): ?array
    {
        if (!$user->hasCompletedLocation()) {
            return null;
        }

        $jurisdiction = $bill->jurisdiction;

        if (!$jurisdiction) {
            return null;
        }

        if ($jurisdiction->type === 'federal') {
            $stateCode = $this->extractUserStateCode($user);
            $district = trim((string) $user->federal_district);

            if ($stateCode === null || $district === '') {
                return null;
            }

            return [
                'jurisdiction_type' => 'federal',
                'state_code' => $stateCode,
                'district' => $district,
                'chamber' => null,
                'population_chamber' => LegislativeChamber::GENERAL,
                'district_key' => sprintf('federal:%s:%s', $stateCode, $district),
                'display_name' => $district === 'At-Large'
                    ? sprintf('%s At-Large Congressional District', $stateCode)
                    : sprintf('%s Congressional District %s', $stateCode, $district),
            ];
        }

        $stateCode = strtoupper((string) $jurisdiction->code);
        $billChamber = $this->resolveStateBillChamber($bill);
        $district = $this->resolveUserStateDistrict($user, $stateCode, $billChamber);

        if ($stateCode === '' || $district === '') {
            return null;
        }

        $displayPrefix = LegislativeChamber::displayLabel($billChamber);
        $districtKey = sprintf('state:%s:%s', $stateCode, $district);

        if (in_array($billChamber, [LegislativeChamber::HOUSE, LegislativeChamber::SENATE, LegislativeChamber::LEGISLATURE], true)) {
            $districtKey = sprintf('state:%s:%s:%s', $stateCode, $billChamber, $district);
        }

        return [
            'jurisdiction_type' => 'state',
            'state_code' => $stateCode,
            'district' => $district,
            'chamber' => $billChamber,
            'population_chamber' => $this->populationChamberForStateBill($billChamber),
            'district_match_fields' => $this->stateDistrictMatchFields($billChamber),
            'district_key' => $districtKey,
            'display_name' => sprintf('%s %s District %s', $stateCode, $displayPrefix, $district),
        ];
    }

    private function extractUserStateCode(User $user): ?string
    {
        foreach ([
            $user->state_district,
            $user->state_lower_district,
            $user->state_upper_district,
        ] as $district) {
            $stateDistrict = trim((string) $district);

            if ($stateDistrict !== '' && preg_match('/^([A-Z]{2})[-\s]?/i', $stateDistrict, $matches)) {
                return strtoupper($matches[1]);
            }
        }

        $state = trim((string) $user->state);
        if ($state === '') {
            return null;
        }

        $jurisdiction = Jurisdiction::query()
            ->where('type', 'state')
            ->where(function ($query) use ($state) {
                $query->whereRaw('UPPER(code) = ?', [strtoupper($state)])
                    ->orWhereRaw('UPPER(name) = ?', [strtoupper($state)]);
            })
            ->first();

        return $jurisdiction?->code ? strtoupper((string) $jurisdiction->code) : null;
    }

    private function normalizeStateDistrict(string $stateDistrict, string $stateCode): string
    {
        $stateDistrict = trim($stateDistrict);
        $stateCode = strtoupper(trim($stateCode));

        if ($stateDistrict === '') {
            return '';
        }

        return preg_replace('/^' . preg_quote($stateCode, '/') . '[-\s]?/i', '', $stateDistrict) ?: $stateDistrict;
    }

    private function resolveStateBillChamber(Bill $bill): ?string
    {
        $explicitChamber = LegislativeChamber::normalize($bill->chamber);

        if ($explicitChamber !== null && $explicitChamber !== LegislativeChamber::GENERAL) {
            return $explicitChamber;
        }

        return LegislativeChamber::inferFromBillNumber((string) $bill->number);
    }

    private function resolveUserStateDistrict(User $user, string $stateCode, ?string $billChamber): string
    {
        foreach ($this->stateDistrictCandidates($user, $billChamber) as $candidate) {
            $district = $this->normalizeStateDistrict((string) $candidate, $stateCode);

            if ($district !== '') {
                return $district;
            }
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function stateDistrictCandidates(User $user, ?string $billChamber): array
    {
        return array_values(array_filter(match ($billChamber) {
            LegislativeChamber::HOUSE => [
                (string) $user->state_lower_district,
                (string) $user->state_district,
            ],
            LegislativeChamber::SENATE => [
                (string) $user->state_upper_district,
                (string) $user->state_district,
            ],
            default => [
                (string) $user->state_district,
                (string) $user->state_lower_district,
                (string) $user->state_upper_district,
            ],
        }));
    }

    /**
     * @return array<int, string>
     */
    private function stateDistrictMatchFields(?string $billChamber): array
    {
        return match ($billChamber) {
            LegislativeChamber::HOUSE => ['state_lower_district', 'state_district'],
            LegislativeChamber::SENATE => ['state_upper_district', 'state_district'],
            default => ['state_district', 'state_lower_district', 'state_upper_district'],
        };
    }

    private function populationChamberForStateBill(?string $billChamber): string
    {
        return match ($billChamber) {
            LegislativeChamber::HOUSE => LegislativeChamber::HOUSE,
            LegislativeChamber::SENATE => LegislativeChamber::SENATE,
            LegislativeChamber::LEGISLATURE => LegislativeChamber::LEGISLATURE,
            default => LegislativeChamber::GENERAL,
        };
    }

    private function staticPopulationCount(string $jurisdictionType): int
    {
        return match (strtolower(trim($jurisdictionType))) {
            'federal' => (int) config('services.district_population.static_federal_voters', 750000),
            'state' => (int) config('services.district_population.static_state_voters', 250000),
            default => (int) config('services.district_population.static_default_voters', 500000),
        };
    }

    private function syncAtlasPopulationIfConfigured(array $context): void
    {
        if (strtolower(trim((string) config('services.district_population.provider', 'manual'))) !== 'atlas_stats') {
            return;
        }

        if (blank($context['state_code'] ?? null)) {
            return;
        }

        try {
            $this->atlasDistrictPopulationSyncService->sync([
                'state_code' => $context['state_code'],
                'provider' => 'atlas_stats',
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
