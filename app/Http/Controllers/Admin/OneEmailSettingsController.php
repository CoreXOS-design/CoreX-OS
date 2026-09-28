<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Users\OneEmailService;
use Illuminate\Http\Request;

/**
 * AT-423 — the "Team Inbox" section of Settings (Agency group).
 * Spec: .ai/specs/one-email-sub-users.md §6.1 / §6.8a.
 *
 * Deliberately its own section and saver, not a Settings → Features row (Andre, 2026-09-21:
 * it is a big change for an agency). Turning it on requires choosing the shared inbox —
 * an existing user of this agency who signs in with a real email. Turning it off only
 * stops new sub-users being added: the shared inbox stays recorded so existing sub-users
 * keep signing in and keep receiving mail.
 *
 * Acts on the agency the admin is working in (like the other Settings savers).
 * Boolean writes are $request->has()-guarded (agency-onboarding-setup.md §6.1).
 */
class OneEmailSettingsController extends Controller
{
    public function update(Request $request, OneEmailService $oneEmail)
    {
        abort_unless(auth()->user()?->hasPermission('manage_performance_settings'), 403);

        $request->validate([
            'one_email_enabled' => ['nullable', 'boolean'],
            'one_email_user_id' => ['nullable', 'integer'],
        ]);

        // The outcome travels in the URL (?saved=on|off|inbox|saved), NOT only a session flash:
        // the Settings page's background pollers (portal-leads, reminders) can land between
        // this POST and the redirected GET and use up a one-time flash, so the admin would
        // see no confirmation at all (reproduced locally, 2026-09-21).
        $back = fn (?string $saved = null) => redirect()->route('corex.settings', array_filter(['s' => 'team-inbox', 'saved' => $saved]));

        // No agency context (e.g. an owner who has not switched into an agency) → reject,
        // never guess a tenant (STANDARDS Rule 17).
        $agency = $oneEmail->agencyFor(auth()->user());
        if (!$agency) {
            return $back()->with('error', 'No agency selected — switch into an agency first.');
        }

        $enabled = $request->has('one_email_enabled')
            ? $request->boolean('one_email_enabled')
            : (bool) $agency->one_email_enabled;

        // The shared inbox: a newly chosen one, else the one already recorded.
        $mainId = $request->filled('one_email_user_id')
            ? (int) $request->input('one_email_user_id')
            : (int) $agency->one_email_user_id;

        if ($request->filled('one_email_user_id')
            && !$oneEmail->candidates($agency)->contains('id', $mainId)) {
            return $back()->withErrors([
                'one_email_user_id' => 'Choose a person from this agency who signs in with a real email address — that inbox is the one every sub-user\'s email will go to.',
            ]);
        }

        if ($enabled && !$mainId) {
            return $back()->withErrors([
                'one_email_user_id' => 'Choose whose inbox is shared before turning Team Inbox on.',
            ]);
        }

        $wasEnabled = (bool) $agency->one_email_enabled;
        $oldMainId  = (int) $agency->one_email_user_id;

        $agency->forceFill([
            'one_email_enabled' => $enabled,
            'one_email_user_id' => $mainId ?: null,
        ])->save();

        // The Team Inbox section words the message from this code + the agency's current state.
        $saved = match (true) {
            $enabled && !$wasEnabled => 'on',
            !$enabled && $wasEnabled => 'off',
            $mainId !== $oldMainId   => 'inbox',
            default                  => 'saved',
        };

        return $back($saved);
    }
}
