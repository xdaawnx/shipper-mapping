<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\City;

class Province extends Model
{
    protected $table = 'province';
    protected $primaryKey = 'province_id'; // Set the correct primary key

    public function cities()
    {
        return $this->hasMany(City::class, 'province_id', 'province_id');
    }
}
