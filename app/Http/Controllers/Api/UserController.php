<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Jurisdiction;
use App\Models\ManagedContent;
use App\Models\NotificationDevice;
use App\Models\Representative;
use App\Models\UserVote;
use App\Services\BillInsightsService;
use App\Services\FirebaseMessagingService;
use App\Services\LocationService;
use App\Services\OpenStatesApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function show(Request $request)
    {
        return response()->json($request->user()->mobileProfile());
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $input = [];

        if ($request->hasAny(['name', 'full_name'])) {
            $input['name'] = trim((string) ($request->input('name') ?: $request->input('full_name')));
        }

        if ($request->has('email')) {
            $input['email'] = Str::lower(trim((string) $request->input('email')));
        }

        if ($request->hasAny(['phone_number', 'phone'])) {
            $input['phone_number'] = trim((string) ($request->input('phone_number') ?: $request->input('phone')));
        }

        if ($request->hasAny(['address', 'street_address'])) {
            $input['address'] = trim((string) ($request->input('address') ?: $request->input('street_address')));
        }

        if ($request->has('country')) {
            $input['country'] = trim((string) $request->input('country'));
        }

        if ($request->has('state')) {
            $input['state'] = trim((string) $request->input('state'));
        }

        if ($request->has('district')) {
            $input['district'] = trim((string) $request->input('district'));
        }

        if ($request->hasAny(['zip_code', 'postal_code'])) {
            $input['zip_code'] = trim((string) ($request->input('zip_code') ?: $request->input('postal_code')));
        }

        if ($request->has('notification_preferences')) {
            $input['notification_preferences'] = $request->input('notification_preferences');
        }

        if ($request->has('remove_profile_image')) {
            $input['remove_profile_image'] = $request->boolean('remove_profile_image');
        }

        $validationPayload = $input;

        if ($request->hasFile('profile_image')) {
            $validationPayload['profile_image'] = $request->file('profile_image');
        }

        $validator = Validator::make($validationPayload, [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'email' => ['sometimes', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:25'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'district' => ['sometimes', 'nullable', 'string', 'max:100'],
            'zip_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'notification_preferences' => ['sometimes', 'array'],
            'remove_profile_image' => ['sometimes', 'boolean'],
            'profile_image' => ['sometimes', 'file', 'image', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        foreach (['phone_number', 'address', 'country', 'state', 'district', 'zip_code'] as $nullableField) {
            if (array_key_exists($nullableField, $input) && $input[$nullableField] === '') {
                $input[$nullableField] = null;
            }
        }

        if (array_key_exists('email', $input) && $input['email'] !== $user->email) {
            $input['email_verified_at'] = null;
        }

        $user->forceFill($input)->save();

        if ($request->hasFile('profile_image')) {
            $this->deleteProfileImage($user->profile_photo_path);

            $user->forceFill([
                'profile_photo_path' => $request->file('profile_image')->store('profile-photos', 'public'),
            ])->save();
        } elseif (($input['remove_profile_image'] ?? false) === true) {
            $this->deleteProfileImage($user->profile_photo_path);
            $user->forceFill([
                'profile_photo_path' => null,
            ])->save();
        }

        $user->refresh();

        return response()->json([
            'message' => 'Profile updated.',
            'user' => $user->mobileProfile(),
        ]);
    }

    public function emailPreferences(Request $request)
    {
        return response()->json($request->user()->emailPreferencesPayload());
    }

    public function updateEmailPreferences(Request $request)
    {
        $user = $request->user();
        $preferenceKeys = array_keys($user::emailPreferenceDefinitions());

        $rules = [];

        foreach ($preferenceKeys as $key) {
            $rules[$key] = ['sometimes', 'boolean'];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $notificationPreferences = is_array($user->notification_preferences)
            ? $user->notification_preferences
            : [];

        foreach ($preferenceKeys as $key) {
            if ($request->has($key)) {
                $notificationPreferences[$key] = $request->boolean($key);
            }
        }

        $user->forceFill([
            'notification_preferences' => $notificationPreferences,
        ])->save();

        $user->refresh();

        return response()->json([
            'message' => 'Email preferences updated.',
            'email_preferences' => $user->emailPreferencesPayload(),
        ]);
    }

    public function notificationPreferences(Request $request)
    {
        return response()->json($request->user()->pushNotificationPreferencesPayload());
    }

    public function privacySettings(Request $request)
    {
        $user = $request->user();
        $privacyPages = ManagedContent::query()
            ->published()
            ->where('audience', ManagedContent::AUDIENCE_PRIVACY)
            ->whereNotNull('slug')
            ->orderBy('display_order')
            ->orderBy('title')
            ->get(['slug', 'title', 'summary']);

        return response()->json([
            'title' => 'Privacy Settings',
            'summary' => 'Control your data and privacy preferences.',
            'sections' => [
                [
                    'key' => 'policies',
                    'title' => 'Policies',
                    'items' => $privacyPages->map(function (ManagedContent $item): array {
                        return [
                            'key' => $item->slug,
                            'title' => $item->title,
                            'summary' => $item->summary,
                            'slug' => $item->slug,
                            'endpoint' => "/api/content/pages/{$item->slug}",
                        ];
                    })->values(),
                ],
                [
                    'key' => 'controls',
                    'title' => 'Your Controls',
                    'items' => [
                        [
                            'key' => 'email_preferences',
                            'title' => 'Email Preferences',
                            'summary' => 'Manage how you receive email notifications from us.',
                            'endpoint' => '/api/user/email-preferences',
                            'method' => 'GET',
                        ],
                        [
                            'key' => 'notification_preferences',
                            'title' => 'Notification Preferences',
                            'summary' => 'Customize how and when you receive alerts.',
                            'endpoint' => '/api/user/notification-preferences',
                            'method' => 'GET',
                        ],
                        [
                            'key' => 'delete_account',
                            'title' => 'Delete Account',
                            'summary' => 'Permanently remove your account and data.',
                            'endpoint' => '/api/user',
                            'method' => 'DELETE',
                            'requires_password' => true,
                        ],
                    ],
                ],
            ],
            'status' => [
                'email_verified' => $user->hasVerifiedEmail(),
                'location_verified' => $user->hasCompletedLocation(),
                'identity_verified' => $user->hasVerifiedIdentity(),
            ],
        ]);
    }

    public function updateNotificationPreferences(Request $request)
    {
        $user = $request->user();
        $preferenceKeys = array_keys($user::pushNotificationPreferenceDefinitions());
        $rules = [];

        foreach ($preferenceKeys as $key) {
            $rules[$key] = ['sometimes', 'boolean'];
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $notificationPreferences = is_array($user->notification_preferences)
            ? $user->notification_preferences
            : [];

        foreach ($preferenceKeys as $key) {
            if ($request->has($key)) {
                $notificationPreferences[$key] = $request->boolean($key);
            }
        }

        $user->forceFill([
            'notification_preferences' => $notificationPreferences,
        ])->save();

        $user->refresh();

        return response()->json([
            'message' => 'Notification preferences updated.',
            'notification_preferences' => $user->pushNotificationPreferencesPayload(),
        ]);
    }

    public function registerNotificationDevice(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'in:ios,android,web'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'notifications_enabled' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $user = $request->user();
        $device = NotificationDevice::updateOrCreate(
            ['device_token' => trim((string) $request->input('device_token'))],
            [
                'user_id' => $user->id,
                'platform' => $request->input('platform'),
                'device_name' => $request->input('device_name'),
                'app_version' => $request->input('app_version'),
                'notifications_enabled' => $request->has('notifications_enabled')
                    ? $request->boolean('notifications_enabled')
                    : true,
                'last_seen_at' => now(),
            ]
        );

        return response()->json([
            'message' => 'Notification device registered.',
            'device' => $this->notificationDevicePayload($device->fresh()),
        ]);
    }

    public function unregisterNotificationDevice(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'device_token' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $deleted = NotificationDevice::where('user_id', $request->user()->id)
            ->where('device_token', trim((string) $request->input('device_token')))
            ->delete();

        return response()->json([
            'message' => $deleted > 0
                ? 'Notification device removed.'
                : 'Notification device not found.',
        ]);
    }

    public function sendTestNotification(Request $request, FirebaseMessagingService $firebaseMessagingService)
    {
        $user = $request->user();
        $preferenceDefinitions = $user::pushNotificationPreferenceDefinitions();

        $validator = Validator::make($request->all(), [
            'type' => ['required', 'string', Rule::in(array_keys($preferenceDefinitions))],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:255'],
            'device_token' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $type = (string) $request->input('type');
        $preferences = $user->normalizedPushNotificationPreferences();

        if (($preferences[$type] ?? false) !== true) {
            return response()->json([
                'message' => 'This notification type is currently disabled for the user.',
            ], 422);
        }

        $devices = $user->notificationDevices()
            ->where('notifications_enabled', true)
            ->when($request->filled('device_token'), function ($query) use ($request) {
                $query->where('device_token', trim((string) $request->input('device_token')));
            })
            ->get();

        if ($devices->isEmpty()) {
            return response()->json([
                'message' => 'No registered notification devices were found for this user.',
            ], 422);
        }

        $title = trim((string) $request->input('title')) ?: $preferenceDefinitions[$type]['title'];
        $body = trim((string) $request->input('body')) ?: 'This is a test notification from DEMOS.';
        $sent = [];
        $failed = [];

        foreach ($devices as $device) {
            try {
                $sent[] = [
                    'device_token' => $device->device_token,
                    'response' => $firebaseMessagingService->sendToDevice(
                        $device->device_token,
                        $title,
                        $body,
                        [
                            'type' => $type,
                            'test' => true,
                            'user_id' => $user->id,
                        ]
                    ),
                ];
            } catch (\Throwable $exception) {
                $failed[] = [
                    'device_token' => $device->device_token,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if (empty($sent)) {
            return response()->json([
                'message' => 'Unable to send the test notification to any registered device.',
                'failed' => $failed,
            ], 502);
        }

        return response()->json([
            'message' => 'Test notification sent.',
            'sent_count' => count($sent),
            'failed_count' => count($failed),
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }

    public function destroy(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'password' => ['required_without:current_password', 'string'],
            'current_password' => ['required_without:password', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $password = (string) ($request->input('current_password') ?: $request->input('password'));

        if (!Hash::check($password, (string) $user->password)) {
            return response()->json([
                'password' => ['The provided password is incorrect.'],
            ], 422);
        }

        $user->tokens()->delete();
        $user->delete();

        return response()->json([
            'message' => 'Account deleted successfully.',
        ]);
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

    public function votes(Request $request, BillInsightsService $billInsightsService)
    {
        $user = $request->user();
        $votes = $user->userVotes()->with('bill.jurisdiction')->paginate(20);

        $votes->setCollection($votes->getCollection()->map(function (UserVote $vote) use ($billInsightsService, $user) {
            if ($vote->bill) {
                $billInsightsService->attachCardStats($vote->bill, $user);
            }

            return $vote;
        }));

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

    private function deleteProfileImage(?string $path): void
    {
        $path = trim((string) $path);

        if ($path === '' || filter_var($path, FILTER_VALIDATE_URL)) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    private function notificationDevicePayload(NotificationDevice $device): array
    {
        return [
            'id' => $device->id,
            'device_token' => $device->device_token,
            'platform' => $device->platform,
            'device_name' => $device->device_name,
            'app_version' => $device->app_version,
            'notifications_enabled' => $device->notifications_enabled,
            'last_seen_at' => $device->last_seen_at?->toISOString(),
        ];
    }
}
