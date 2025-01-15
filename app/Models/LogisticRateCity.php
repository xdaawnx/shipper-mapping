<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogisticRateCity extends Model
{
    protected $table = 'logistic_rate_city';

    protected $primaryKey = 'local_id'; // Set the primary key
    protected $keyType = 'int'; // Specify the data type of the primary key
    public $incrementing = true; // True if the primary key is auto-incrementing

    protected $fillable = [
        'logistic_id',
        'rate_id',
        'city_id',
        'rate_enabled',
        'order_enabled',
        'destination_enabled',
        'dropoff_enabled',
        'cod_origin_enabled',
        'cod_destination_enabled',
        'hubless_enabled',
        'implant_enabled',
        'cashless',
        'multikoli_enabled',
        'created_date',
        'updated_date',
        'created_by',
        'updated_by',
    ];

    public $timestamps = false;
}
