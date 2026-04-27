<?php

namespace App\Models;

use App\Support\SummaryText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_VOTING_CLOSED = 'voting_closed';
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'external_id',
        'jurisdiction_id',
        'number',
        'title',
        'summary',
        'ai_summary_plain',
        'ai_bill_impact',
        'status',
        'introduced_date',
        'official_vote_date',
        'voting_deadline',
        'bill_text_url',
        'sponsors',
        'committees',
        'amendments_history',
        'related_documents',
    ];

    protected $casts = [
        'introduced_date' => 'datetime',
        'official_vote_date' => 'datetime',
        'voting_deadline' => 'datetime',
        'sponsors' => 'array',
        'committees' => 'array',
        'amendments_history' => 'array',
        'related_documents' => 'array',
    ];

    protected $hidden = [
        'ai_summary_plain',
        'ai_bill_impact',
    ];

    protected function summary(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => SummaryText::toPlainText($value),
        );
    }

    protected function aiSummaryPlain(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => SummaryText::toPlainTextLimited($value, 500),
            set: fn (?string $value): ?string => SummaryText::toPlainTextLimited($value, 500),
        );
    }

    protected function aiBillImpact(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => SummaryText::toPlainTextLimited($value, 500),
            set: fn (?string $value): ?string => SummaryText::toPlainTextLimited($value, 500),
        );
    }

    public function jurisdiction()
    {
        return $this->belongsTo(Jurisdiction::class);
    }

    public function votes()
    {
        return $this->hasMany(Vote::class)->whereNull('amendment_id');
    }

    public function userVotes()
    {
        return $this->hasMany(UserVote::class);
    }

    public function amendments()
    {
        return $this->hasMany(Amendment::class);
    }

    public static function statusOptions(): array
    {
        return [
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_VOTING_CLOSED => 'Voting Closed',
            self::STATUS_PASSED => 'Passed',
            self::STATUS_FAILED => 'Failed',
        ];
    }

    public function isVotingOpen(): bool
    {
        return (bool) ($this->voting_deadline && now()->lt($this->voting_deadline));
    }
}
