<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Jurisdiction;
use App\Models\Representative;
use App\Services\LocationService;
use App\Services\OpenStatesApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function show(Request $request)
    {
        return response()->json($request->user());
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'notification_preferences' => 'sometimes|array',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user->update($request->only('name', 'notification_preferences'));

        return response()->json(['message' => 'Profile updated.', 'user' => $user]);
    }

    public function verifyLocation(Request $request, LocationService $locationService)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'address' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $coords = $locationService->geocodeAddress($request->address);
        if (!$coords) {
            return response()->json(['message' => 'Unable to geocode address.'], 422);
        }

        $districts = $locationService->getDistrictsFromLocation($coords['lat'], $coords['lng']);
        if (!$districts['federal_district'] || !$districts['state_district']) {
            return response()->json(['message' => 'Unable to determine districts.'], 422);
        }

        $user->update([
            'address' => $request->address,
            'latitude' => $coords['lat'],
            'longitude' => $coords['lng'],
            'federal_district' => $districts['federal_district'],
            'state_district' => $districts['state_district'],
        ]);

        return response()->json(['message' => 'Location verified.', 'districts' => $districts]);
    }

    public function votes(Request $request)
    {
        $user = $request->user();
        $votes = $user->userVotes()->with('bill')->paginate(20);
        return response()->json($votes);
    }

    public function supports(Request $request)
    {
        $user = $request->user();
        $amendmentSupports = $user->amendmentSupports()->with('amendment.bill')->get();
        $proposalSupports = $user->proposalSupports()->with('proposal')->get();

        return response()->json([
            'amendments' => $amendmentSupports,
            'proposals' => $proposalSupports,
        ]);
    }

    public function submissions(Request $request)
    {
        $user = $request->user();
        $amendments = $user->amendments()->with('bill')->get();
        $proposals = $user->citizenProposals()->get();

        return response()->json([
            'amendments' => $amendments,
            'proposals' => $proposals,
        ]);
    }

    public function representatives(Request $request, OpenStatesApi $openStatesApi)
    {
        $user = $request->user();

        if (!$user->federal_district || !$user->state_district) {
            return response()->json(['message' => 'User location not set.'], 400);
        }

        $federalReps = Representative::whereHas('jurisdiction', function ($q) {
            $q->where('type', 'federal');
        })->where('district', $user->federal_district)->get();

        $stateCode = null;
        $stateDistrict = (string) $user->state_district;
        $normalizedDistrict = preg_replace('/^[A-Z]{2}[-\s]?/i', '', $stateDistrict) ?: $stateDistrict;

        if (preg_match('/^([A-Z]{2})[-\s]?/i', $stateDistrict, $matches)) {
            $stateCode = strtoupper($matches[1]);
        }

        if (!$stateCode && $user->latitude && $user->longitude) {
            $people = $openStatesApi->getPeopleByLocation($user->latitude, $user->longitude);

            foreach ($people as $person) {
                $jurisdiction = data_get($person, 'current_role.jurisdiction');

                if (!is_string($jurisdiction)) {
                    continue;
                }

                if (preg_match('/state:([a-z]{2})/i', $jurisdiction, $matches)) {
                    $stateCode = strtoupper($matches[1]);
                    break;
                }
            }
        }

        $stateJurisdiction = $stateCode
            ? Jurisdiction::where('code', $stateCode)->first()
            : null;

        $stateReps = $stateJurisdiction
            ? Representative::where('jurisdiction_id', $stateJurisdiction->id)
                ->where(function ($query) use ($stateDistrict, $normalizedDistrict) {
                    $query->where('district', $stateDistrict)
                        ->orWhere('district', $normalizedDistrict);
                })
                ->get()
            : collect();

        return response()->json([
            'federal' => $federalReps,
            'state' => $stateReps,
        ]);
    }
}