{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md --}}
{{--
    AT-217 (DR2) — the DR2 capture screen. An exact rebuild of DR1's capture
    (resources/views/admin/deals/form.blade.php) writing the SAME `deals` tables
    via Dr2\DealRegisterController, PLUS the §2 enhancements:
      §2.2 Property   — free-text address replaced by the canonical searchable
                        picker; the pick is linked on deals.property_id.
      §2.3 Seller/Buyer — auto-offered from the linked property (seller auto-fills;
                        buyers show as a tick-list; both stay editable).
      §2.4 Attorney   — supplier-directory search + add-new-inline modal.
      §2.5 Selling price — prefilled from the property's price (overridable).
      §2.6 Commission — prefilled from property.commission_percent × price (overridable).
      §2.8 External-agency layout — checkbox + fields laid out without collision.
    Sides/splits/agents logic is unchanged from DR1 (§2.7). DR1 stays untouched.
--}}
<x-app-layout>
    <x-slot name="header">
        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">{{ $mode === 'create' ? 'Add Deal (DR2)' : 'Edit Deal (DR2)' }}</h2>
                    <div class="text-sm text-white/60">Link the property once — seller, buyer, price &amp; commission follow automatically.</div>
                </div>
                <a href="{{ route('deals-dr2.index') }}"
                   class="inline-flex items-center rounded-xl bg-white/10 px-4 py-2 text-sm font-semibold text-white ring-1 ring-white/20 hover:bg-white/15">
                    &larr; Back to DR2 Register
                </a>
            </div>
        </div>
    </x-slot>

    @if($errors->any())
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 mb-4">
            {{ $errors->first() }}
        </div>
    @endif

    @php
        // PHP 8.4-safe (no nested ternary) — DR1 parity.
        $hasErrors = $errors->any();

        $oldListingAgents = old('listing_agents', null);
        $oldSellingAgents = old('selling_agents', null);

        $listingSelectedIds = [];
        $sellingSelectedIds = [];

        if (is_array($oldListingAgents)) {
            $listingSelectedIds = array_map('strval', $oldListingAgents);
        } elseif ($deal->exists) {
            $listingSelectedIds = $deal->agents
                ->filter(fn($a) => $a->pivot?->side === 'listing')
                ->pluck('id')->map(fn($v) => (string)$v)->values()->all();
        }

        if (is_array($oldSellingAgents)) {
            $sellingSelectedIds = array_map('strval', $oldSellingAgents);
        } elseif ($deal->exists) {
            $sellingSelectedIds = $deal->agents
                ->filter(fn($a) => $a->pivot?->side === 'selling')
                ->pluck('id')->map(fn($v) => (string)$v)->values()->all();
        }

        $listingPercents = [];
        $sellingPercents = [];

        if (!$hasErrors && $deal->exists) {
            $listingPercents = $deal->agents
                ->filter(fn($a) => $a->pivot?->side === 'listing')
                ->mapWithKeys(fn($a) => [(string)$a->id => $a->pivot->agent_split_percent])->toArray();
            $sellingPercents = $deal->agents
                ->filter(fn($a) => $a->pivot?->side === 'selling')
                ->mapWithKeys(fn($a) => [(string)$a->id => $a->pivot->agent_split_percent])->toArray();
        }

        // §2.2 — the currently-linked property (edit mode) for the picker's initial label.
        $linkedProperty = ($deal->exists && $deal->property_id) ? $deal->property : null;
    @endphp

    <div class="page-wrap">
    <div class="space-y-6">

    <form method="POST"
          action="{{ $mode === 'create' ? route('deals-dr2.store') : route('deals-dr2.update', $deal) }}"
          class="space-y-6">
        @csrf
        @if($mode === 'edit') @method('PUT') @endif

        {{-- Deal Details --}}
        <div>
            <h2 class="ds-section-header">Deal Details</h2>
            <div class="ds-section-sub mb-4">Core deal + commission capture (commission is VAT-inclusive).</div>

            <div class="ds-status-card">
                <div class="deal-grid">
            <div>
                <label class="ds-label block mb-1">Deal No (system)</label>
                <input type="text" value="{{ $deal->deal_no ?? 'Auto' }}" disabled>
            </div>

            <div>
                <label class="ds-label block mb-1">Branch</label>
                @php
                    $u = auth()->user();
                    $dealScope = \App\Services\PermissionService::getDataScope($u, 'deals');
                    $isBM = $dealScope === 'branch';
                    $effectiveBranchId = $u?->effectiveBranchId();
                @endphp
                @if($isBM)
                    <select disabled>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ (string)$effectiveBranchId === (string)$b->id ? 'selected' : '' }}>
                                {{ $b->name }} ({{ $b->code }})
                            </option>
                        @endforeach
                    </select>
                    <input type="hidden" name="branch_id" value="{{ $effectiveBranchId }}">
                @else
                    <select name="branch_id">
                        <option value="">-- Select --</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ (string)old('branch_id', $deal->branch_id) === (string)$b->id ? 'selected' : '' }}>
                                {{ $b->name }} ({{ $b->code }})
                            </option>
                        @endforeach
                    </select>
                @endif
            </div>

            @if($deal->exists)
            <div>
                <label class="ds-label block mb-1">Branch Manager</label>
                @php $dealBranchManager = $deal->branchManager(); @endphp
                <input type="text" value="{{ $dealBranchManager?->name ?? '—' }}" disabled>
                @if($deal->managed_by_user_id)
                    <div class="ds-section-sub mt-1">Named at registration.</div>
                @endif
            </div>
            @endif

            <div>
                <label class="ds-label block mb-1">Period</label>
                <input type="month" name="period" value="{{ old('period', $deal->period) }}" required>
            </div>

            <div>
                <label class="ds-label block mb-1">Deal Date</label>
                <input type="date" name="deal_date" value="{{ old('deal_date', optional($deal->deal_date)->format('Y-m-d')) }}" required>
            </div>

            {{-- §2.2 PROPERTY — canonical searchable link (token-AND, unit/complex clarity) --}}
            <div class="field-full" id="dr2-property-picker">
                <label class="ds-label block mb-1">Property</label>
                <input type="hidden" name="property_id" id="dr2_property_id" value="{{ old('property_id', $deal->property_id) }}">
                <div style="position:relative;">
                    <input type="text" id="dr2_property_search" class="w-full"
                           autocomplete="off"
                           placeholder="Search an existing property by address, complex, unit…"
                           value="{{ old('property_address', $linkedProperty?->buildDisplayAddress() ?? $deal->property_address) }}">
                    <div id="dr2_property_results"
                         style="position:absolute;z-index:40;left:0;right:0;top:100%;background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);max-height:16rem;overflow:auto;display:none;"></div>
                </div>
                {{-- The free-text address is what gets stored (DR1 parity) — kept in sync with the pick,
                     but editable when no CoreX property matches (input-space: property not found). --}}
                <input type="hidden" name="property_address" id="dr2_property_address"
                       value="{{ old('property_address', $linkedProperty?->buildDisplayAddress() ?? $deal->property_address) }}">
                <div id="dr2_property_linked" class="text-xs mt-1"
                     style="{{ old('property_id', $deal->property_id) ? '' : 'display:none;' }}color:#047857;">
                    ✓ Linked to CoreX property <span id="dr2_property_linked_id">#{{ old('property_id', $deal->property_id) }}</span>.
                    <button type="button" id="dr2_property_unlink" class="underline text-gray-500 ml-1">unlink</button>
                </div>
                <div class="text-xs text-gray-400 mt-1">No match? Just type the address — the deal saves without a link.</div>
            </div>

            {{-- §2.3 SELLER — auto-filled from the linked property (editable) --}}
            <div>
                <label class="ds-label block mb-1">Seller</label>
                <input type="text" name="seller_name" id="dr2_seller_name" value="{{ old('seller_name', $deal->seller_name) }}">
                <div id="dr2_seller_hint" class="text-xs text-gray-400 mt-1" style="display:none;"></div>
            </div>

            {{-- §2.3 BUYER — tick-list of linked buyers (editable free-text too) --}}
            <div>
                <label class="ds-label block mb-1">Buyer</label>
                <input type="text" name="buyer_name" id="dr2_buyer_name" value="{{ old('buyer_name', $deal->buyer_name) }}">
                <div id="dr2_buyer_ticklist" class="mt-2 space-y-1" style="display:none;">
                    <div class="text-xs text-gray-500">Linked buyers on this property — tick to fill:</div>
                    <div id="dr2_buyer_options" class="space-y-1"></div>
                </div>
            </div>

            {{-- §2.4 ATTORNEY — supplier search + add-new-inline --}}
            <div class="field-full" id="dr2-attorney-picker">
                <label class="ds-label block mb-1">Attorney</label>
                <input type="hidden" name="attorney_name" id="dr2_attorney_name" value="{{ old('attorney_name', $deal->attorney_name) }}">
                <div style="position:relative;">
                    <input type="text" id="dr2_attorney_search" class="w-full" autocomplete="off"
                           placeholder="Search transfer attorneys / conveyancers…"
                           value="{{ old('attorney_name', $deal->attorney_name) }}">
                    <div id="dr2_attorney_results"
                         style="position:absolute;z-index:40;left:0;right:0;top:100%;background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);max-height:16rem;overflow:auto;display:none;"></div>
                </div>
                <button type="button" id="dr2_attorney_addnew" class="text-xs text-blue-600 underline mt-1">+ Add a new attorney</button>
            </div>

            {{-- §2.5 SELLING PRICE — prefilled from property.price (overridable) --}}
            <div>
                <label class="ds-label block mb-1">Selling Price</label>
                <input type="number" step="0.01" class="input-base money-input" name="property_value" id="dr2_property_value"
                       value="{{ old('property_value', $deal->property_value) }}" required>
                <div id="dr2_price_hint" class="text-xs text-gray-400 mt-1" style="display:none;">Prefilled from the property — override if the sale differs.</div>
            </div>

            {{-- §2.6 COMMISSION — prefilled from property.commission_percent × price (overridable) --}}
            <div>
                <label class="ds-label block mb-1">Total Commission (Incl VAT)</label>
                <input type="number" step="0.01" class="input-base money-input" name="total_commission" id="dr2_total_commission"
                       value="{{ old('total_commission', $deal->total_commission) }}" required>
                <div class="mt-1 text-xs text-gray-500">Internal pools/allocations are calculated <span class="font-semibold">Ex VAT</span> (VAT is tracked separately).</div>
                <div id="dr2_comm_hint" class="text-xs text-gray-400 mt-1" style="display:none;"></div>
            </div>
                </div>
            </div>
        </div>

        {{-- Status & Registration --}}
        <div>
            <h2 class="ds-section-header">Status &amp; Registration</h2>
            <div class="ds-section-sub mb-4">Admin tracking fields (optional where applicable).</div>

            <div class="ds-status-card">
                <div class="deal-grid pt-2">
            <div>
                <label class="ds-label block mb-1">Accepted Status</label>
                @php $as = old('accepted_status', $deal->accepted_status); @endphp
                <select name="accepted_status">
                    <option value="">-- Select --</option>
                    <option value="P" {{ $as === 'P' ? 'selected' : '' }}>P - Pending</option>
                    <option value="D" {{ $as === 'D' ? 'selected' : '' }}>D - Declined</option>
                    <option value="G" {{ $as === 'G' ? 'selected' : '' }}>G - Granted</option>
                    <option value="R" {{ $as === 'R' ? 'selected' : '' }}>R - Registered</option>
                </select>
            </div>

            <div>
                <label class="ds-label block mb-1">Commission Status</label>
                @php $cs = old('commission_status', $deal->commission_status); @endphp
                <select name="commission_status">
                    <option value="">-- Select --</option>
                    <option value="Not Paid" {{ $cs === 'Not Paid' ? 'selected' : '' }}>Not Paid</option>
                    <option value="Paid" {{ $cs === 'Paid' ? 'selected' : '' }}>Paid</option>
                    <option value="Loss" {{ $cs === 'Loss' ? 'selected' : '' }}>Loss</option>
                </select>
            </div>

            <div>
                <label class="ds-label block mb-1">Registration Date</label>
                <input type="date" name="registration_date" value="{{ old('registration_date', optional($deal->registration_date)->format('Y-m-d')) }}">
            </div>

            <div>
                <label class="ds-label block mb-1">Remarks</label>
                <input type="text" name="remarks" value="{{ old('remarks', $deal->remarks) }}">
            </div>
                </div>
            </div>
        </div>

        {{-- §2.7 Sides, splits & agents — unchanged logic; §2.8 external-agency layout fixed --}}
        <div>
            <h2 class="ds-section-header">Sides, Splits &amp; Agents</h2>
            <div class="ds-section-sub mb-4">Set external / our share and lock listing + selling split to total 100%.</div>

            <div class="ds-status-card">
                <div class="deal-grid pt-4">
            @foreach(['listing' => ['Listing', $listingSelectedIds], 'selling' => ['Selling', $sellingSelectedIds]] as $side => $meta)
            @php [$sideLabel, $sideSelectedIds] = $meta; @endphp
            <div>
                <h3 class="font-bold" style="color:#0b2a4a">{{ $sideLabel }} Side</h3>

                {{-- §2.8 — external-agency row rebuilt as a non-colliding responsive stack.
                     Old layout crammed checkbox + our-share + split + agency name onto one
                     flex line so the labels overlapped; each control now owns its own row. --}}
                <div class="mt-2 space-y-3">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="{{ $side }}_external" id="{{ $side }}_external"
                               {{ old($side.'_external', $deal->{$side.'_external'}) ? 'checked' : '' }}>
                        <span>External agency handled this side</span>
                    </label>

                    <div class="dr2-external-fields grid grid-cols-1 sm:grid-cols-2 gap-3"
                         id="{{ $side }}_external_fields"
                         style="{{ old($side.'_external', $deal->{$side.'_external'}) ? '' : 'display:none;' }}">
                        <div>
                            <label class="ds-label block mb-1">Our Share %</label>
                            <input type="number" step="0.01" name="{{ $side }}_our_share_percent" class="w-full"
                                   value="{{ old($side.'_our_share_percent', $deal->{$side.'_our_share_percent'}) }}" placeholder="Our Share %">
                        </div>
                        <div>
                            <label class="ds-label block mb-1">External Agency</label>
                            <input type="text" name="{{ $side }}_external_agency" class="w-full"
                                   value="{{ old($side.'_external_agency', $deal->{$side.'_external_agency'}) }}" placeholder="Agency name">
                        </div>
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <div class="ds-label">{{ $sideLabel }} split %</div>
                            @if($side === 'listing')
                            <div class="text-xs text-gray-500"><span id="listing_split_label">—</span> / <span id="selling_split_label">—</span></div>
                            @endif
                        </div>
                        <div class="mt-2 flex items-center gap-3">
                            <input id="{{ $side }}_split_percent" type="number" step="0.01" name="{{ $side }}_split_percent"
                                   value="{{ old($side.'_split_percent', $deal->{$side.'_split_percent'} ?? 50) }}"
                                   class="w-24 rounded-lg border-gray-200" placeholder="%">
                            <input id="{{ $side }}_split_slider" type="range" min="0" max="100" step="0.01"
                                   class="flex-1" value="{{ old($side.'_split_percent', $deal->{$side.'_split_percent'} ?? 50) }}">
                        </div>
                    </div>
                </div>

                <div class="mt-3 space-y-3">
                    <div>
                        <label class="ds-label block mb-1">{{ $sideLabel }} Agents</label>
                        <select id="{{ $side }}_select" class="multi-select" multiple size="6">
                            @foreach($agents as $agent)
                                <option value="{{ $agent->id }}" {{ in_array((string)$agent->id, $sideSelectedIds, true) ? 'selected' : '' }}>
                                    {{ $agent->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="text-xs text-gray-500 mt-1">Hold Ctrl / Cmd to select multiple.</div>
                    </div>
                    <div id="{{ $side }}_selected" class="space-y-2"></div>
                </div>
            </div>
            @endforeach
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end">
            <button type="submit" class="corex-btn-primary px-5 py-2.5 text-sm">
                {{ $mode === 'create' ? 'Save Deal' : 'Update Deal' }}
            </button>
        </div>
    </form>
    </div>
    </div>

    {{-- §2.4 add-new-attorney inline modal --}}
    <div id="dr2_attorney_modal" style="display:none;position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.4);align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:.75rem;max-width:32rem;width:92%;padding:1.5rem;">
            <h3 class="font-bold mb-3" style="color:#0b2a4a">Add a new attorney</h3>
            <div class="space-y-3">
                <div><label class="ds-label block mb-1">Name / Firm *</label><input type="text" id="dr2_new_att_name" class="w-full"></div>
                <div><label class="ds-label block mb-1">Company</label><input type="text" id="dr2_new_att_company" class="w-full"></div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div><label class="ds-label block mb-1">Email</label><input type="email" id="dr2_new_att_email" class="w-full"></div>
                    <div><label class="ds-label block mb-1">Phone</label><input type="text" id="dr2_new_att_phone" class="w-full"></div>
                </div>
                <div id="dr2_new_att_error" class="text-sm text-red-600" style="display:none;"></div>
            </div>
            <div class="flex items-center justify-end gap-2 mt-4">
                <button type="button" id="dr2_new_att_cancel" class="corex-btn-secondary px-4 py-2 text-sm">Cancel</button>
                <button type="button" id="dr2_new_att_save" class="corex-btn-primary px-4 py-2 text-sm">Save attorney</button>
            </div>
        </div>
    </div>

    <script>
    (function () {
        // ---- CSRF (for the inline-supplier POST) ----
        const csrf = document.querySelector('input[name="_token"]')?.value
                  || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const R = {
            properties: @json(route('deals-dr2.search.properties')),
            // {property} placeholder replaced at call-time
            propertyContacts: @json(route('deals-dr2.search.property-contacts', ['property' => '__ID__'])),
            suppliers: @json(route('deals-dr2.suppliers.search')),
            supplierInline: @json(route('deals-dr2.suppliers.inline')),
        };

        function debounce(fn, ms) { let t; return function (...a) { clearTimeout(t); t = setTimeout(() => fn.apply(this, a), ms); }; }
        function money(v) { const n = Number(v); return isNaN(n) ? '' : n.toLocaleString('en-ZA'); }

        // ============ §2.2 PROPERTY PICKER ============
        const pSearch  = document.getElementById('dr2_property_search');
        const pResults = document.getElementById('dr2_property_results');
        const pId      = document.getElementById('dr2_property_id');
        const pAddr    = document.getElementById('dr2_property_address');
        const pLinked  = document.getElementById('dr2_property_linked');
        const pLinkedId = document.getElementById('dr2_property_linked_id');
        const priceEl  = document.getElementById('dr2_property_value');
        const commEl   = document.getElementById('dr2_total_commission');
        const priceHint = document.getElementById('dr2_price_hint');
        const commHint  = document.getElementById('dr2_comm_hint');

        function closeResults() { pResults.style.display = 'none'; pResults.innerHTML = ''; }

        // Keep the stored free-text address in sync with whatever's typed (input-space:
        // no CoreX match → the typed address is what saves, property_id stays null).
        pSearch.addEventListener('input', function () { pAddr.value = pSearch.value; });

        const runPropSearch = debounce(function () {
            const q = pSearch.value.trim();
            if (q.length < 2) { closeResults(); return; }
            fetch(R.properties + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                .then(r => r.ok ? r.json() : [])
                .then(rows => {
                    if (!Array.isArray(rows) || rows.length === 0) {
                        pResults.innerHTML = '<div style="padding:.6rem .8rem;color:#9ca3af;font-size:.85rem;">No matching property — type the address to save without a link.</div>';
                        pResults.style.display = 'block';
                        return;
                    }
                    pResults.innerHTML = rows.map(row => {
                        const addr = row.address || row.label || row.title || ('Property #' + row.id);
                        const price = (row.price != null && row.price !== '') ? 'R ' + money(row.price) : '';
                        return '<div class="dr2-prop-row" role="button" tabindex="0"' +
                               ' data-id="' + row.id + '"' +
                               ' data-address="' + String(addr).replace(/"/g, '&quot;') + '"' +
                               ' data-price="' + (row.price ?? '') + '"' +
                               ' data-comm="' + (row.commission_percent ?? '') + '"' +
                               ' style="padding:.6rem .8rem;cursor:pointer;border-bottom:1px solid #f3f4f6;">' +
                               '<div style="font-weight:600;color:#0b2a4a;">' + addr + '</div>' +
                               (price ? '<div style="font-size:.8rem;color:#6b7280;">' + price + '</div>' : '') +
                               '</div>';
                    }).join('');
                    pResults.style.display = 'block';
                    Array.from(pResults.querySelectorAll('.dr2-prop-row')).forEach(el => {
                        el.addEventListener('mouseover', () => el.style.background = '#f9fafb');
                        el.addEventListener('mouseout', () => el.style.background = '#fff');
                        el.addEventListener('click', () => pickProperty(el.dataset));
                        el.addEventListener('keydown', e => { if (e.key === 'Enter') pickProperty(el.dataset); });
                    });
                })
                .catch(closeResults);
        }, 220);

        pSearch.addEventListener('input', runPropSearch);
        pSearch.addEventListener('focus', runPropSearch);
        document.addEventListener('click', e => { if (!e.target.closest('#dr2-property-picker')) closeResults(); });

        function pickProperty(d) {
            pId.value = d.id;
            pAddr.value = d.address;
            pSearch.value = d.address;
            pLinkedId.textContent = '#' + d.id;
            pLinked.style.display = '';
            closeResults();

            // §2.5 selling price prefill — only when empty or user hasn't overridden.
            if (d.price && (!priceEl.value || priceEl.dataset.prefilled === '1')) {
                priceEl.value = Number(d.price);
                priceEl.dataset.prefilled = '1';
                priceHint.style.display = '';
            }
            // §2.6 commission prefill — commission_percent × price (VAT-incl figure), overridable.
            const pct = parseFloat(d.comm);
            const price = parseFloat(d.price);
            if (!isNaN(pct) && !isNaN(price) && (!commEl.value || commEl.dataset.prefilled === '1')) {
                const gross = Math.round(price * (pct / 100) * 100) / 100;
                commEl.value = gross;
                commEl.dataset.prefilled = '1';
                commHint.textContent = 'Prefilled from ' + pct + '% of R ' + money(price) + ' — override if different.';
                commHint.style.display = '';
            }
            // §2.3 seller/buyer from the linked property.
            loadPropertyContacts(d.id);
        }

        // If the user manually edits price/commission, stop treating them as prefilled.
        priceEl.addEventListener('input', () => { priceEl.dataset.prefilled = '0'; });
        commEl.addEventListener('input', () => { commEl.dataset.prefilled = '0'; });

        document.getElementById('dr2_property_unlink').addEventListener('click', function () {
            pId.value = '';
            pLinked.style.display = 'none';
            document.getElementById('dr2_buyer_ticklist').style.display = 'none';
            document.getElementById('dr2_seller_hint').style.display = 'none';
        });

        // ============ §2.3 SELLER / BUYER FROM PROPERTY ============
        const sellerEl   = document.getElementById('dr2_seller_name');
        const sellerHint = document.getElementById('dr2_seller_hint');
        const buyerEl    = document.getElementById('dr2_buyer_name');
        const buyerWrap  = document.getElementById('dr2_buyer_ticklist');
        const buyerOpts  = document.getElementById('dr2_buyer_options');

        function loadPropertyContacts(propertyId) {
            fetch(R.propertyContacts.replace('__ID__', propertyId), { headers: { 'Accept': 'application/json' } })
                .then(r => r.ok ? r.json() : { sellers: [], buyers: [] })
                .then(data => {
                    const sellers = data.sellers || [];
                    const buyers  = data.buyers || [];

                    // Seller: auto-fill only when the field is empty (never clobber a typed name).
                    if (sellers.length && !sellerEl.value.trim()) {
                        sellerEl.value = sellers.map(s => s.name).filter(Boolean).join(', ');
                    }
                    if (sellers.length) {
                        sellerHint.textContent = 'From the linked property: ' + sellers.map(s => s.name).join(', ');
                        sellerHint.style.display = '';
                    } else {
                        sellerHint.style.display = 'none';
                    }

                    // Buyer: tick-list of linked buyers (input-space: none → keep the free-text box).
                    if (buyers.length) {
                        buyerOpts.innerHTML = buyers.map(b =>
                            '<label class="inline-flex items-center gap-2 text-sm mr-3">' +
                            '<input type="checkbox" class="dr2-buyer-opt" data-name="' + String(b.name || '').replace(/"/g, '&quot;') + '">' +
                            '<span>' + (b.name || 'Contact #' + b.id) + '</span></label>'
                        ).join('');
                        buyerWrap.style.display = '';
                        Array.from(buyerOpts.querySelectorAll('.dr2-buyer-opt')).forEach(cb => {
                            cb.addEventListener('change', syncBuyers);
                        });
                    } else {
                        buyerWrap.style.display = 'none';
                    }
                })
                .catch(() => {});
        }

        function syncBuyers() {
            const picked = Array.from(buyerOpts.querySelectorAll('.dr2-buyer-opt:checked'))
                .map(cb => cb.dataset.name).filter(Boolean);
            if (picked.length) buyerEl.value = picked.join(', ');
        }

        // Edit mode: if the deal is already linked, load its property's contacts on open.
        if (pId.value) { loadPropertyContacts(pId.value); }

        // ============ §2.4 ATTORNEY SUPPLIER PICKER ============
        const aSearch  = document.getElementById('dr2_attorney_search');
        const aResults = document.getElementById('dr2_attorney_results');
        const aName    = document.getElementById('dr2_attorney_name');

        aSearch.addEventListener('input', () => { aName.value = aSearch.value; });

        function closeAtt() { aResults.style.display = 'none'; aResults.innerHTML = ''; }

        const runAttSearch = debounce(function () {
            const q = aSearch.value.trim();
            if (q.length < 2) { closeAtt(); return; }
            fetch(R.suppliers + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } })
                .then(r => r.ok ? r.json() : { results: [] })
                .then(data => {
                    const rows = (data && data.results) || [];
                    if (!rows.length) { closeAtt(); return; }
                    aResults.innerHTML = rows.map(row => {
                        const sub = [row.company, row.email, row.phone].filter(Boolean).join(' · ');
                        return '<div class="dr2-att-row" data-name="' + String(row.name || '').replace(/"/g, '&quot;') + '"' +
                               ' style="padding:.6rem .8rem;cursor:pointer;border-bottom:1px solid #f3f4f6;">' +
                               '<div style="font-weight:600;color:#0b2a4a;">' + (row.name || '') + '</div>' +
                               (sub ? '<div style="font-size:.8rem;color:#6b7280;">' + sub + '</div>' : '') +
                               '</div>';
                    }).join('');
                    aResults.style.display = 'block';
                    Array.from(aResults.querySelectorAll('.dr2-att-row')).forEach(el => {
                        el.addEventListener('mouseover', () => el.style.background = '#f9fafb');
                        el.addEventListener('mouseout', () => el.style.background = '#fff');
                        el.addEventListener('click', () => { aName.value = el.dataset.name; aSearch.value = el.dataset.name; closeAtt(); });
                    });
                })
                .catch(closeAtt);
        }, 220);

        aSearch.addEventListener('input', runAttSearch);
        aSearch.addEventListener('focus', runAttSearch);
        document.addEventListener('click', e => { if (!e.target.closest('#dr2-attorney-picker')) closeAtt(); });

        // ---- add-new-inline modal ----
        const modal   = document.getElementById('dr2_attorney_modal');
        const mName    = document.getElementById('dr2_new_att_name');
        const mCompany = document.getElementById('dr2_new_att_company');
        const mEmail   = document.getElementById('dr2_new_att_email');
        const mPhone   = document.getElementById('dr2_new_att_phone');
        const mError   = document.getElementById('dr2_new_att_error');

        function openModal() {
            mName.value = aSearch.value.trim();
            mCompany.value = mEmail.value = mPhone.value = '';
            mError.style.display = 'none';
            modal.style.display = 'flex';
            mName.focus();
        }
        function closeModal() { modal.style.display = 'none'; }

        document.getElementById('dr2_attorney_addnew').addEventListener('click', openModal);
        document.getElementById('dr2_new_att_cancel').addEventListener('click', closeModal);
        modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

        document.getElementById('dr2_new_att_save').addEventListener('click', function () {
            const name = mName.value.trim();
            if (!name) { mError.textContent = 'A name or firm is required.'; mError.style.display = 'block'; return; }
            mError.style.display = 'none';
            this.disabled = true;
            fetch(R.supplierInline, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({
                    name: name,
                    company: mCompany.value.trim() || null,
                    email: mEmail.value.trim() || null,
                    phone: mPhone.value.trim() || null,
                    specialty: 'transfer_attorney',
                }),
            })
            .then(r => r.json().then(j => ({ ok: r.ok, j })))
            .then(({ ok, j }) => {
                if (!ok) {
                    const msg = j && j.message ? j.message : 'Could not save the attorney.';
                    mError.textContent = msg; mError.style.display = 'block'; return;
                }
                const prov = (j && j.provider) || {};
                aName.value = prov.name || name;
                aSearch.value = prov.name || name;
                closeModal();
            })
            .catch(() => { mError.textContent = 'Network error — please try again.'; mError.style.display = 'block'; })
            .finally(() => { this.disabled = false; });
        });

        // ============ §2.8 EXTERNAL toggles ============
        ['listing', 'selling'].forEach(side => {
            const cb = document.getElementById(side + '_external');
            const fields = document.getElementById(side + '_external_fields');
            if (cb && fields) {
                cb.addEventListener('change', () => { fields.style.display = cb.checked ? '' : 'none'; });
            }
        });

        // ============ §2.7 SIDES / SPLITS / AGENTS — DR1 logic verbatim ============
        function syncSelected(selectEl, containerEl, sideName, initialPercents) {
            const selectedIds = Array.from(selectEl.selectedOptions).map(o => o.value);
            Array.from(containerEl.querySelectorAll('[data-user-id]')).forEach(row => {
                if (!selectedIds.includes(row.getAttribute('data-user-id'))) row.remove();
            });
            selectedIds.forEach(id => {
                if (containerEl.querySelector('[data-user-id="' + id + '"]')) return;
                const opt = selectEl.querySelector('option[value="' + id + '"]');
                const label = opt ? opt.textContent : ('User ' + id);
                const initial = (initialPercents && (id in initialPercents)) ? initialPercents[id] : '';
                const row = document.createElement('div');
                row.className = 'flex items-center gap-3';
                row.setAttribute('data-user-id', id);
                row.innerHTML =
                    '<input type="hidden" name="' + sideName + '_agents[]" value="' + id + '">' +
                    '<div class="w-48 font-semibold" style="color:var(--text-primary, #0b2a4a)">' + label + '</div>' +
                    '<input type="number" step="0.01" name="' + sideName + '_override[' + id + ']" placeholder="% override" class="w-32 rounded-lg border-gray-200" value="' + (initial ?? '') + '">' +
                    '<button type="button" class="text-xs text-red-600">Remove</button>';
                row.querySelector('button').addEventListener('click', () => {
                    Array.from(selectEl.options).forEach(o => { if (o.value === id) o.selected = false; });
                    row.remove();
                });
                containerEl.appendChild(row);
            });
            const allRows = containerEl.querySelectorAll('[data-user-id]');
            if (allRows.length === 1) {
                const input = allRows[0].querySelector('input[type=number]');
                if (input && !input.value) input.value = '100';
            } else if (allRows.length > 1) {
                allRows.forEach(r => { const input = r.querySelector('input[type=number]'); if (input && input.value === '100') input.value = ''; });
            }
        }

        const listingSelect = document.getElementById('listing_select');
        const sellingSelect = document.getElementById('selling_select');
        const listingSelected = document.getElementById('listing_selected');
        const sellingSelected = document.getElementById('selling_selected');
        const listingPercents = @json($listingPercents);
        const sellingPercents = @json($sellingPercents);

        syncSelected(listingSelect, listingSelected, 'listing', listingPercents);
        syncSelected(sellingSelect, sellingSelected, 'selling', sellingPercents);
        listingSelect.addEventListener('change', () => syncSelected(listingSelect, listingSelected, 'listing', listingPercents));
        sellingSelect.addEventListener('change', () => syncSelected(sellingSelect, sellingSelected, 'selling', sellingPercents));

        const lNum = document.getElementById('listing_split_percent');
        const sNum = document.getElementById('selling_split_percent');
        const lSl  = document.getElementById('listing_split_slider');
        const sSl  = document.getElementById('selling_split_slider');
        const lLab = document.getElementById('listing_split_label');
        const sLab = document.getElementById('selling_split_label');

        function clamp(v){ v = parseFloat(v); return isNaN(v) ? 0 : Math.max(0, Math.min(100, v)); }
        function fmt(v){ return (Math.round(v * 100) / 100).toFixed(2) + '%'; }
        function setLabels(l, s){ if (lLab) lLab.textContent = fmt(l); if (sLab) sLab.textContent = fmt(s); }
        function syncFromListing(v){ const l = clamp(v); const sell = Math.round((100 - l) * 100) / 100; if (lNum) lNum.value = l; if (lSl) lSl.value = l; if (sNum) sNum.value = sell; if (sSl) sSl.value = sell; setLabels(l, sell); }
        function syncFromSelling(v){ const sell = clamp(v); const l = Math.round((100 - sell) * 100) / 100; if (sNum) sNum.value = sell; if (sSl) sSl.value = sell; if (lNum) lNum.value = l; if (lSl) lSl.value = l; setLabels(l, sell); }

        if (lNum && sNum && lSl && sSl) {
            syncFromListing(clamp(lNum.value || lSl.value));
            lSl.addEventListener('input', e => syncFromListing(e.target.value));
            sSl.addEventListener('input', e => syncFromSelling(e.target.value));
            lNum.addEventListener('input', e => syncFromListing(e.target.value));
            sNum.addEventListener('input', e => syncFromSelling(e.target.value));
        }

        [listingSelect, sellingSelect].forEach(el => {
            el.addEventListener('wheel', function (e) {
                const atTop = this.scrollTop === 0;
                const atBottom = this.scrollTop + this.clientHeight >= this.scrollHeight - 1;
                if ((e.deltaY < 0 && atTop) || (e.deltaY > 0 && atBottom)) { e.preventDefault(); window.scrollBy({ top: e.deltaY, behavior: 'auto' }); }
            }, { passive: false });
        });
    })();
    </script>
</x-app-layout>
