<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class P24ImportLog extends Model
{
    protected $table = 'p24_import_log';

    protected $fillable = [
        'email_uid',
        'email_subject',
        'email_date',
        'listings_found',
        'listings_new',
        'listings_updated',
        'status',
        'error_message',
    ];

    protected $casts = [
        'email_date' => 'datetime',
        'listings_found' => 'integer',
        'listings_new' => 'integer',
        'listings_updated' => 'integer',
    ];
}
