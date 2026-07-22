<x-app-layout>
    <x-slot name="header">
        <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h2 class="text-xl font-bold text-white leading-tight">{{ $mode === 'create' ? 'Add Deal' : 'Edit Deal' }}</h2>
                    <div class="text-sm text-white/60">Capture the deal accurately so settlement + rollups reconcile end-to-end.</div>
                </div>
                <a href="{{ route('deals-dr2.index') }}"
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

    @include('dr2.partials._grant-conflict-modal')

    <div class="space-y-6">

<form method="POST" action="{{ $mode === 'create' ? route('deals-dr2.store') : route('deals-dr2.update', $deal) }}" class="space-y-6">
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

            {{-- (Enhancement 6) Deal Type — compulsory radios, NO default (silent default = silent wrong data) --}}
            <div class="field-full">
                <label class="ds-label block mb-1">Deal Type <span style="color:#dc2626;">*</span></label>
                @php $dt = old('deal_type', $deal->deal_type); @endphp
                <div class="flex flex-wrap gap-4 pt-1">
                    @foreach(['bond' => 'Bond Sale', 'cash' => 'Cash Sale', 'sale_of_2nd' => 'Sale of 2nd Property'] as $val => $lbl)
                    <label class="inline-flex items-center gap-2">
                        <input type="radio" name="deal_type" value="{{ $val }}" {{ $dt === $val ? 'checked' : '' }} required>
                        <span>{{ $lbl }}</span>
                    </label>
                    @endforeach
                </div>
            </div>

            {{-- AT-334 — Pipeline. Composition (Deal Structure) is now the DEFAULT: a new deal
                 starts with NO template, so it lands with zero steps and the Deal Structure tab
                 drives the build (pick conditions → Build). A standard template is an advanced/
                 legacy choice — pick one here to attach it instead. NO auto-select on deal_type
                 (the old JS that forced the deal_type's default template is removed). --}}
            @if(($mode ?? 'create') === 'create' && isset($availableTemplates) && $availableTemplates->isNotEmpty())
            <div class="field-full">
                <label class="ds-label block mb-1">Pipeline</label>
                <select name="pipeline_template_id" id="dr2-pipeline-select" class="ds-input w-full">
                    <option value="">— None — build from Deal Structure (default) —</option>
                    @foreach($availableTemplates as $tpl)
                        <option value="{{ $tpl->id }}" {{ (string) old('pipeline_template_id') === (string) $tpl->id ? 'selected' : '' }}>{{ $tpl->name }} · {{ $tpl->deal_type }}{{ $tpl->is_default ? ' (default)' : '' }}</option>
                    @endforeach
                </select>
                <p class="text-xs mt-1" style="color: var(--text-muted);">Leave as “None” to build the pipeline from the Deal Structure tab (recommended). Or pick a standard template to attach one instead.</p>
            </div>
            @endif

            {{-- (Enhancement 1) Property — rich searchable picker matching the PDF splitter --}}
            <div class="field-full" id="dr2-prop">
                <label class="ds-label block mb-1">Property</label>
                <input type="hidden" name="property_id" id="dr2_property_id" value="{{ old('property_id', $deal->property_id) }}">
                <div style="position:relative;">
                    <input type="text" id="dr2_property_search" class="w-full" autocomplete="off"
                           placeholder="Search a property by address, reference, complex…"
                           value="{{ old('property_address', ($deal->property ? $deal->property->buildDisplayAddress() : $deal->property_address)) }}">
                    <div id="dr2_property_results" style="position:absolute;z-index:40;left:0;right:0;top:100%;background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);max-height:16rem;overflow:auto;display:none;"></div>
                </div>
                <input type="hidden" name="property_address" id="dr2_property_address" value="{{ old('property_address', $deal->property_address) }}">
                <div id="dr2_property_linked" class="text-xs mt-1" style="{{ old('property_id', $deal->property_id) ? '' : 'display:none;' }}color:#047857;">✓ Linked to property <span id="dr2_property_linked_id">#{{ old('property_id', $deal->property_id) }}</span> <button type="button" id="dr2_property_unlink" class="underline text-gray-500 ml-1">unlink</button></div>
                <div class="flex items-center justify-between mt-1">
                    <div class="text-xs text-gray-400">No CoreX match? Type the address — the deal still saves.</div>
                    {{-- Wave 2 resale guard — the search shows on-market listings by default so a
                         renovated-and-relisted address steers to the LIVE record, not its sold twin. --}}
                    <label class="text-[11px] flex items-center gap-1 cursor-pointer" style="color:var(--text-muted,#6b7280);">
                        <input type="checkbox" id="dr2_property_showall" class="w-3 h-3"> Show sold/archived too
                    </label>
                </div>
            </div>

            {{-- (Enhancement 2 + DR2 party picker) Seller — property tick-list (fast
                 path) + full contact search + add-new. Linking here also creates the
                 property↔contact SELLER link (one action, both records). --}}
            <div id="dr2-seller">
                <label class="ds-label block mb-1">Seller</label>
                <input type="hidden" name="seller_contact_ids" id="dr2_seller_ids" value="{{ old('seller_contact_ids', collect($sellerParties ?? [])->pluck('id')->implode(',')) }}">
                <input type="text" name="seller_name" id="dr2_seller_name" value="{{ old('seller_name', $deal->seller_name) }}" placeholder="Seller name(s)">
                <div id="dr2_seller_tokens" class="mt-1 flex flex-wrap gap-1.5"></div>
                <div style="position:relative;">
                    <input type="text" id="dr2_seller_search" class="w-full mt-1" autocomplete="off" placeholder="Search a contact to link as seller…">
                    <div id="dr2_seller_results" style="position:absolute;z-index:40;left:0;right:0;top:100%;background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);max-height:16rem;overflow:auto;display:none;"></div>
                </div>
                <div id="dr2_seller_offer" class="mt-1 flex flex-wrap gap-1.5" style="display:none;"></div>
                <div class="mt-1"><button type="button" class="dr2-addnew text-xs underline text-gray-500" data-kind="seller">＋ Add a new contact</button></div>
                <div id="dr2_seller_newform" class="mt-1" style="display:none;"></div>
            </div>

            {{-- (Enhancement 2 + DR2 party picker) Buyer — same component as the seller;
                 the tick-list is the fast path, search is the universal path. Linking
                 creates the property↔contact BUYER link. --}}
            <div id="dr2-buyer">
                <label class="ds-label block mb-1">Buyer</label>
                <input type="hidden" name="buyer_contact_ids" id="dr2_buyer_ids" value="{{ old('buyer_contact_ids', collect($buyerParties ?? [])->pluck('id')->implode(',')) }}">
                <input type="text" name="buyer_name" id="dr2_buyer_name" value="{{ old('buyer_name', $deal->buyer_name) }}" placeholder="Buyer name(s)">
                <div id="dr2_buyer_tokens" class="mt-1 flex flex-wrap gap-1.5"></div>
                <div style="position:relative;">
                    <input type="text" id="dr2_buyer_search" class="w-full mt-1" autocomplete="off" placeholder="Search a contact to link as buyer…">
                    <div id="dr2_buyer_results" style="position:absolute;z-index:40;left:0;right:0;top:100%;background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);max-height:16rem;overflow:auto;display:none;"></div>
                </div>
                <div id="dr2_buyer_offer" class="mt-1 flex flex-wrap gap-1.5" style="display:none;"></div>
                <div class="mt-1"><button type="button" class="dr2-addnew text-xs underline text-gray-500" data-kind="buyer">＋ Add a new contact</button></div>
                <div id="dr2_buyer_newform" class="mt-1" style="display:none;"></div>
            </div>

            {{-- (Enhancement 3 / walk fix 2) Attorney = FIRM + contact person. Search a
                 firm's people, or add a new firm+contact inline. The deal links both. --}}
            <div class="field-full" id="dr2-att">
                <label class="ds-label block mb-1">Attorney (firm &amp; contact)</label>
                <input type="hidden" name="attorney_name" id="dr2_attorney_name" value="{{ old('attorney_name', $deal->attorney_name) }}">
                <input type="hidden" name="attorney_provider_id" id="dr2_attorney_provider_id" value="{{ old('attorney_provider_id', $deal->attorney_provider_id) }}">
                <input type="hidden" name="attorney_contact_id" id="dr2_attorney_contact_id" value="{{ old('attorney_contact_id', $deal->attorney_contact_id) }}">
                <div style="position:relative;">
                    <input type="text" id="dr2_attorney_search" class="w-full" autocomplete="off" placeholder="Search a firm or attorney (e.g. BBB Inc, or the attorney's name)…" value="{{ old('attorney_name', $deal->attorney_name) }}">
                    <div id="dr2_attorney_results" style="position:absolute;z-index:40;left:0;right:0;top:100%;background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);max-height:16rem;overflow:auto;display:none;"></div>
                </div>
                <button type="button" id="dr2_attorney_addnew" class="text-xs text-blue-600 underline mt-1">+ Add a new attorney (firm &amp; contact)</button>
            </div>

            {{-- AT-228 — Bond Originator (firm & contact), same picker UX as the attorney field. --}}
            <div class="field-full" id="dr2-bond">
                <label class="ds-label block mb-1">Bond originator (firm &amp; contact)</label>
                <input type="hidden" name="bond_originator_provider_id" id="dr2_bond_provider_id" value="{{ old('bond_originator_provider_id', $deal->bond_originator_provider_id) }}">
                <input type="hidden" name="bond_originator_contact_id" id="dr2_bond_contact_id" value="{{ old('bond_originator_contact_id', $deal->bond_originator_contact_id) }}">
                <div style="position:relative;">
                    <input type="text" id="dr2_bond_search" class="w-full" autocomplete="off" placeholder="Search a bond originator firm or contact…">
                    <div id="dr2_bond_results" style="position:absolute;z-index:40;left:0;right:0;top:100%;background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);max-height:16rem;overflow:auto;display:none;"></div>
                </div>
                <button type="button" id="dr2_bond_addnew" class="text-xs text-blue-600 underline mt-1">+ Add a new bond originator (firm &amp; contact)</button>
            </div>

            {{-- (Enhancement 7 / walk fix 1+2) Financials — commission with a VAT basis toggle
                 and live two-way % ↔ amount binding. Stored truth stays DR1's (Incl-VAT total);
                 Excl + VAT are DERIVED for display, not forked into storage. --}}
            <div class="field-full"><h3 class="ds-label" style="margin-top:.35rem;font-weight:700;color:#0b2a4a;">Financials</h3></div>

            {{-- (Enhancement 4) Selling Price — prefilled from the advertised price, overridable --}}
            <div>
                <label class="ds-label block mb-1">Selling Price</label>
                <input type="number" step="0.01" class="input-base money-input" name="property_value" id="dr2_property_value" value="{{ old('property_value', $deal->property_value) }}" required>
            </div>

            {{-- VAT basis — what the amount you enter means (agency VAT rate from config) --}}
            <div>
                <label class="ds-label block mb-1">Commission basis</label>
                <select class="input-base" id="dr2_vat_mode">
                    <option value="incl">VAT-inclusive</option>
                    <option value="excl">VAT-exclusive</option>
                </select>
                <div class="mt-1 text-xs text-gray-400">Both figures are shown below either way.</div>
            </div>

            {{-- Commission % — of the selling price; two-way with the amount --}}
            <div>
                <label class="ds-label block mb-1">Commission %</label>
                <input type="number" step="0.01" class="input-base" name="commission_percent_display" id="dr2_commission_percent" value="{{ old('commission_percent_display') }}">
                <div class="mt-1 text-xs text-gray-400">Prefills from the property; two-way with the amount.</div>
            </div>

            {{-- Commission amount in the selected basis — two-way with % --}}
            <div>
                <label class="ds-label block mb-1"><span id="dr2_comm_amount_label">Commission (Incl VAT)</span></label>
                <input type="number" step="0.01" class="input-base money-input" id="dr2_commission_amount" value="">
                <div class="mt-1 text-xs text-gray-400">Fill either % or amount — the other populates live.</div>
            </div>

            {{-- Derived figures + the stored Incl-VAT total (DR1 truth) --}}
            <div class="field-full">
                <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm" style="color:#374151;">
                    <span>Incl VAT: <strong>R <span id="dr2_comm_incl_disp">0.00</span></strong></span>
                    <span>Excl VAT: <strong>R <span id="dr2_comm_excl_disp">0.00</span></strong></span>
                    <span>VAT (<span id="dr2_vat_pct_disp">15</span>%): <strong>R <span id="dr2_comm_vat_disp">0.00</span></strong></span>
                </div>
                <div class="mt-1 text-xs text-gray-500">Stored as the <span class="font-semibold">Incl-VAT total</span> (as DR1 stores it); pools/allocations compute Ex VAT.</div>
                <input type="hidden" name="total_commission" id="dr2_total_commission" value="{{ old('total_commission', $deal->total_commission) }}">
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

                {{-- (Johan DR2-walk fix 1) External-agency layout relaid as a non-colliding
                     responsive stack. The old single flex row crammed the checkbox + our-share
                     + split + agency name together and the labels collided. --}}
                <div class="mt-2 space-y-3">
                    <div>
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

                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="listing_external" id="listing_external" {{ old('listing_external', $deal->listing_external) ? 'checked' : '' }}>
                        <span>External agency handled this side</span>
                    </label>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="ds-label block mb-1">Our Share %</label>
                            <input type="number" step="0.01" name="listing_our_share_percent" class="w-full" value="{{ old('listing_our_share_percent', $deal->listing_our_share_percent) }}" placeholder="Our Share %">
                        </div>
                        <div>
                            <label class="ds-label block mb-1">External Agency</label>
                            <input type="text" name="listing_external_agency" class="w-full" placeholder="External agency name" value="{{ old('listing_external_agency', $deal->listing_external_agency) }}">
                        </div>
                    </div>
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

                {{-- (Johan DR2-walk fix 1) External-agency layout — non-colliding responsive stack, selling side. --}}
                <div class="mt-2 space-y-3">
                    <div>
                        <div class="ds-label">Selling split %</div>
                        <div class="mt-2 flex items-center gap-3">
                            <input id="selling_split_percent" type="number" step="0.01" name="selling_split_percent"
                                   value="{{ old('selling_split_percent', $deal->selling_split_percent ?? 50) }}"
                                   class="w-24 rounded-lg border-gray-200" placeholder="%">
                            <input id="selling_split_slider" type="range" min="0" max="100" step="0.01"
                                   class="flex-1" value="{{ old('selling_split_percent', $deal->selling_split_percent ?? 50) }}">
                        </div>
                    </div>

                    <label class="inline-flex items-center gap-2">
                        <input type="checkbox" name="selling_external" id="selling_external" {{ old('selling_external', $deal->selling_external) ? 'checked' : '' }}>
                        <span>External agency handled this side</span>
                    </label>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="ds-label block mb-1">Our Share %</label>
                            <input type="number" step="0.01" name="selling_our_share_percent" class="w-full" value="{{ old('selling_our_share_percent', $deal->selling_our_share_percent) }}" placeholder="Our Share %">
                        </div>
                        <div>
                            <label class="ds-label block mb-1">External Agency</label>
                            <input type="text" name="selling_external_agency" class="w-full" placeholder="External agency name" value="{{ old('selling_external_agency', $deal->selling_external_agency) }}">
                        </div>
                    </div>
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


            // External-agency auto-tick: typing an external-agency NAME on a side
            // ticks that side's "External agency handled this side" box for you.
            // Un-tick is deliberately non-destructive — we only clear a box that
            // THIS logic ticked, that was NOT already checked when the page loaded
            // (i.e. not a saved-external deal), and that the user did not tick
            // themselves. Clearing the name in those safe cases un-ticks the box.
            ['listing', 'selling'].forEach(side => {
                const nameEl = document.querySelector(`input[name="${side}_external_agency"]`);
                const boxEl  = document.getElementById(`${side}_external`);
                if (!nameEl || !boxEl) return;

                const wasCheckedOnLoad = boxEl.checked;
                let autoTicked = false;

                // The user taking manual control of the box wins — stop auto-managing.
                boxEl.addEventListener('change', () => { autoTicked = false; });

                const apply = () => {
                    const hasName = nameEl.value.trim() !== '';
                    if (hasName && !boxEl.checked) {
                        boxEl.checked = true;   // programmatic — does not fire 'change'
                        autoTicked = true;
                    } else if (!hasName && boxEl.checked && autoTicked && !wasCheckedOnLoad) {
                        boxEl.checked = false;
                        autoTicked = false;
                    }
                };

                nameEl.addEventListener('input', apply);
                nameEl.addEventListener('blur', apply);
                apply(); // reflect any pre-filled name (old()/edit repopulation)
            });
        </script>
    </form>

    </div>

</div>

{{-- (walk fix 2) Add-new attorney inline modal — a FIRM + a contact person.
     Field order per Johan: Firm, Attorney, Contact, Email, Address. --}}
<div id="dr2_att_modal" style="display:none;position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.4);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:.75rem;max-width:34rem;width:92%;padding:1.5rem;">
        <h3 class="font-bold mb-1" style="color:#0b2a4a">Add a new attorney</h3>
        <p class="text-xs text-gray-500 mb-3">A firm can have several people — add the attorney and the person you actually deal with.</p>
        <div class="deal-grid">
            <div class="field-full"><label class="ds-label block mb-1">Firm *</label><input type="text" id="dr2_na_firm" class="w-full" placeholder="e.g. BBB Inc"></div>
            <div><label class="ds-label block mb-1">Attorney</label><input type="text" id="dr2_na_attorney" class="w-full" placeholder="the attorney"></div>
            <div><label class="ds-label block mb-1">Contact</label><input type="text" id="dr2_na_contact" class="w-full" placeholder="assistant / paralegal"></div>
            <div class="field-full"><label class="ds-label block mb-1">Email</label><input type="email" id="dr2_na_email" class="w-full"></div>
            <div class="field-full"><label class="ds-label block mb-1">Address</label><input type="text" id="dr2_na_address" class="w-full"></div>
        </div>
        <div id="dr2_na_error" class="text-sm text-red-600 mt-2" style="display:none;"></div>
        <div class="flex items-center justify-end gap-2 mt-4">
            <button type="button" id="dr2_na_cancel" class="corex-btn-secondary px-4 py-2 text-sm">Cancel</button>
            <button type="button" id="dr2_na_save" class="corex-btn-primary px-4 py-2 text-sm">Save attorney</button>
        </div>
    </div>
</div>

{{-- AT-228 — Add-new bond originator modal (mirror of the attorney add-new) --}}
<div id="dr2_bond_modal" style="display:none;position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.4);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:.75rem;max-width:34rem;width:92%;padding:1.5rem;">
        <h3 class="font-bold mb-1" style="color:#0b2a4a">Add a new bond originator</h3>
        <p class="text-xs text-gray-500 mb-3">A firm can have several people — add the originator and the person you deal with.</p>
        <div class="deal-grid">
            <div class="field-full"><label class="ds-label block mb-1">Firm *</label><input type="text" id="dr2_nb_firm" class="w-full" placeholder="e.g. BetterBond"></div>
            <div><label class="ds-label block mb-1">Originator</label><input type="text" id="dr2_nb_attorney" class="w-full" placeholder="the originator"></div>
            <div><label class="ds-label block mb-1">Contact</label><input type="text" id="dr2_nb_contact" class="w-full" placeholder="assistant"></div>
            <div class="field-full"><label class="ds-label block mb-1">Email</label><input type="email" id="dr2_nb_email" class="w-full"></div>
            <div class="field-full"><label class="ds-label block mb-1">Address</label><input type="text" id="dr2_nb_address" class="w-full"></div>
        </div>
        <div id="dr2_nb_error" class="text-sm text-red-600 mt-2" style="display:none;"></div>
        <div class="flex items-center justify-end gap-2 mt-4">
            <button type="button" id="dr2_nb_cancel" class="corex-btn-secondary px-4 py-2 text-sm">Cancel</button>
            <button type="button" id="dr2_nb_save" class="corex-btn-primary px-4 py-2 text-sm">Save bond originator</button>
        </div>
    </div>
</div>

<script>
(function () {
    const csrf = document.querySelector('input[name="_token"]')?.value
              || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const R = {
        properties: @json(route('deals-dr2.search.properties')),
        propertyContacts: @json(route('deals-dr2.search.property-contacts', ['property' => '__ID__'])),
        contacts: @json(route('deals-dr2.search.contacts')),
        contactInline: @json(route('deals-dr2.contact.inline')),
        attorneySearch: @json(route('deals-dr2.attorney.search')),
        attorneyInline: @json(route('deals-dr2.attorney.inline')),
    };
    const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
    const money = v => { const n = Number(v); return isNaN(n) ? '' : n.toLocaleString('en-ZA'); };
    const esc = s => String(s == null ? '' : s).replace(/"/g, '&quot;');

    // AT-334 — mode + the deal's saved parties (edit), so the picker seeds tokens/hidden ids
    // from deal_contacts and a create-deal auto-tokenizes the property's seller.
    const DR2 = {
        mode: @json($mode ?? 'create'),
        sellerParties: @json($sellerParties ?? []),
        buyerParties: @json($buyerParties ?? []),
    };

    // ---------- Enhancement 1: property picker (splitter-parity rich rows) ----------
    const pSearch = document.getElementById('dr2_property_search');
    const pResults = document.getElementById('dr2_property_results');
    const pId = document.getElementById('dr2_property_id');
    const pAddr = document.getElementById('dr2_property_address');
    const pLinked = document.getElementById('dr2_property_linked');
    const pLinkedId = document.getElementById('dr2_property_linked_id');
    const priceEl = document.getElementById('dr2_property_value');
    const pctEl = document.getElementById('dr2_commission_percent');
    const amtEl = document.getElementById('dr2_commission_amount');      // primary, in the selected basis
    const totalEl = document.getElementById('dr2_total_commission');     // hidden = Incl-VAT total (DR1 stored truth)
    const modeEl = document.getElementById('dr2_vat_mode');
    const vatRate = @json((float) \App\Models\PerformanceSetting::get('vat_rate', 15));
    const inclDisp = document.getElementById('dr2_comm_incl_disp');
    const exclDisp = document.getElementById('dr2_comm_excl_disp');
    const vatDisp = document.getElementById('dr2_comm_vat_disp');
    const amtLabel = document.getElementById('dr2_comm_amount_label');
    document.getElementById('dr2_vat_pct_disp').textContent = vatRate;

    const closeProp = () => { pResults.style.display = 'none'; pResults.innerHTML = ''; };
    pSearch.addEventListener('input', () => { pAddr.value = pSearch.value; });

    const showAllProp = document.getElementById('dr2_property_showall');
    const statusBadge = (row) => {
        const on = row.on_market !== false && row.status !== undefined ? row.on_market !== false : true;
        const label = (row.status || (on ? 'on market' : 'off market')).replace(/_/g, ' ');
        const col = on ? '#065f46' : '#b91c1c';
        const bg  = on ? '#ecfdf5' : '#fef2f2';
        return '<span style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.02em;padding:.05rem .35rem;border-radius:.35rem;color:' + col + ';background:' + bg + ';">' + esc(label) + '</span>';
    };
    const runProp = debounce(() => {
        const q = pSearch.value.trim();
        if (q.length < 2) { closeProp(); return; }
        const url = R.properties + '?q=' + encodeURIComponent(q) + (showAllProp && showAllProp.checked ? '&all=1' : '');
        fetch(url, { headers: { Accept: 'application/json' } })
            .then(r => r.ok ? r.json() : [])
            .then(rows => {
                if (!Array.isArray(rows) || !rows.length) {
                    pResults.innerHTML = '<div style="padding:.6rem .8rem;color:#9ca3af;font-size:.85rem;">No match — type the address to save without a link.</div>';
                    pResults.style.display = 'block'; return;
                }
                pResults.innerHTML = rows.map(row => {
                    const addr = row.address || row.label || ('Property #' + row.id);
                    const sub = [row.ref ? ('Ref ' + row.ref) : '', row.seller ? ('Seller: ' + row.seller) : '', row.agent ? ('Agent: ' + row.agent) : ''].filter(Boolean).join(' · ');
                    const price = (row.price != null && row.price !== '') ? 'R ' + money(row.price) : '';
                    // Wave 2 resale guard — dates row: listed date, and sold date on off-market twins.
                    const dates = [row.listed_date ? ('Listed ' + row.listed_date) : '', row.sold_date ? ('Sold ' + row.sold_date) : ''].filter(Boolean).join(' · ');
                    const offMarket = row.on_market === false;
                    return '<div class="dr2-prow" role="button" tabindex="0" data-id="' + row.id + '" data-address="' + esc(addr) + '" data-price="' + (row.price ?? '') + '" data-comm="' + (row.commission_percent ?? '') + '" data-onmarket="' + (offMarket ? '0' : '1') + '" data-status="' + esc(row.status || '') + '" data-sold="' + esc(row.sold_date || '') + '" style="padding:.6rem .8rem;cursor:pointer;border-bottom:1px solid #f3f4f6;' + (offMarket ? 'background:#fff7f7;' : '') + '">'
                        + '<div style="display:flex;align-items:center;gap:.4rem;"><span style="font-weight:600;color:#0b2a4a;">' + addr + '</span>' + statusBadge(row) + '</div>'
                        + (sub ? '<div style="font-size:.78rem;color:#6b7280;">' + sub + '</div>' : '')
                        + (dates ? '<div style="font-size:.72rem;color:#6b7280;">' + dates + '</div>' : '')
                        + (price ? '<div style="font-size:.78rem;color:#6b7280;">' + price + '</div>' : '') + '</div>';
                }).join('');
                pResults.style.display = 'block';
                pResults.querySelectorAll('.dr2-prow').forEach(el => {
                    el.addEventListener('mouseover', () => el.style.background = el.dataset.onmarket === '0' ? '#fdecec' : '#f9fafb');
                    el.addEventListener('mouseout', () => el.style.background = el.dataset.onmarket === '0' ? '#fff7f7' : '#fff');
                    el.addEventListener('click', () => pickProp(el.dataset));
                });
            }).catch(closeProp);
    }, 220);
    if (showAllProp) showAllProp.addEventListener('change', runProp);
    pSearch.addEventListener('input', runProp);
    pSearch.addEventListener('focus', runProp);
    document.addEventListener('click', e => { if (!e.target.closest('#dr2-prop')) closeProp(); });

    function pickProp(d) {
        // Wave 2 resale guard — a hard WARN before linking a sold/archived record:
        // those never receive status updates from new deals, so it is almost always
        // the wrong record (the agent likely wants the live listing at this address).
        if (d.onmarket === '0') {
            const soldBit = d.sold ? (' was sold on ' + d.sold) : (' is ' + ((d.status || 'off market').replace(/_/g, ' ')));
            if (!confirm('This property record' + soldBit + '. Deals on it will NOT update property/portal statuses. Did you mean the active listing at this address? Click Cancel to keep searching, or OK to link this record anyway.')) {
                return;
            }
        }
        pId.value = d.id; pAddr.value = d.address; pSearch.value = d.address;
        pLinkedId.textContent = '#' + d.id; pLinked.style.display = '';
        closeProp();
        // Enhancement 4: price prefill (only when empty or still prefilled)
        if (d.price && (!priceEl.value || priceEl.dataset.prefilled === '1')) {
            priceEl.value = Number(d.price); priceEl.dataset.prefilled = '1';
        }
        // Enhancement 5: commission % prefill; the amount + Incl/Excl/VAT derive from it.
        if (d.comm && parseFloat(d.comm) > 0 && (!pctEl.value || pctEl.dataset.prefilled === '1')) {
            pctEl.value = parseFloat(d.comm); pctEl.dataset.prefilled = '1';
            recompute('pct');
        } else {
            recompute(pctEl.value ? 'pct' : 'amount');
        }
        loadPropContacts(d.id);
    }
    priceEl.addEventListener('input', () => { priceEl.dataset.prefilled = '0'; recompute(pctEl.value ? 'pct' : 'amount'); });
    pctEl.addEventListener('input', () => { pctEl.dataset.prefilled = '0'; recompute('pct'); });
    amtEl.addEventListener('input', () => recompute('amount'));
    modeEl.addEventListener('change', () => recompute('mode'));

    // (walk fix 1+2) Two-way commission binding with a VAT basis. `primary` is the amount
    // in the selected basis; % is of the selling price; Incl/Excl/VAT all derive; the HIDDEN
    // total_commission always carries the Incl-VAT figure (DR1's stored truth — not forked).
    const fmt = n => (Math.round((parseFloat(n) || 0) * 100) / 100).toFixed(2);
    const zar = n => Number(fmt(n)).toLocaleString('en-ZA', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    function recompute(source) {
        const price = parseFloat(priceEl.value) || 0;
        const mode = modeEl.value; // 'incl' | 'excl'
        amtLabel.textContent = mode === 'incl' ? 'Commission (Incl VAT)' : 'Commission (Excl VAT)';
        let primary = parseFloat(amtEl.value) || 0;
        let pct = parseFloat(pctEl.value) || 0;
        if (source === 'pct') { primary = price > 0 ? price * (pct / 100) : 0; }
        else { pct = price > 0 ? (primary / price) * 100 : 0; }   // 'amount' or 'mode'
        let incl, excl;
        if (mode === 'incl') { incl = primary; excl = incl / (1 + vatRate / 100); }
        else { excl = primary; incl = excl * (1 + vatRate / 100); }
        const vat = incl - excl;
        if (source !== 'amount') { amtEl.value = primary > 0 ? fmt(primary) : ''; }
        if (source !== 'pct') { pctEl.value = pct > 0 ? fmt(pct) : ''; }
        totalEl.value = incl > 0 ? fmt(incl) : '';   // stored Incl-VAT total (DR1 truth)
        inclDisp.textContent = zar(incl); exclDisp.textContent = zar(excl); vatDisp.textContent = zar(vat);
    }
    document.getElementById('dr2_property_unlink').addEventListener('click', () => {
        pId.value = ''; pLinked.style.display = 'none';
        sellerField.setOffer([]); buyerField.setOffer([]);
    });

    // ---------- Enhancement 2 + DR2 party picker: buyer/seller = tick-list (fast
    //            path) + full contact search + add-new. Selecting a contact captures
    //            its id (hidden CSV) so the SAVE creates the property↔contact link
    //            with the right role. Reusable component for both parties. ----------
    function partyField(kind) {
        const idsEl    = document.getElementById('dr2_' + kind + '_ids');
        const nameEl   = document.getElementById('dr2_' + kind + '_name');
        const tokensEl = document.getElementById('dr2_' + kind + '_tokens');
        const searchEl = document.getElementById('dr2_' + kind + '_search');
        const resultsEl= document.getElementById('dr2_' + kind + '_results');
        const offerEl  = document.getElementById('dr2_' + kind + '_offer');
        const newFormEl= document.getElementById('dr2_' + kind + '_newform');

        let tokens = [];   // [{id, name}] — contacts to link on save
        let offered = [];  // [{id, name}] — property's already-linked party (fast path)

        // AT-334 — seed tokens from the hidden input's server value (old() ?? the saved deal's
        // party ids). Names come from the saved party list; unknown ids (e.g. a search-picked
        // contact on a validation-fail re-render) degrade to "Contact #id" but the id — the
        // thing that drives the save — is always preserved. This is what stops an untouched
        // edit save from posting empty ids and wiping deal_contacts.
        const seedNames = {};
        (DR2[kind + 'Parties'] || []).forEach(p => { seedNames[parseInt(p.id, 10)] = p.name; });
        (idsEl.value || '').split(',').map(s => parseInt(s, 10)).filter(Boolean).forEach(id => {
            if (!tokens.some(t => t.id === id)) tokens.push({ id, name: seedNames[id] || ('Contact #' + id) });
        });

        const syncIds  = () => { idsEl.value = tokens.map(t => t.id).join(','); };
        const parts    = () => { const c = nameEl.value.trim(); return c ? c.split(/\s*,\s*/).filter(Boolean) : []; };
        const addName  = n => { const p = parts(); if (n && !p.includes(n)) { p.push(n); nameEl.value = p.join(', '); } };
        const dropName = n => { nameEl.value = parts().filter(x => x !== n).join(', '); };

        function renderTokens() {
            tokensEl.innerHTML = '';
            tokens.forEach(t => {
                const chip = document.createElement('span');
                chip.className = 'inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded';
                chip.style.cssText = 'border:1px solid #34d399;background:#ecfdf5;color:#065f46;';
                chip.appendChild(document.createTextNode('🔗 ' + t.name));
                const x = document.createElement('button');
                x.type = 'button'; x.textContent = '×'; x.style.cssText = 'font-weight:700;line-height:1;margin-left:.15rem;';
                x.addEventListener('click', () => { tokens = tokens.filter(z => z.id !== t.id); dropName(t.name); syncIds(); renderTokens(); renderOffer(); });
                chip.appendChild(x);
                tokensEl.appendChild(chip);
            });
        }
        function addToken(id, name) {
            id = parseInt(id, 10);
            if (!id || tokens.some(t => t.id === id)) return;
            tokens.push({ id, name: name || ('Contact #' + id) });
            addName(name); syncIds(); renderTokens(); renderOffer();
        }
        function renderOffer() {
            offerEl.innerHTML = '';
            const remaining = offered.filter(o => o.name && !tokens.some(t => t.id === o.id));
            if (!remaining.length) { offerEl.style.display = 'none'; return; }
            offerEl.style.display = '';
            remaining.forEach(o => {
                const b = document.createElement('button');
                b.type = 'button'; b.className = 'text-xs whitespace-nowrap px-2 py-0.5 rounded';
                b.style.cssText = 'border:1px solid #cbd5e1;color:#0b2a4a;background:#f8fafc;';
                b.textContent = '+ ' + o.name;
                b.addEventListener('click', () => addToken(o.id, o.name));
                offerEl.appendChild(b);
            });
        }

        // --- contact search (universal path) ---
        const closeRes = () => { resultsEl.style.display = 'none'; resultsEl.innerHTML = ''; };
        const runSearch = debounce(() => {
            const q = searchEl.value.trim();
            if (q.length < 2) { closeRes(); return; }
            fetch(R.contacts + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
                .then(r => r.ok ? r.json() : [])
                .then(rows => {
                    if (!Array.isArray(rows) || !rows.length) {
                        resultsEl.innerHTML = '<div style="padding:.6rem .8rem;color:#9ca3af;font-size:.85rem;">No contact match — use “Add a new contact”.</div>';
                        resultsEl.style.display = 'block'; return;
                    }
                    resultsEl.innerHTML = rows.map(row => {
                        const nm = row.name || row.label || ('Contact #' + row.id);
                        const sub = [row.phone, row.email, row.type].filter(Boolean).join(' · ');
                        return '<div class="dr2-crow" role="button" tabindex="0" data-id="' + row.id + '" data-name="' + esc(nm) + '" style="padding:.5rem .8rem;cursor:pointer;border-bottom:1px solid #f3f4f6;">'
                            + '<div style="font-weight:600;color:#0b2a4a;">' + esc(nm) + '</div>'
                            + (sub ? '<div style="font-size:.75rem;color:#6b7280;">' + esc(sub) + '</div>' : '') + '</div>';
                    }).join('');
                    resultsEl.style.display = 'block';
                    resultsEl.querySelectorAll('.dr2-crow').forEach(el => {
                        el.addEventListener('mouseover', () => el.style.background = '#f9fafb');
                        el.addEventListener('mouseout', () => el.style.background = '#fff');
                        el.addEventListener('click', () => { addToken(el.dataset.id, el.dataset.name); searchEl.value = ''; closeRes(); });
                    });
                }).catch(closeRes);
        }, 220);
        searchEl.addEventListener('input', runSearch);
        searchEl.addEventListener('focus', runSearch);
        document.addEventListener('click', e => { if (!e.target.closest('#dr2-' + kind)) closeRes(); });

        // --- add-new contact inline (Match-or-Create on the server) ---
        let formBuilt = false;
        function buildForm() {
            newFormEl.innerHTML = ''
                + '<div style="border:1px solid #e5e7eb;border-radius:.5rem;padding:.6rem;background:#f8fafc;">'
                + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem;">'
                + '<input type="text"  class="nf-first" placeholder="First name*">'
                + '<input type="text"  class="nf-last"  placeholder="Last name">'
                + '<input type="text"  class="nf-phone" placeholder="Phone">'
                + '<input type="email" class="nf-email" placeholder="Email">'
                + '</div>'
                + '<div class="nf-msg" style="font-size:.75rem;color:#b91c1c;margin-top:.3rem;display:none;"></div>'
                + '<div style="margin-top:.4rem;display:flex;gap:.4rem;">'
                + '<button type="button" class="nf-save text-xs px-3 py-1 rounded" style="background:#0b2a4a;color:#fff;">Create &amp; link</button>'
                + '<button type="button" class="nf-cancel text-xs px-3 py-1 rounded" style="border:1px solid #cbd5e1;">Cancel</button>'
                + '</div></div>';
            const q = s => newFormEl.querySelector(s);
            const msg = q('.nf-msg');
            const show = m => { msg.textContent = m; msg.style.display = ''; };
            q('.nf-cancel').addEventListener('click', () => { newFormEl.style.display = 'none'; });
            q('.nf-save').addEventListener('click', () => {
                msg.style.display = 'none';
                const payload = { first_name: q('.nf-first').value.trim(), last_name: q('.nf-last').value.trim(), phone: q('.nf-phone').value.trim(), email: q('.nf-email').value.trim() };
                if (!payload.first_name) { show('First name is required.'); return; }
                postContact(payload, false, show);
            });
            formBuilt = true;
        }
        function postContact(payload, bypass, show) {
            fetch(R.contactInline, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                body: JSON.stringify(Object.assign({}, payload, { bypass_duplicate_check: bypass ? 1 : 0 })),
            }).then(async r => {
                const body = await r.json().catch(() => ({}));
                if ((r.status === 201 || r.ok) && body.id) { addToken(body.id, body.name); newFormEl.style.display = 'none'; return; }
                if (r.status === 409 && body.duplicate_detected) {
                    const first = (body.duplicate_detected.duplicates || [])[0];
                    if (first && confirm('A matching contact already exists: ' + first.name + '. Link that existing contact instead?')) { addToken(first.id, first.name); newFormEl.style.display = 'none'; return; }
                    if (body.duplicate_detected.can_override && confirm('Create a NEW contact anyway (override the duplicate)?')) { postContact(payload, true, show); return; }
                    if (show) show('A matching contact exists — search for it above, or override.');
                    return;
                }
                if (show) show(body.message || 'Could not add the contact.');
            }).catch(() => { if (show) show('Network error — please retry.'); });
        }

        // AT-334 — reflect any seeded tokens (edit-path) on load.
        renderTokens();

        return {
            setOffer(list) { offered = (list || []).filter(o => o && o.id && o.name); renderOffer(); },
            // AT-334 create-path: capture the property's seller id so "looks linked" == "is linked".
            autoPickAll(list) { (list || []).forEach(o => { if (o && o.id && o.name) addToken(o.id, o.name); }); },
            openNew() { if (!formBuilt) buildForm(); newFormEl.style.display = newFormEl.style.display === 'none' ? '' : 'none'; },
        };
    }

    const sellerField = partyField('seller');
    const buyerField  = partyField('buyer');
    document.querySelectorAll('.dr2-addnew').forEach(btn =>
        btn.addEventListener('click', () => (btn.dataset.kind === 'seller' ? sellerField : buyerField).openNew()));

    function loadPropContacts(pid) {
        fetch(R.propertyContacts.replace('__ID__', pid), { headers: { Accept: 'application/json' } })
            .then(r => r.ok ? r.json() : { sellers: [], buyers: [] })
            .then(data => {
                const sellers = data.sellers || [], buyers = data.buyers || [];
                // Seller: auto-fill the name when empty (never clobber a typed name).
                const sName = document.getElementById('dr2_seller_name');
                if (sellers.length && !sName.value.trim()) sName.value = sellers.map(s => s.name).filter(Boolean).join(', ');
                sellerField.setOffer(sellers.map(s => ({ id: s.id, name: s.name })));
                buyerField.setOffer(buyers.map(b => ({ id: b.id, name: b.name })));
                // AT-334 create-path: auto-tokenize the property's SELLER so its id is captured
                // on save (the "name auto-fills but id never posts" drop). Seller only — a seller
                // is singular, so this is safe; BUYERS stay click-to-pick to preserve the
                // 25c2d4a8 multi-offer phantom fix (never resurrect unpicked buyers).
                if (DR2.mode === 'create') {
                    sellerField.autoPickAll(sellers.map(s => ({ id: s.id, name: s.name })));
                }
            }).catch(() => {});
    }
    if (pId.value) loadPropContacts(pId.value);

    // ---------- Fix 2: attorney = FIRM + contact person (search + add-new) ----------
    const aSearch = document.getElementById('dr2_attorney_search');
    const aResults = document.getElementById('dr2_attorney_results');
    const aName = document.getElementById('dr2_attorney_name');
    const aProvId = document.getElementById('dr2_attorney_provider_id');
    const aContactId = document.getElementById('dr2_attorney_contact_id');
    const closeAtt = () => { aResults.style.display = 'none'; aResults.innerHTML = ''; };
    // Typing free-text keeps the display name but clears the firm/contact link until a pick.
    aSearch.addEventListener('input', () => { aName.value = aSearch.value; aProvId.value = ''; aContactId.value = ''; });
    const runAtt = debounce(() => {
        const q = aSearch.value.trim();
        if (q.length < 2) { closeAtt(); return; }
        fetch(R.attorneySearch + '?q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } })
            .then(r => r.ok ? r.json() : { results: [] })
            .then(data => {
                const rows = (data && data.results) || [];
                if (!rows.length) { closeAtt(); return; }
                aResults.innerHTML = rows.map((row, i) => {
                    const line1 = row.firm + (row.attorney ? ' — ' + row.attorney : '');
                    const sub = [row.contact ? 'via ' + row.contact : '', row.email].filter(Boolean).join(' · ');
                    return '<div class="dr2-arow" data-i="' + i + '" style="padding:.6rem .8rem;cursor:pointer;border-bottom:1px solid #f3f4f6;"><div style="font-weight:600;color:#0b2a4a;">' + esc(line1) + '</div>' + (sub ? '<div style="font-size:.78rem;color:#6b7280;">' + esc(sub) + '</div>' : '') + '</div>';
                }).join('');
                aResults.style.display = 'block';
                aResults.querySelectorAll('.dr2-arow').forEach(el => {
                    const row = rows[parseInt(el.dataset.i, 10)];
                    el.addEventListener('mouseover', () => el.style.background = '#f9fafb');
                    el.addEventListener('mouseout', () => el.style.background = '#fff');
                    el.addEventListener('click', () => {
                        aName.value = row.label; aSearch.value = row.label;
                        aProvId.value = row.provider_id || ''; aContactId.value = row.contact_id || '';
                        closeAtt();
                    });
                });
            }).catch(closeAtt);
    }, 220);
    aSearch.addEventListener('input', runAtt);
    aSearch.addEventListener('focus', runAtt);
    document.addEventListener('click', e => { if (!e.target.closest('#dr2-att')) closeAtt(); });

    const modal = document.getElementById('dr2_att_modal');
    const mFirm = document.getElementById('dr2_na_firm'), mAttorney = document.getElementById('dr2_na_attorney');
    const mContact = document.getElementById('dr2_na_contact'), mEmail = document.getElementById('dr2_na_email');
    const mAddress = document.getElementById('dr2_na_address'), mErr = document.getElementById('dr2_na_error');
    document.getElementById('dr2_attorney_addnew').addEventListener('click', () => {
        mFirm.value = aSearch.value.trim(); mAttorney.value = mContact.value = mEmail.value = mAddress.value = '';
        mErr.style.display = 'none'; modal.style.display = 'flex'; mFirm.focus();
    });
    document.getElementById('dr2_na_cancel').addEventListener('click', () => modal.style.display = 'none');
    modal.addEventListener('click', e => { if (e.target === modal) modal.style.display = 'none'; });
    document.getElementById('dr2_na_save').addEventListener('click', function () {
        const firm = mFirm.value.trim();
        if (!firm) { mErr.textContent = 'A firm is required.'; mErr.style.display = 'block'; return; }
        this.disabled = true;
        fetch(R.attorneyInline, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ firm, attorney: mAttorney.value.trim() || null, contact: mContact.value.trim() || null, email: mEmail.value.trim() || null, address: mAddress.value.trim() || null }),
        }).then(r => r.json().then(j => ({ ok: r.ok, j }))).then(({ ok, j }) => {
            if (!ok) { mErr.textContent = (j && j.message) || 'Could not save the attorney.'; mErr.style.display = 'block'; return; }
            aName.value = j.label || firm; aSearch.value = j.label || firm;
            aProvId.value = j.provider_id || ''; aContactId.value = j.contact_id || '';
            modal.style.display = 'none';
        }).catch(() => { mErr.textContent = 'Network error — please try again.'; mErr.style.display = 'block'; })
          .finally(() => { this.disabled = false; });
    });

    // ---------- AT-228: bond originator = FIRM + contact (mirror of the attorney picker) ----------
    (function () {
        const bSearch = document.getElementById('dr2_bond_search');
        if (!bSearch) { return; }
        const bResults = document.getElementById('dr2_bond_results');
        const bProvId = document.getElementById('dr2_bond_provider_id');
        const bContactId = document.getElementById('dr2_bond_contact_id');
        const SPEC = '&specialty=bond_originator';
        const closeB = () => { bResults.style.display = 'none'; bResults.innerHTML = ''; };
        bSearch.addEventListener('input', () => { bProvId.value = ''; bContactId.value = ''; });
        const runB = debounce(() => {
            const q = bSearch.value.trim();
            if (q.length < 2) { closeB(); return; }
            fetch(R.attorneySearch + '?q=' + encodeURIComponent(q) + SPEC, { headers: { Accept: 'application/json' } })
                .then(r => r.ok ? r.json() : { results: [] })
                .then(data => {
                    const rows = (data && data.results) || [];
                    if (!rows.length) { closeB(); return; }
                    bResults.innerHTML = rows.map((row, i) => {
                        const line1 = row.firm + (row.attorney ? ' — ' + row.attorney : '');
                        const sub = [row.contact ? 'via ' + row.contact : '', row.email].filter(Boolean).join(' · ');
                        return '<div class="dr2-brow" data-i="' + i + '" style="padding:.6rem .8rem;cursor:pointer;border-bottom:1px solid #f3f4f6;"><div style="font-weight:600;color:#0b2a4a;">' + esc(line1) + '</div>' + (sub ? '<div style="font-size:.78rem;color:#6b7280;">' + esc(sub) + '</div>' : '') + '</div>';
                    }).join('');
                    bResults.style.display = 'block';
                    bResults.querySelectorAll('.dr2-brow').forEach(el => {
                        const row = rows[parseInt(el.dataset.i, 10)];
                        el.addEventListener('click', () => {
                            bSearch.value = row.label; bProvId.value = row.provider_id || ''; bContactId.value = row.contact_id || ''; closeB();
                        });
                    });
                }).catch(closeB);
        }, 220);
        bSearch.addEventListener('input', runB);
        bSearch.addEventListener('focus', runB);
        document.addEventListener('click', e => { if (!e.target.closest('#dr2-bond')) closeB(); });

        const bModal = document.getElementById('dr2_bond_modal');
        const nbFirm = document.getElementById('dr2_nb_firm'), nbAtt = document.getElementById('dr2_nb_attorney');
        const nbContact = document.getElementById('dr2_nb_contact'), nbEmail = document.getElementById('dr2_nb_email');
        const nbAddress = document.getElementById('dr2_nb_address'), nbErr = document.getElementById('dr2_nb_error');
        document.getElementById('dr2_bond_addnew').addEventListener('click', () => {
            nbFirm.value = bSearch.value.trim(); nbAtt.value = nbContact.value = nbEmail.value = nbAddress.value = '';
            nbErr.style.display = 'none'; bModal.style.display = 'flex'; nbFirm.focus();
        });
        document.getElementById('dr2_nb_cancel').addEventListener('click', () => bModal.style.display = 'none');
        bModal.addEventListener('click', e => { if (e.target === bModal) bModal.style.display = 'none'; });
        document.getElementById('dr2_nb_save').addEventListener('click', function () {
            const firm = nbFirm.value.trim();
            if (!firm) { nbErr.textContent = 'A firm is required.'; nbErr.style.display = 'block'; return; }
            this.disabled = true;
            fetch(R.attorneyInline + '?specialty=bond_originator', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ specialty: 'bond_originator', firm, attorney: nbAtt.value.trim() || null, contact: nbContact.value.trim() || null, email: nbEmail.value.trim() || null, address: nbAddress.value.trim() || null }),
            }).then(r => r.json().then(j => ({ ok: r.ok, j }))).then(({ ok, j }) => {
                if (!ok) { nbErr.textContent = (j && j.message) || 'Could not save the bond originator.'; nbErr.style.display = 'block'; return; }
                bSearch.value = j.label || firm; bProvId.value = j.provider_id || ''; bContactId.value = j.contact_id || ''; bModal.style.display = 'none';
            }).catch(() => { nbErr.textContent = 'Network error — please try again.'; nbErr.style.display = 'block'; })
              .finally(() => { this.disabled = false; });
        });
    })();

    // On load: in EDIT mode the stored total_commission is the Incl-VAT figure (DR1 truth)
    // but %/amount are UI-only (not persisted) — seed the basis=incl, amount=stored total, and
    // derive % + Excl/VAT. On a fresh create, derive from a prefilled % if present.
    if (parseFloat(totalEl.value) > 0) {
        modeEl.value = 'incl';
        amtEl.value = fmt(totalEl.value);
        recompute('amount');
    } else if (parseFloat(priceEl.value) > 0 && parseFloat(pctEl.value) > 0) {
        recompute('pct');
    } else {
        recompute('mode'); // set the amount label + zeroed display
    }
})();
</script>
</x-app-layout>
