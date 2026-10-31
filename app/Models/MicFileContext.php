<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Market context per file (AT-519 5.9, spec 11.1b). Written only by FileContextBuilder; read through FileContext::forFiles(). */
class MicFileContext extends Model
{
    protected $table = 'mic_file_context';

    public $incrementing = false;

    protected $primaryKey = null;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['nearby_sales' => 'array', 'last_deeds_sale' => 'array', 'matched_buyers' => 'array', 'our_history' => 'array'];
}
