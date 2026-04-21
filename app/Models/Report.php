<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Model as EloquentModel;

class Report extends Model
{
    use HasFactory;

    public const REPORTABLE_TYPE_AMENDMENT = 'amendment';
    public const REPORTABLE_TYPE_PROPOSAL = 'proposal';

    public const REASON_SPAM = 'spam';
    public const REASON_OFFENSIVE = 'offensive';
    public const REASON_JOKE = 'joke';
    public const REASON_DUPLICATE = 'duplicate';
    public const REASON_OTHER = 'other';

    public const STATUS_PENDING = 'pending';
    public const STATUS_REVIEWED = 'reviewed';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_ACTION_TAKEN = 'action_taken';

    protected ?EloquentModel $resolvedReportableCache = null;

    protected bool $hasResolvedReportableCache = false;

    protected $fillable = [
        'user_id',
        'reportable_type',
        'reportable_id',
        'reason',
        'description',
        'status',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reportable()
    {
        return $this->morphTo();
    }

    public function resolvedReportable(): ?EloquentModel
    {
        if ($this->hasResolvedReportableCache) {
            return $this->resolvedReportableCache;
        }

        $this->hasResolvedReportableCache = true;

        $reportableType = static::normalizeReportableType($this->getRawOriginal('reportable_type') ?? $this->reportable_type);
        $reportableId = $this->getRawOriginal('reportable_id') ?? $this->reportable_id;

        if (($reportableType === null) || blank($reportableId)) {
            return $this->resolvedReportableCache = null;
        }

        return $this->resolvedReportableCache = $reportableType::query()->find($reportableId);
    }

    public function reportableTypeLabel(): string
    {
        return match (static::normalizeReportableType($this->getRawOriginal('reportable_type') ?? $this->reportable_type)) {
            Amendment::class => 'Amendment',
            CitizenProposal::class => 'Citizen Proposal',
            default => 'Unknown',
        };
    }

    public static function reportableTypeOptions(): array
    {
        return [
            Amendment::class => 'Amendment',
            CitizenProposal::class => 'Citizen Proposal',
        ];
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_PENDING => 'Pending',
            self::STATUS_REVIEWED => 'Reviewed',
            self::STATUS_DISMISSED => 'Dismissed',
            self::STATUS_ACTION_TAKEN => 'Action Taken',
        ];
    }

    public static function normalizeReportableType(mixed $value): ?string
    {
        $type = trim((string) $value);

        return match ($type) {
            Amendment::class, self::REPORTABLE_TYPE_AMENDMENT => Amendment::class,
            CitizenProposal::class, self::REPORTABLE_TYPE_PROPOSAL, 'citizen_proposal' => CitizenProposal::class,
            default => null,
        };
    }

    public function setReportableTypeAttribute(mixed $value): void
    {
        $normalized = static::normalizeReportableType($value);

        $this->attributes['reportable_type'] = $normalized ?? trim((string) $value);
        $this->hasResolvedReportableCache = false;
        $this->resolvedReportableCache = null;
    }

    public static function reasonOptions(): array
    {
        return [
            self::REASON_SPAM => 'Spam',
            self::REASON_OFFENSIVE => 'Offensive',
            self::REASON_JOKE => 'Joke',
            self::REASON_DUPLICATE => 'Duplicate',
            self::REASON_OTHER => 'Other',
        ];
    }
}
