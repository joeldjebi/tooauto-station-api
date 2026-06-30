<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QrcodeAssignment extends Model
{
    protected $fillable = [
        'station_id', 'qrcode_id', 'assigned_at', 'station_id', 'station_service_id', 'user_id'
    ];

    protected $dates = ['assigned_at'];

    public function station()
    {
        return $this->belongsTo(Station::class);
    }
	
	
    public function station_serivce()
    {
        return $this->belongsTo(Station_service::class);
    }

    public function qrcode()
    {
        return $this->belongsTo(QrcodeGenerate::class);
    }
	
	
	public function user()
	{
		return $this->belongsTo(User::class);
	}

    public function chauffeur()
    {
        return $this->belongsTo(Chauffeur::class, 'user_id');
    }

}