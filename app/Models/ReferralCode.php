<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReferralCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'code'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function stationServices()
    {
        return $this->hasMany(StationService::class);
    }

    public static function generateUniqueCode()
    {
        do {
            $code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
