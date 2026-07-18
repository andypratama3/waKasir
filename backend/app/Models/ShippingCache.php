<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingCache extends Model
{
    protected $fillable = [
        'province_id',
        'province_name',
        'city_id',
        'city_name',
        'city_type',
        'subdistrict_id',
        'subdistrict_name',
        'postal_code',
    ];
}
