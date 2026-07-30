{{-- Pipeline Dashboard — the deal-context tabs, moved ON TOP of the Timeline and List views (Johan's
     original design: the old right-side rail becomes a top tab bar). Renders the SAME panels the board
     did — Deal Structure / Supplier Work Orders / Documents / Email Parties / Proforma — same partials,
     same routes, fully functional. Needs: deal, conditionCatalog, dealConditions, hasPipeline, locked,
     steps, woNeedsAttention. --}}
@php($woAtt = ($woNeedsAttention ?? false))
<div x-data="{ tab: ({{ ($hasPipeline ?? false) ? 'true' : 'false' }} ? (window.localStorage.getItem('dr2_ctx_tab') || 'wo') : 'structure') }"
     x-init="$watch('tab', v => window.localStorage.setItem('dr2_ctx_tab', v))"
     @dr2-open-structure.window="tab='structure'"
     style="margin-bottom:1rem;">

    {{-- Deal-level DECLINE — prominent at the top of the deal panels (the Timeline's own header is
         owned by cc5, so this is the visible non-tab home on the Timeline). Reuses the canonical
         accepted_status → 'D' transition (locks the pipeline; re-grantable from the register). --}}
    @php $accepted = (string) ($deal->accepted_status ?? 'P'); @endphp
    <div style="display:flex;justify-content:flex-end;align-items:center;margin-bottom:.4rem;">
        @if($accepted === 'D')
            <span style="color:#b91c1c;font-size:12px;font-weight:700;">● Deal Declined — re-grant from the register</span>
        @elseif($accepted !== 'R')
            <form method="POST" action="{{ route('deals-dr2.pipeline.decline', $deal) }}" style="margin:0;"
                  onsubmit="return confirm('Decline this deal? Its pipeline locks (read-only) and it drops to Declined. It stays re-grantable from the register.');">
                @csrf
                <button type="submit" style="background:#fff;border:1px solid #fca5a5;color:#b91c1c;border-radius:8px;padding:4px 11px;font-size:12px;font-weight:600;cursor:pointer;">Decline deal</button>
            </form>
        @endif
    </div>

    <div class="dr2-tabbar" role="tablist" aria-label="Deal context">
        <button type="button" class="dr2-tab" :class="tab==='structure' ? 'corex-tab-active' : ''" @click="tab='structure'" role="tab" :aria-selected="tab==='structure'">Deal Structure</button>
        <button type="button" class="dr2-tab" :class="tab==='wo' ? 'corex-tab-active' : ''" @click="tab='wo'" role="tab" :aria-selected="tab==='wo'" style="{{ $woAtt ? 'color:#b91c1c;font-weight:700;' : '' }}" title="{{ $woAtt ? 'A work order is waiting for a supplier' : '' }}">Supplier Work Orders{!! $woAtt ? ' <span aria-hidden=&quot;true&quot; style=&quot;color:#dc2626&quot;>&#9679;</span>' : '' !!}</button>
        <button type="button" class="dr2-tab" :class="tab==='docs' ? 'corex-tab-active' : ''" @click="tab='docs'" role="tab" :aria-selected="tab==='docs'">Documents</button>
        <button type="button" class="dr2-tab" :class="tab==='email' ? 'corex-tab-active' : ''" @click="tab='email'" role="tab" :aria-selected="tab==='email'">Email Parties</button>
        <button type="button" class="dr2-tab" :class="tab==='pi' ? 'corex-tab-active' : ''" @click="tab='pi'" role="tab" :aria-selected="tab==='pi'">Proforma Invoice</button>
    </div>

    <div x-show="tab==='structure'" x-cloak role="tabpanel">
        @include('dr2._deal-structure', ['deal' => $deal, 'conditionCatalog' => $conditionCatalog, 'dealConditions' => $dealConditions, 'hasPipeline' => $hasPipeline, 'locked' => $locked])
    </div>
    <div x-show="tab==='wo'" x-cloak role="tabpanel">
        @include('dr2._supplier-work-orders', ['deal' => $deal, 'steps' => $steps, 'locked' => $locked])
    </div>
    <div x-show="tab==='docs'" x-cloak role="tabpanel">
        @include('dr2._deal-documents', ['deal' => $deal])
    </div>
    <div x-show="tab==='email'" x-cloak role="tabpanel">
        @include('dr2._email-parties', ['deal' => $deal])
    </div>
    <div x-show="tab==='pi'" x-cloak role="tabpanel">
        @include('proforma._deal-section', ['deal' => $deal])
    </div>
</div>
