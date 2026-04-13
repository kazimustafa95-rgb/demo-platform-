<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProposalSupport extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'citizen_proposal_id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function proposal()
    {
        return $this->belongsTo(CitizenProposal::class, 'citizen_proposal_id');
    }
}
