<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Amendment extends Model
{
    use HasFactory;

    public const SOURCE_USER = 'user';
    public const SOURCE_CONGRESS_GOV = 'congress_gov';
    public const SOURCE_OPENSTATES = 'openstates';

    protected $fillable = [
        'external_id',
        'source',
        'user_id',
        'bill_id',
        'title',
        'congress',
        'amendment_type',
        'amendment_number',
        'chamber',
        'sponsors',
        'latest_action',
        'proposed_at',
        'submitted_at',
        'text_url',
        'congress_gov_url',
        'metadata',
        'amendment_text',
        'category',
        'support_count',
        'threshold_reached',
        'threshold_reached_at',
        'hidden',
    ];

    protected $casts = [
        'sponsors' => 'array',
        'latest_action' => 'array',
        'metadata' => 'array',
        'proposed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'threshold_reached_at' => 'datetime',
        'threshold_reached' => 'boolean',
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
            get: function (): ?string {
                $submittedAt = $this->submitted_at ?? $this->created_at;

                return $submittedAt?->toDateString();
            },
        );
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function votes()
    {
        return $this->hasMany(Vote::class);
    }

    public function supports()
    {
        return $this->hasMany(AmendmentSupport::class);
    }

    public function reports()
    {
        return $this->morphMany(Report::class, 'reportable');
    }

    public function scopeUserGenerated($query)
    {
        return $query->where('source', self::SOURCE_USER);
    }

    public function scopeImported($query)
    {
        return $query->whereIn('source', [
            self::SOURCE_CONGRESS_GOV,
            self::SOURCE_OPENSTATES,
        ]);
    }
}
