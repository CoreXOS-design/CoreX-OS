<?php

use App\Models\DeskSession;
use App\Services\Desk\DeskSessionService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// CoreX Desk (spec .ai/specs/corex-desk.md section 7.2): only the session's agent, or the support user who joined it (while it is
// live), may subscribe - and never an ended session. Everything on this channel (screen frames, pointer, highlights) is private to them.
Broadcast::channel('desk.session.{id}', function ($user, $id) {
    $session = DeskSession::withoutGlobalScopes()->find((int) $id);

    return $session !== null && app(DeskSessionService::class)->canJoinChannel($user, $session);
});
