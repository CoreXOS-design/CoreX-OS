{{-- ════════════════════════════════════════════════════════════════════════
     CONTACT HEADER (AT-336, segmented strip AT-393)

     This is the contact's IDENTITY SECTION, not page chrome — a surface panel
     that presents the person, so it is deliberately NOT a `corex-page-banner`
     flat bar like the index/list pages.

     Layout — two bands, roughly half the height of the old three:
       · Identity row — Back (icon) + the name + badges on the LEFT, every
                        action on the RIGHT, all on one line.
       · Facts strip  — the six record facts as six equal cells across the
                        bottom of the card, split by hairline rules, edge to
                        edge. The rules come from a 1px grid gap over a
                        --border background, so they stay correct however
                        the cells wrap on narrower screens.

     $commMeta / $commTint / $allPhones / $allEmails / $primaryAgent are all
     resolved once in show.blade.php and read here.
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="rounded-lg overflow-hidden" style="background:var(--surface); border:1px solid var(--border); box-shadow:0 1px 2px rgba(15,23,42,0.06);">

    {{-- Identity row — Back + name + badges LEFT, actions RIGHT. --}}
    <div class="px-5 py-3.5 flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 min-w-0">
            @include('corex.contacts._header-back')
            <h1 class="text-2xl font-bold leading-tight" style="color: var(--text-primary);">{{ $contact->full_name }}</h1>
            @include('corex.contacts._header-badges')
        </div>
        @include('corex.contacts._header-actions', [
            'wrapClass' => 'flex flex-wrap items-center justify-end gap-2',
        ])
    </div>

    {{-- Facts strip — six cells, hairline-separated, flush to the card edge. --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-px"
         style="background:var(--border); border-top:1px solid var(--border);">

        <div class="px-4 py-2.5 min-w-0" style="background:var(--surface-2);">
            <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Phone</div>
            <div class="text-sm truncate mt-0.5" style="color:var(--text-primary);">
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

        <div class="px-4 py-2.5 min-w-0" style="background:var(--surface-2);">
            <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Email</div>
            <div class="text-sm truncate mt-0.5" style="color:var(--text-primary);">
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

        <div class="px-4 py-2.5 min-w-0" style="background:var(--surface-2);">
            <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Agent</div>
            <div class="text-sm truncate mt-0.5" style="color:var(--text-primary);">{{ $primaryAgent?->name ?? 'Unassigned' }}</div>
        </div>

        <div class="px-4 py-2.5 min-w-0" style="background:var(--surface-2);">
            <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Co-agent</div>
            <div class="text-sm truncate mt-0.5" style="color:var(--text-primary);">{{ $contact->secondAgent?->name ?? '—' }}</div>
        </div>

        <div class="px-4 py-2.5 min-w-0" style="background:var(--surface-2);">
            <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Created</div>
            <div class="text-sm truncate mt-0.5" style="color:var(--text-primary);">
                {{ $contact->created_at->format('d M Y') }}
                @if($contact->updated_at->ne($contact->created_at))
                <span style="color:var(--text-muted);">· upd. {{ $contact->updated_at->diffForHumans(null, true) }} ago</span>
                @endif
            </div>
        </div>

        <div class="px-4 py-2.5 min-w-0" style="background:var(--surface-2);">
            <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Records</div>
            <div class="text-sm truncate mt-0.5" style="color:var(--text-primary);">
                {{ $contact->documents->count() }} file{{ $contact->documents->count() !== 1 ? 's' : '' }}
                · {{ $contact->contactNotes->count() }} note{{ $contact->contactNotes->count() !== 1 ? 's' : '' }}
            </div>
        </div>
    </div>
</div>
