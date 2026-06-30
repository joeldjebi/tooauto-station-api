<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Alert extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicule_id',
        'type_alert_id',
        'date_debut',
        'date_fin',
        'kilometrage',
        'autres',
        'station_service_id',
        'station_id'
    ];

    public function vehicule()
    {
        return $this->belongsTo(Vehicule::class);
    }

    public function typeAlert()
    {
        return $this->belongsTo(TypeAlert::class, 'type_alert_id');
    }

    public function stationService()
    {
        return $this->belongsTo(StationService::class);
    }

    public function station()
    {
        return $this->belongsTo(Station::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}