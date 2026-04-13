<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Vote extends Model
{
    use HasFactory;

    protected $fillable = [
        'bill_id',
        'amendment_id',
        'representative_id',
        'vote',
        'roll_call_id',
        'vote_date',
    ];

    protected $casts = [
        'vote_date' => 'datetime',
    ];

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function amendment()
    {
        return $this->belongsTo(Amendment::class);
    }

    public function representative()
    {
        return $this->belongsTo(Representative::class);
    }
}
