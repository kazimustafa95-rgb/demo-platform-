<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'password',
        'verified_at',
        'is_verified',
        'identity_verification_provider',
        'identity_verification_status',
        'identity_verification_reference',
        'identity_verified_at',
        'identity_verification_meta',
        'suspended_at',
        'suspension_ends_at',
        'suspension_reason',
        'address',
        'country',
        'state',
        'district',
        'zip_code',
        'latitude',
        'longitude',
        'federal_district',
        'state_district',
        'notification_preferences',
        'subscription_tier',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code',
        'email_verification_code_expires_at',
        'email_verification_code_sent_at',
        'identity_verification_reference',
        'identity_verification_meta',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_code_expires_at' => 'datetime',
            'email_verification_code_sent_at' => 'datetime',
            'verified_at' => 'datetime',
            'is_verified' => 'boolean',
            'identity_verified_at' => 'datetime',
            'identity_verification_meta' => 'array',
            'suspended_at' => 'datetime',
            'suspension_ends_at' => 'datetime',
            'notification_preferences' => 'array',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return !$this->isSuspended() && $this->hasRole('admin');
    }

    public function userVotes()
    {
        return $this->hasMany(UserVote::class);
    }

    public function amendments()
    {
        return $this->hasMany(Amendment::class);
    }

    public function citizenProposals()
    {
        return $this->hasMany(CitizenProposal::class);
    }

    public function amendmentSupports()
    {
        return $this->hasMany(AmendmentSupport::class);
    }

    public function proposalSupports()
    {
        return $this->hasMany(ProposalSupport::class);
    }

    public function reports()
    {
        return $this->hasMany(Report::class);
    }

    public function notificationDevices()
    {
        return $this->hasMany(NotificationDevice::class);
    }

    public function hasCompletedLocation(): bool
    {
        return filled($this->federal_district) && filled($this->state_district);
    }

    public function isSuspended(): bool
    {
        if ($this->suspended_at === null) {
            return false;
        }

        return $this->suspension_ends_at === null || $this->suspension_ends_at->isFuture();
    }

    public function suspend(?string $reason = null, mixed $until = null): void
    {
        $this->forceFill([
            'suspended_at' => now(),
            'suspension_ends_at' => $until,
            'suspension_reason' => $reason,
        ])->save();
    }

    public function clearSuspension(): void
    {
        $this->forceFill([
            'suspended_at' => null,
            'suspension_ends_at' => null,
            'suspension_reason' => null,
        ])->save();
    }

    public function suspensionDetails(): array
    {
        return [
            'active' => $this->isSuspended(),
            'reason' => $this->suspension_reason,
            'suspended_at' => $this->suspended_at?->toISOString(),
            'ends_at' => $this->suspension_ends_at?->toISOString(),
        ];
    }

    public function nextOnboardingStep(): string
    {
        if (!$this->hasVerifiedEmail()) {
            return 'verify_email';
        }

        if (!$this->hasCompletedLocation()) {
            return 'select_location';
        }

        return 'complete';
    }

    public function hasVerifiedIdentity(): bool
    {
        $status = strtolower(trim((string) $this->identity_verification_status));

        return $this->identity_verified_at !== null
            || in_array($status, ['approved', 'verified', 'completed', 'passed'], true);
    }

    public function isVerifiedConstituent(): bool
    {
        return $this->hasVerifiedEmail()
            && $this->hasCompletedLocation()
            && !$this->isSuspended()
            && ($this->hasVerifiedIdentity() || $this->is_verified);
    }

    public function mobileProfile(): array
    {
        $residentialAddress = $this->formattedResidentialAddress();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'full_name' => $this->name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'profile_photo_url' => $this->profilePhotoUrl(),
            'profile_image_url' => $this->profilePhotoUrl(),
            'notification_preferences' => $this->notification_preferences,
            'email_preferences' => $this->emailPreferencesPayload(),
            'push_notification_preferences' => $this->pushNotificationPreferencesPayload(),
            'email_verified' => $this->hasVerifiedEmail(),
            'email_verified_at' => $this->email_verified_at?->toISOString(),
            'is_verified' => $this->is_verified,
            'verified_at' => $this->verified_at?->toISOString(),
            'identity_verification' => [
                'provider' => $this->identity_verification_provider,
                'status' => $this->identity_verification_status,
                'verified' => $this->hasVerifiedIdentity(),
                'verified_at' => $this->identity_verified_at?->toISOString(),
            ],
            'suspension' => $this->suspensionDetails(),
            'location_completed' => $this->hasCompletedLocation(),
            'next_step' => $this->nextOnboardingStep(),
            'profile_information' => [
                'full_name' => $this->name,
                'email_address' => $this->email,
                'phone_number' => $this->phone_number,
                'profile_photo_url' => $this->profilePhotoUrl(),
                'profile_image_url' => $this->profilePhotoUrl(),
                'residential_address' => $residentialAddress,
                'address' => [
                    'street_address' => $this->address,
                    'district' => $this->district,
                    'state' => $this->state,
                    'country' => $this->country,
                    'zip_code' => $this->zip_code,
                ],
            ],
            'verification_status' => [
                'email_verified' => $this->hasVerifiedEmail(),
                'location_verified' => $this->hasCompletedLocation(),
                'identity_verified' => $this->hasVerifiedIdentity(),
                'constituent_verified' => $this->isVerifiedConstituent(),
                'eligible_to_participate' => $this->isVerifiedConstituent(),
                'verified_at' => $this->verified_at?->toISOString(),
                'identity_verified_at' => $this->identity_verified_at?->toISOString(),
            ],
            'location' => [
                'country' => $this->country,
                'state' => $this->state,
                'district' => $this->district,
                'street_address' => $this->address,
                'zip_code' => $this->zip_code,
                'residential_address' => $residentialAddress,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'federal_district' => $this->federal_district,
                'state_district' => $this->state_district,
            ],
        ];
    }

    public static function emailPreferenceDefinitions(): array
    {
        return [
            'account_updates' => [
                'title' => 'Account Updates',
                'description' => 'Important updates about your account',
                'group' => 'account',
                'default' => true,
            ],
            'security_alerts' => [
                'title' => 'Security Alerts',
                'description' => 'Login alerts and security notifications',
                'group' => 'account',
                'default' => true,
            ],
            'reminders' => [
                'title' => 'Reminders',
                'description' => 'Activity reminders and notifications',
                'group' => 'account',
                'default' => true,
            ],
            'promotions' => [
                'title' => 'Promotions',
                'description' => 'Special offers and discounts',
                'group' => 'marketing',
                'default' => false,
            ],
            'newsletter' => [
                'title' => 'Newsletter',
                'description' => 'Weekly updates and product news',
                'group' => 'marketing',
                'default' => false,
            ],
        ];
    }

    public function normalizedEmailPreferences(): array
    {
        $storedPreferences = is_array($this->notification_preferences)
            ? $this->notification_preferences
            : [];

        $preferences = [];

        foreach (self::emailPreferenceDefinitions() as $key => $definition) {
            $preferences[$key] = array_key_exists($key, $storedPreferences)
                ? (bool) $storedPreferences[$key]
                : (bool) $definition['default'];
        }

        return $preferences;
    }

    public function emailPreferencesPayload(): array
    {
        $preferences = $this->normalizedEmailPreferences();
        $sections = [];

        foreach (['account' => 'Account', 'marketing' => 'Marketing'] as $group => $title) {
            $items = [];

            foreach (self::emailPreferenceDefinitions() as $key => $definition) {
                if ($definition['group'] !== $group) {
                    continue;
                }

                $items[] = [
                    'key' => $key,
                    'title' => $definition['title'],
                    'description' => $definition['description'],
                    'enabled' => $preferences[$key],
                ];
            }

            $sections[] = [
                'key' => $group,
                'title' => $title,
                'items' => $items,
            ];
        }

        return [
            'summary' => 'Manage how you receive email notifications from us.',
            'preferences' => $preferences,
            'sections' => $sections,
        ];
    }

    public static function pushNotificationPreferenceDefinitions(): array
    {
        return [
            'bill_updates' => [
                'title' => 'Bill Updates',
                'description' => "Get notified when bills you're tracking change status",
                'default' => true,
            ],
            'significance_alerts' => [
                'title' => 'Significance Alerts',
                'description' => 'Alerts when bills reach statistical significance threshold',
                'default' => true,
            ],
            'weekly_digest' => [
                'title' => 'Weekly Digest',
                'description' => 'Summary of activity in your district every week',
                'default' => false,
            ],
            'proposal_activity' => [
                'title' => 'Proposal Activity',
                'description' => 'Updates on proposals and amendments',
                'default' => true,
            ],
            'representative_updates' => [
                'title' => 'Representative Updates',
                'description' => 'News and statements from your representatives',
                'default' => false,
            ],
        ];
    }

    public function normalizedPushNotificationPreferences(): array
    {
        $storedPreferences = is_array($this->notification_preferences)
            ? $this->notification_preferences
            : [];

        $preferences = [];

        foreach (self::pushNotificationPreferenceDefinitions() as $key => $definition) {
            $preferences[$key] = array_key_exists($key, $storedPreferences)
                ? (bool) $storedPreferences[$key]
                : (bool) $definition['default'];
        }

        return $preferences;
    }

    public function pushNotificationPreferencesPayload(): array
    {
        $preferences = $this->normalizedPushNotificationPreferences();
        $items = [];

        foreach (self::pushNotificationPreferenceDefinitions() as $key => $definition) {
            $items[] = [
                'key' => $key,
                'title' => $definition['title'],
                'description' => $definition['description'],
                'enabled' => $preferences[$key],
            ];
        }

        return [
            'title' => 'Notification Preferences',
            'summary' => 'Customize how and when you receive alerts.',
            'preferences' => $preferences,
            'items' => $items,
        ];
    }

    private function formattedResidentialAddress(): ?string
    {
        $address = implode(', ', array_filter([
            $this->address,
            $this->district,
            $this->state . ($this->zip_code ? ' ' . $this->zip_code : ''),
            $this->country && $this->country !== 'United States' ? $this->country : null,
        ]));

        return $address !== '' ? $address : null;
    }

    private function profilePhotoUrl(): ?string
    {
        $path = trim((string) $this->profile_photo_path);

        if ($path === '') {
            return null;
        }

        if (filter_var($path, FILTER_VALIDATE_URL)) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
