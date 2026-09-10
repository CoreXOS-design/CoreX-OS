{{--
    Rental Applications tab — AT-392. Johan: "the rental application is not
    a pillar of corex but the contact is, so we need to save the
    information on the contact so its available at any point if anyone
    needs to look at it." Current status (a derived cache kept in sync by
    App\Listeners\Contact\RecomputeRentalApplicationStatus) plus the full,
    permanent history — every application this contact has ever had.

    Spec: .ai/specs/rental-applications.md — Contact status section.
--}}
@php
    $rentalStatusLabels = [
        'none' => ['label' => 'No applications', 'bg' => 'var(--surface-2)', 'fg' => 'var(--text-muted)'],
        'invited' => ['label' => 'Invited', 'bg' => 'color-mix(in srgb, var(--ds-blue, #2563eb) 14%, transparent)', 'fg' => 'var(--ds-blue, #2563eb)'],
        'in_progress' => ['label' => 'In progress', 'bg' => 'color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, transparent)', 'fg' => 'var(--ds-amber, #f59e0b)'],
        'approved' => ['label' => 'Approved', 'bg' => 'color-mix(in srgb, var(--ds-green, #16a34a) 14%, transparent)', 'fg' => 'var(--ds-green, #16a34a)'],
        'declined' => ['label' => 'Declined', 'bg' => 'color-mix(in srgb, var(--ds-red, #dc2626) 14%, transparent)', 'fg' => 'var(--ds-red, #dc2626)'],
        'withdrawn' => ['label' => 'Withdrawn', 'bg' => 'var(--surface-2)', 'fg' => 'var(--text-muted)'],
    ];
    $currentStatus = $rentalStatusLabels[$contact->rental_application_status] ?? $rentalStatusLabels['none'];
    // AT-392 — viewer-scoped (own/branch/agency, agency-configurable in
    // Role Manager, default agency-wide). $visibleRentalApplications and
    // $hasAnyRentalApplications both come from the controller — ONE query
    // drives both this list and the tab badge (show.blade.php:90), so
    // they can never disagree. $hasAnyRentalApplications is the unscoped
    // existence check, used ONLY to tell "genuinely none" apart from
    // "some exist but your access level doesn't show them" below — never
    // to decide what's actually listed.
    $rentalApps = $visibleRentalApplications;
@endphp

<div class="flex items-center gap-3 flex-wrap">
    <span class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Current status</span>
    <span class="text-xs font-semibold px-2.5 py-1 rounded-md" style="background:{{ $currentStatus['bg'] }}; color:{{ $currentStatus['fg'] }};">
        {{ $currentStatus['label'] }}
    </span>
    @if($contact->rental_application_status_updated_at)
        <span class="text-[11px]" style="color:var(--text-muted);">as of {{ $contact->rental_application_status_updated_at->format('d M Y, H:i') }}</span>
    @endif
</div>

@if($rentalApps->isEmpty())
    <div class="rounded-md p-6 text-center" style="background:var(--surface-2); border:1px dashed var(--border);">
        @if($hasAnyRentalApplications)
            {{-- Scoping legitimately hid everything — never say "no
                 applications" when some genuinely exist; that would be
                 a false statement about the contact, not just an empty list. --}}
            <p class="text-sm font-medium" style="color:var(--text-secondary);">No rental applications visible at your access level</p>
            <p class="text-xs mt-1" style="color:var(--text-muted);">This contact has rental application history, but your current Rental History access (set in Role Manager) doesn't include it. Ask an admin to widen it if you need to see it.</p>
        @else
            <p class="text-sm font-medium" style="color:var(--text-secondary);">No rental applications yet</p>
            <p class="text-xs mt-1" style="color:var(--text-muted);">Every application this contact ever has will show up here, with its outcome and date.</p>
        @endif
    </div>
@else
    <div x-data="{
            sort: 'date_desc',
            outcome: 'all',
            apps: {{ $rentalApps->map(fn ($a) => [
                'id' => $a->id,
                'status' => $a->status,
                'address' => $a->property?->buildDisplayAddress() ?: ($a->property_address_override ?: 'No property on record'),
                'date' => optional($a->submitted_at ?: $a->created_at)->format('d M Y'),
                'timestamp' => optional($a->submitted_at ?: $a->created_at)->timestamp,
                'amount' => $a->approved_rental_amount,
                'view_url' => route('corex.rental-applications.review', $a->id),
                'pdf_url' => route('corex.rental-applications.pdf', $a->id),
            ])->values()->toJson() }},
        }"
         class="space-y-3">
        <div class="flex items-center gap-3 flex-wrap">
            <span class="text-xs" style="color:var(--text-muted);">{{ $rentalApps->count() }} application{{ $rentalApps->count() !== 1 ? 's' : '' }}</span>
            <select x-model="outcome" class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                <option value="all">All outcomes</option>
                <option value="approved">Approved</option>
                <option value="declined">Declined</option>
                <option value="withdrawn">Withdrawn</option>
                <option value="in_progress,returned,under_assessment,reopened">In progress</option>
                <option value="draft,sent">Invited</option>
            </select>
            <select x-model="sort" class="text-xs rounded-md border px-2 py-1.5" style="border-color:var(--border); background:var(--surface); color:var(--text-primary);">
                <option value="date_desc">Newest first</option>
                <option value="date_asc">Oldest first</option>
            </select>
        </div>

        <template x-for="app in apps
            .filter(a => outcome === 'all' || outcome.split(',').includes(a.status))
            .sort((a, b) => sort === 'date_desc' ? b.timestamp - a.timestamp : a.timestamp - b.timestamp)"
            :key="app.id">
            <div class="rounded-md p-3 flex items-center justify-between gap-3"
                 style="background:var(--surface); border:1px solid var(--border);">
                <a :href="app.view_url" class="min-w-0 flex-1 hover:opacity-80">
                    <div class="text-sm font-medium truncate" style="color:var(--text-primary);" x-text="app.address"></div>
                    <div class="text-[11px] mt-0.5" style="color:var(--text-muted);">
                        <span x-text="app.date"></span>
                        <template x-if="app.amount"><span> · Approved for R<span x-text="Number(app.amount).toLocaleString()"></span>/mo</span></template>
                    </div>
                </a>
                <div class="flex items-center gap-2 flex-shrink-0">
                    {{-- AT-392 — Johan: "allow the agent to download and print it if
                         they want to physically file the whole pack." Same existing
                         PDF service every other rental-application download already uses. --}}
                    <a :href="app.pdf_url" @click.stop title="Download the full application pack"
                       class="text-[11px] font-semibold px-2 py-0.5 rounded-md whitespace-nowrap" style="color:var(--brand-icon,#2563eb); border:1px solid var(--border);">
                        Download
                    </a>
                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-md whitespace-nowrap"
                          :style="{
                              approved: 'background:color-mix(in srgb, var(--ds-green, #16a34a) 14%, transparent); color:var(--ds-green, #16a34a);',
                              declined: 'background:color-mix(in srgb, var(--ds-red, #dc2626) 14%, transparent); color:var(--ds-red, #dc2626);',
                              withdrawn: 'background:var(--surface-2); color:var(--text-muted);',
                          }[app.status] || 'background:color-mix(in srgb, var(--ds-amber, #f59e0b) 14%, transparent); color:var(--ds-amber, #f59e0b);'"
                          x-text="app.status.replaceAll('_', ' ')"></span>
                </div>
            </div>
        </template>

        <div x-show="apps.filter(a => outcome === 'all' || outcome.split(',').includes(a.status)).length === 0" x-cloak
             class="rounded-md p-4 text-center text-xs" style="background:var(--surface-2); color:var(--text-muted);">
            No applications match this filter.
        </div>
    </div>
@endif
