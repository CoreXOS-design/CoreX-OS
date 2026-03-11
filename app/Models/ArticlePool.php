<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ArticlePool extends Model
{
    use SoftDeletes;

    protected $table = 'article_pool';

    protected $fillable = [
        'source',
        'title',
        'url',
        'url_hash',
        'snippet',
        'published_at',
        'tags_json',
        'scraped_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'scraped_at'   => 'datetime',
        'tags_json'    => 'array',
    ];
}
