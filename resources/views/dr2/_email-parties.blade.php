{{--
    AT-331 — "Email Parties" tab of the DR2 pipeline right panel.
    LAYOUT-ONLY extraction from dr2/_deal-documents.blade.php: this is the AT-228
    party-first document-distribution block, lifted verbatim into its own tab so it
    is never pushed off-screen (Johan missed "send docs" because it sat at the bottom
    of the Documents card). NO logic change — same permission gate, same
    $distParties / $sentDist view-model, same compose routes.
    @include('dr2._email-parties', ['deal' => $deal]).
--}}
@if(auth()->user()?->hasPermission('deals_v2.distribute_documents'))
    @php
        $distParties  = app(\App\Services\DealV2\Dr2DistributionComposer::class)->parties($deal);
        $anySendable  = collect($distParties)->contains(fn ($p) => $p['sendable']);
        $canEditDeal  = auth()->user()?->hasPermission('create_deals');
        $sentDist = $deal->deal_v2_id
            ? \App\Models\DealV2\DealDocumentDistribution::withoutGlobalScopes()->where('deal_id', $deal->deal_v2_id)->with('document')->latest()->take(12)->get()
            : collect();
    @endphp
    <div class="corex-card" style="padding:1rem;" data-tour="dr2-email-parties"
         x-data="{ openSend: true, openSent: true }">

        {{-- Standard collapse/expand section header (house convention). --}}
        <button type="button" @click="openSend = !openSend" class="dr2-sect-head">
            <span class="dr2-chev" :class="openSend ? '' : 'dr2-chev-closed'">▾</span>
            <span class="dr2-sect-title">Send documents to a party</span>
        </button>

        <div x-show="openSend" x-cloak style="margin-top:.6rem;">
            {{-- Empty state — the door never just greys out: it explains WHY there is
                 nothing to send to yet and links to where a party gets linked. --}}
            @if(! $anySendable)
                <div style="font-size:.78rem;color:var(--text-secondary,#4b5563);background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 6%, var(--surface,#fff));border:1px solid var(--border,rgba(0,0,0,.08));border-radius:8px;padding:.6rem .7rem;margin-bottom:.6rem;">
                    <div style="font-weight:600;margin-bottom:.15rem;">No party is linked to this deal yet.</div>
                    <div style="color:var(--text-muted,#6b7280);">
                        Link a seller, buyer, transferring attorney or bond originator to this deal, then send them documents from here.
                    </div>
                    @if($canEditDeal)
                        <a href="{{ route('deals-dr2.edit', $deal) }}" class="corex-btn-primary" style="display:inline-block;margin-top:.5rem;font-size:.78rem;padding:.3rem .8rem;">Link a party on this deal</a>
                    @endif
                </div>
            @endif

            <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;">
                @foreach($distParties as $p)
                    @if($p['sendable'])
                        <a href="{{ route('deals-dr2.distribute.compose', ['deal'=>$deal,'party'=>$p['role']]) }}" class="corex-btn-outline" style="font-size:.78rem;padding:.3rem .7rem;">
                            Send to {{ $p['label'] }}{{ count($p['default_documents']) ? ' · '.count($p['default_documents']).' default' : '' }}
                        </a>
                    @else
                        <span style="font-size:.72rem;padding:.3rem .6rem;color:#9ca3af;border:1px dashed var(--border,#ddd);border-radius:8px;display:inline-flex;gap:.4rem;align-items:center;">
                            <span>{{ $p['label'] }} — {{ $p['note'] ?? 'not linked yet' }}</span>
                            @if($canEditDeal)<a href="{{ route('deals-dr2.edit', $deal) }}" style="color:var(--brand-icon,#0ea5e9);text-decoration:underline;">link</a>@endif
                        </span>
                    @endif
                @endforeach
            </div>
        </div>

        @if($sentDist->isNotEmpty())
            <button type="button" @click="openSent = !openSent" class="dr2-sect-head" style="margin-top:.9rem;">
                <span class="dr2-chev" :class="openSent ? '' : 'dr2-chev-closed'">▾</span>
                <span class="dr2-sect-title">Sent — what went to whom</span>
            </button>
            <div x-show="openSent" x-cloak style="margin-top:.4rem;display:flex;flex-direction:column;gap:.3rem;">
                @foreach($sentDist as $d)
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem;font-size:.78rem;padding:.35rem .5rem;border:1px solid var(--border,rgba(0,0,0,.06));border-radius:6px;">
                        <span style="min-width:0;">{{ $d->document?->original_name ?? 'Document' }} <span style="color:#9ca3af;">→ {{ ucwords(str_replace('_',' ',$d->party_role)) }} · {{ $d->recipient_email ?: 'recipient' }}</span></span>
                        <span style="white-space:nowrap;color:#9ca3af;">{{ $d->channel }}/{{ $d->delivery_mode==='secure_link'?'link':'attach' }}{{ $d->part_of>1 ? ' · pt '.$d->part_no.'/'.$d->part_of : '' }} · {{ $d->status }} · {{ $d->sent_at?->format('d M') }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@else
    {{-- No distribute permission: same as before (this block simply did not render).
         A neutral, honest empty-state so the tab is never a dead blank. --}}
    <div class="corex-card" style="padding:1rem;">
        <span class="dr2-sect-title">Send documents to a party</span>
        <p style="font-size:.8rem;color:var(--text-muted,#9ca3af);margin:.5rem 0 0;">
            You do not have permission to send documents to parties on this deal.
        </p>
    </div>
@endif
