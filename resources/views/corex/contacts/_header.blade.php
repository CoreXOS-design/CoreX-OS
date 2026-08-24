{{-- ════════════════════════════════════════════════════════════════════════
     CONTACT HEADER (AT-336)

     This is the contact's IDENTITY SECTION, not page chrome — a surface panel
     that presents the person, so it is deliberately NOT a `corex-page-banner`
     flat bar like the index/list pages.

     Layout:
       · Toolbar    — "Back to Contacts" pinned left, every other action right.
       · Identity   — the name centred, badges beneath. No avatar: the name is
                      the identity mark, and dropping it keeps the tab bar high.
       · Facts well — a bordered --surface-2 grid where each record fact is a
                      label/value pair, rather than a run of grey prose.

     $commMeta / $commTint / $allPhones / $allEmails / $primaryAgent are all
     resolved once in show.blade.php and read here.
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="rounded-lg overflow-hidden" style="background:var(--surface); border:1px solid var(--border); box-shadow:0 1px 2px rgba(15,23,42,0.06);">

    {{-- Toolbar — Back pinned LEFT, every other action right-aligned. --}}
    <div class="px-5 py-2.5 flex flex-wrap items-center justify-between gap-2"
         style="border-bottom:1px solid var(--border); background:var(--surface-2);">
        @include('corex.contacts._header-back')
        @include('corex.contacts._header-actions', [
            'wrapClass' => 'flex flex-wrap items-center justify-end gap-2',
        ])
    </div>

    {{-- Identity --}}
    <div class="px-6 pt-4 pb-3 flex flex-col items-center text-center">
        <h1 class="text-3xl font-bold leading-tight" style="color: var(--text-primary);">{{ $contact->full_name }}</h1>
        <div class="mt-2">
            @include('corex.contacts._header-badges', ['justify' => 'justify-center'])
        </div>
    </div>

    {{-- Facts well --}}
    <div class="px-6 pb-5">
        <div class="rounded-md px-5 py-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-8 gap-y-4"
             style="background:var(--surface-2); border:1px solid var(--border);">

            <div class="min-w-0">
                <div class="text-[11px] uppercase tracking-widest font-semibold mb-1" style="color:var(--text-muted);">Phone</div>
                <div class="text-sm truncate" style="color:var(--text-primary);">
                    @php $__ph = $allPhones->first(); @endphp
                    @if($__ph)
                        <a href="tel:{{ preg_replace('/\s+/', '', $__ph->phone) }}" class="no-underline hover:underline" style="color:inherit;">{{ $__ph->phone }}</a>
                        @if($allPhones->count() > 1)<span class="ml-1" style="color:var(--text-muted);">+{{ $allPhones->count() - 1 }} more</span>@endif
                    @elseif($contact->phone)
                        {{ $contact->phone }}
                    @else
                        <span style="color:var(--text-muted);">—</span>
                    @endif
                </div>
            </div>

            <div class="min-w-0">
                <div class="text-[11px] uppercase tracking-widest font-semibold mb-1" style="color:var(--text-muted);">Email</div>
                <div class="text-sm truncate" style="color:var(--text-primary);">
                    @php $__em = $allEmails->first(); @endphp
                    @if($__em)
                        <a href="mailto:{{ $__em->email }}" class="no-underline hover:underline" style="color:inherit;">{{ $__em->email }}</a>
                        @if($allEmails->count() > 1)<span class="ml-1" style="color:var(--text-muted);">+{{ $allEmails->count() - 1 }} more</span>@endif
                    @elseif($contact->email)
                        <a href="mailto:{{ $contact->email }}" class="no-underline hover:underline" style="color:inherit;">{{ $contact->email }}</a>
                    @else
                        <span style="color:var(--text-muted);">—</span>
                    @endif
                </div>
            </div>

            <div class="min-w-0">
                <div class="text-[11px] uppercase tracking-widest font-semibold mb-1" style="color:var(--text-muted);">Agent</div>
                <div class="text-sm truncate" style="color:var(--text-primary);">{{ $primaryAgent?->name ?? 'Unassigned' }}</div>
            </div>

            <div class="min-w-0">
                <div class="text-[11px] uppercase tracking-widest font-semibold mb-1" style="color:var(--text-muted);">Co-agent</div>
                <div class="text-sm truncate" style="color:var(--text-primary);">{{ $contact->secondAgent?->name ?? '—' }}</div>
            </div>

            <div class="min-w-0">
                <div class="text-[11px] uppercase tracking-widest font-semibold mb-1" style="color:var(--text-muted);">Created</div>
                <div class="text-sm" style="color:var(--text-primary);">
                    {{ $contact->created_at->format('d M Y') }}
                    @if($contact->updated_at->ne($contact->created_at))
                    <span style="color:var(--text-muted);">· upd. {{ $contact->updated_at->diffForHumans(null, true) }} ago</span>
                    @endif
                </div>
            </div>

            <div class="min-w-0">
                <div class="text-[11px] uppercase tracking-widest font-semibold mb-1" style="color:var(--text-muted);">Records</div>
                <div class="text-sm" style="color:var(--text-primary);">
                    {{ $contact->documents->count() }} file{{ $contact->documents->count() !== 1 ? 's' : '' }}
                    · {{ $contact->contactNotes->count() }} note{{ $contact->contactNotes->count() !== 1 ? 's' : '' }}
                </div>
            </div>
        </div>
    </div>
</div>
