<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PdfSplitterFeedback extends Model
{
    use SoftDeletes;

    protected $table = 'pdf_splitter_feedback';

    protected $fillable = [
        'base_name',
        'page_number',
        'auto_label',
        'final_label',
        'snippet',
        'scores',
    ];

    protected $casts = [
        'scores' => 'array',
    ];
}
