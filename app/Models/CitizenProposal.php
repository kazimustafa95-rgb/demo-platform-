<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CitizenProposal extends Model
{
    use HasFactory;

    public const JURISDICTION_FOCUS_FEDERAL = 'federal';
    public const JURISDICTION_FOCUS_STATE = 'state';

    protected $fillable = [
        'user_id',
        'title',
        'content',
        'problem_statement',
        'proposed_solution',
        'category',
        'jurisdiction_focus',
        'support_count',
        'threshold_reached',
        'threshold_reached_at',
        'is_duplicate',
        'hidden',
    ];

    protected $casts = [
        'threshold_reached_at' => 'datetime',
        'threshold_reached' => 'boolean',
        'is_duplicate' => 'boolean',
        'hidden' => 'boolean',
    ];

    protected $appends = [
        'username',
        'submitted_date',
    ];

    protected function username(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->user?->name,
        );
    }

    protected function submittedDate(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->created_at?->toDateString(),
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function supports()
    {
        return $this->hasMany(ProposalSupport::class);
    }

    public function reports()
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    public static function focusOptions(): array
    {
        return [
            self::JURISDICTION_FOCUS_FEDERAL => 'Federal',
            self::JURISDICTION_FOCUS_STATE => 'State',
        ];
    }
}
