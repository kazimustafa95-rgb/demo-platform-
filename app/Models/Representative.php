<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Representative extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_id',
        'first_name',
        'last_name',
        'party',
        'chamber',
        'district',
        'jurisdiction_id',
        'photo_url',
        'contact_info',
        'committee_assignments',
        'years_in_office_start',
        'years_in_office_end',
    ];

    protected $casts = [
        'contact_info' => 'array',
        'committee_assignments' => 'array',
    ];

    public function jurisdiction()
    {
        return $this->belongsTo(Jurisdiction::class);
    }

    public function votes()
    {
        return $this->hasMany(Vote::class);
    }
}
