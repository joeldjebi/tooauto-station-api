<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StationService extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'adresse',
        'mobile',
        'contact',
        'longitude',
        'latitude',
        'statut',
        'logo',
        'created_by',
        'referral_code_id',
		'borne_electrique',
        'nuit',
        'station_electrique',
    ];

    public function referralCode()
    {
        return $this->belongsTo(ReferralCode::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(Station::class, 'created_by');
    }
}
