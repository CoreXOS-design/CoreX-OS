{{-- Rental application fields — shipped-field tick grid.
     .ai/specs/rental-application-field-config.md — every row here is one of the
     32 SHIPPED fields from RentalApplication::submissionFieldRegistry(), grouped
     by RentalApplication::SUBMISSION_FIELD_SECTIONS. Johan's own model, verbatim:
     "everything is tick / untick for optional / compulsory." Two checkboxes per
     row — Shown, Compulsory — posting through the SAME two canonical endpoints
     the full settings screen uses (updateFieldDisplayConfig, updateRequiredFields).

     Label/help-text/order overrides and the full custom-field editor stay OUT of
     this step deliberately (conductor's ruling, 2026-09-20) — they are fine-tuning
     an agency does once live, and already have a home at
     /corex/settings/rental-applications (linked below). This form must never
     wipe that customisation just because it doesn't render it, so every override
     this agency already has is reposted verbatim as a hidden field below —
     preserve-and-repost, not silent loss (agency-onboarding-setup.md §6.1).

     $rentalFieldSections = section label => Collection of resolved field rows
     (key, label, shown, required, order), from resolvedFieldConfigFor(). --}}

<div class="space-y-5">
    <div>
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary);">Rental application fields</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted);">
            Tick <strong>Shown</strong> to include a field on your rental application form. Tick <strong>Compulsory</strong>
            to require an applicant to fill it in before they can submit. A field left unshown is never asked for at all.
        </p>
    </div>

    @foreach ($rentalFieldSections as $section => $fields)
        <div>
            <h4 class="text-xs font-semibold uppercase tracking-wider mb-2" style="color:var(--text-muted,#94a3b8);">{{ $section }}</h4>
            <div class="overflow-x-auto rounded-md" style="border:1px solid var(--border,#e5e7eb);">
                <table class="w-full text-sm">
                    <thead>
                        <tr style="border-bottom:1px solid var(--border,#e5e7eb); background:var(--surface-2,#f8fafc);">
                            <th class="text-left text-xs font-semibold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted);">Field</th>
                            <th class="text-center text-xs font-semibold uppercase tracking-wider px-3 py-2 w-24" style="color:var(--text-muted);">Shown</th>
                            <th class="text-center text-xs font-semibold uppercase tracking-wider px-3 py-2 w-24" style="color:var(--text-muted);">Compulsory</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($fields as $field)
                            <tr style="border-bottom:1px solid var(--border,#e5e7eb);">
                                <td class="px-3 py-2" style="color:var(--text-primary);">{{ $field['label'] }}</td>
                                <td class="px-3 py-2 text-center">
                                    <input type="checkbox" name="shown_field_keys[]" value="{{ $field['key'] }}" @checked($field['shown'])>
                                </td>
                                <td class="px-3 py-2 text-center">
                                    <input type="checkbox" name="required_field_keys[]" value="{{ $field['key'] }}" @checked($field['required'])>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    {{-- Preserve-and-repost: this step never renders label, help-text, or order
         overrides, so every existing one must travel through unchanged or
         updateFieldDisplayConfig() will overwrite it with an empty array. --}}
    @foreach ($rentalFieldLabelOverrides as $overrideKey => $overrideLabel)
        <input type="hidden" name="field_labels[{{ $overrideKey }}]" value="{{ $overrideLabel }}">
    @endforeach
    @foreach ($rentalFieldHelpTextOverrides as $overrideKey => $overrideText)
        <input type="hidden" name="field_help_text[{{ $overrideKey }}]" value="{{ $overrideText }}">
    @endforeach
    @foreach ($rentalFieldOrder as $orderPosition => $orderKey)
        <input type="hidden" name="field_order[{{ $orderKey }}]" value="{{ $orderPosition }}">
    @endforeach

    <input type="hidden" name="field_display_submitted" value="1">
    <input type="hidden" name="required_fields_submitted" value="1">

    {{-- updateReturnGate() validates return_gate_method together with these two
         rate-limit fields as one required group — this step only ever shows the
         method choice (the two limits are an expert carve-out, conductor's
         ruling), so they repost unchanged from this agency's current values. --}}
    <input type="hidden" name="return_gate_attempt_max" value="{{ $rentalReturnGateAttemptMax }}">
    <input type="hidden" name="return_gate_attempt_window_minutes" value="{{ $rentalReturnGateAttemptWindowMinutes }}">
</div>
