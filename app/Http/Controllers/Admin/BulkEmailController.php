<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\BulkAnnouncementMail;
use App\Models\Agency;
use App\Models\BulkEmailBroadcast;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Bulk Email — the System Owner broadcasts a branded email to every CoreX
 * user or to one specific agency's users. Lives as a tab on the System
 * Updates admin page.
 *
 * Spec: .ai/specs/system-updates-bulk-email.md
 *
 * ── On permissions (spec §7) ──────────────────────────────────────────────
 * owner_only route middleware + guardOwner() belt-and-braces, no grantable
 * permission key — identical reasoning to SystemUpdateController: this
 * broadcasts arbitrary text, by email, to every user of every agency. A
 * permission key is a delegation path via the Role Manager; owner_only has
 * none. DO NOT "fix" this by adding a permission key.
 */
class BulkEmailController extends Controller
{
    private function guardOwner(Request $request): void
    {
        abort_unless($request->user()?->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    public function create(Request $request): View
    {
        $this->guardOwner($request);

        $agencies = Agency::withCount(['users' => function ($q) {
                $q->withoutGlobalScope(AgencyScope::class)
                    ->whereNotNull('email')
                    ->where('is_active', true);
            }])
            ->orderBy('name')
            ->get();

        $totalActiveUsers = $this->baseRecipientQuery()->count();

        // id as a tiebreaker: created_at has second precision, so two broadcasts
        // sent within the same second would otherwise tie and sort unpredictably.
        $broadcasts = BulkEmailBroadcast::with(['sender', 'targetAgency'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('admin.system-updates.bulk-email', compact('agencies', 'totalActiveUsers', 'broadcasts'));
    }

    public function send(Request $request): RedirectResponse
    {
        $this->guardOwner($request);

        $request->merge([
            'subject' => is_string($request->input('subject')) ? trim($request->input('subject')) : $request->input('subject'),
            'body'    => is_string($request->input('body')) ? trim($request->input('body')) : $request->input('body'),
        ]);

        $validated = $request->validate([
            'subject'          => ['required', 'string', 'max:200'],
            'body'             => ['required', 'string', 'max:5000'],
            'target_type'      => ['required', Rule::in([BulkEmailBroadcast::TARGET_ALL, BulkEmailBroadcast::TARGET_AGENCY])],
            'target_agency_id' => ['required_if:target_type,' . BulkEmailBroadcast::TARGET_AGENCY, 'nullable', 'integer', 'exists:agencies,id'],
        ], [
            'subject.required'          => 'Give the email a subject.',
            'body.required'             => 'Write a message — this is what recipients will read.',
            'subject.max'               => 'Keep the subject under 200 characters.',
            'body.max'                  => 'Keep the message under 5 000 characters.',
            'target_agency_id.required_if' => 'Choose which agency to send to.',
        ]);

        // Never trust the client-submitted count — recompute fresh (spec §9.1).
        $recipients = $this->baseRecipientQuery();

        if ($validated['target_type'] === BulkEmailBroadcast::TARGET_AGENCY) {
            $recipients->where('agency_id', $validated['target_agency_id']);
        }

        $recipients = $recipients->get(['id', 'name', 'email']);

        if ($recipients->isEmpty()) {
            return back()
                ->withInput()
                ->with('error', 'No active users match that target — nothing was sent.');
        }

        foreach ($recipients as $recipient) {
            Mail::to($recipient->email)->queue(
                new BulkAnnouncementMail($validated['subject'], $validated['body'], $recipient->name)
            );
        }

        BulkEmailBroadcast::create([
            'subject'          => $validated['subject'],
            'body'             => $validated['body'],
            'target_type'      => $validated['target_type'],
            'target_agency_id' => $validated['target_type'] === BulkEmailBroadcast::TARGET_AGENCY
                ? $validated['target_agency_id']
                : null,
            'recipient_count'  => $recipients->count(),
            'sent_by_user_id'  => $request->user()->id,
        ]);

        return redirect()->route('admin.system-updates.bulk-email.create')
            ->with('success', "Queued to {$recipients->count()} user(s).");
    }

    /**
     * Every active user with an email, cross-agency. Explicit
     * withoutGlobalScope regardless of the owner's switcher state — the
     * owner-role scope bypass stops the moment they've switched into an
     * agency, and that must never silently narrow "All CoreX Users" to one
     * agency (spec §9.1).
     */
    private function baseRecipientQuery()
    {
        return User::withoutGlobalScope(AgencyScope::class)
            ->whereNotNull('email')
            ->where('is_active', true);
    }
}
