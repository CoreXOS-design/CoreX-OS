<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * P24's own statement that these listing numbers are one property ("Listed by N Estate Agencies"). Global public
 * fact, no agency_id (.ai/specs/mic-work-tab.md §11.2). Written by Prospecting\P24GroupRecorder, read by the matcher.
 */
class P24GroupMember extends Model
{
    protected $table = 'p24_group_members';

    protected $fillable = ['p24_group_id', 'listing_number', 'group_size', 'first_seen_at', 'seen_at'];

    protected $casts = [
        'group_size'    => 'integer',
        'first_seen_at' => 'datetime',
        'seen_at'       => 'datetime',
    ];
}
