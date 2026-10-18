{{--
    .ai/specs/rental-inspections.md §47 — the lock on a signed or sent report, for the inspection page AND the phone
    recording screen (behaviour: resources/js/rental-inspection-signing.js, window.inspectionReportLock).

      • signed, not yet sent  -> "This report is signed, so it is locked."  [Edit report] — warns that ALL signatures are
                                 cleared and everyone must sign again; a reason is required.
      • sent to the parties   -> "can never be changed, by anyone" + [Start new inspection] (same property, lease and type).
      • replaces / replaced by -> a plain link each way.
      • after an Edit report   -> a reminder to resend the (fresh) links until every voided signer has been sent theirs.

    In: $inspectionIdJs (a JS expression), $reloadOnChange (bool).
--}}
@php
    $reloadOnChange = $reloadOnChange ?? false;
    $lockUser = auth()->user();
    $canEditReport = (bool) $lockUser?->hasPermission('rental_inspections.edit_details');
    $canStartNew = (bool) $lockUser?->hasPermission('rental_inspections.create');
@endphp
<div x-data="inspectionReportLock({ inspectionId: {{ $inspectionIdJs }}, base: @js(url('/corex/rental-inspections')), reloadOnChange: @js($reloadOnChange) })"
     x-init="init()" @signing-links-changed.window="load()" x-show="visible" x-cloak
     class="rounded-md p-3 space-y-2 text-sm" style="background:color-mix(in srgb, #d97706 10%, transparent); border:1px solid color-mix(in srgb, #d97706 40%, transparent); color:var(--text-primary);"
     data-qa="report-lock">

    {{-- Signed, not yet sent: locked, "Edit report" is the one way to change it. --}}
    <template x-if="lock && lock.signed_locked && !lock.distributed">
        <div class="space-y-2" data-qa="report-signed-locked">
            <p><strong>This report is signed, so it is locked.</strong> To change anything the agent must press <em>Edit report</em>.</p>
            @if($canEditReport)
                <div x-show="!confirming">
                    <button type="button" @click="confirming = true; error = ''" x-show="lock.can_reopen" data-qa="edit-report"
                            class="text-xs font-semibold px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">Edit report</button>
                </div>
                <div x-show="confirming" x-cloak class="space-y-2 rounded-md p-3" style="background:var(--surface); border:1px solid #dc2626;" data-qa="edit-report-confirm">
                    <p class="font-semibold" style="color:#dc2626;">Editing clears ALL signatures. Everyone must sign again.</p>
                    <p class="text-xs" style="color:var(--text-muted);">Every tenant's, the landlord's and the agent's signature is voided (kept as history, never counted again), their signing links stop working, and you will mark the report ready to sign again when you are done.</p>
                    <label class="block text-xs font-semibold" for="reopen-reason" style="color:var(--text-secondary);">Why is the report being changed? (required)</label>
                    <textarea id="reopen-reason" x-model="reason" rows="2" maxlength="1000" class="w-full rounded-md px-2 py-1 text-sm" style="border:1px solid var(--border);"></textarea>
                    <div class="flex items-center gap-2">
                        <button type="button" @click="reopen()" :disabled="busy" class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:#dc2626;" data-qa="edit-report-go"
                                x-text="busy ? 'Working…' : 'Clear all signatures and edit'"></button>
                        <button type="button" @click="confirming = false" class="text-xs underline" style="color:var(--text-secondary);">Cancel</button>
                    </div>
                </div>
            @else
                <p class="text-xs" style="color:var(--text-muted);">Changing a signed report needs someone with permission to edit inspection details.</p>
            @endif
        </div>
    </template>

    {{-- Sent to the parties: nobody, in any role, edits or reopens it. The only way forward is a new inspection. --}}
    <template x-if="lock && lock.distributed">
        <div class="space-y-2" data-qa="report-distributed">
            <p><strong>This report has been sent to the parties, so it can never be changed — by anyone.</strong> If something in it is wrong, the only way forward is a new inspection that replaces it. This one stays exactly as it was sent.</p>
            <template x-if="lock.replaced_by">
                <p>Replaced by <a :href="lock.replaced_by.url" class="underline font-semibold" x-text="lock.replaced_by.label"></a>.</p>
            </template>
            @if($canStartNew)
                <button type="button" x-show="lock.can_replace" @click="startNew()" :disabled="busy" data-qa="start-new-inspection"
                        class="text-xs font-semibold px-3 py-1.5 rounded-md text-white" style="background:var(--brand-button,#0ea5e9);"
                        x-text="busy ? 'Starting…' : 'Start new inspection'"></button>
            @endif
        </div>
    </template>

    <template x-if="lock && lock.replaces">
        <p data-qa="report-replaces">This inspection replaces <a :href="lock.replaces.url" class="underline font-semibold" x-text="lock.replaces.label"></a>, which had been sent to the parties and can no longer be changed.</p>
    </template>

    <template x-if="reopened && reopened.resend_needed">
        <p data-qa="report-resend-needed"><strong>This report was edited after people signed.</strong> All signatures were cleared and the old links stopped working. When the report is ready to sign again, resend each person their link — nobody has been told yet.</p>
    </template>

    <p class="text-xs" style="color:#dc2626;" x-show="error" x-cloak x-text="error" data-qa="report-lock-error"></p>
    <p class="text-xs" style="color:#059669;" x-show="notice" x-cloak x-text="notice"></p>
</div>
