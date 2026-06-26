<x-app-layout>
    <x-slot name="header">
        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">{{ $mode === 'create' ? 'Add Deal' : 'Edit Deal' }}</h2>
                    <div class="text-sm text-white/60">Capture the deal accurately so settlement + rollups reconcile end-to-end.</div>
                </div>
                <a href="{{ route('admin.deals') }}"
                   class="inline-flex items-center rounded-xl bg-white/10 px-4 py-2 text-sm font-semibold text-white ring-1 ring-white/20 hover:bg-white/15">
                    &larr; Back to Deal Register
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
        // PHP 8.4-safe (no nested ternary)
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
                ->pluck('id')
                ->map(fn($v) => (string)$v)
                ->values()
                ->all();
        }

        if (is_array($oldSellingAgents)) {
            $sellingSelectedIds = array_map('strval', $oldSellingAgents);
        } elseif ($deal->exists) {
            $sellingSelectedIds = $deal->agents
                ->filter(fn($a) => $a->pivot?->side === 'selling')
                ->pluck('id')
                ->map(fn($v) => (string)$v)
                ->values()
                ->all();
        }

        // When errors exist, percents should be blank (intentional UX).
        $listingPercents = [];
        $sellingPercents = [];

        if (!$hasErrors && $deal->exists) {
            $listingPercents = $deal->agents
                ->filter(fn($a) => $a->pivot?->side === 'listing')
                ->mapWithKeys(fn($a) => [(string)$a->id => $a->pivot->agent_split_percent])
                ->toArray();

            $sellingPercents = $deal->agents
                ->filter(fn($a) => $a->pivot?->side === 'selling')
                ->mapWithKeys(fn($a) => [(string)$a->id => $a->pivot->agent_split_percent])
                ->toArray();
        }
    @endphp

    <div class="page-wrap">

    <div class="space-y-6">

<form method="POST" action="{{ $mode === 'create' ? url('/admin/deals') : route('admin.deals.update', $deal) }}" class="space-y-6">
        @csrf

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
            {{-- Admin Multi-Branch Manager — the manager named on this deal.
                 Captured at registration when an admin acts as the branch's
                 manager; otherwise resolved from the branch_manager role. --}}
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

            <div class="field-full">
                <label class="ds-label block mb-1">Property Address</label>
                <input type="text" name="property_address" class="w-full" value="{{ old('property_address', $deal->property_address) }}">
            </div>

            <div>
                <label class="ds-label block mb-1">Seller</label>
                <input type="text" name="seller_name" value="{{ old('seller_name', $deal->seller_name) }}">
            </div>

            <div>
                <label class="ds-label block mb-1">Buyer</label>
                <input type="text" name="buyer_name" value="{{ old('buyer_name', $deal->buyer_name) }}">
            </div>

            <div class="field-full">
                <label class="ds-label block mb-1">Attorney</label>
                <input type="text" name="attorney_name" class="w-full" value="{{ old('attorney_name', $deal->attorney_name) }}">
            </div>

            <div>
                <label class="ds-label block mb-1">Selling Price</label>
                <input type="number" step="0.01" class="input-base money-input" name="property_value" value="{{ old('property_value', $deal->property_value) }}" required>
            </div>

            <div>
                <label class="ds-label block mb-1">Total Commission (Incl VAT)</label>
                <input type="number" step="0.01" class="input-base money-input" name="total_commission" value="{{ old('total_commission', $deal->total_commission) }}" required>
                <div class="mt-1 text-xs text-gray-500">Internal pools/allocations are calculated <span class="font-semibold">Ex VAT</span> (VAT is tracked separately).</div>
            </div>
                </div>
            </div>
        </div>

        {{-- Status & Registration --}}
        <div>
            <h2 class="ds-section-header">Status & Registration</h2>
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

        {{-- Sides, splits & agents --}}
        <div>
            <h2 class="ds-section-header">Sides, Splits & Agents</h2>
            <div class="ds-section-sub mb-4">Set external / our share and lock listing + selling split to total 100%.</div>

            <div class="ds-status-card">
                <div class="deal-grid pt-4">
            <!-- LISTING -->
            <div>
                <h3 class="font-bold" style="color:#0b2a4a">Listing Side</h3>

                <div class="flex items-center gap-3">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="listing_external" id="listing_external" {{ old('listing_external', $deal->listing_external) ? 'checked' : '' }}>
                        External
                    </label>

                    <input type="number" step="0.01" name="listing_our_share_percent" value="{{ old('listing_our_share_percent', $deal->listing_our_share_percent) }}" class="w-36" placeholder="Our Share %">
                    <div class="w-64">
                        <div class="flex items-center justify-between">
                            <div class="ds-label">Listing split %</div>
                            <div class="text-xs text-gray-500"><span id="listing_split_label">—</span> / <span id="selling_split_label">—</span></div>
                        </div>
                        <div class="mt-2 flex items-center gap-3">
                            <input id="listing_split_percent" type="number" step="0.01" name="listing_split_percent"
                                   value="{{ old('listing_split_percent', $deal->listing_split_percent ?? 50) }}"
                                   class="w-24 rounded-lg border-gray-200" placeholder="%">
                            <input id="listing_split_slider" type="range" min="0" max="100" step="0.01"
                                   class="flex-1" value="{{ old('listing_split_percent', $deal->listing_split_percent ?? 50) }}">
                        </div>
                    </div>
                    <input type="text" name="listing_external_agency" placeholder="External Agency (if external)" class="flex-1" value="{{ old('listing_external_agency', $deal->listing_external_agency) }}">
                </div>

                <div class="mt-3 space-y-3">
                    <div>
                        <label class="ds-label block mb-1">Listing Agents</label>
                        <select id="listing_select" class="multi-select" multiple size="6">
                            @foreach($agents as $agent)
                                <option value="{{ $agent->id }}" {{ in_array((string)$agent->id, $listingSelectedIds, true) ? 'selected' : '' }}>
                                    {{ $agent->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="text-xs text-gray-500 mt-1">Hold Ctrl / Cmd to select multiple.</div>
                    </div>

                    <div id="listing_selected" class="space-y-2"></div>
                </div>
            </div>

            <!-- SELLING -->
            <div>
                <h3 class="font-bold" style="color:#0b2a4a">Selling Side</h3>

                <div class="flex items-center gap-3">
                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="selling_external" id="selling_external" {{ old('selling_external', $deal->selling_external) ? 'checked' : '' }}>
                        External
                    </label>

                    <input type="number" step="0.01" name="selling_our_share_percent" value="{{ old('selling_our_share_percent', $deal->selling_our_share_percent) }}" class="w-36" placeholder="Our Share %">
                    <div class="w-64">
                        <div class="ds-label">Selling split %</div>
                        <div class="mt-2 flex items-center gap-3">
                            <input id="selling_split_percent" type="number" step="0.01" name="selling_split_percent"
                                   value="{{ old('selling_split_percent', $deal->selling_split_percent ?? 50) }}"
                                   class="w-24 rounded-lg border-gray-200" placeholder="%">
                            <input id="selling_split_slider" type="range" min="0" max="100" step="0.01"
                                   class="flex-1" value="{{ old('selling_split_percent', $deal->selling_split_percent ?? 50) }}">
                        </div>
                    </div>
                    <input type="text" name="selling_external_agency" placeholder="External Agency (if external)" class="flex-1" value="{{ old('selling_external_agency', $deal->selling_external_agency) }}">
                </div>

                <div class="mt-3 space-y-3">
                    <div>
                        <label class="ds-label block mb-1">Selling Agents</label>
                        <select id="selling_select" class="multi-select" multiple size="6">
                            @foreach($agents as $agent)
                                <option value="{{ $agent->id }}" {{ in_array((string)$agent->id, $sellingSelectedIds, true) ? 'selected' : '' }}>
                                    {{ $agent->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="text-xs text-gray-500 mt-1">Hold Ctrl / Cmd to select multiple.</div>
                    </div>

                    <div id="selling_selected" class="space-y-2"></div>
                </div>
            </div>
        </div>

            </div>
        </div>


        <div class="flex items-center justify-end">
            <button type="submit"
                    class="corex-btn-primary px-5 py-2.5 text-sm">
                {{ $mode === 'create' ? 'Save Deal' : 'Update Deal' }}
            </button>
        </div>

        <script>
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

                    row.innerHTML = `
                        <input type="hidden" name="${sideName}_agents[]" value="${id}">
                        <div class="w-48 font-semibold" style="color:var(--text-primary, #0b2a4a)">${label}</div>
                        <input type="number" step="0.01" name="${sideName}_override[${id}]" placeholder="% override" class="w-32 rounded-lg border-gray-200" value="${initial ?? ''}">
                        <button type="button" class="text-xs text-red-600">Remove</button>
                    `;

                    row.querySelector('button').addEventListener('click', () => {
                        Array.from(selectEl.options).forEach(o => {
                            if (o.value === id) o.selected = false;
                        });
                        row.remove();
                    });

                    containerEl.appendChild(row);
                });

                // Auto-fill 100% when single agent selected
                const allRows = containerEl.querySelectorAll('[data-user-id]');
                if (allRows.length === 1) {
                    const input = allRows[0].querySelector('input[type=number]');
                    if (input && !input.value) input.value = '100';
                } else if (allRows.length > 1) {
                    allRows.forEach(r => {
                        const input = r.querySelector('input[type=number]');
                        if (input && input.value === '100') input.value = '';
                    });
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

            listingSelect.addEventListener('change', () => {
                console.log('[DealForm] Listing agent selection changed', Array.from(listingSelect.selectedOptions).map(o => o.value));
                syncSelected(listingSelect, listingSelected, 'listing', listingPercents);
            });
            sellingSelect.addEventListener('change', () => {
                console.log('[DealForm] Selling agent selection changed', Array.from(sellingSelect.selectedOptions).map(o => o.value));
                syncSelected(sellingSelect, sellingSelected, 'selling', sellingPercents);
            });

            console.log('[DealForm] Agent selection initialized', {
                listingSelect: !!listingSelect,
                sellingSelect: !!sellingSelect,
                listingSelected: !!listingSelected,
                sellingSelected: !!sellingSelected,
            });


            // Side split sliders: keep listing + selling = 100.00 (UI convenience only; server validates truth)
            const lNum = document.getElementById('listing_split_percent');
            const sNum = document.getElementById('selling_split_percent');
            const lSl  = document.getElementById('listing_split_slider');
            const sSl  = document.getElementById('selling_split_slider');
            const lLab = document.getElementById('listing_split_label');
            const sLab = document.getElementById('selling_split_label');

            function clamp(v){ v = parseFloat(v); return isNaN(v) ? 0 : Math.max(0, Math.min(100, v)); }
            function fmt(v){ return (Math.round(v * 100) / 100).toFixed(2) + '%'; }

            function setLabels(l, s){
                if (lLab) lLab.textContent = fmt(l);
                if (sLab) sLab.textContent = fmt(s);
            }

            function syncFromListing(v){
                const l = clamp(v);
                const sell = Math.round((100 - l) * 100) / 100;
                if (lNum) lNum.value = l;
                if (lSl)  lSl.value  = l;
                if (sNum) sNum.value = sell;
                if (sSl)  sSl.value  = sell;
                setLabels(l, sell);
            }

            function syncFromSelling(v){
                const sell = clamp(v);
                const l = Math.round((100 - sell) * 100) / 100;
                if (sNum) sNum.value = sell;
                if (sSl)  sSl.value  = sell;
                if (lNum) lNum.value = l;
                if (lSl)  lSl.value  = l;
                setLabels(l, sell);
            }

            if (lNum && sNum && lSl && sSl) {
                // init
                const initL = clamp(lNum.value || lSl.value);
                syncFromListing(initL);

                lSl.addEventListener('input', e => syncFromListing(e.target.value));
                sSl.addEventListener('input', e => syncFromSelling(e.target.value));

                lNum.addEventListener('input', e => syncFromListing(e.target.value));
                sNum.addEventListener('input', e => syncFromSelling(e.target.value));
            }


                        // Prevent multi-select scroll hijacking page scroll
            [listingSelect, sellingSelect].forEach(el => {
                el.addEventListener('wheel', function(e) {
                    const atTop = this.scrollTop === 0;
                    const atBottom = this.scrollTop + this.clientHeight >= this.scrollHeight - 1;
                    if ((e.deltaY < 0 && atTop) || (e.deltaY > 0 && atBottom)) {
                        e.preventDefault();
                        window.scrollBy({ top: e.deltaY, behavior: 'auto' });
                    }
                }, { passive: false });
            });
        </script>
    </form>

    </div>

</div>
</x-app-layout>
