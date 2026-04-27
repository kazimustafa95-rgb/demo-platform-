<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\DistrictPopulation;
use App\Models\Jurisdiction;
use App\Models\User;

class DistrictPopulationService
{
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
        return DistrictPopulation::query()
            ->where('jurisdiction_type', $context['jurisdiction_type'])
            ->where('district', $context['district'])
            ->when(
                $context['state_code'] !== null,
                fn ($query) => $query->where('state_code', $context['state_code']),
                fn ($query) => $query->whereNull('state_code')
            )
            ->first();
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
                'district_key' => sprintf('federal:%s:%s', $stateCode, $district),
                'display_name' => $district === 'At-Large'
                    ? sprintf('%s At-Large Congressional District', $stateCode)
                    : sprintf('%s Congressional District %s', $stateCode, $district),
            ];
        }

        $stateCode = strtoupper((string) $jurisdiction->code);
        $district = $this->normalizeStateDistrict((string) $user->state_district, $stateCode);

        if ($stateCode === '' || $district === '') {
            return null;
        }

        return [
            'jurisdiction_type' => 'state',
            'state_code' => $stateCode,
            'district' => $district,
            'district_key' => sprintf('state:%s:%s', $stateCode, $district),
            'display_name' => sprintf('%s State District %s', $stateCode, $district),
        ];
    }

    private function extractUserStateCode(User $user): ?string
    {
        $stateDistrict = trim((string) $user->state_district);

        if ($stateDistrict !== '' && preg_match('/^([A-Z]{2})[-\s]?/i', $stateDistrict, $matches)) {
            return strtoupper($matches[1]);
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

    private function staticPopulationCount(string $jurisdictionType): int
    {
        return match (strtolower(trim($jurisdictionType))) {
            'federal' => (int) config('services.district_population.static_federal_voters', 750000),
            'state' => (int) config('services.district_population.static_state_voters', 250000),
            default => (int) config('services.district_population.static_default_voters', 500000),
        };
    }
}
