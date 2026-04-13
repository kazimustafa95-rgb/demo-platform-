<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Jurisdiction extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type', 'code'];

    public function bills()
    {
        return $this->hasMany(Bill::class);
    }

    public function representatives()
    {
        return $this->hasMany(Representative::class);
    }
}
