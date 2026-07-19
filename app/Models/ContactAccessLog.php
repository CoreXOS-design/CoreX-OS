<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\StampsOnBehalfOf;
class ContactAccessLog extends Model
{
    use BelongsToAgency, StampsOnBehalfOf;

    public $timestamps = false;

    protected $table = 'contact_access_log';

    protected $fillable = [
        'agency_id', 'contact_id', 'user_id', 'impersonator_id', 'action_type',
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

    /**
     * The real admin behind an impersonated action (AT-118). The column was fillable but had
     * no relation, so nothing could render it — closed here alongside the AT-267 onBehalfOf().
     * Distinct concept from onBehalfOf(): impersonation is admin-as-user; on-behalf is
     * assistant-for-agent. Both can be set, and both are now renderable.
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }
}
