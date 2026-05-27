<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\Concerns\BelongsToAgency;
class ProspectingSearch extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'user_id',
        'portal_source',
        'search_url',
        'search_description',
        'total_results',
        'pages_captured',
        'listing_count',
        'captured_at',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
    ];

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
