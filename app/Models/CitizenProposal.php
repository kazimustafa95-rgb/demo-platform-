<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CitizenProposal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'content',
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
}
