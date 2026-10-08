{{-- Rentals step - the agency's decline reason templates (rental-applications.md "Front-half decisions"). An information row
     with a link, like the lease-agreement row: it posts no field and has no saver, so a partial-step post cannot wipe it.
     The wording of the decline email itself is the two controls below it. Vars: $wzDeclineTemplateCount. --}}
<div class="p-4 space-y-2" style="background:var(--surface-2,#f8fafc); border:1px solid var(--border,#e5e7eb); border-radius:6px;">
    <div class="flex items-center justify-between gap-3">
        <h4 class="text-sm font-semibold" style="color:var(--text-primary);">Decline reasons</h4>
        @if (!empty($wzDeclineTemplateCount))
            <span class="ds-badge ds-badge-success">{{ $wzDeclineTemplateCount }} set up</span>
        @else
            <span class="ds-badge ds-badge-warning">None yet</span>
        @endif
    </div>
    <p class="text-xs" style="color:var(--text-muted);">
        What it is: the reasons (with a short note on what would help) an authoriser picks from when declining a rental application. The pick is written into the decline email.
    </p>
    <p class="text-xs" style="color:var(--text-muted);">
        What this changes: the wording an applicant reads when declined. With none set up, the decline email carries no reason paragraph.
    </p>
    @permission('rental_applications.manage_settings')
        <a href="{{ route('corex.settings.rental-applications.decline-reason-templates.index') }}" class="text-xs font-semibold" style="color:var(--accent,#2563eb);">Settings → Rental applications → Decline reasons</a>
    @else
        <p class="text-xs" style="color:var(--text-muted);">Ask your agency administrator to set these up under Settings → Rental applications.</p>
    @endpermission
</div>
