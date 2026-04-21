<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    use HasFactory;

    public const REASON_SPAM = 'spam';
    public const REASON_OFFENSIVE = 'offensive';
    public const REASON_JOKE = 'joke';
    public const REASON_DUPLICATE = 'duplicate';
    public const REASON_OTHER = 'other';

    public const STATUS_PENDING = 'pending';

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
