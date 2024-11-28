<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Province;
use App\Models\Suburb;

class City extends Model
{
    protected $table = 'city';
    protected $primaryKey = 'city_id'; // Set the correct primary key

    public function province()
    {
        return $this->belongsTo(Province::class, 'province_id', 'province_id');
    }

    public function suburbs()
    {
        return $this->hasMany(Suburb::class, 'city_id', 'city_id');
    }
}
