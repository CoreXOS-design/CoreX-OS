<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PdfSplitterLearnedPhrase extends Model
{
    use SoftDeletes;

    protected $table = 'pdf_splitter_learned_phrases';

    protected $fillable = [
        'phrase',
        'bucket',
        'hits',
        'weight',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
