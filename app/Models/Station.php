<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Station extends Authenticatable implements JWTSubject
{
    protected $fillable = [
        'first_name',
        'last_name',
        'mobile',
        'email',
        'role',
        'password',
        'statut',
        'created_by',
		'station_service_id',
    ];

    protected $hidden = ['password'];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [];
    }

    public function qrcodeAssignments()
    {
        return $this->hasMany(QrcodeAssignment::class, 'station_id');
    }

    public function assignedQrcodes()
    {
        return $this->hasManyThrough(
            QrcodeGenerate::class,
            QrcodeAssignment::class,
            'station_id', // Foreign key on qrcode_assignments
            'id',         // Foreign key on qrcode_generates
            'id',         // Local key on stations
            'qrcode_id'   // Local key on qrcode_assignments
        );
    }

}