{{--
    REGRESSION FIX (2026-09-12) — Johan (approved): "withdrawn" reads
    everywhere as if the applicant acted for themselves. They didn't — there
    is no applicant self-service withdraw anywhere in this module (a real
    feature for later, deliberately not built this weekend). Every withdrawn
    record is really an agent recording that the applicant told them so (a
    call, an email). This used to be one more silent value in the generic
    "under assessment / withdrawn" status dropdown, with an OPTIONAL note —
    the same low-friction shape that let Finding 2 (an agent silently
    flipping a withdrawn application back to under_assessment with one
    click) happen in the other direction. Recording a withdrawal is now its
    own explicit, clearly-labelled action with a REQUIRED note, exactly like
    the Reopen action this same session built for the way back
    (_reopen-terminal.blade.php).

    'withdrawn' is deliberately still in RentalApplication::
    AGENT_SETTABLE_STATUSES (it IS an agent's own judgement call — see that
    constant's docblock) — only its OWN dropdown <option> was removed
    (index/show/view-readonly.blade.php), replaced by this explicit control.
    Same endpoint (RentalApplicationController::updateStatus()), same audit
    trail (RentalApplicationStatusHistory::record() — who, when, note), the
    note is just no longer optional for this one target status
    (required_if:status,withdrawn in the controller's own validation — never
    trust the browser's `required` attribute below as the only guard).

    Include wherever the generic status dropdown is offered for 'returned'
    or 'under_assessment' (the only statuses a withdrawal can be recorded
    from — see RentalApplication::AGENT_SETTABLE_STATUSES's own gate):
    @include('corex.rental-applications._record-withdrawn', ['application' => $application])
--}}
{{--
    RENAME, 2026-09-13 round 1 (Johan) — "Record withdrawn" never said
    "applicant" anywhere an agent would see it without hovering the title
    tooltip below. Renamed the button to "Applicant withdrawn", and the
    same phrase everywhere else this status is shown (the pill, the tile,
    the audit trail), so the whole screen shared one vocabulary.

    CORRECTED, round 2, same day (Johan, testing live) — "the button now
    says applicant withdrawn. almost worst... a user will know its not
    withdrawn - click it to withdraw." Round 1 fixed WHO but broke WHAT: a
    button reading "Applicant withdrawn" describes a state, so an agent
    scanning the row could read it as "this application already IS
    withdrawn" rather than "click here to record that." Johan's own rule,
    worth keeping: a CONTROL must read as an instruction (what happens
    when you press it), never as a description of the record's current
    state — that's what a status pill is for. So the split now is:
      - The CONTROL (this button, and its own confirm button below) is an
        imperative: "Withdraw application" / "Confirm — withdraw
        application".
      - The STATUS (RentalApplication::WITHDRAWN_LABEL — the pill, the
        list tile) stays exactly as round 1 left it, "Applicant
        withdrawn"/"Applicant Withdrawn" — a description is correct there,
        unchanged.
      - The AUDIT TRAIL and the Reopen tooltip record what ALREADY
        happened — also descriptions, also unchanged from round 1.
    Losing "applicant" from the always-visible button text reopens the
    original question (who is this recording?) — closed instead by the
    plain sentence now sitting inside the popover itself, the one place
    left where an agent decides to click Confirm.
--}}
@if(in_array($application->status, ['returned', 'under_assessment'], true))
<div x-data="{ open: false }" class="inline-block align-top">
    <button type="button" @click="open = !open" class="corex-btn-outline text-xs" style="color: var(--ds-red, #dc2626);"
            title="Records that the applicant told you they're withdrawing — this doesn't notify them or do anything else">Withdraw application</button>
    <div x-show="open" x-cloak @click.outside="open = false"
         class="mt-2 p-3 rounded-md text-left" style="background: var(--surface-2); border: 1px solid var(--border); width: 280px;">
        <form method="POST" action="{{ route('corex.rental-applications.update-status', $application) }}">
            @csrf
            <input type="hidden" name="status" value="withdrawn">
            <p class="text-xs mb-2" style="color: var(--text-secondary);">This records that the <strong>applicant</strong> has decided to withdraw — not something the agency is deciding for them.</p>
            <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">What did the applicant tell you? (required)</label>
            <textarea name="note" rows="3" required class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border); background: var(--surface);"
                      placeholder="e.g. Applicant called to say they found another place"></textarea>
            <div class="flex gap-2 mt-2">
                <button type="submit" class="corex-btn-primary text-xs" style="background: var(--ds-red, #dc2626); border-color: var(--ds-red, #dc2626);">Confirm — withdraw application</button>
                <button type="button" @click="open = false" class="corex-btn-outline text-xs">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endif
