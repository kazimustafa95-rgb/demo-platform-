<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Setting extends Model
{
    use HasFactory;

    public const DEFINITIONS = [
        'platform_name' => [
            'label' => 'Platform Name',
            'group' => 'General',
            'description' => 'The public-facing product name shown across the platform.',
        ],
        'contact_email' => [
            'label' => 'Contact Email',
            'group' => 'General',
            'description' => 'Primary public contact address for platform questions.',
        ],
        'support_email' => [
            'label' => 'Support Email',
            'group' => 'General',
            'description' => 'Support address used for help and account-related communication.',
        ],
        'amendment_threshold' => [
            'label' => 'Amendment Share Threshold',
            'group' => 'Engagement',
            'description' => 'Support count required before amendment sharing unlocks.',
        ],
        'proposal_threshold' => [
            'label' => 'Citizen Proposal Share Threshold',
            'group' => 'Engagement',
            'description' => 'Support count required before citizen proposal sharing unlocks.',
        ],
        'duplicate_threshold' => [
            'label' => 'Duplicate Detection Threshold',
            'group' => 'Engagement',
            'description' => 'Similarity percentage used to reject citizen proposals that match real bills.',
        ],
        'voting_deadline_hours' => [
            'label' => 'Voting Deadline Hours',
            'group' => 'Voting',
            'description' => 'Hours before the official vote when constituent voting closes.',
        ],
        'proposal_active_days' => [
            'label' => 'Citizen Proposal Active Days',
            'group' => 'Voting',
            'description' => 'Default time window that citizen proposals stay active for support.',
        ],
        'auto_hide_report_count' => [
            'label' => 'Auto-hide Report Threshold',
            'group' => 'Moderation',
            'description' => 'Number of reports required before content is hidden automatically.',
        ],
        'feature_amendments_enabled' => [
            'label' => 'Amendments Enabled',
            'group' => 'Features',
            'description' => 'Enable or disable citizen amendment submission and support.',
        ],
        'feature_citizen_proposals_enabled' => [
            'label' => 'Citizen Proposals Enabled',
            'group' => 'Features',
            'description' => 'Enable or disable citizen proposal submission and support.',
        ],
        'maintenance_mode' => [
            'label' => 'Maintenance Mode',
            'group' => 'Features',
            'description' => 'Use a truthy value to indicate planned maintenance mode messaging.',
        ],
    ];

    protected $fillable = ['key', 'value'];

    protected static function booted(): void
    {
        static::saving(function (Setting $setting): void {
            $setting->value = static::normalizeValueForStorage((string) $setting->key, $setting->value);
        });
    }

    /**
     * Convenience getter.
     */
    public static function get(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }

    public static function options(): array
    {
        return collect(self::DEFINITIONS)
            ->mapWithKeys(fn (array $definition, string $key): array => [$key => $definition['label']])
            ->all();
    }

    public static function labelFor(string $key): string
    {
        return self::DEFINITIONS[$key]['label'] ?? $key;
    }

    public static function groupFor(string $key): string
    {
        return self::DEFINITIONS[$key]['group'] ?? 'Other';
    }

    public static function descriptionFor(string $key): ?string
    {
        return self::DEFINITIONS[$key]['description'] ?? null;
    }

    public static function validationRulesFor(string $key): array
    {
        return match ($key) {
            'platform_name' => ['required', 'string', 'min:2', 'max:120'],
            'contact_email', 'support_email' => ['required', 'string', 'email:rfc', 'max:255'],
            'duplicate_threshold' => ['required', 'integer', 'between:1,100'],
            'amendment_threshold', 'proposal_threshold', 'voting_deadline_hours', 'proposal_active_days', 'auto_hide_report_count' => ['required', 'integer', 'min:1', 'max:100000'],
            'feature_amendments_enabled', 'feature_citizen_proposals_enabled', 'maintenance_mode' => ['required', static::booleanLikeRule()],
            default => ['nullable', 'string', 'max:2000'],
        };
    }

    public static function normalizeValueForStorage(string $key, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = is_string($value) ? trim($value) : $value;

        if ($normalized === '') {
            return null;
        }

        return match ($key) {
            'contact_email', 'support_email' => Str::lower(trim((string) $normalized)),
            'duplicate_threshold', 'amendment_threshold', 'proposal_threshold', 'voting_deadline_hours', 'proposal_active_days', 'auto_hide_report_count' => (string) ((int) $normalized),
            'feature_amendments_enabled', 'feature_citizen_proposals_enabled', 'maintenance_mode' => static::normalizeBooleanLike($normalized),
            default => trim((string) $normalized),
        };
    }

    protected static function booleanLikeRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! static::isBooleanLike($value)) {
                $fail('The ' . str_replace('_', ' ', $attribute) . ' field must be a boolean-style value such as 1, 0, true, or false.');
            }
        };
    }

    protected static function isBooleanLike(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'], true);
    }

    protected static function normalizeBooleanLike(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? '1' : '0';
    }
}
