<?php

namespace App\Http\Controllers;

use App\Services\LocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class MobileUserController extends Controller
{
    public function saveLocation(Request $request, LocationService $locationService)
    {
        $legacyAddress = trim((string) $request->input('address'));
        $input = [
            'country' => trim((string) $request->input('country')),
            'state' => trim((string) $request->input('state')),
            'district' => trim((string) $request->input('district')),
            'street_address' => trim((string) $request->input('street_address')),
            'zip_code' => trim((string) ($request->input('zip_code') ?: $request->input('postal_code'))),
            'latitude' => $request->input('latitude'),
            'longitude' => $request->input('longitude'),
        ];

        $validator = Validator::make($input, [
            'country' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'district' => 'nullable|string|max:100',
            'street_address' => 'nullable|string|max:255',
            'zip_code' => 'nullable|string|max:20',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        $validator->after(function ($validator) use ($input, $legacyAddress): void {
            $hasCoordinates = $input['latitude'] !== null || $input['longitude'] !== null;
            $hasLegacyAddress = $legacyAddress !== '';

            if ($hasCoordinates) {
                if ($input['latitude'] === null || $input['longitude'] === null) {
                    $validator->errors()->add('latitude', 'Both latitude and longitude are required for auto-detect.');
                }

                return;
            }

            if ($hasLegacyAddress) {
                return;
            }

            foreach (['country', 'state', 'district', 'street_address', 'zip_code'] as $field) {
                if ($input[$field] === '') {
                    $validator->errors()->add($field, 'This field is required.');
                }
            }
        });

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = $request->user();
        $latitude = $input['latitude'] !== null ? (float) $input['latitude'] : null;
        $longitude = $input['longitude'] !== null ? (float) $input['longitude'] : null;
        $locationFields = [
            'country' => $input['country'] !== '' ? $input['country'] : null,
            'state' => $input['state'] !== '' ? $input['state'] : null,
            'district' => $input['district'] !== '' ? $input['district'] : null,
            'street_address' => $input['street_address'] !== '' ? $input['street_address'] : null,
            'zip_code' => $input['zip_code'] !== '' ? $input['zip_code'] : null,
        ];

        if ($latitude === null || $longitude === null) {
            $fullAddress = $legacyAddress !== ''
                ? $legacyAddress
                : implode(', ', array_filter([
                    $locationFields['street_address'],
                    $locationFields['district'],
                    $locationFields['state'],
                    $locationFields['country'],
                    $locationFields['zip_code'],
                ]));
            
            $coords = $locationService->geocodeAddress($fullAddress);
            if (!$coords) {
                return response()->json(['message' => 'Unable to geocode address.'], 422);
            }

            $latitude = (float) $coords['lat'];
            $longitude = (float) $coords['lng'];
        } else {
            $resolvedAddress = $this->reverseGeocode($latitude, $longitude);

            if ($resolvedAddress) {
                $locationFields = [
                    'country' => $resolvedAddress['country'] ?? $locationFields['country'],
                    'state' => $resolvedAddress['state'] ?? $locationFields['state'],
                    'district' => $resolvedAddress['district'] ?? $locationFields['district'],
                    'street_address' => $resolvedAddress['street_address'] ?? $locationFields['street_address'],
                    'zip_code' => $resolvedAddress['zip_code'] ?? $locationFields['zip_code'],
                ];
            }
        }

        $districts = $locationService->getDistrictsFromLocation($latitude, $longitude);

        if (!$districts['federal_district'] || !$districts['state_district']) {
            return response()->json(['message' => 'Unable to determine legislative districts for this location.'], 422);
        }

        $user->update([
            'address' => $locationFields['street_address'] ?: ($legacyAddress !== '' ? $legacyAddress : null),
            'country' => $locationFields['country'],
            'state' => $locationFields['state'],
            'district' => $locationFields['district'],
            'zip_code' => $locationFields['zip_code'],
            'latitude' => $latitude,
            'longitude' => $longitude,
            'federal_district' => $districts['federal_district'],
            'state_district' => $districts['state_district'],
            'verified_at' => now(),
            'is_verified' => true,
        ]);

        $user = $user->fresh();

        return response()->json([
            'message' => 'Location verified.',
            'next_step' => $user->nextOnboardingStep(),
            'districts' => $districts,
            'user' => $user->mobileProfile(),
        ]);
    }

    private function reverseGeocode(float $latitude, float $longitude): ?array
    {
        $response = Http::get('https://maps.googleapis.com/maps/api/geocode/json', [
            'latlng' => $latitude.','.$longitude,
            'key' => config('services.google_maps.api_key'),
        ]);

        if ($response->failed() || $response->json('status') !== 'OK') {
            return null;
        }

        $result = $response->json('results.0');
        $components = collect($result['address_components'] ?? []);
        $lookup = function (string $type, string $field = 'long_name') use ($components): ?string {
            $component = $components->first(function (array $component) use ($type) {
                return in_array($type, $component['types'] ?? [], true);
            });

            return is_array($component) ? ($component[$field] ?? null) : null;
        };

        $streetNumber = $lookup('street_number');
        $route = $lookup('route');
        $streetAddress = trim(implode(' ', array_filter([$streetNumber, $route])));

        return [
            'country' => $lookup('country'),
            'state' => $lookup('administrative_area_level_1'),
            'district' => $lookup('locality')
                ?? $lookup('administrative_area_level_2')
                ?? $lookup('sublocality')
                ?? $lookup('neighborhood'),
            'street_address' => $streetAddress !== '' ? $streetAddress : ($result['formatted_address'] ?? null),
            'zip_code' => $lookup('postal_code'),
        ];
    }
}
