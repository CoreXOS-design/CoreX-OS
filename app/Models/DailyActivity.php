<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailyActivity extends Model
{
    protected $guarded = [];

protected $casts = [
        'activity_date' => 'date',
    ];
}
