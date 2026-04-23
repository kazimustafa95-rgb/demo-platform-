<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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

        $censusDistricts = $this->getDistrictsFromCensusGeocoder($lat, $lng);
        $googleAndCensusDistricts = $this->mergeDistrictResults($googleDistricts, $censusDistricts);

        if ($this->hasRequiredDistricts($googleAndCensusDistricts)) {
            return $googleAndCensusDistricts;
        }

        $openStatesDistricts = $this->getDistrictsFromOpenStates($lat, $lng);

        return $this->mergeDistrictResults($googleAndCensusDistricts, $openStatesDistricts);
    }

    private function getDistrictsFromGoogleCivic(?string $address): array
    {
        if (blank($this->googleMapsKey) || blank($address)) {
            return $this->emptyDistrictResult(
                diagnostics: [[
                    'source' => 'google_civic',
                    'status' => null,
                    'reason' => 'missing_address_or_key',
                ]]
            );
        }

        try {
            $response = Http::get('https://www.googleapis.com/civicinfo/v2/divisionsByAddress', [
                'address' => $address,
                'key' => $this->googleMapsKey,
            ]);
        } catch (ConnectionException) {
            return $this->emptyDistrictResult(
                diagnostics: [[
                    'source' => 'google_civic',
                    'status' => null,
                    'reason' => 'connection_exception',
                ]]
            );
        }

        if ($response->failed()) {
            return $this->emptyDistrictResult(
                diagnostics: [$this->buildLookupFailureDiagnostic('google_civic', $response)]
            );
        }

        return $this->extractDistrictsFromGoogleIdentifiers(
            (array) $response->json('divisions', []),
            [],
            (string) ($response->json('normalizedInput.state') ?? ''),
            'google_civic'
        );
    }

    private function getDistrictsFromGoogleRepresentatives(?string $address): array
    {
        if (blank($this->googleMapsKey) || blank($address)) {
            return $this->emptyDistrictResult(
                diagnostics: [[
                    'source' => 'google_civic_representatives',
                    'status' => null,
                    'reason' => 'missing_address_or_key',
                ]]
            );
        }

        try {
            $response = Http::get('https://www.googleapis.com/civicinfo/v2/representatives', [
                'address' => $address,
                'includeOffices' => 'true',
                'key' => $this->googleMapsKey,
            ]);
        } catch (ConnectionException) {
            return $this->emptyDistrictResult(
                diagnostics: [[
                    'source' => 'google_civic_representatives',
                    'status' => null,
                    'reason' => 'connection_exception',
                ]]
            );
        }

        if ($response->failed()) {
            return $this->emptyDistrictResult(
                diagnostics: [$this->buildLookupFailureDiagnostic('google_civic_representatives', $response)]
            );
        }

        return $this->extractDistrictsFromGoogleIdentifiers(
            (array) $response->json('divisions', []),
            $this->extractGoogleOfficeDivisionIds((array) $response->json('offices', [])),
            (string) ($response->json('normalizedInput.state') ?? ''),
            'google_civic_representatives'
        );
    }

    private function extractDistrictsFromGoogleIdentifiers(
        array $divisions,
        array $additionalOcdIds,
        string $normalizedState,
        string $source
    ): array
    {
        $stateCode = strtoupper(trim($normalizedState));
        $federalDistrict = null;
        $stateLowerDistrict = null;
        $stateUpperDistrict = null;
        $stateAlternativeDistrict = null;

        foreach ($this->collectGoogleOcdIds($divisions, $additionalOcdIds) as $ocdId) {
            if ($stateCode === '' && preg_match('/\/state:([a-z]{2})(?:\/|$)/i', $ocdId, $stateMatch)) {
                $stateCode = strtoupper($stateMatch[1]);
            }

            if ($federalDistrict === null && preg_match('/\/state:([a-z]{2})\/cd:([^\/]+)$/i', $ocdId, $matches)) {
                $stateCode = $stateCode !== '' ? $stateCode : strtoupper($matches[1]);
                $federalDistrict = $this->normalizeFederalDistrict($matches[2]);
            }

            if ($federalDistrict === null && preg_match('/\/country:us\/district:dc\/cd:([^\/]+)$/i', $ocdId, $matches)) {
                $stateCode = $stateCode !== '' ? $stateCode : 'DC';
                $federalDistrict = $this->normalizeFederalDistrict($matches[1]);
            }

            if ($federalDistrict === null && preg_match('/\/country:us\/district:dc$/i', $ocdId)) {
                $stateCode = $stateCode !== '' ? $stateCode : 'DC';
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

        $stateDistrict = $stateLowerDistrict ?? $stateUpperDistrict ?? $stateAlternativeDistrict;

        return [
            'federal_district' => $federalDistrict,
            'state_district' => $stateDistrict && $stateCode !== '' ? ($stateCode . '-' . $stateDistrict) : null,
            'state_code' => $stateCode !== '' ? $stateCode : null,
            'source' => $source,
            'lookup_diagnostics' => [],
        ];
    }

    private function collectGoogleOcdIds(array $divisions, array $additionalOcdIds = []): array
    {
        $ocdIds = [];

        foreach ($divisions as $divisionId => $division) {
            $normalizedDivisionId = trim((string) $divisionId);
            if ($normalizedDivisionId !== '') {
                $ocdIds[] = $normalizedDivisionId;
            }

            $aliases = is_array($division['alsoKnownAs'] ?? null) ? $division['alsoKnownAs'] : [];

            foreach ($aliases as $alias) {
                $normalizedAlias = trim((string) $alias);
                if ($normalizedAlias !== '') {
                    $ocdIds[] = $normalizedAlias;
                }
            }
        }

        foreach ($additionalOcdIds as $ocdId) {
            $normalizedOcdId = trim((string) $ocdId);
            if ($normalizedOcdId !== '') {
                $ocdIds[] = $normalizedOcdId;
            }
        }

        return array_values(array_unique($ocdIds));
    }

    private function extractGoogleOfficeDivisionIds(array $offices): array
    {
        $divisionIds = [];

        foreach ($offices as $office) {
            if (!is_array($office)) {
                continue;
            }

            $divisionId = trim((string) ($office['divisionId'] ?? $office['division_id'] ?? ''));
            if ($divisionId !== '') {
                $divisionIds[] = $divisionId;
            }
        }

        return $divisionIds;
    }

    private function normalizeFederalDistrict(string $district): string
    {
        $normalizedDistrict = trim($district);
        $upperDistrict = strtoupper(str_replace(' ', '-', $normalizedDistrict));

        if (in_array($upperDistrict, ['AL', 'AT-LARGE'], true)) {
            return 'At-Large';
        }

        return $normalizedDistrict;
    }

    private function getDistrictsFromCensusGeocoder($lat, $lng): array
    {
        if ($lat === null || $lng === null) {
            return $this->emptyDistrictResult(
                diagnostics: [[
                    'source' => 'census_geocoder',
                    'status' => null,
                    'reason' => 'missing_coordinates',
                ]]
            );
        }

        try {
            $response = Http::get('https://geocoding.geo.census.gov/geocoder/geographies/coordinates', [
                'x' => $lng,
                'y' => $lat,
                'benchmark' => 'Public_AR_Current',
                'vintage' => 'Current_Current',
                'format' => 'json',
            ]);
        } catch (ConnectionException) {
            return $this->emptyDistrictResult(
                diagnostics: [[
                    'source' => 'census_geocoder',
                    'status' => null,
                    'reason' => 'connection_exception',
                ]]
            );
        }

        if ($response->failed()) {
            return $this->emptyDistrictResult(
                diagnostics: [$this->buildLookupFailureDiagnostic('census_geocoder', $response)]
            );
        }

        $geographies = $this->extractCensusGeographies((array) $response->json());

        if ($geographies === []) {
            return $this->emptyDistrictResult();
        }

        $stateEntry = $this->findFirstCensusGeography($geographies, '/^States$/i');
        $upperEntry = $this->findFirstCensusGeography($geographies, '/State Legislative Districts - Upper$/i');
        $lowerEntry = $this->findFirstCensusGeography($geographies, '/State Legislative Districts - Lower$/i');
        $congressionalEntry = $this->findFirstCensusGeography($geographies, '/Congressional Districts$/i');

        $stateCode = $this->normalizeCensusStateCode(
            $stateEntry['STATE'] ?? $lowerEntry['STATE'] ?? $upperEntry['STATE'] ?? $congressionalEntry['STATE'] ?? null
        );

        $stateDistrict = $this->normalizeCensusLegislativeDistrict(
            $lowerEntry['BASENAME'] ?? $lowerEntry['NAME'] ?? null
        ) ?? $this->normalizeCensusLegislativeDistrict(
            $upperEntry['BASENAME'] ?? $upperEntry['NAME'] ?? null
        );

        $federalDistrict = $this->normalizeCensusFederalDistrict(
            $congressionalEntry['NAME'] ?? null,
            $congressionalEntry['CD119'] ?? null
        );

        return [
            'federal_district' => $federalDistrict,
            'state_district' => $stateDistrict !== null && $stateCode !== null ? ($stateCode . '-' . $stateDistrict) : null,
            'state_code' => $stateCode,
            'source' => 'census_geocoder',
            'lookup_diagnostics' => [],
        ];
    }

    private function extractCensusGeographies(array $payload): array
    {
        $result = is_array($payload['result'] ?? null) ? $payload['result'] : [];
        $directGeographies = is_array($result['geographies'] ?? null) ? $result['geographies'] : [];

        if ($directGeographies !== []) {
            return $directGeographies;
        }

        $addressMatches = is_array($result['addressMatches'] ?? null) ? $result['addressMatches'] : [];
        $firstMatch = is_array($addressMatches[0] ?? null) ? $addressMatches[0] : [];

        return is_array($firstMatch['geographies'] ?? null) ? $firstMatch['geographies'] : [];
    }

    private function findFirstCensusGeography(array $geographies, string $pattern): ?array
    {
        foreach ($geographies as $layerName => $entries) {
            if (!is_string($layerName) || preg_match($pattern, $layerName) !== 1) {
                continue;
            }

            if (!is_array($entries) || !is_array($entries[0] ?? null)) {
                continue;
            }

            return $entries[0];
        }

        return null;
    }

    private function normalizeCensusFederalDistrict(?string $name, mixed $rawDistrictCode): ?string
    {
        $districtName = trim((string) $name);

        if ($districtName !== '' && preg_match('/at[-\s]?large/i', $districtName) === 1) {
            return 'At-Large';
        }

        if ($districtName !== '' && preg_match('/District\s+(.+)$/i', $districtName, $matches) === 1) {
            return trim($matches[1]);
        }

        $districtCode = trim((string) $rawDistrictCode);
        if ($districtCode === '') {
            return null;
        }

        return ltrim($districtCode, '0') ?: '0';
    }

    private function normalizeCensusLegislativeDistrict(?string $value): ?string
    {
        $district = trim((string) $value);

        if ($district === '') {
            return null;
        }

        if (preg_match('/(?:District|Ward)\s+(.+)$/i', $district, $matches) === 1) {
            return trim($matches[1]);
        }

        return $district;
    }

    private function normalizeCensusStateCode(mixed $value): ?string
    {
        $state = trim((string) $value);

        if ($state === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $state) === 1) {
            return $this->fipsToStateCode(str_pad($state, 2, '0', STR_PAD_LEFT));
        }

        return strtoupper($state);
    }

    private function fipsToStateCode(string $fips): ?string
    {
        return [
            '01' => 'AL',
            '02' => 'AK',
            '04' => 'AZ',
            '05' => 'AR',
            '06' => 'CA',
            '08' => 'CO',
            '09' => 'CT',
            '10' => 'DE',
            '11' => 'DC',
            '12' => 'FL',
            '13' => 'GA',
            '15' => 'HI',
            '16' => 'ID',
            '17' => 'IL',
            '18' => 'IN',
            '19' => 'IA',
            '20' => 'KS',
            '21' => 'KY',
            '22' => 'LA',
            '23' => 'ME',
            '24' => 'MD',
            '25' => 'MA',
            '26' => 'MI',
            '27' => 'MN',
            '28' => 'MS',
            '29' => 'MO',
            '30' => 'MT',
            '31' => 'NE',
            '32' => 'NV',
            '33' => 'NH',
            '34' => 'NJ',
            '35' => 'NM',
            '36' => 'NY',
            '37' => 'NC',
            '38' => 'ND',
            '39' => 'OH',
            '40' => 'OK',
            '41' => 'OR',
            '42' => 'PA',
            '44' => 'RI',
            '45' => 'SC',
            '46' => 'SD',
            '47' => 'TN',
            '48' => 'TX',
            '49' => 'UT',
            '50' => 'VT',
            '51' => 'VA',
            '53' => 'WA',
            '54' => 'WV',
            '55' => 'WI',
            '56' => 'WY',
        ][$fips] ?? null;
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
            'lookup_diagnostics' => array_values(array_merge(
                is_array($primary['lookup_diagnostics'] ?? null) ? $primary['lookup_diagnostics'] : [],
                is_array($fallback['lookup_diagnostics'] ?? null) ? $fallback['lookup_diagnostics'] : [],
            )),
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

    private function emptyDistrictResult(array $diagnostics = []): array
    {
        return [
            'federal_district' => null,
            'state_district' => null,
            'state_code' => null,
            'source' => null,
            'lookup_diagnostics' => $diagnostics,
        ];
    }

    private function buildLookupFailureDiagnostic(string $source, Response $response): array
    {
        $errorMessage = trim((string) ($response->json('error.message') ?? ''));

        if ($errorMessage === '') {
            $errorMessage = Str::limit(trim($response->body()), 300, '...');
        }

        return [
            'source' => $source,
            'status' => $response->status(),
            'reason' => 'http_failure',
            'error' => $errorMessage !== '' ? $errorMessage : null,
        ];
    }
}
