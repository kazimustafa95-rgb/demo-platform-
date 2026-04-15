<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class LocationService
{
    protected string $googleMapsKey;

    public function __construct()
    {
        $this->googleMapsKey = (string) config('services.google_maps.api_key', '');
    }

    public function geocodeAddress(string $address): ?array
    {
        $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
            'address' => $address,
            'key' => $this->googleMapsKey,
        ]);
        if ($response->failed() || $response->json('status') !== 'OK') {
            return null;
        }

        $location = $response->json('results.0.geometry.location');

        return [
            'lat' => $location['lat'],
            'lng' => $location['lng'],
        ];
    }

    public function getDistrictsFromLocation($lat, $lng, ?string $address = null): array
    {
        $civicDistricts = $this->getDistrictsFromGoogleCivic($address);

        if ($this->hasRequiredDistricts($civicDistricts)) {
            return $civicDistricts;
        }

        $representativeDistricts = $this->getDistrictsFromGoogleRepresentatives($address);
        $googleDistricts = $this->mergeDistrictResults($civicDistricts, $representativeDistricts);

        if ($this->hasRequiredDistricts($googleDistricts)) {
            return $googleDistricts;
        }

        $openStatesDistricts = $this->getDistrictsFromOpenStates($lat, $lng);

        return $this->mergeDistrictResults($googleDistricts, $openStatesDistricts);
    }

    private function getDistrictsFromGoogleCivic(?string $address): array
    {
        if (blank($this->googleMapsKey) || blank($address)) {
            return $this->emptyDistrictResult();
        }

        try {
            $response = Http::get('https://www.googleapis.com/civicinfo/v2/divisionsByAddress', [
                'address' => $address,
                'key' => $this->googleMapsKey,
            ]);
        } catch (ConnectionException) {
            return $this->emptyDistrictResult();
        }

        if ($response->failed()) {
            return $this->emptyDistrictResult();
        }

        return $this->extractDistrictsFromGoogleDivisions(
            (array) $response->json('divisions', []),
            (string) ($response->json('normalizedInput.state') ?? ''),
            'google_civic'
        );
    }

    private function getDistrictsFromGoogleRepresentatives(?string $address): array
    {
        if (blank($this->googleMapsKey) || blank($address)) {
            return $this->emptyDistrictResult();
        }

        try {
            $response = Http::get('https://www.googleapis.com/civicinfo/v2/representatives', [
                'address' => $address,
                'includeOffices' => 'false',
                'key' => $this->googleMapsKey,
            ]);
        } catch (ConnectionException) {
            return $this->emptyDistrictResult();
        }

        if ($response->failed()) {
            return $this->emptyDistrictResult();
        }

        return $this->extractDistrictsFromGoogleDivisions(
            (array) $response->json('divisions', []),
            (string) ($response->json('normalizedInput.state') ?? ''),
            'google_civic_representatives'
        );
    }

    private function extractDistrictsFromGoogleDivisions(array $divisions, string $normalizedState, string $source): array
    {
        $stateCode = strtoupper(trim($normalizedState));
        $federalDistrict = null;
        $stateLowerDistrict = null;
        $stateUpperDistrict = null;
        $stateAlternativeDistrict = null;

        foreach ($divisions as $divisionId => $division) {
            $aliases = is_array($division['alsoKnownAs'] ?? null) ? $division['alsoKnownAs'] : [];

            foreach (array_merge([(string) $divisionId], $aliases) as $ocdId) {
                if ($stateCode === '' && preg_match('/\/state:([a-z]{2})(?:\/|$)/i', $ocdId, $stateMatch)) {
                    $stateCode = strtoupper($stateMatch[1]);
                }

                if ($federalDistrict === null && preg_match('/\/state:([a-z]{2})\/cd:([^\/]+)$/i', $ocdId, $matches)) {
                    $stateCode = $stateCode !== '' ? $stateCode : strtoupper($matches[1]);
                    $federalDistrict = strtoupper($matches[2]) === 'AL' ? 'At-Large' : $matches[2];
                }

                if ($federalDistrict === null && $stateCode === 'DC' && preg_match('/\/country:us\/district:dc$/i', $ocdId)) {
                    $federalDistrict = 'At-Large';
                }

                if ($stateLowerDistrict === null && preg_match('/\/state:([a-z]{2})\/sldl:([^\/]+)$/i', $ocdId, $matches)) {
                    $stateCode = $stateCode !== '' ? $stateCode : strtoupper($matches[1]);
                    $stateLowerDistrict = $matches[2];
                }

                if ($stateUpperDistrict === null && preg_match('/\/state:([a-z]{2})\/sldu:([^\/]+)$/i', $ocdId, $matches)) {
                    $stateCode = $stateCode !== '' ? $stateCode : strtoupper($matches[1]);
                    $stateUpperDistrict = $matches[2];
                }

                if ($stateAlternativeDistrict === null && preg_match(
                    '/\/state:([a-z]{2})\/(?:ward|council_district|district):([^\/]+)$/i',
                    $ocdId,
                    $matches
                )) {
                    $stateCode = $stateCode !== '' ? $stateCode : strtoupper($matches[1]);
                    $stateAlternativeDistrict = $matches[2];
                }
            }
        }

        $stateDistrict = $stateLowerDistrict ?? $stateUpperDistrict ?? $stateAlternativeDistrict;

        return [
            'federal_district' => $federalDistrict,
            'state_district' => $stateDistrict && $stateCode !== '' ? ($stateCode . '-' . $stateDistrict) : null,
            'state_code' => $stateCode !== '' ? $stateCode : null,
            'source' => $source,
        ];
    }

    private function getDistrictsFromOpenStates($lat, $lng): array
    {
        $openStates = app(OpenStatesApi::class);
        $people = $openStates->getPeopleByLocation($lat, $lng);

        $federalDistrict = null;
        $stateDistrict = null;
        $stateCode = null;

        foreach ($people as $person) {
            if (!isset($person['current_role'])) {
                continue;
            }

            $role = $person['current_role'];

            if (!in_array($role['org_classification'] ?? '', ['legislature', 'lower', 'upper'], true)) {
                continue;
            }

            $jurisdiction = $role['jurisdiction'] ?? '';
            $district = $role['district'] ?? null;

            if ($jurisdiction === 'ocd-jurisdiction/country:us/government') {
                $federalDistrict = $district;
                continue;
            }

            if (preg_match('/state:([a-z]{2})/i', $jurisdiction, $matches)) {
                $stateCode = strtoupper($matches[1]);
            }

            if ($district) {
                $stateDistrict = $stateCode ? ($stateCode . '-' . $district) : $district;
            }
        }

        return [
            'federal_district' => $federalDistrict,
            'state_district' => $stateDistrict,
            'state_code' => $stateCode,
            'source' => 'open_states',
        ];
    }

    private function hasRequiredDistricts(array $districts): bool
    {
        return filled($districts['federal_district'] ?? null)
            && filled($districts['state_district'] ?? null);
    }

    private function mergeDistrictResults(array $primary, array $fallback): array
    {
        return [
            'federal_district' => $primary['federal_district'] ?? $fallback['federal_district'] ?? null,
            'state_district' => $primary['state_district'] ?? $fallback['state_district'] ?? null,
            'state_code' => $primary['state_code'] ?? $fallback['state_code'] ?? null,
            'source' => $this->mergeSources($primary['source'] ?? null, $fallback['source'] ?? null),
        ];
    }

    private function mergeSources(?string $primary, ?string $fallback): ?string
    {
        $sources = array_values(array_unique(array_filter([$primary, $fallback])));

        if ($sources === []) {
            return null;
        }

        return implode('+', $sources);
    }

    private function emptyDistrictResult(): array
    {
        return [
            'federal_district' => null,
            'state_district' => null,
            'state_code' => null,
            'source' => null,
        ];
    }
}
