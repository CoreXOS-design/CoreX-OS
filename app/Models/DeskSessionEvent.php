<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * CoreX Desk - the append-only trail of one session (spec .ai/specs/corex-desk.md section 5). Written only through
 * DeskSessionService; there is no edit or delete screen. The screen content itself is never stored here.
 */
class DeskSessionEvent extends Model
{
    use SoftDeletes;

    public $timestamps = false;

    public const CREATED = 'created';
    public const JOINED = 'joined';
    public const PAGE = 'page';
    public const HIGHLIGHT = 'highlight';
    public const HIGHLIGHT_CLEARED = 'highlight_cleared';
    public const POINTER_ON = 'pointer_on';
    public const POINTER_OFF = 'pointer_off';
    public const ENDED = 'ended';
    // Phase 3 (extension tabs): an agent's other Chrome tab joined / left the session, or moved to another site (host change).
    public const TAB_JOIN = 'tab_join';
    public const TAB_LEAVE = 'tab_leave';
    public const TAB_UPDATE = 'tab_update';
    // Phase 2 (take-over): the consent flow, and every action the agent's browser replayed (never a typed value).
    public const CONTROL_REQUESTED = 'control_requested';
    public const CONTROL_GRANTED = 'control_granted';
    public const CONTROL_DECLINED = 'control_declined';
    public const CONTROL_REVOKED = 'control_revoked';
    public const CONTROL_ENDED = 'control_ended';
    public const CONTROL_ACTION = 'control_action';
    // A line typed in the session message strip, by support or by the agent (who, text, tab).
    public const MESSAGE = 'message';
    // Control followed the agent's replayed click into a new CoreX tab (spec section 4f).
    public const CONTROL_MOVED = 'control_moved';

    protected $fillable = ['desk_session_id', 'agency_id', 'type', 'payload', 'actor_user_id', 'created_at'];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(DeskSession::class, 'desk_session_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id')->withoutGlobalScopes();
    }
}
