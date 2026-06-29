{{--
    Viewing Packs — shared "Packs" section (AT-110 discoverability).

    Rendered INSIDE the existing "Viewings & Feedback" tab on BOTH the buyer
    pipeline detail and the contact record, so a pack built for a buyer is always
    findable again. IDENTICAL on both surfaces — one partial, one place to change.

    Requires:
      $contact  — the Contact (buyer) whose packs we list (buyer detail passes
                  $buyer; contact record passes $contact).

    Query mirrors ViewingPackController::index() — BelongsToAgency auto-scopes to
    the current agency; SoftDeletes excludes archived packs by default. Inline
    query here (no controller change) follows the existing in-view query idiom in
    these tabs (e.g. buyer_portal_links / lost-deal reasons). Read-only list →
    links straight to the existing corex.viewing-packs.* CRUD; builds no new
    pack infrastructure.

    NOTE: these surfaces (and the sidebar link) are NOT permission-gated yet —
    role/permission gating is AT-112 (separate ticket) and will wrap this.
--}}
@php
    $packs = \App\Models\ViewingPack::where('contact_id', $contact->id)
        ->withCount('viewingPackProperties')
        ->latest()
        ->get();
@endphp

<div>
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-xs font-bold uppercase tracking-widest" style="color:var(--text-muted);">Packs ({{ number_format($packs->count()) }})</h3>
        @if($packs->isNotEmpty())
            {{-- Start another pack — reuses the existing .store entry point. --}}
            <form method="POST" action="{{ route('corex.viewing-packs.store') }}" class="inline">
                @csrf
                <input type="hidden" name="contact_id" value="{{ $contact->id }}">
                <button type="submit" class="text-[11px] font-semibold no-underline hover:underline" style="color:var(--brand-icon, #00d4aa);">+ New Pack</button>
            </form>
        @endif
    </div>

    @forelse($packs as $pack)
        @php
            $statusVariant = match($pack->status) {
                \App\Models\ViewingPack::STATUS_READY => 'ds-badge-success',
                default => 'ds-badge-default',
            };
            $hasProperties = ($pack->viewing_pack_properties_count ?? 0) > 0;
        @endphp
        <div class="rounded-md p-4 mb-2" style="background:var(--surface); border:1px solid var(--border);">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <a href="{{ route('corex.viewing-packs.show', $pack) }}"
                       class="text-sm font-semibold truncate block no-underline hover:underline" style="color:var(--text-primary);">{{ $pack->title ?: 'Untitled pack' }}</a>
                    <div class="text-[10px] mt-0.5" style="color:var(--text-muted);">
                        {{ number_format($pack->viewing_pack_properties_count ?? 0) }} {{ \Illuminate\Support\Str::plural('property', $pack->viewing_pack_properties_count ?? 0) }}
                        · Created {{ $pack->created_at?->format('D, j M Y') ?? '—' }}
                    </div>
                </div>
                <div class="flex flex-col items-end gap-1 flex-shrink-0">
                    <span class="ds-badge {{ $statusVariant }}">{{ ucfirst($pack->status ?? 'draft') }}</span>
                    <div class="flex items-center gap-2">
                        @if($hasProperties)
                            <a href="{{ route('corex.viewing-packs.buyer-pack', $pack) }}" target="_blank"
                               class="text-[10px] no-underline hover:underline" style="color:var(--text-secondary);" title="Download the buyer-facing pack PDF">Buyer Pack</a>
                            <a href="{{ route('corex.viewing-packs.agent-sheet', $pack) }}" target="_blank"
                               class="text-[10px] no-underline hover:underline" style="color:var(--text-secondary);" title="Download the eyes-only agent sheet PDF">Agent Sheet</a>
                        @endif
                        <a href="{{ route('corex.viewing-packs.show', $pack) }}"
                           class="text-[10px] font-semibold no-underline hover:underline" style="color:var(--brand-icon, #00d4aa);">Open / Edit</a>
                    </div>
                </div>
            </div>
        </div>
    @empty
        {{-- No packs yet — start one right here (reuses the existing .store form). --}}
        <div class="rounded-md p-4" style="background:var(--surface); border:1px dashed var(--border);">
            <p class="text-xs mb-3" style="color:var(--text-muted);">No viewing packs built for this buyer yet.</p>
            <form method="POST" action="{{ route('corex.viewing-packs.store') }}" class="inline">
                @csrf
                <input type="hidden" name="contact_id" value="{{ $contact->id }}">
                <button type="submit" class="corex-btn-primary">Build Viewing Pack</button>
            </form>
        </div>
    @endforelse
</div>
