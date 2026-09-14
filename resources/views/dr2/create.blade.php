<x-app-layout>
    <x-slot name="header">
        <div class="rounded-md px-6 py-5 corex-page-banner">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div>
                    <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">{{ $mode === 'create' ? 'Add Deal' : 'Edit Deal' }}</h1>
                    <p class="text-xs" style="color: var(--text-muted);">Capture the deal accurately so settlement + rollups reconcile end-to-end.</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('deals-dr2.index') }}" class="corex-btn-outline text-xs shrink-0">
                        &larr; Back to Deal Register
                    </a>
                </div>
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

<form method="POST" id="dr2-main-form" action="{{ $mode === 'create' ? route('deals-dr2.store') : route('deals-dr2.update', $deal) }}" class="space-y-6">
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

            {{-- Deal Type radio removed — structure (and therefore the effective deal type) is
                 now captured entirely on the Deal Structure tab after capture. deal_type is left
                 null at creation and derived from the composed conditions. --}}

            {{-- AT-334 — Pipeline. Composition (Deal Structure) is now the DEFAULT: a new deal
                 starts with NO template, so it lands with zero steps and the Deal Structure tab
                 drives the build (pick conditions → Build). A standard template is an advanced/
                 legacy choice — pick one here to attach it instead. --}}
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
                    <div id="dr2_property_results" style="position:absolute;z-index:40;left:0;right:0;top:100%;background:var(--surface);border:1px solid var(--border);border-radius:6px;box-shadow:0 8px 24px var(--shadow, rgba(0,0,0,.08));max-height:16rem;overflow:auto;display:none;"></div>
                </div>
                <input type="hidden" name="property_address" id="dr2_property_address" value="{{ old('property_address', $deal->property_address) }}">
                <div id="dr2_property_linked" class="text-xs mt-1" style="{{ old('property_id', $deal->property_id) ? '' : 'display:none;' }}color:#047857;">✓ Linked to property <span id="dr2_property_linked_id">#{{ old('property_id', $deal->property_id) }}</span> <button type="button" id="dr2_property_unlink" class="underline ml-1" style="color:var(--text-muted)">unlink</button></div>
                <div class="flex items-center justify-between mt-1">
                    <div class="text-xs" style="color:var(--text-faint)">No CoreX match? Type the address — the deal still saves.</div>
                    {{-- Wave 2 resale guard — the search shows on-market listings by default so a
                         renovated-and-relisted address steers to the LIVE record, not its sold twin. --}}
                    <label class="text-[11px] flex items-center gap-1 cursor-pointer" style="color:var(--text-muted,#6b7280);">
                        <input type="checkbox" id="dr2_property_showall" class="w-3 h-3"> Show sold/archived too
                    </label>
                </div>
                <div class="mt-1 text-xs" style="color:var(--text-faint)">Only link a second property here when the SAME owner(s) are selling all of them together on this one deal.</div>
            </div>

            {{-- Johan, 2026-09-19 — "you choose the properties at the top and
                 have the financials together at the financials section." This
                 is SELECTION only — which property record is on the deal.
                 Every figure that must reconcile (each property's own price
                 and commission, the running totals, the balance verdict) now
                 lives together in the Financials section below, so nothing
                 can be edited while its verdict is off screen. See
                 "Properties on this deal" inside Financials.

                 A <form> cannot contain another <form> — a browser silently
                 drops nested ones (found on a real browser pass, AT-398).
                 None of the actions here live inside a nested <form>: each
                 is a plain button/input associated to its REAL <form> —
                 declared outside this page's main form, via the HTML5
                 form="..." attribute, which works regardless of DOM nesting. --}}
            @if(($mode ?? 'create') === 'edit' && $deal->exists)
            @php
                $dr2ActiveProps = $deal->properties()->orderByDesc('deal_properties.is_primary')->orderBy('deal_properties.created_at')->get();
                $dr2RemovedProps = $deal->withTrashedProperties()->wherePivotNotNull('deal_properties.deleted_at')->get();
            @endphp
            <div class="field-full" id="dr2mp-picker-area">
                @error('property_id')
                    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 mb-2">{{ $message }}</div>
                @enderror
                @error('allocated_price')
                    <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 mb-2">{{ $message }}</div>
                @enderror

                {{-- Removed properties — archive/restore, mirrors dr2/_removed-steps.blade.php --}}
                @if($dr2RemovedProps->isNotEmpty())
                <div x-data="{ dr2mpRm:false }" style="margin-bottom:.75rem;">
                    <button type="button" @click="dr2mpRm=!dr2mpRm"
                            style="padding:.25rem .7rem;font-size:12px;font-weight:600;color:#b45309;background:#fff;border:1px solid #fcd34d;border-radius:999px;cursor:pointer;font-family:inherit;">
                        <span x-text="dr2mpRm ? '▾' : '▸'"></span> Removed properties ({{ $dr2RemovedProps->count() }})
                    </button>
                    <div x-show="dr2mpRm" x-cloak style="margin-top:.5rem;display:flex;flex-direction:column;gap:.3rem;">
                        @foreach($dr2RemovedProps as $rp)
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:.6rem;padding:.3rem .1rem;font-size:12.5px;">
                                <span style="text-decoration:line-through;color:#6b7280;">{{ $rp->address }}</span>
                                {{-- Real form lives outside the main <form> — see "AT-398 standalone forms" below. --}}
                                <button type="submit" form="dr2mp-restore-form-{{ $rp->id }}" style="padding:.15rem .7rem;font-size:11.5px;font-weight:600;color:#2563eb;background:#fff;border:1px solid #bfdbfe;border-radius:6px;cursor:pointer;font-family:inherit;">Restore</button>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Add another property, Johan 2026-09-16 — "why offer a
                     search, it can be a plain dropdown." Populated by the SAME
                     loadEligibleDropdown() JS both this screen and create mode
                     call — one implementation, not two. Picking a property
                     here does not itself add it — its price/commission are
                     entered in Financials below, which is where the "Add to
                     deal" confirmation actually lives now. --}}
                <label class="text-xs font-semibold block mb-1" style="color:var(--text-secondary);">Add another property</label>
                <select id="dr2mp_picker" class="input-base w-full text-xs">
                    <option value="">Loading…</option>
                </select>
                <div id="dr2mp_picker_empty" class="text-xs mt-1" style="color:var(--text-faint);display:none;">No other properties share this deal's exact owner set — nothing eligible to add.</div>
            </div>
            @elseif(($mode ?? 'create') === 'create')
            {{-- Johan, 2026-09-14/16 — the create-time gap he found himself: this
                 section didn't exist on create at all. His ruling on WHY it must
                 be create-time, not save-then-add: "2 properties sold together
                 makes up 1 selling price... save the deal with figures not
                 balancing, or save with wrong figures, then reopen, change the
                 deal to get it back to correct figures? That was never the
                 spec." Held entirely client-side until the ONE "Save Deal"
                 submit — see DealRegisterController::store()'s own handling. --}}
            <div class="field-full" id="dr2cp-picker-area">
                <label class="text-xs font-semibold block mb-1" style="color:var(--text-secondary);">Add another property</label>
                <select id="dr2cp_picker" class="input-base w-full text-xs">
                    <option value="">Loading…</option>
                </select>
                <div id="dr2cp_picker_empty" class="text-xs mt-1" style="color:var(--text-faint);display:none;">No other properties share this deal's exact owner set — nothing eligible to add.</div>
            </div>
            @endif

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
                    <div id="dr2_seller_results" style="position:absolute;z-index:40;left:0;right:0;top:100%;background:var(--surface);border:1px solid var(--border);border-radius:6px;box-shadow:0 8px 24px var(--shadow, rgba(0,0,0,.08));max-height:16rem;overflow:auto;display:none;"></div>
                </div>
                <div id="dr2_seller_offer" class="mt-1 flex flex-wrap gap-1.5" style="display:none;"></div>
                <div class="mt-1"><button type="button" class="dr2-addnew text-xs underline" style="color:var(--text-muted)" data-kind="seller">＋ Add a new contact</button></div>
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
                    <div id="dr2_buyer_results" style="position:absolute;z-index:40;left:0;right:0;top:100%;background:var(--surface);border:1px solid var(--border);border-radius:6px;box-shadow:0 8px 24px var(--shadow, rgba(0,0,0,.08));max-height:16rem;overflow:auto;display:none;"></div>
                </div>
                <div id="dr2_buyer_offer" class="mt-1 flex flex-wrap gap-1.5" style="display:none;"></div>
                <div class="mt-1"><button type="button" class="dr2-addnew text-xs underline" style="color:var(--text-muted)" data-kind="buyer">＋ Add a new contact</button></div>
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
                    <div id="dr2_attorney_results" style="position:absolute;z-index:40;left:0;right:0;top:100%;background:var(--surface);border:1px solid var(--border);border-radius:6px;box-shadow:0 8px 24px var(--shadow, rgba(0,0,0,.08));max-height:16rem;overflow:auto;display:none;"></div>
                </div>
                <button type="button" id="dr2_attorney_addnew" class="text-xs underline mt-1" style="color:var(--brand-icon)">+ Add a new attorney (firm &amp; contact)</button>
            </div>

            {{-- AT-228 — Bond Originator (firm & contact), same picker UX as the attorney field. --}}
            <div class="field-full" id="dr2-bond">
                <label class="ds-label block mb-1">Bond originator (firm &amp; contact)</label>
                <input type="hidden" name="bond_originator_provider_id" id="dr2_bond_provider_id" value="{{ old('bond_originator_provider_id', $deal->bond_originator_provider_id) }}">
                <input type="hidden" name="bond_originator_contact_id" id="dr2_bond_contact_id" value="{{ old('bond_originator_contact_id', $deal->bond_originator_contact_id) }}">
                <div style="position:relative;">
                    <input type="text" id="dr2_bond_search" class="w-full" autocomplete="off" placeholder="Search a bond originator firm or contact…">
                    <div id="dr2_bond_results" style="position:absolute;z-index:40;left:0;right:0;top:100%;background:var(--surface);border:1px solid var(--border);border-radius:6px;box-shadow:0 8px 24px var(--shadow, rgba(0,0,0,.08));max-height:16rem;overflow:auto;display:none;"></div>
                </div>
                <button type="button" id="dr2_bond_addnew" class="text-xs underline mt-1" style="color:var(--brand-icon)">+ Add a new bond originator (firm &amp; contact)</button>
            </div>

            {{-- External Agency is captured PER SIDE in "Sides, Splits & Agents" below
                 (each side has its own firm+contact picker). The old single top-level
                 external-agency field was retired here — see the per-side pickers. --}}

            {{-- (Enhancement 7 / walk fix 1+2) Financials — commission with a VAT basis toggle
                 and live two-way % ↔ amount binding. Stored truth stays DR1's (Incl-VAT total);
                 Excl + VAT are DERIVED for display, not forked into storage. --}}
            <div class="field-full"><h3 class="ds-label" style="margin-top:.35rem;font-weight:700;color:var(--text-primary);">Financials</h3></div>

            {{-- AT-398 split-pricing — once a deal covers more than one property, its
                 Selling Price/Commission are always the SUM of every property's own
                 price (see the "Properties on this deal" list below); this deal has
                 more than one, so these fields become read-only here — the sum they
                 already show round-trips unchanged on save, and each property's own
                 price is edited in the list below instead. --}}
            @php $dr2MultiPriced = $deal->exists && $deal->properties->count() > 1; @endphp

            {{-- (Enhancement 4) Selling Price — prefilled from the advertised price, overridable --}}
            <div>
                <label class="ds-label block mb-1">Selling Price</label>
                <input type="number" step="0.01" class="input-base money-input" name="property_value" id="dr2_property_value" value="{{ old('property_value', $deal->property_value) }}" required {{ $dr2MultiPriced ? 'readonly' : '' }}>
                @if($dr2MultiPriced)
                    <div class="mt-1 text-xs" id="dr2_selling_price_multi_hint" style="color:var(--text-faint)">This deal has more than one property — this is the sum of their prices below. Edit each property's own price there.</div>
                @endif
            </div>

            {{-- VAT basis — what the amount you enter means (agency VAT rate from config) --}}
            <div>
                <label class="ds-label block mb-1">Commission basis</label>
                <select class="input-base" id="dr2_vat_mode" {{ $dr2MultiPriced ? 'disabled' : '' }}>
                    <option value="incl">VAT-inclusive</option>
                    <option value="excl">VAT-exclusive</option>
                </select>
                <div class="mt-1 text-xs" style="color:var(--text-faint)">Both figures are shown below either way.</div>
            </div>

            {{-- Commission % — of the selling price; two-way with the amount --}}
            <div>
                <label class="ds-label block mb-1">Commission %</label>
                <input type="number" step="0.01" class="input-base" name="commission_percent_display" id="dr2_commission_percent" value="{{ old('commission_percent_display') }}" {{ $dr2MultiPriced ? 'readonly' : '' }}>
                <div class="mt-1 text-xs" style="color:var(--text-faint)">Prefills from the property; two-way with the amount.</div>
            </div>

            {{-- Commission amount in the selected basis — two-way with % --}}
            <div>
                <label class="ds-label block mb-1"><span id="dr2_comm_amount_label">Commission (Incl VAT)</span></label>
                <input type="number" step="0.01" class="input-base money-input" id="dr2_commission_amount" value="" {{ $dr2MultiPriced ? 'readonly' : '' }}>
                <div class="mt-1 text-xs" style="color:var(--text-faint)">Fill either % or amount — the other populates live.</div>
            </div>

            {{-- Derived figures + the stored Incl-VAT total (DR1 truth) --}}
            <div class="field-full">
                <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm" style="color:var(--text-secondary);">
                    <span>Incl VAT: <strong>R <span id="dr2_comm_incl_disp">0.00</span></strong></span>
                    <span>Excl VAT: <strong>R <span id="dr2_comm_excl_disp">0.00</span></strong></span>
                    <span>VAT (<span id="dr2_vat_pct_disp">15</span>%): <strong>R <span id="dr2_comm_vat_disp">0.00</span></strong></span>
                </div>
                <div class="mt-1 text-xs" style="color:var(--text-muted)">Stored as the <span class="font-semibold">Incl-VAT total</span> (as DR1 stores it); pools/allocations compute Ex VAT.</div>
                <input type="hidden" name="total_commission" id="dr2_total_commission" value="{{ old('total_commission', $deal->total_commission) }}">
            </div>

            {{-- Johan, 2026-09-19, verbatim ruling: "we move it and make it
                 work and look proper like it belongs there, not just slapped
                 in somewhere." Every figure that must reconcile against the
                 totals above — each property's own selling price and
                 commission, and (create mode only) the live balance verdict
                 — lives here, in Financials, immediately below the totals it
                 feeds. The balance line sits BETWEEN the totals above and the
                 rows below so neither can be edited with its verdict off
                 screen — that was the actual defect Johan reported.

                 Labels on each row match the total they feed, WORD FOR WORD,
                 and the commission label follows the Commission basis
                 selector live (dr2SyncCommissionLabels(), shared by create
                 and edit) — an agent typing into a box marked "Price" has no
                 way to know which price; a box marked "Selling price" or
                 "Commission (Incl VAT)" does. Selling price carries no VAT
                 qualifier — Johan confirmed selling price has no VAT
                 dimension at all ("selling is the total price incl comm, not
                 vat"); only commission does. --}}
            @if(($mode ?? 'create') === 'edit' && $deal->exists)
            @php
                $dr2ActiveProps = $deal->properties()->orderByDesc('deal_properties.is_primary')->orderBy('deal_properties.created_at')->get();
            @endphp
            <div class="field-full" id="dr2-multi-props">
                <label class="ds-label block mb-1">Properties on this deal ({{ $dr2ActiveProps->count() }})</label>

                @if($dr2ActiveProps->count() > 1)
                <div class="flex items-center gap-3 mb-2 flex-wrap">
                    <input type="text" id="dr2mp_filter" placeholder="Filter by address…" class="input-base text-xs" style="max-width:220px;">
                    <div class="flex items-center gap-1 text-[11px]" style="color:var(--text-muted);">
                        Sort:
                        <button type="button" class="dr2mp-sort-btn underline" data-sort="address" style="color:var(--text-secondary);">Address</button>
                        <button type="button" class="dr2mp-sort-btn underline" data-sort="price" style="color:var(--text-secondary);">Selling price</button>
                        <button type="button" class="dr2mp-sort-btn underline" data-sort="added" style="color:var(--text-secondary);">Date added</button>
                    </div>
                </div>
                @endif

                <div id="dr2mp_list" class="flex flex-col gap-1.5 mb-2">
                    @forelse($dr2ActiveProps as $p)
                        <div class="dr2mp-row" data-property-id="{{ $p->id }}" data-address="{{ strtolower($p->address ?? '') }}" data-price="{{ (float) ($p->pivot->allocated_price ?? 0) }}" data-added="{{ optional($p->pivot->created_at)->timestamp ?? 0 }}"
                             style="display:flex;align-items:center;justify-content:space-between;gap:.6rem;padding:.5rem .7rem;border:1px solid var(--border);border-radius:8px;">
                            <div style="min-width:0;flex-shrink:0;">
                                <span style="font-weight:600;color:var(--text-primary);">{{ $p->address }}</span>
                                @if($p->pivot->is_primary)
                                    <span title="Pick a different property as primary before removing this one" style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.02em;padding:.05rem .35rem;border-radius:.35rem;color:#065f46;background:#ecfdf5;">Primary</span>
                                @endif
                            </div>
                            <div style="display:flex;align-items:center;gap:.6rem;flex-shrink:0;flex-wrap:wrap;">
                                <button type="button" class="dr2mp-edit-price text-xs underline" data-property="{{ $p->id }}" data-address="{{ $p->address }}" data-price="{{ $p->pivot->allocated_price }}" data-commission="{{ $p->pivot->allocated_commission }}" style="color:var(--text-muted);">R {{ number_format((float) ($p->pivot->allocated_price ?? 0), 2) }} selling price · R {{ number_format((float) ($p->pivot->allocated_commission ?? 0), 2) }} commission — edit</button>
                                @if(!$p->pivot->is_primary)
                                    {{-- Real form lives outside the main <form> — see "AT-398 standalone forms" below. --}}
                                    <button type="submit" form="dr2mp-remove-form-{{ $p->id }}" class="dr2mp-remove-trigger text-xs" data-address="{{ $p->address }}" style="color:#b91c1c;background:none;border:none;padding:0;cursor:pointer;font-family:inherit;">Remove</button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="text-xs" style="color:var(--text-faint);padding:.5rem;">No properties linked yet — search above to add the first one.</div>
                    @endforelse
                </div>

                {{-- Inline price editor — populated by JS when a row's own summary
                     is clicked. Not a <form> here; its inputs/button are associated
                     (form="dr2mp_edit_form") to the real, standalone form declared
                     outside the main form below. --}}
                <div id="dr2mp_edit_box" style="display:none;border:1px solid var(--border);border-radius:8px;padding:.6rem;margin-bottom:.75rem;background:var(--surface);">
                    <div class="text-xs font-semibold mb-1" id="dr2mp_edit_label" style="color:var(--text-secondary);"></div>
                    <div style="display:flex;gap:.6rem;align-items:end;flex-wrap:wrap;">
                        <div>
                            <label class="text-[11px] block" style="color:var(--text-muted);">Selling price</label>
                            <input type="number" step="0.01" min="0" name="allocated_price" id="dr2mp_edit_price" class="input-base text-xs" form="dr2mp_edit_form" required>
                        </div>
                        <div>
                            <label class="text-[11px] block" id="dr2mp_edit_commission_label" style="color:var(--text-muted);">Commission (Incl VAT)</label>
                            <input type="number" step="0.01" min="0" name="allocated_commission" id="dr2mp_edit_commission" class="input-base text-xs" form="dr2mp_edit_form" required>
                        </div>
                        <button type="submit" form="dr2mp_edit_form" class="corex-btn-outline text-xs">Save</button>
                        <button type="button" id="dr2mp_edit_cancel" class="text-xs underline" style="color:var(--text-muted);">Cancel</button>
                    </div>
                </div>

                {{-- Add another property's price/commission — appears once a
                     property is picked from the dropdown above. Not a <form>
                     here; its inputs/button are associated (form="dr2mp_add_form_real")
                     to the real, standalone form declared outside the main form below. --}}
                <div id="dr2mp_add_form" style="display:none;border:1px solid var(--border);border-radius:8px;padding:.6rem;background:var(--surface);">
                    <div class="text-xs mb-1" id="dr2mp_add_label" style="color:var(--text-muted);"></div>
                    <div style="display:flex;gap:.6rem;align-items:end;flex-wrap:wrap;">
                        <div>
                            <label class="text-[11px] block" style="color:var(--text-muted);">Selling price</label>
                            <input type="number" step="0.01" min="0" name="allocated_price" id="dr2mp_add_price" class="input-base text-xs" form="dr2mp_add_form_real">
                        </div>
                        <div>
                            <label class="text-[11px] block" id="dr2mp_add_commission_label" style="color:var(--text-muted);">Commission (Incl VAT)</label>
                            <input type="number" step="0.01" min="0" name="allocated_commission" id="dr2mp_add_commission" class="input-base text-xs" form="dr2mp_add_form_real">
                        </div>
                        <button type="submit" form="dr2mp_add_form_real" class="corex-btn-outline text-xs">Add to deal</button>
                        <button type="button" id="dr2mp_add_cancel" class="text-xs underline" style="color:var(--text-muted);">Cancel</button>
                    </div>
                </div>
            </div>
            @elseif(($mode ?? 'create') === 'create')
            {{-- AT-flow-fix, Johan 2026-09-19, verbatim: "Picking the 2nd
                 property is the trigger to load both, show them and show
                 their selling price and comm fields to be completed."
                 SUPERSEDES the earlier "independently-typed total,
                 reconciled against the sum" design (§8d) — see
                 .ai/specs/dr2-multi-property.md §8e. Picking a second
                 property from the dropdown above renders BOTH rows
                 immediately — no separate "confirm" step. Each row's
                 Selling price prefills from the property record and stays
                 editable; each row's Commission starts BLANK for the agent
                 or BM to complete, exactly like the single-property flow
                 leaves commission for the BM to fill. Selling
                 Price/Commission above become READ-ONLY the moment a
                 second property exists — they ARE the sum, displayed live,
                 never a second independently-typed figure to disagree with
                 the parts. --}}
            <div class="field-full" id="dr2cp-multi-props" data-multi-hint="This deal has more than one property — this is the sum of their prices below. Edit each property's own price there.">
                <label class="ds-label block mb-1">Properties on this deal (<span id="dr2cp_count">1</span>)</label>
                <div class="mt-1 text-xs mb-2" style="color:var(--text-faint);" id="dr2cp_single_hint">
                    Add a second property above only when the SAME owner(s) are selling all of them together on this one deal.
                </div>

                <div id="dr2cp_owner_error" class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-800 mb-2" style="display:none;"></div>

                <div id="dr2cp_list" class="flex flex-col gap-1.5 mb-2"></div>

                <div id="dr2cp_hidden_inputs"></div>
            </div>
            @endif
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
                <h3 class="font-bold" style="color:var(--text-primary)">Listing Side</h3>

                {{-- (Johan DR2-walk fix 1) External-agency layout relaid as a non-colliding
                     responsive stack. The old single flex row crammed the checkbox + our-share
                     + split + agency name together and the labels collided. --}}
                <div class="mt-2 space-y-3">
                    <div>
                        <div class="flex items-center justify-between">
                            <div class="ds-label">Listing split %</div>
                            <div class="text-xs" style="color:var(--text-muted)"><span id="listing_split_label">—</span> / <span id="selling_split_label">—</span></div>
                        </div>
                        <div class="mt-2 flex items-center gap-3">
                            <input id="listing_split_percent" type="number" step="0.01" name="listing_split_percent"
                                   value="{{ old('listing_split_percent', $deal->listing_split_percent ?? 50) }}"
                                   class="w-24 rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border)" placeholder="%">
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
                        {{-- External Agency (firm & contact) — same searchable-supplier picker as
                             attorney / bond-originator. The hidden name is the display label,
                             persisted to listing_external_agency (unchanged); provider+contact ids
                             make this side's agency an emailable party via Email Parties. --}}
                        <div id="dr2-lext">
                            <label class="ds-label block mb-1">External Agency</label>
                            <input type="hidden" name="listing_external_agency" id="dr2_lext_name" value="{{ old('listing_external_agency', $deal->listing_external_agency) }}">
                            <input type="hidden" name="listing_external_agency_provider_id" id="dr2_lext_provider_id" value="{{ old('listing_external_agency_provider_id', $deal->listing_external_agency_provider_id) }}">
                            <input type="hidden" name="listing_external_agency_contact_id" id="dr2_lext_contact_id" value="{{ old('listing_external_agency_contact_id', $deal->listing_external_agency_contact_id) }}">
                            <div style="position:relative;">
                                <input type="text" id="dr2_lext_search" class="w-full" autocomplete="off" placeholder="Search an external agency firm or contact…" value="{{ old('listing_external_agency', $deal->listing_external_agency) }}">
                                <div id="dr2_lext_results" style="position:absolute;z-index:40;left:0;right:0;top:100%;background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);max-height:16rem;overflow:auto;display:none;"></div>
                            </div>
                            <button type="button" id="dr2_lext_addnew" class="text-xs text-blue-600 underline mt-1">+ Add a new external agency</button>
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
                        <div class="text-xs mt-1" style="color:var(--text-muted)">Hold Ctrl / Cmd to select multiple.</div>
                    </div>

                    <div id="listing_selected" class="space-y-2"></div>
                </div>
            </div>

            <!-- SELLING -->
            <div>
                <h3 class="font-bold" style="color:var(--text-primary)">Selling Side</h3>

                {{-- (Johan DR2-walk fix 1) External-agency layout — non-colliding responsive stack, selling side. --}}
                <div class="mt-2 space-y-3">
                    <div>
                        <div class="ds-label">Selling split %</div>
                        <div class="mt-2 flex items-center gap-3">
                            <input id="selling_split_percent" type="number" step="0.01" name="selling_split_percent"
                                   value="{{ old('selling_split_percent', $deal->selling_split_percent ?? 50) }}"
                                   class="w-24 rounded-md px-3 py-2 text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border)" placeholder="%">
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
                        {{-- External Agency (firm & contact) — same picker as the listing side. --}}
                        <div id="dr2-sext">
                            <label class="ds-label block mb-1">External Agency</label>
                            <input type="hidden" name="selling_external_agency" id="dr2_sext_name" value="{{ old('selling_external_agency', $deal->selling_external_agency) }}">
                            <input type="hidden" name="selling_external_agency_provider_id" id="dr2_sext_provider_id" value="{{ old('selling_external_agency_provider_id', $deal->selling_external_agency_provider_id) }}">
                            <input type="hidden" name="selling_external_agency_contact_id" id="dr2_sext_contact_id" value="{{ old('selling_external_agency_contact_id', $deal->selling_external_agency_contact_id) }}">
                            <div style="position:relative;">
                                <input type="text" id="dr2_sext_search" class="w-full" autocomplete="off" placeholder="Search an external agency firm or contact…" value="{{ old('selling_external_agency', $deal->selling_external_agency) }}">
                                <div id="dr2_sext_results" style="position:absolute;z-index:40;left:0;right:0;top:100%;background:#fff;border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);max-height:16rem;overflow:auto;display:none;"></div>
                            </div>
                            <button type="button" id="dr2_sext_addnew" class="text-xs text-blue-600 underline mt-1">+ Add a new external agency</button>
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
                        <div class="text-xs mt-1" style="color:var(--text-muted)">Hold Ctrl / Cmd to select multiple.</div>
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
                        <input type="number" step="0.01" name="${sideName}_override[${id}]" placeholder="% override" class="w-32 rounded-lg border" style="border-color:var(--border)" value="${initial ?? ''}">
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


            // External-agency auto-tick now lives inside the per-side external-agency
            // pickers (dr2_lext / dr2_sext): selecting or typing an agency ticks that
            // side's "External agency handled this side" box. See the picker IIFE below.
        </script>
    </form>

    </div>

</div>

@if(($mode ?? 'create') === 'edit' && $deal->exists)
{{-- AT-398 standalone forms — the REAL <form> elements for the multi-property
     list above. A <form> cannot contain another <form>: a browser silently
     discards a nested one (and, worse, this broke the PAGE's own "Update
     Deal" submit when these lived inside it — found on a real browser pass).
     Every visible control for these lives inside the main form above and is
     wired to its real form here purely via the HTML5 form="..." attribute,
     which associates regardless of DOM position. Nothing here is visible —
     these are naked <form> tags carrying only CSRF/method/action. --}}
@php
    $dr2ActiveNonPrimary = $deal->properties()->wherePivot('is_primary', false)->get();
    $dr2RemovedPropsStandalone = $deal->withTrashedProperties()->wherePivotNotNull('deal_properties.deleted_at')->get();
@endphp
<div style="display:none;" aria-hidden="true">
    @foreach($dr2ActiveNonPrimary as $p)
        <form method="POST" action="{{ route('deals-dr2.properties.remove', [$deal, $p]) }}" id="dr2mp-remove-form-{{ $p->id }}" class="dr2mp-remove-form" data-address="{{ $p->address }}">
            @csrf
            @method('DELETE')
        </form>
    @endforeach

    @foreach($dr2RemovedPropsStandalone as $rp)
        <form method="POST" action="{{ route('deals-dr2.properties.restore', [$deal, $rp]) }}" id="dr2mp-restore-form-{{ $rp->id }}">
            @csrf
        </form>
    @endforeach

    <form method="POST" id="dr2mp_edit_form">
        @csrf
        @method('PATCH')
    </form>

    <form method="POST" action="{{ route('deals-dr2.properties.add', $deal) }}" id="dr2mp_add_form_real">
        @csrf
    </form>
</div>
@endif

{{-- (walk fix 2) Add-new attorney inline modal — a FIRM + a contact person.
     Field order per Johan: Firm, Attorney, Contact, Email, Address. --}}
<div id="dr2_att_modal" style="display:none;position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.4);align-items:center;justify-content:center;">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;max-width:34rem;width:92%;padding:1.5rem;box-shadow:0 24px 64px rgba(0,0,0,.25);">
        <h3 class="font-bold mb-1" style="color:var(--text-primary)">Add a new attorney</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted)">A firm can have several people — add the attorney and the person you actually deal with.</p>
        <div class="deal-grid">
            <div class="field-full"><label class="ds-label block mb-1">Firm *</label><input type="text" id="dr2_na_firm" class="w-full" placeholder="e.g. BBB Inc"></div>
            <div><label class="ds-label block mb-1">Attorney</label><input type="text" id="dr2_na_attorney" class="w-full" placeholder="the attorney"></div>
            <div><label class="ds-label block mb-1">Contact</label><input type="text" id="dr2_na_contact" class="w-full" placeholder="assistant / paralegal"></div>
            <div class="field-full"><label class="ds-label block mb-1">Email</label><input type="email" id="dr2_na_email" class="w-full"></div>
            <div class="field-full"><label class="ds-label block mb-1">Address</label><input type="text" id="dr2_na_address" class="w-full"></div>
        </div>
        <div id="dr2_na_error" class="text-sm text-red-600 mt-2" style="display:none;"></div>
        <div class="flex items-center justify-end gap-2 mt-4">
            <button type="button" id="dr2_na_cancel" class="corex-btn-outline px-4 py-2 text-sm">Cancel</button>
            <button type="button" id="dr2_na_save" class="corex-btn-primary px-4 py-2 text-sm">Save attorney</button>
        </div>
    </div>
</div>

{{-- AT-228 — Add-new bond originator modal (mirror of the attorney add-new) --}}
<div id="dr2_bond_modal" style="display:none;position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.4);align-items:center;justify-content:center;">
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;max-width:34rem;width:92%;padding:1.5rem;box-shadow:0 24px 64px rgba(0,0,0,.25);">
        <h3 class="font-bold mb-1" style="color:var(--text-primary)">Add a new bond originator</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted)">A firm can have several people — add the originator and the person you deal with.</p>
        <div class="deal-grid">
            <div class="field-full"><label class="ds-label block mb-1">Firm *</label><input type="text" id="dr2_nb_firm" class="w-full" placeholder="e.g. BetterBond"></div>
            <div><label class="ds-label block mb-1">Originator</label><input type="text" id="dr2_nb_attorney" class="w-full" placeholder="the originator"></div>
            <div><label class="ds-label block mb-1">Contact</label><input type="text" id="dr2_nb_contact" class="w-full" placeholder="assistant"></div>
            <div class="field-full"><label class="ds-label block mb-1">Email</label><input type="email" id="dr2_nb_email" class="w-full"></div>
            <div class="field-full"><label class="ds-label block mb-1">Address</label><input type="text" id="dr2_nb_address" class="w-full"></div>
        </div>
        <div id="dr2_nb_error" class="text-sm text-red-600 mt-2" style="display:none;"></div>
        <div class="flex items-center justify-end gap-2 mt-4">
            <button type="button" id="dr2_nb_cancel" class="corex-btn-outline px-4 py-2 text-sm">Cancel</button>
            <button type="button" id="dr2_nb_save" class="corex-btn-primary px-4 py-2 text-sm">Save bond originator</button>
        </div>
    </div>
</div>

{{-- Note 2 — Add-new external agency modal (mirror of the attorney / bond add-new) --}}
<div id="dr2_extagency_modal" style="display:none;position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.4);align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:.75rem;max-width:34rem;width:92%;padding:1.5rem;">
        <h3 class="font-bold mb-1" style="color:#0b2a4a">Add a new external agency</h3>
        <p class="text-xs text-gray-500 mb-3">A firm can have several people — add the agency and the person you deal with.</p>
        <div class="deal-grid">
            <div class="field-full"><label class="ds-label block mb-1">Firm *</label><input type="text" id="dr2_nx_firm" class="w-full" placeholder="e.g. Seeff Hibiscus Coast"></div>
            <div><label class="ds-label block mb-1">Agent</label><input type="text" id="dr2_nx_attorney" class="w-full" placeholder="the agent"></div>
            <div><label class="ds-label block mb-1">Contact</label><input type="text" id="dr2_nx_contact" class="w-full" placeholder="assistant"></div>
            <div class="field-full"><label class="ds-label block mb-1">Email</label><input type="email" id="dr2_nx_email" class="w-full"></div>
            <div class="field-full"><label class="ds-label block mb-1">Address</label><input type="text" id="dr2_nx_address" class="w-full"></div>
        </div>
        <div id="dr2_nx_error" class="text-sm text-red-600 mt-2" style="display:none;"></div>
        <div class="flex items-center justify-end gap-2 mt-4">
            <button type="button" id="dr2_nx_cancel" class="corex-btn-secondary px-4 py-2 text-sm">Cancel</button>
            <button type="button" id="dr2_nx_save" class="corex-btn-primary px-4 py-2 text-sm">Save external agency</button>
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
        propertiesUpdatePrice: @json($deal->exists ? route('deals-dr2.properties.updatePrice', ['deal' => $deal->id, 'property' => '__ID__']) : null),
        eligibleProperties: @json(route('deals-dr2.search.eligible-properties')),
    };
    const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };
    const money = v => { const n = Number(v); return isNaN(n) ? '' : n.toLocaleString('en-ZA'); };
    const esc = s => String(s == null ? '' : s).replace(/"/g, '&quot;');

    /**
     * "Add another property" eligibility, Johan 2026-09-16, verbatim: "add
     * another property should only display the other properties on this
     * seller. why offer a search, it can be a plain dropdown." Refined by
     * him before any code was written: the set that matters is whichever
     * properties will actually PASS DealPropertyOwnerGate's exact-owner-set
     * check, not merely "linked to this seller" — a seller who owns one
     * property solely and another jointly has two DIFFERENT owner sets.
     * ONE shared function for create AND edit (Johan: "same behaviour on
     * create and on edit. One implementation, not two.") — populates a
     * plain <select>, never a search box, and says so in plain words when
     * nothing is eligible rather than presenting an empty dropdown
     * (Johan: "the control must say so in plain words... should never see
     * a control that looks broken when it is simply empty").
     */
    function loadEligibleDropdown(selectEl, emptyEl, referencePropertyId, excludeIds, extraParams) {
        if (!referencePropertyId) {
            selectEl.style.display = 'none';
            emptyEl.textContent = 'Pick the primary property above first.';
            emptyEl.style.display = '';
            return;
        }
        selectEl.innerHTML = '<option value="">Loading…</option>';
        selectEl.style.display = '';
        emptyEl.style.display = 'none';
        const params = new URLSearchParams(extraParams || {});
        params.set('reference_property_id', referencePropertyId);
        (excludeIds || []).forEach(id => params.append('exclude[]', id));
        fetch(R.eligibleProperties + '?' + params.toString(), { headers: { Accept: 'application/json' } })
            .then(r => r.ok ? r.json() : { properties: [] })
            .then(data => {
                const props = data.properties || [];
                if (!props.length) {
                    selectEl.style.display = 'none';
                    emptyEl.textContent = "No other properties share this deal's exact owner set — nothing eligible to add.";
                    emptyEl.style.display = '';
                    return;
                }
                selectEl.style.display = '';
                emptyEl.style.display = 'none';
                selectEl.innerHTML = '';
                selectEl.appendChild(new Option('Choose a property…', ''));

                // Johan's ruling, 2026-09-16: a property sharing this
                // seller but failing the owner-set match must NOT be
                // silently absent — "an agent who knows their seller owns
                // three houses, opens the dropdown and sees two, will
                // conclude the system lost one." Shown disabled, with a
                // plain-language reason (never "owner set" — the server
                // already writes this in the agent's own language), kept
                // BELOW every real, pickable option so the actual choices
                // are never buried (his own explicit instruction). A
                // disabled <option> is genuinely unselectable by the
                // browser itself — no click, no keyboard nav lands on it —
                // but that is a convenience only: store()/applyCreateTime
                // MultiProperty() re-run the real gate server-side
                // regardless of anything this dropdown shows.
                const eligibleRows = props.filter(p => p.eligible !== false);
                const ineligibleRows = props.filter(p => p.eligible === false);

                const makeOption = p => {
                    const label = p.label + (p.ref ? ' (Ref ' + p.ref + ')' : '');
                    const opt = new Option(label, p.id);
                    opt.dataset.address = p.label;
                    opt.dataset.price = p.price ?? '';
                    return opt;
                };

                eligibleRows.forEach(p => selectEl.appendChild(makeOption(p)));
                if (ineligibleRows.length) {
                    const group = document.createElement('optgroup');
                    group.label = "Can't be added — different owners";
                    selectEl.appendChild(group);
                    ineligibleRows.forEach(p => {
                        const opt = makeOption(p);
                        opt.disabled = true;
                        opt.title = p.reason || "Can't be added — the owners on this property aren't the same as the owners on this deal.";
                        group.appendChild(opt);
                    });
                }
            })
            .catch(() => {
                selectEl.style.display = 'none';
                emptyEl.textContent = 'Could not load eligible properties — reload the page and try again.';
                emptyEl.style.display = '';
            });
    }

    // AT-334 — mode + the deal's saved parties (edit), so the picker seeds tokens/hidden ids
    // from deal_contacts and a create-deal auto-tokenizes the property's seller.
    const DR2 = {
        mode: @json($mode ?? 'create'),
        sellerParties: @json($sellerParties ?? []),
        buyerParties: @json($buyerParties ?? []),
        dealId: @json($deal->exists ? $deal->id : null),
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

    // Shared by both the create-time staging block and the edit-mode
    // add/edit forms below — a property's commission is stored canonically
    // as Incl VAT everywhere (allocated_commission, total_commission), same
    // as the Financials total. These convert between that canonical value
    // and whatever the Commission basis selector currently displays, using
    // the SAME vatRate recompute() uses — never a second conversion rule.
    const dr2ToDisplay = incl => modeEl.value === 'incl' ? incl : incl / (1 + vatRate / 100);
    const dr2ToCanonicalIncl = displayed => modeEl.value === 'incl' ? displayed : displayed * (1 + vatRate / 100);

    // Johan's ruling, 2026-09-19 — the per-property commission label must
    // match the total it feeds, WORD FOR WORD, and follow the Commission
    // basis selector live. One place sets this text everywhere it appears
    // (the Financials total, the edit screen's add/edit forms, and every
    // create-mode rendered property row) so they can never drift out of
    // sync with each other or with the selector. Create mode has no
    // separate add-form anymore (AT-flow-fix, 2026-09-19 — picking a
    // property renders its row directly), so only its rows need covering.
    function dr2SetCommissionLabelText(text) {
        amtLabel.textContent = text;
        const ids = ['dr2mp_add_commission_label', 'dr2mp_edit_commission_label'];
        ids.forEach(id => { const el = document.getElementById(id); if (el) el.textContent = text; });
        document.querySelectorAll('.dr2-row-commission-label').forEach(el => { el.textContent = text; });
    }

    // The Financials Commission Amount's own canonical Incl-VAT value,
    // preserved ACROSS a basis flip — see the 'mode' branch in recompute()
    // below for why this exists. Seeded from whatever the hidden field
    // already holds on page load (an existing deal's stored Incl-VAT total).
    let dr2FinCanonicalIncl = parseFloat(totalEl.value) || 0;

    function recompute(source) {
        const price = parseFloat(priceEl.value) || 0;
        const mode = modeEl.value; // 'incl' | 'excl'
        dr2SetCommissionLabelText(mode === 'incl' ? 'Commission (Incl VAT)' : 'Commission (Excl VAT)');
        let primary = parseFloat(amtEl.value) || 0;
        let pct = parseFloat(pctEl.value) || 0;
        let incl, excl;
        if (source === 'mode') {
            // AT-Focus-Fix's OWN sibling bug, found while re-verifying point 5
            // in the browser: flipping the basis used to re-derive incl/excl
            // from whatever raw digits were already sitting in amtEl —
            // SILENTLY REINTERPRETING the same typed amount as if it had
            // always meant the other basis (a 20,000 Incl-VAT commission
            // became a 23,000 Incl-VAT commission purely from flipping the
            // dropdown, no digit touched). Johan's ruling forbids exactly
            // this ("do not silently reinterpret... that quietly changes a
            // financial figure without anyone touching it"). Fixed the same
            // way as the per-property rows: convert from a PRESERVED
            // canonical Incl-VAT value, never re-derive it from stale digits.
            incl = dr2FinCanonicalIncl;
            excl = incl / (1 + vatRate / 100);
            primary = mode === 'incl' ? incl : excl;
            pct = price > 0 ? (primary / price) * 100 : 0;
        } else {
            if (source === 'pct') { primary = price > 0 ? price * (pct / 100) : 0; }
            else { pct = price > 0 ? (primary / price) * 100 : 0; }   // 'amount'
            if (mode === 'incl') { incl = primary; excl = incl / (1 + vatRate / 100); }
            else { excl = primary; incl = excl * (1 + vatRate / 100); }
            dr2FinCanonicalIncl = incl;
        }
        const vat = incl - excl;
        if (source !== 'amount') { amtEl.value = primary > 0 ? fmt(primary) : ''; }
        if (source !== 'pct') { pctEl.value = pct > 0 ? fmt(pct) : ''; }
        totalEl.value = incl > 0 ? fmt(incl) : '';   // stored Incl-VAT total (DR1 truth)
        inclDisp.textContent = zar(incl); exclDisp.textContent = zar(excl); vatDisp.textContent = zar(vat);

        // AT-Focus-Fix's sibling bug, Johan 2026-09-18 — commission was
        // reported "off by R17,800" against nothing visible on screen.
        // Root cause: totalCommEl (dr2_total_commission) is a HIDDEN field;
        // setting .value programmatically (right above) never fires its own
        // 'input' event, so the balance banner's OWN listener on that field
        // could structurally never fire from here — it only ever refreshed
        // when the Selling Price field or a property row was touched, so it
        // silently compared the correct live sum against a stale snapshot
        // from whenever THAT last happened. Calling the summary refresh
        // directly, every time recompute() runs for ANY reason, closes that
        // gap for good — window-scoped because dr2cpRecomputeSummary is
        // declared inside a block (function declarations in a block are not
        // hoisted to this outer scope) and may not exist at all on the edit
        // screen, where there is no live balance concept to refresh.
        window.dr2cpRecomputeSummary?.();
        // A basis flip doesn't change any REAL commission amount (see point
        // 5 in .ai/specs/dr2-multi-property.md) — every property row stores
        // its commission canonically as Incl VAT and derives what's shown
        // from the current basis at render time, so flipping the basis only
        // ever needs a re-render, never a value mutation.
        if (source === 'mode') { window.dr2cpRerenderRowsForBasisFlip?.(); }
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
                // "Add another property" eligibility, Johan 2026-09-16 —
                // the primary property just changed (or loaded), so
                // whichever properties are gate-eligible against it changed
                // too. Refreshes whichever mode's dropdown exists on this
                // page directly (rather than calling into the create-mode
                // block's own scoped refresh function, which isn't reliably
                // reachable from here across block scopes) — covers both
                // the initial page load AND a mid-session primary change.
                const dr2AcceptedStatusElNow = document.querySelector('[name="accepted_status"]');
                const dr2PickerAcceptedStatus = dr2AcceptedStatusElNow?.value || 'P';
                const dr2cpPickerEl = document.getElementById('dr2cp_picker');
                if (dr2cpPickerEl) {
                    const dr2cpExcludeNow = (window.dr2cpAdditionalIds || []);
                    loadEligibleDropdown(dr2cpPickerEl, document.getElementById('dr2cp_picker_empty'), pid, dr2cpExcludeNow, { accepted_status: dr2PickerAcceptedStatus });
                }
                const dr2mpPickerEl = document.getElementById('dr2mp_picker');
                if (dr2mpPickerEl) {
                    const dr2mpActiveIdsNow = Array.from(document.querySelectorAll('.dr2mp-row[data-property-id]')).map(el => el.dataset.propertyId);
                    loadEligibleDropdown(dr2mpPickerEl, document.getElementById('dr2mp_picker_empty'), pid, dr2mpActiveIdsNow, { accepted_status: dr2PickerAcceptedStatus, deal_id: DR2.dealId || '' });
                }
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

    // ---------- Per-side external agency = FIRM + contact (mirror of the attorney / bond-originator
    //            picker), one per side. Both sides share ONE add-new modal (dr2_extagency_modal),
    //            writing back into whichever side opened it. ----------
    (function () {
        const xModal = document.getElementById('dr2_extagency_modal');
        if (!xModal) { return; }
        const nxFirm = document.getElementById('dr2_nx_firm'), nxAtt = document.getElementById('dr2_nx_attorney');
        const nxContact = document.getElementById('dr2_nx_contact'), nxEmail = document.getElementById('dr2_nx_email');
        const nxAddress = document.getElementById('dr2_nx_address'), nxErr = document.getElementById('dr2_nx_error');
        const SPEC = '&specialty=external_agency';
        let active = null;

        const fieldsFor = (side) => {
            const p = side === 'listing' ? 'dr2_lext' : 'dr2_sext';
            return {
                box:     document.getElementById(side === 'listing' ? 'dr2-lext' : 'dr2-sext'),
                search:  document.getElementById(p + '_search'),
                name:    document.getElementById(p + '_name'),
                prov:    document.getElementById(p + '_provider_id'),
                contact: document.getElementById(p + '_contact_id'),
                results: document.getElementById(p + '_results'),
                addnew:  document.getElementById(p + '_addnew'),
                chk:     document.getElementById(side + '_external'),
            };
        };

        const wire = (side) => {
            const f = fieldsFor(side);
            if (!f.search) { return; }
            const close = () => { f.results.style.display = 'none'; f.results.innerHTML = ''; };
            const tick  = () => { if (f.chk && !f.chk.checked) { f.chk.checked = true; } };

            // A free-typed name still persists (legacy behaviour) + marks the side external;
            // a picked provider additionally sets the ids that make it an emailable party.
            f.search.addEventListener('input', () => {
                f.prov.value = ''; f.contact.value = ''; f.name.value = f.search.value;
                if (f.search.value.trim() !== '') { tick(); }
            });

            const run = debounce(() => {
                const q = f.search.value.trim();
                if (q.length < 2) { close(); return; }
                fetch(R.attorneySearch + '?q=' + encodeURIComponent(q) + SPEC, { headers: { Accept: 'application/json' } })
                    .then(r => r.ok ? r.json() : { results: [] })
                    .then(data => {
                        const rows = (data && data.results) || [];
                        if (!rows.length) { close(); return; }
                        f.results.innerHTML = rows.map((row, i) => {
                            const line1 = row.firm + (row.attorney ? ' — ' + row.attorney : '');
                            const sub = [row.contact ? 'via ' + row.contact : '', row.email].filter(Boolean).join(' · ');
                            return '<div class="dr2-xsrow" data-i="' + i + '" style="padding:.6rem .8rem;cursor:pointer;border-bottom:1px solid #f3f4f6;"><div style="font-weight:600;color:#0b2a4a;">' + esc(line1) + '</div>' + (sub ? '<div style="font-size:.78rem;color:#6b7280;">' + esc(sub) + '</div>' : '') + '</div>';
                        }).join('');
                        f.results.style.display = 'block';
                        f.results.querySelectorAll('.dr2-xsrow').forEach(el => {
                            const row = rows[parseInt(el.dataset.i, 10)];
                            el.addEventListener('click', () => {
                                f.search.value = row.label; f.name.value = row.label;
                                f.prov.value = row.provider_id || ''; f.contact.value = row.contact_id || '';
                                tick(); close();
                            });
                        });
                    }).catch(close);
            }, 220);
            f.search.addEventListener('input', run);
            f.search.addEventListener('focus', run);
            document.addEventListener('click', e => { if (!e.target.closest('#' + f.box.id)) close(); });

            f.addnew.addEventListener('click', () => {
                active = f;
                nxFirm.value = f.search.value.trim(); nxAtt.value = nxContact.value = nxEmail.value = nxAddress.value = '';
                nxErr.style.display = 'none'; xModal.style.display = 'flex'; nxFirm.focus();
            });
        };

        wire('listing');
        wire('selling');

        document.getElementById('dr2_nx_cancel').addEventListener('click', () => xModal.style.display = 'none');
        xModal.addEventListener('click', e => { if (e.target === xModal) xModal.style.display = 'none'; });
        document.getElementById('dr2_nx_save').addEventListener('click', function () {
            if (!active) { return; }
            const firm = nxFirm.value.trim();
            if (!firm) { nxErr.textContent = 'A firm is required.'; nxErr.style.display = 'block'; return; }
            this.disabled = true;
            fetch(R.attorneyInline + '?specialty=external_agency', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ specialty: 'external_agency', firm, attorney: nxAtt.value.trim() || null, contact: nxContact.value.trim() || null, email: nxEmail.value.trim() || null, address: nxAddress.value.trim() || null }),
            }).then(r => r.json().then(j => ({ ok: r.ok, j }))).then(({ ok, j }) => {
                if (!ok) { nxErr.textContent = (j && j.message) || 'Could not save the external agency.'; nxErr.style.display = 'block'; return; }
                active.search.value = j.label || firm; active.name.value = j.label || firm;
                active.prov.value = j.provider_id || ''; active.contact.value = j.contact_id || '';
                if (active.chk && !active.chk.checked) { active.chk.checked = true; }
                xModal.style.display = 'none';
            }).catch(() => { nxErr.textContent = 'Network error — please try again.'; nxErr.style.display = 'block'; })
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

    // ---------- AT-398 — multi-property add/remove list (edit mode only) ----------
    const dr2mpRoot = document.getElementById('dr2-multi-props');
    if (dr2mpRoot) {
        // Filter the active list by address (client-side — the list is always a
        // handful of rows per deal, never a paginated set).
        const dr2mpFilter = document.getElementById('dr2mp_filter');
        if (dr2mpFilter) {
            dr2mpFilter.addEventListener('input', () => {
                const q = dr2mpFilter.value.trim().toLowerCase();
                dr2mpRoot.querySelectorAll('.dr2mp-row').forEach(row => {
                    row.style.display = !q || row.dataset.address.includes(q) ? '' : 'none';
                });
            });
        }

        // Sort the active list by address / price / date added (client-side,
        // toggles ascending/descending on repeat clicks of the same column).
        let dr2mpSortDir = 1;
        let dr2mpSortKey = null;
        dr2mpRoot.querySelectorAll('.dr2mp-sort-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const key = btn.dataset.sort;
                dr2mpSortDir = (dr2mpSortKey === key) ? -dr2mpSortDir : 1;
                dr2mpSortKey = key;
                const list = document.getElementById('dr2mp_list');
                const rows = Array.from(list.querySelectorAll('.dr2mp-row'));
                rows.sort((a, b) => {
                    const av = key === 'price' || key === 'added' ? parseFloat(a.dataset[key]) || 0 : a.dataset[key];
                    const bv = key === 'price' || key === 'added' ? parseFloat(b.dataset[key]) || 0 : b.dataset[key];
                    return av < bv ? -dr2mpSortDir : av > bv ? dr2mpSortDir : 0;
                });
                rows.forEach(r => list.appendChild(r));
            });
        });

        // Edit price — one shared inline box, filled in for whichever row was clicked.
        const dr2mpEditBox = document.getElementById('dr2mp_edit_box');
        const dr2mpEditForm = document.getElementById('dr2mp_edit_form');
        const dr2mpEditLabel = document.getElementById('dr2mp_edit_label');
        const dr2mpEditPrice = document.getElementById('dr2mp_edit_price');
        const dr2mpEditCommission = document.getElementById('dr2mp_edit_commission');
        dr2mpRoot.querySelectorAll('.dr2mp-edit-price').forEach(btn => {
            btn.addEventListener('click', () => {
                dr2mpEditLabel.textContent = 'Editing ' + btn.dataset.address;
                dr2mpEditPrice.value = btn.dataset.price || '';
                // btn.dataset.commission is allocated_commission, stored
                // canonically Incl VAT — display it in whatever basis is
                // currently selected, same as every other commission field.
                const editCommIncl = parseFloat(btn.dataset.commission) || 0;
                dr2mpEditCommission.value = editCommIncl ? fmt(dr2ToDisplay(editCommIncl)) : '';
                dr2mpEditForm.action = R.propertiesUpdatePrice.replace('__ID__', btn.dataset.property);
                dr2mpEditBox.style.display = '';
                dr2mpEditBox.scrollIntoView({ block: 'nearest' });
            });
        });
        const dr2mpEditCancel = document.getElementById('dr2mp_edit_cancel');
        if (dr2mpEditCancel) dr2mpEditCancel.addEventListener('click', () => { dr2mpEditBox.style.display = 'none'; });
        // Convert what's DISPLAYED (whichever basis is selected right now)
        // back to canonical Incl VAT right before the browser reads the
        // field for submission — the server (and every sum this feeds)
        // expects allocated_commission to always be Incl VAT.
        dr2mpEditForm.addEventListener('submit', () => {
            dr2mpEditCommission.value = fmt(dr2ToCanonicalIncl(parseFloat(dr2mpEditCommission.value) || 0));
        });

        // Remove — confirm before submitting the (soft-delete) form. These forms
        // live OUTSIDE dr2mpRoot now (see the "AT-398 standalone forms" block
        // after the page's main form) — only the trigger button is inside the
        // list; the submit event still fires on the form itself either way.
        document.querySelectorAll('.dr2mp-remove-form').forEach(form => {
            form.addEventListener('submit', e => {
                if (!confirm('Remove ' + form.dataset.address + ' from this deal?')) e.preventDefault();
            });
        });

        // Add another property, Johan 2026-09-16 — a plain dropdown of
        // gate-eligible properties only, populated by the SAME
        // loadEligibleDropdown() create mode uses below (one
        // implementation, not two). Page reloads on every real add/remove
        // here (edit mode's forms are real page-POSTs, not AJAX), so this
        // only ever needs to load once.
        const dr2mpPicker = document.getElementById('dr2mp_picker');
        const dr2mpPickerEmpty = document.getElementById('dr2mp_picker_empty');
        const dr2mpAddForm = document.getElementById('dr2mp_add_form');
        const dr2mpAddPrice = document.getElementById('dr2mp_add_price');
        const dr2mpAddCommission = document.getElementById('dr2mp_add_commission');
        const dr2mpAddLabel = document.getElementById('dr2mp_add_label');
        const dr2mpActiveIds = Array.from(dr2mpRoot.querySelectorAll('.dr2mp-row[data-property-id]')).map(el => el.dataset.propertyId);
        const dr2AcceptedStatusEl = document.querySelector('[name="accepted_status"]');
        loadEligibleDropdown(dr2mpPicker, dr2mpPickerEmpty, pId.value, dr2mpActiveIds, { accepted_status: dr2AcceptedStatusEl?.value || 'P', deal_id: DR2.dealId || '' });
        dr2mpPicker.addEventListener('change', () => {
            const opt = dr2mpPicker.selectedOptions[0];
            if (!opt || !opt.value) { dr2mpAddForm.style.display = 'none'; return; }
            dr2mpAddLabel.textContent = 'Adding ' + (opt.dataset.address || opt.textContent);
            dr2mpAddPrice.value = opt.dataset.price ? Number(opt.dataset.price) : '';
            dr2mpAddCommission.value = '';
            dr2mpAddForm.style.display = '';
            // Johan's ruling, 2026-09-19 — the money for a picked property is
            // entered here in Financials, not at the top; scroll it into view.
            dr2mpAddForm.scrollIntoView({ block: 'center', behavior: 'smooth' });
        });
        const dr2mpAddCancel = document.getElementById('dr2mp_add_cancel');
        if (dr2mpAddCancel) dr2mpAddCancel.addEventListener('click', () => {
            dr2mpAddForm.style.display = 'none'; dr2mpPicker.value = '';
            dr2mpAddPrice.value = ''; dr2mpAddCommission.value = '';
        });
        // Same canonical-Incl-VAT conversion as the edit form above.
        const dr2mpAddFormReal = document.getElementById('dr2mp_add_form_real');
        if (dr2mpAddFormReal) dr2mpAddFormReal.addEventListener('submit', () => {
            dr2mpAddCommission.value = fmt(dr2ToCanonicalIncl(parseFloat(dr2mpAddCommission.value) || 0));
        });
    }

    // ---------- Create-time multi-property staging (Johan, 2026-09-14/16, ----------
    // ---------- flow rebuilt 2026-09-19 — see .ai/specs/dr2-multi-property.md §8e --
    // Held entirely client-side until the ONE "Save Deal" submit — see
    // DealRegisterController::store() for the server-side persistence.
    //
    // AT-flow-fix, Johan 2026-09-19, verbatim: "Picking the 2nd property is
    // the trigger to load both, show them and show their selling price and
    // comm fields to be completed." SUPERSEDES the earlier design where
    // Selling Price/Commission above were an independently-typed total,
    // reconciled against the sum with a live balance banner and a
    // submit-blocking check. His own correction on his own earlier ruling:
    // "the master must never be a second, independently-typed figure — it
    // IS the sum, displayed." So Selling Price/Commission above are now
    // DERIVED — read-only the moment a second property exists, always equal
    // to the sum of the rows below by construction. There is no longer a
    // second number that could disagree with the parts, so there is nothing
    // left to balance-check, warn about, or block a save over — that whole
    // class of machinery (dr2cpBalanced(), the verdict banner, the
    // submit-time block) is REMOVED, not left inert. Do not reinstate it —
    // see the spec section for the full reasoning and why it would be
    // reintroducing a solved problem, not restoring a safeguard.
    const dr2cpRoot = document.getElementById('dr2cp-multi-props');
    if (dr2cpRoot) {
        // Each entry stores its commission CANONICALLY as Incl VAT, always —
        // matching dr2_total_commission's own established convention (see
        // recompute()'s comment: "stored Incl-VAT total (DR1 truth)"). What
        // a row DISPLAYS is derived from the canonical value at render time
        // using whatever basis is currently selected; what the user TYPES is
        // converted back to canonical before it's stored. This survived the
        // flow rebuild unchanged — still needed, orthogonal to who computes
        // the total (see point 5, .ai/specs/dr2-multi-property.md §8e).
        let dr2cpAdditional = []; // [{propertyId, address, price, commissionIncl}]
        let dr2cpPrimary = null;  // {price, commissionIncl} — set the moment a 2nd property is added

        const dr2cpList = document.getElementById('dr2cp_list');
        const dr2cpCount = document.getElementById('dr2cp_count');
        const dr2cpSingleHint = document.getElementById('dr2cp_single_hint');
        // Not rendered server-side for create mode (a brand-new deal is
        // never $dr2MultiPriced at load) — DealMultiPropertyBladeTest
        // asserts a single-property page never contains this text at all.
        // Created/removed on demand here rather than always rendered
        // hidden (which would have put the text in the response body
        // regardless of CSS visibility) — and the text itself is read from
        // dr2cpRoot's own data-multi-hint attribute, not hardcoded as a JS
        // string literal, for the same reason: a literal would still be
        // part of the compiled <script> output on every page (this same
        // blade file serves edit mode too), defeating the point. The
        // data attribute only exists at all inside the create-mode-only
        // #dr2cp-multi-props block, so edit mode's compiled output never
        // contains this text in ANY form.
        function dr2EnsureSellingPriceMultiHint(show) {
            let el = document.getElementById('dr2_selling_price_multi_hint');
            if (show && !el) {
                el = document.createElement('div');
                el.id = 'dr2_selling_price_multi_hint';
                el.className = 'mt-1 text-xs';
                el.style.color = 'var(--text-faint)';
                el.textContent = dr2cpRoot.dataset.multiHint;
                propValueEl.insertAdjacentElement('afterend', el);
            } else if (!show && el) {
                el.remove();
            }
        }
        const dr2cpOwnerError = document.getElementById('dr2cp_owner_error');
        const dr2cpHiddenInputs = document.getElementById('dr2cp_hidden_inputs');
        const dr2cpPicker = document.getElementById('dr2cp_picker');
        const dr2cpPickerEmpty = document.getElementById('dr2cp_picker_empty');
        const propValueEl = document.getElementById('dr2_property_value');
        const totalCommEl = document.getElementById('dr2_total_commission');

        // dr2ToDisplay/dr2ToCanonicalIncl/dr2FinCanonicalIncl/fmt/zar are
        // shared, outer-scope helpers — see their own docblocks above
        // recompute().
        const dr2CurrentMode = () => modeEl.value; // 'incl' | 'excl'

        const dr2cpShowOwnerError = msg => {
            dr2cpOwnerError.textContent = msg || '';
            dr2cpOwnerError.style.display = msg ? '' : 'none';
        };

        function dr2cpRenderRow(label, isPrimary, price, commissionIncl, onPrice, onCommissionIncl, onRemove) {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:.6rem;padding:.5rem .7rem;border:1px solid var(--border);border-radius:8px;flex-wrap:wrap;';
            const commissionLabelText = dr2CurrentMode() === 'incl' ? 'Commission (Incl VAT)' : 'Commission (Excl VAT)';
            const priceDisplay = fmt(price);
            // Commission starts BLANK for the agent/BM to complete (Johan,
            // point 3 — "exactly as the single-property flow leaves
            // commission for the BM to fill") rather than showing "0.00".
            const commDisplay = commissionIncl ? fmt(dr2ToDisplay(commissionIncl)) : '';
            row.innerHTML = '<div style="min-width:0;flex-shrink:0;"><span style="font-weight:600;color:var(--text-primary);">' + esc(label) + '</span>'
                + (isPrimary ? ' <span title="Pick a different property as primary before removing this one" style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.02em;padding:.05rem .35rem;border-radius:.35rem;color:#065f46;background:#ecfdf5;">Primary</span>' : '') + '</div>'
                + '<div style="display:flex;align-items:center;gap:.5rem;flex-shrink:0;">'
                + '<label class="text-[11px]" style="color:var(--text-muted);">Selling price <input type="number" step="0.01" min="0" class="input-base text-xs dr2-row-price" style="width:110px;" value="' + esc(priceDisplay) + '"></label>'
                + '<label class="text-[11px]" style="color:var(--text-muted);"><span class="dr2-row-commission-label">' + esc(commissionLabelText) + '</span> <input type="number" step="0.01" min="0" class="input-base text-xs dr2-row-commission" style="width:110px;" placeholder="0.00" value="' + esc(commDisplay) + '"></label>'
                + (isPrimary ? '' : '<button type="button" class="dr2-row-remove text-xs" style="color:#b91c1c;background:none;border:none;padding:0;cursor:pointer;font-family:inherit;">Remove</button>')
                + '</div>';
            row.querySelector('.dr2-row-price').addEventListener('input', e => { onPrice(parseFloat(e.target.value) || 0); dr2cpSyncMaster(); });
            row.querySelector('.dr2-row-commission').addEventListener('input', e => { onCommissionIncl(dr2ToCanonicalIncl(parseFloat(e.target.value) || 0)); dr2cpSyncMaster(); });
            const removeBtn = row.querySelector('.dr2-row-remove');
            if (removeBtn) removeBtn.addEventListener('click', () => onRemove());
            return row;
        }

        // AT-Focus-Fix, Johan 2026-09-16 — see .ai/specs/dr2-multi-property.md
        // §8c for the full history: any field that recalculates live as the
        // user types must update a value/display, never rebuild the DOM
        // subtree that field itself lives in. Still the rule here:
        // dr2cpSyncMaster() never touches dr2cpList and is what every
        // keystroke calls; dr2cpRenderRows() rebuilds the visible rows and
        // is called ONLY on add/remove/basis-flip, never from a
        // value-change listener.
        //
        // Does the job dr2cpRecomputeSummary() used to split off from row
        // rendering, but the job itself changed: instead of computing a
        // sum/total/diff verdict, it WRITES the sum directly into the
        // (now read-only) Selling Price/Commission fields — they ARE the
        // sum, live, per Johan's ruling. The multi branch derives
        // incl/excl/pct/VAT display directly rather than calling
        // recompute(); the single-mode revert branch DOES call
        // recompute('amount') (simplest way to re-derive %/incl/excl/VAT
        // display for the restored figures) — recompute() itself calls
        // window.dr2cpRecomputeSummary?.() at its end, i.e. straight back
        // into this function, so a re-entrancy guard is required or the
        // two calls recurse forever (found live in browser verification,
        // "Maximum call stack size exceeded", 2026-09-19).
        let dr2cpSyncingMaster = false;
        function dr2cpSyncMaster() {
            if (dr2cpSyncingMaster) { return; }
            dr2cpSyncingMaster = true;
            try {
                dr2cpSyncMasterBody();
            } finally {
                dr2cpSyncingMaster = false;
            }
        }
        function dr2cpSyncMasterBody() {
            window.dr2cpAdditionalIds = dr2cpAdditional.map(p => p.propertyId);
            const multi = dr2cpAdditional.length > 0;
            dr2cpCount.textContent = String(1 + dr2cpAdditional.length);
            dr2cpSingleHint.style.display = multi ? 'none' : '';
            dr2EnsureSellingPriceMultiHint(multi);
            propValueEl.readOnly = multi;
            pctEl.readOnly = multi;
            amtEl.readOnly = multi;
            modeEl.disabled = multi;

            if (!multi) {
                dr2cpHiddenInputs.innerHTML = '';
                // Dropped back to one property (the last additional row was
                // removed) — restore the master to the sole remaining
                // property's own figures, not blank, matching how
                // single-property mode already behaves.
                if (dr2cpPrimary) {
                    propValueEl.value = dr2cpPrimary.price > 0 ? fmt(dr2cpPrimary.price) : '';
                    amtEl.value = dr2cpPrimary.commissionIncl > 0 ? fmt(dr2ToDisplay(dr2cpPrimary.commissionIncl)) : '';
                    recompute('amount');
                }
                return;
            }

            const sumPrice = (dr2cpPrimary ? dr2cpPrimary.price : 0) + dr2cpAdditional.reduce((s, p) => s + (parseFloat(p.price) || 0), 0);
            const sumCommissionIncl = (dr2cpPrimary ? dr2cpPrimary.commissionIncl : 0) + dr2cpAdditional.reduce((s, p) => s + (parseFloat(p.commissionIncl) || 0), 0);

            propValueEl.value = sumPrice > 0 ? fmt(sumPrice) : '';
            const mode = modeEl.value;
            const displayedComm = dr2ToDisplay(sumCommissionIncl);
            amtEl.value = sumCommissionIncl > 0 ? fmt(displayedComm) : '';
            pctEl.value = (sumCommissionIncl > 0 && sumPrice > 0) ? fmt((displayedComm / sumPrice) * 100) : '';
            const excl = sumCommissionIncl / (1 + vatRate / 100);
            totalEl.value = sumCommissionIncl > 0 ? fmt(sumCommissionIncl) : '';
            inclDisp.textContent = zar(sumCommissionIncl); exclDisp.textContent = zar(excl); vatDisp.textContent = zar(sumCommissionIncl - excl);
            dr2FinCanonicalIncl = sumCommissionIncl; // keep the shared canonical in sync with the derived master

            dr2cpHiddenInputs.innerHTML = '';
            dr2cpAdditional.forEach((p, idx) => {
                ['property_id', 'allocated_price', 'allocated_commission'].forEach(field => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'properties[' + idx + '][' + field + ']';
                    input.value = field === 'property_id' ? p.propertyId : field === 'allocated_price' ? p.price : p.commissionIncl;
                    dr2cpHiddenInputs.appendChild(input);
                });
            });
            // The primary's own row travels as properties[N] too (N = additional
            // count) — store() tells it apart from the others by matching
            // property_id against the deal's own primary property_id, never by
            // array position (see store()'s own handling). Always submitted
            // Incl VAT, same as allocated_commission everywhere else.
            const primaryIdx = dr2cpAdditional.length;
            [['property_id', pId.value], ['allocated_price', dr2cpPrimary.price], ['allocated_commission', dr2cpPrimary.commissionIncl]].forEach(([field, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'properties[' + primaryIdx + '][' + field + ']';
                input.value = value;
                dr2cpHiddenInputs.appendChild(input);
            });
        }
        // recompute() (outer scope, runs when the Financials Commission %/
        // amount fields are edited directly — only reachable in single-
        // property mode now that they're read-only otherwise) calls this
        // via window — see its own docblock for exactly why that
        // indirection exists.
        window.dr2cpRecomputeSummary = dr2cpSyncMaster;

        // Rebuilds the VISIBLE rows — the DOM subtree the user's cursor can
        // actually be inside. Only ever called on a structural change (a
        // property added or removed, or the commission basis flipping,
        // which changes what's DISPLAYED, never the canonical Incl-VAT
        // value stored) — never from a value-change keystroke.
        function dr2cpRenderRows() {
            dr2cpList.innerHTML = '';
            dr2cpSyncMaster();
            if (!dr2cpAdditional.length) { return; }

            dr2cpList.appendChild(dr2cpRenderRow(
                pAddr.value || ('Property #' + pId.value), true, dr2cpPrimary.price, dr2cpPrimary.commissionIncl,
                v => { dr2cpPrimary.price = v; }, v => { dr2cpPrimary.commissionIncl = v; }, () => {},
            ));
            dr2cpAdditional.forEach((p, idx) => {
                dr2cpList.appendChild(dr2cpRenderRow(
                    p.address, false, p.price, p.commissionIncl,
                    v => { p.price = v; }, v => { p.commissionIncl = v; },
                    () => {
                        dr2cpAdditional.splice(idx, 1);
                        // dr2cpRenderRows() -> dr2cpSyncMaster() reads
                        // dr2cpPrimary to restore the single-mode master
                        // fields to the remaining property's own figures —
                        // it must still be set when that runs. Null it out
                        // ONLY after, or the revert silently no-ops and the
                        // master is left showing stale, pre-removal values
                        // (found live in browser verification, 2026-09-19).
                        dr2cpRenderRows();
                        if (dr2cpAdditional.length === 0) { dr2cpPrimary = null; }
                        dr2cpRefreshPicker();
                    },
                ));
            });
        }
        // Called from recompute() (outer scope) whenever the Commission basis
        // selector changes — see point 5 in .ai/specs/dr2-multi-property.md.
        window.dr2cpRerenderRowsForBasisFlip = () => { if (dr2cpAdditional.length) dr2cpRenderRows(); };

        // "Add another property," Johan 2026-09-16 — a plain dropdown of
        // gate-eligible properties, populated by the SAME
        // loadEligibleDropdown() edit mode uses. The dropdown's own
        // server-side filtering (DealPropertyOwnerGate::ownerSetsMatch(),
        // exact-set equality) IS the eligibility check — nothing offered
        // here can ever fail the gate at save time.
        function dr2cpRefreshPicker() {
            const excludeIds = dr2cpAdditional.map(p => p.propertyId);
            const dr2AcceptedStatusEl = document.querySelector('[name="accepted_status"]');
            loadEligibleDropdown(dr2cpPicker, dr2cpPickerEmpty, pId.value, excludeIds, { accepted_status: dr2AcceptedStatusEl?.value || 'P' });
        }

        // AT-flow-fix, Johan 2026-09-19, verbatim: "Picking the 2nd property
        // is the trigger to load both, show them and show their selling
        // price and comm fields to be completed." Selecting IS adding — no
        // separate "Add to deal" confirm step. Price prefills from the
        // property record (its own advertised price, same source the
        // primary property picker already uses); commission starts blank.
        dr2cpPicker.addEventListener('change', () => {
            const opt = dr2cpPicker.selectedOptions[0];
            if (!opt || !opt.value) { return; }
            const id = opt.value;
            const address = opt.dataset.address || opt.textContent;
            const priceFromRecord = opt.dataset.price ? Number(opt.dataset.price) : 0;

            if (dr2cpAdditional.length === 0) {
                // First addition — freeze whatever's currently in the main
                // Selling Price/Commission fields as the PRIMARY's own row
                // (its own captured figures so far, from the single-property
                // flow) before those fields become the derived master.
                dr2cpPrimary = { price: parseFloat(propValueEl.value) || 0, commissionIncl: parseFloat(totalCommEl.value) || 0 };
            }
            dr2cpAdditional.push({ propertyId: id, address, price: priceFromRecord, commissionIncl: 0 });
            dr2cpRenderRows();
            dr2cpRefreshPicker();
            dr2cpPicker.value = ''; // always resets to "Choose a property…" — picking again adds a THIRD, doesn't re-open anything
            dr2cpList.scrollIntoView({ block: 'center', behavior: 'smooth' });
        });

        dr2cpRefreshPicker();
    }
})();
</script>
</x-app-layout>
