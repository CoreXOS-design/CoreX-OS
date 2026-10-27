<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One person on an agency's board / senior management - the people who sign the RMCP board approval letter.
 * `user_id` is optional: a member who is also a CoreX user can sign inside the app; everyone else signs from the emailed link.
 */
class AgencyBoardMember extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = ['agency_id', 'name', 'title', 'email', 'user_id', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
