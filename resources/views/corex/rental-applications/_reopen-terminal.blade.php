{{--
    AT-402 REGRESSION FIX (2026-09-11) — declined applications became a dead
    end. REVIEWABLE_STATUSES deliberately excludes 'declined' (Johan: "an
    approved or declined application opening into a working review screen
    is its own bug") — correct — but the ONLY door to RentalApplication
    ReviewController::reopen() was the Review screen's own "Send back to
    applicant" button, so removing Review from declined rows also removed
    the only reachable path to reopening one. Johan's own earlier ruling
    ("co should be able to reopen... declined and more evidence given") is
    unreachable without hand-typing the /review URL.

    This partial does NOT put Review back on declined/withdrawn rows — it
    gives them their own explicit door, reusing the EXISTING, already-audited
    reopen() endpoint (validated note, override-tier guard, status history,
    audit log, applicant email) exactly as review.blade.php's own
    sendBackToApplicant() calls it — same URL, same request shape, same
    JSON contract. No new backend logic. Once reopened, the application's
    status is 'reopened' — one of REVIEWABLE_STATUSES — so Review opens
    normally again through the existing path.

    2026-09-12 — RENAMED from _reopen-declined.blade.php and broadened to
    withdrawn. A second end-to-end walkthrough found the generic agent
    status dropdown let a withdrawn application be silently flipped back to
    under_assessment with no note and no override check — the exact "one
    door too many" bug class this partial exists to close on the OTHER
    side (giving a status its ONE correct door, not leaving a stray extra
    one open). withdrawn now reopens through this same partial, same
    override-tier gate, same required note — see RentalApplication::
    REOPENABLE_STATUSES's own docblock and RentalApplicationController::
    updateStatus()'s new guard refusing the generic endpoint for this
    transition. The old filename would now be a lie about what this file
    covers, hence the rename.

    Include with: @include('corex.rental-applications._reopen-terminal', ['application' => $application])
--}}
@php
    $reopenGate = in_array($application->status, ['declined', 'withdrawn'], true)
        && auth()->user()->isRentalApplicationOverrideTier((int) $application->agency_id);
@endphp
@if($reopenGate)
<div x-data="{
        open: false,
        note: '',
        sending: false,
        error: '',
        async submit() {
            if (!this.note.trim() || this.sending) return;
            this.sending = true;
            this.error = '';
            try {
                const res = await fetch('{{ route('corex.rental-applications.review.reopen', $application) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ note: this.note }),
                });
                const data = await res.json().catch(() => ({}));
                if (res.ok && data.ok) {
                    window.location.reload();
                    return;
                }
                this.error = data.error || 'Could not reopen — try again.';
            } catch (e) {
                this.error = 'Network error — try again.';
            }
            this.sending = false;
        },
     }"
     class="inline-block align-top">
    <button type="button" @click="open = !open" class="corex-btn-outline text-xs" style="color: var(--ds-amber, #f59e0b);"
            title="Only the head of rentals or an admin may reopen a {{ $application->status }} application">Reopen</button>
    <div x-show="open" x-cloak @click.outside="open = false"
         class="mt-2 p-3 rounded-md text-left" style="background: var(--surface-2); border: 1px solid var(--border); width: 280px;">
        <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">What changed, or what should the applicant provide?</label>
        <textarea x-model="note" rows="3" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border); background: var(--surface);"
                  placeholder="e.g. New payslip provided, income now qualifies"></textarea>
        <p class="text-xs mt-1" x-show="error" x-text="error" style="color: var(--ds-red, #dc2626);"></p>
        <div class="flex gap-2 mt-2">
            <button type="button" @click="submit()" :disabled="sending || !note.trim()" class="corex-btn-primary text-xs" x-text="sending ? 'Reopening…' : 'Confirm reopen'"></button>
            <button type="button" @click="open = false" class="corex-btn-outline text-xs">Cancel</button>
        </div>
    </div>
</div>
@endif
