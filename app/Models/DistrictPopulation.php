<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DistrictPopulation extends Model
{
    use HasFactory;

    protected $fillable = [
        'jurisdiction_type',
        'state_code',
        'district',
        'registered_voter_count',
        'provider',
        'source_reference',
        'source_payload',
        'last_synced_at',
    ];

    protected $casts = [
        'registered_voter_count' => 'integer',
        'source_payload' => 'array',
        'last_synced_at' => 'datetime',
    ];
}
