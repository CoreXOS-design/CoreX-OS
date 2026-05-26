<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class ContactAccessLog extends Model
{
    use BelongsToAgency;

    public $timestamps = false;

    protected $table = 'contact_access_log';

    protected $fillable = [
        'agency_id', 'contact_id', 'user_id', 'action_type',
        'accessed_at', 'ip_address', 'user_agent', 'request_id',
    ];

    protected $casts = [
        'accessed_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
