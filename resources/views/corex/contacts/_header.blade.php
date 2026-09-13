{{-- ════════════════════════════════════════════════════════════════════════
     CONTACT HEADER (AT-336, flattened AT-393)

     A flat banner, not a card: no surrounding panel and no boxed facts well,
     so the tab bar starts roughly half as far down the page as it used to.
     Same content as before — nothing was removed, only re-arranged.

     Layout:
       · Top row    — LEFT: a small "Back to Contacts" link above the name,
                      then the name with its badges inline.
                      RIGHT: every action, aligned to the name's baseline.
       · Facts row  — the six record facts as one labelled row (label above
                      value), wrapping to a second line only on narrow
                      screens.

     $commMeta / $commTint / $allPhones / $allEmails / $primaryAgent are all
     resolved once in show.blade.php and read here.
     ════════════════════════════════════════════════════════════════════════ --}}
<div class="pb-4" style="border-bottom:1px solid var(--border);">

    {{-- Top row — identity LEFT, actions RIGHT. --}}
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            @include('corex.contacts._header-back')
            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-2">
                <h1 class="text-2xl font-bold leading-tight" style="color: var(--text-primary);">{{ $contact->full_name }}</h1>
                @include('corex.contacts._header-badges')
            </div>
        </div>
        @include('corex.contacts._header-actions', [
            'wrapClass' => 'flex flex-wrap items-center justify-end gap-2 sm:pt-5',
        ])
    </div>

    {{-- Facts row — one labelled line, no well. --}}
    <div class="mt-3 flex flex-wrap gap-x-8 gap-y-3">

        <div class="min-w-0">
            <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Phone</div>
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
            <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Email</div>
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
            <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Agent</div>
            <div class="text-sm truncate" style="color:var(--text-primary);">{{ $primaryAgent?->name ?? 'Unassigned' }}</div>
        </div>

        <div class="min-w-0">
            <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Co-agent</div>
            <div class="text-sm truncate" style="color:var(--text-primary);">{{ $contact->secondAgent?->name ?? '—' }}</div>
        </div>

        <div class="min-w-0">
            <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Created</div>
            <div class="text-sm" style="color:var(--text-primary);">
                {{ $contact->created_at->format('d M Y') }}
                @if($contact->updated_at->ne($contact->created_at))
                <span style="color:var(--text-muted);">· upd. {{ $contact->updated_at->diffForHumans(null, true) }} ago</span>
                @endif
            </div>
        </div>

        <div class="min-w-0">
            <div class="text-[11px] uppercase tracking-widest font-semibold" style="color:var(--text-muted);">Records</div>
            <div class="text-sm" style="color:var(--text-primary);">
                {{ $contact->documents->count() }} file{{ $contact->documents->count() !== 1 ? 's' : '' }}
                · {{ $contact->contactNotes->count() }} note{{ $contact->contactNotes->count() !== 1 ? 's' : '' }}
            </div>
        </div>
    </div>
</div>
