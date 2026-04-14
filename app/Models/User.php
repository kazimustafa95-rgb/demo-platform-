<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

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
            'notification_preferences' => 'array',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole('admin');
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

    public function hasCompletedLocation(): bool
    {
        return filled($this->federal_district) && filled($this->state_district);
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
            && ($this->hasVerifiedIdentity() || $this->is_verified);
    }

    public function mobileProfile(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
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
            'location_completed' => $this->hasCompletedLocation(),
            'next_step' => $this->nextOnboardingStep(),
            'location' => [
                'country' => $this->country,
                'state' => $this->state,
                'district' => $this->district,
                'street_address' => $this->address,
                'zip_code' => $this->zip_code,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'federal_district' => $this->federal_district,
                'state_district' => $this->state_district,
            ],
        ];
    }
}
