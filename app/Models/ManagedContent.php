<?php

namespace App\Models;

use App\Support\SummaryText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ManagedContent extends Model
{
    use HasFactory;

    public const TYPE_FAQ = 'faq';
    public const TYPE_GUIDELINE = 'guideline';
    public const TYPE_ANNOUNCEMENT = 'announcement';

    public const AUDIENCE_GLOBAL = 'global';
    public const AUDIENCE_REAL_BILLS = 'real_bills';
    public const AUDIENCE_AMENDMENTS = 'amendments';
    public const AUDIENCE_CITIZEN_PROPOSALS = 'citizen_proposals';
    public const AUDIENCE_PRIVACY = 'privacy';

    protected $fillable = [
        'type',
        'audience',
        'slug',
        'title',
        'summary',
        'body',
        'display_order',
        'is_published',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    protected function summary(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => SummaryText::toPlainText($value),
        );
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('is_published', true)
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    public static function typeOptions(): array
    {
        return [
            self::TYPE_FAQ => 'FAQ',
            self::TYPE_GUIDELINE => 'Guideline',
            self::TYPE_ANNOUNCEMENT => 'Announcement',
        ];
    }

    public static function audienceOptions(): array
    {
        return [
            self::AUDIENCE_GLOBAL => 'Global',
            self::AUDIENCE_REAL_BILLS => 'Real Bills',
            self::AUDIENCE_AMENDMENTS => 'Amendments',
            self::AUDIENCE_CITIZEN_PROPOSALS => 'Citizen Proposals',
            self::AUDIENCE_PRIVACY => 'Privacy',
        ];
    }
}
