<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class LocationService
{
    protected string $googleMapsKey;

    public function __construct()
    {
        $this->googleMapsKey = config('services.google_maps.api_key');
    }

    public function geocodeAddress(string $address): ?array
    {
        $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
            'address' => $address,
            'key' => $this->googleMapsKey,
        ]);
        dd($response->json());
        if ($response->failed() || $response->json('status') !== 'OK') {
            return null;
        }

        $location = $response->json('results.0.geometry.location');

        return [
            'lat' => $location['lat'],
            'lng' => $location['lng'],
        ];
    }

    public function getDistrictsFromLocation($lat, $lng): array
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

            if (($role['org_classification'] ?? '') !== 'legislature') {
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
        ];
    }
}