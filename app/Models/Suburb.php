<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\City;

class Suburb extends Model
{
    protected $table = 'suburb';
    protected $primaryKey = 'suburb_id'; // Set the correct primary key

    public function city()
    {
        return $this->belongsTo(City::class, 'city_id', 'city_id');
    }
}
