<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Vehicule extends Model
{
    use HasFactory;

    protected $fillable = [
        'matricule',
        'marque_id',
        'modele_id',
        'type_vehicule_id',
        'usager_id',
        'station_id',
        'station_service_id',
        'annee',
        'couleur',
        'kilometrage',
        'statut'
    ];

    public function marque()
    {
        return $this->belongsTo(Marque::class);
    }

    public function modele()
    {
        return $this->belongsTo(Modele::class);
    }

    public function typeVehicule()
    {
        return $this->belongsTo(TypeVehicule::class, 'type_vehicule_id');
    }

    public function usager()
    {
        return $this->belongsTo(Usager::class);
    }

    public function station()
    {
        return $this->belongsTo(Station::class);
    }

    public function stationService()
    {
        return $this->belongsTo(StationService::class);
    }

    public function chauffeur()
    {
        return $this->belongsTo(Chauffeur::class, 'user_id');
    }
}