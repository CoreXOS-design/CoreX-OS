<x-app-layout>
    @php
        $isEdit = $deal->exists;
        $hasErrors = $errors->any();
    @endphp

    <div>
        {{-- Sticky header --}}
        <div class="sticky top-0 z-30 -mx-4 -mt-4 mb-0 lg:-mx-6 lg:-mt-6" style="background: var(--surface); border-bottom: 1px solid var(--border);">
            <div class="flex items-center justify-between px-4 sm:px-6 lg:px-8 py-3">
                <div class="flex items-center gap-3 min-w-0">
                    <a href="{{ $isEdit ? route('deals-v2.show', $deal) : route('deals-v2.index') }}" class="inline-flex items-center gap-1 text-sm flex-shrink-0" style="color: var(--text-muted);">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back
                    </a>
                    <span class="flex-shrink-0" style="color: var(--border);">|</span>
                    <h1 class="text-lg font-semibold truncate" style="color: var(--text-primary);">
                        {{ $isEdit ? 'Edit: ' . $deal->reference : 'New Deal' }}
                    </h1>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    @if($locked ?? false)
                        <span class="text-xs px-2.5 py-1 rounded-full font-medium" style="background: rgba(245,158,11,0.15); color: #fbbf24;">Financial fields locked (Paid)</span>
                    @endif
                    <button type="submit" form="dealForm" class="px-4 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium transition-colors">
                        {{ $isEdit ? 'Update Deal' : 'Create Deal' }}
                    </button>
                </div>
            </div>
        </div>

        <div class="p-4 lg:p-6 max-w-5xl mx-auto">
            @if($errors->any())
                <div class="mb-4 p-3 rounded-lg text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: #f87171;">
                    @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif

            <form id="dealForm" method="POST" action="{{ $isEdit ? route('deals-v2.update', $deal) : route('deals-v2.store') }}" class="space-y-6">
                @csrf
                @if($isEdit) @method('PUT') @endif

                {{-- SECTION: Property & Deal Type --}}
                <div class="rounded-xl p-5" style="border: 1px solid var(--border); background: var(--surface);">
                    <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Property & Deal Type</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @if($isEdit)
                            <div>
                                <label class="block text-xs mb-1" style="color: var(--text-muted);">Property</label>
                                <div class="text-sm font-medium" style="color: var(--text-primary);">{{ $deal->property->address ?? '—' }}</div>
                                <input type="hidden" name="property_id" value="{{ $deal->property_id }}">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color: var(--text-muted);">Deal Type</label>
                                <div class="text-sm font-medium" style="color: var(--text-primary);">{{ ucfirst(str_replace('_', ' ', $deal->deal_type)) }}</div>
                                <input type="hidden" name="deal_type" value="{{ $deal->deal_type }}">
                                <input type="hidden" name="pipeline_template_id" value="{{ $deal->pipeline_template_id }}">
                            </div>
                        @else
                            <div>
                                <label class="block text-xs mb-1" style="color: var(--text-muted);">Property ID</label>
                                <input type="number" name="property_id" required value="{{ old('property_id') }}" class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color: var(--text-muted);">Deal Type</label>
                                <select name="deal_type" required class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                    <option value="bond" {{ old('deal_type') === 'bond' ? 'selected' : '' }}>Bond Sale</option>
                                    <option value="cash" {{ old('deal_type') === 'cash' ? 'selected' : '' }}>Cash Sale</option>
                                    <option value="sale_of_2nd" {{ old('deal_type') === 'sale_of_2nd' ? 'selected' : '' }}>Sale of 2nd Property</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color: var(--text-muted);">Pipeline Template</label>
                                <select name="pipeline_template_id" required class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                    @foreach(\App\Models\DealV2\DealPipelineTemplate::active()->orderBy('name')->get() as $tpl)
                                        <option value="{{ $tpl->id }}">{{ $tpl->name }} ({{ $tpl->deal_type }})</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Offer Date</label>
                            <input type="date" name="offer_date" required value="{{ old('offer_date', $deal->offer_date ? $deal->offer_date->format('Y-m-d') : now()->format('Y-m-d')) }}" {{ ($locked ?? false) ? 'disabled' : '' }}
                                   class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                    </div>
                </div>

                {{-- SECTION: Commission --}}
                <div class="rounded-xl p-5" style="border: 1px solid var(--border); background: var(--surface);">
                    <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Commission</h2>
                    @php
                        $incVat = old('total_commission_inc_vat', $isEdit ? ($deal->commission_amount + $deal->commission_vat) : '');
                    @endphp
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4" x-data="{
                        price: {{ old('purchase_price', $deal->purchase_price ?? 0) }},
                        commPct: {{ old('commission_percentage', $deal->commission_percentage ?? 7.5) }},
                        incVat: {{ $incVat ?: 0 }},
                        vr: {{ $vatRate ?? 15 }},
                        get exVat() { return this.incVat > 0 ? this.incVat / (1 + this.vr / 100) : 0; },
                        get vat() { return this.incVat - this.exVat; },
                        calcFromPct() { if (this.price > 0 && this.commPct > 0) { const ex = this.price * (this.commPct / 100); this.incVat = (ex * (1 + this.vr / 100)).toFixed(2); } },
                        calcPctFromInc() { if (this.price > 0 && this.incVat > 0) { const ex = this.incVat / (1 + this.vr / 100); this.commPct = ((ex / this.price) * 100).toFixed(2); } },
                    }">
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Purchase Price (R)</label>
                            <input type="number" name="purchase_price" required x-model="price" @input="calcFromPct()" step="0.01" min="1" {{ ($locked ?? false) ? 'disabled' : '' }}
                                   class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Commission %</label>
                            <input type="number" name="commission_percentage" x-model="commPct" @input="calcFromPct()" step="0.01" min="0" max="100" {{ ($locked ?? false) ? 'disabled' : '' }}
                                   class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Commission (Inc VAT)</label>
                            <input type="number" name="total_commission_inc_vat" x-model="incVat" @input="calcPctFromInc()" step="0.01" min="0" required {{ ($locked ?? false) ? 'disabled' : '' }}
                                   class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Ex VAT / VAT</label>
                            <div class="rounded-md text-sm px-3 py-2 font-mono" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-muted);">
                                R <span x-text="Number(exVat).toLocaleString('en-ZA', {minimumFractionDigits:2})"></span>
                                <span class="text-xs">+ R <span x-text="Number(vat).toLocaleString('en-ZA', {minimumFractionDigits:2})"></span> VAT</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- SECTION: Side Splits + Agents --}}
                <div class="rounded-xl p-5" style="border: 1px solid var(--border); background: var(--surface);">
                    <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Side Splits & Agents</h2>

                    {{-- Listing/Selling split sliders --}}
                    <div class="grid grid-cols-2 gap-4 mb-5" x-data="{
                        lPct: {{ old('listing_split_percent', $deal->listing_split_percent ?? 50) }},
                        sPct: {{ old('selling_split_percent', $deal->selling_split_percent ?? 50) }},
                        syncL(v) { this.lPct = Math.max(0, Math.min(100, parseFloat(v) || 0)); this.sPct = Math.round((100 - this.lPct) * 100) / 100; },
                        syncS(v) { this.sPct = Math.max(0, Math.min(100, parseFloat(v) || 0)); this.lPct = Math.round((100 - this.sPct) * 100) / 100; },
                    }">
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Listing Split %</label>
                            <div class="flex items-center gap-2">
                                <input type="number" name="listing_split_percent" x-model="lPct" @input="syncL($event.target.value)" step="0.01" min="0" max="100" {{ ($locked ?? false) ? 'disabled' : '' }}
                                       class="w-20 rounded-md text-sm px-2 py-1.5 focus:outline-none"
                                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <input type="range" x-model="lPct" @input="syncL($event.target.value)" min="0" max="100" step="0.5" class="flex-1" {{ ($locked ?? false) ? 'disabled' : '' }}>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color: var(--text-muted);">Selling Split %</label>
                            <div class="flex items-center gap-2">
                                <input type="number" name="selling_split_percent" x-model="sPct" @input="syncS($event.target.value)" step="0.01" min="0" max="100" {{ ($locked ?? false) ? 'disabled' : '' }}
                                       class="w-20 rounded-md text-sm px-2 py-1.5 focus:outline-none"
                                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <input type="range" x-model="sPct" @input="syncS($event.target.value)" min="0" max="100" step="0.5" class="flex-1" {{ ($locked ?? false) ? 'disabled' : '' }}>
                            </div>
                        </div>
                    </div>

                    {{-- Listing Side --}}
                    @foreach(['listing', 'selling'] as $side)
                        @php
                            $selectedIds = ${$side . 'SelectedIds'} ?? [];
                            $percents = ${$side . 'Percents'} ?? [];
                        @endphp
                        <div class="rounded-lg p-4 mb-4" style="background: var(--surface-2); border: 1px solid var(--border);">
                            <div class="flex items-center justify-between mb-3">
                                <span class="text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">{{ ucfirst($side) }} Side</span>
                                <label class="inline-flex items-center gap-1.5 text-xs cursor-pointer" style="color: var(--text-secondary);">
                                    <input type="checkbox" name="{{ $side }}_external" value="1" id="{{ $side }}_external_cb"
                                           {{ old($side . '_external', $deal->{$side . '_external'} ?? false) ? 'checked' : '' }}
                                           {{ ($locked ?? false) ? 'disabled' : '' }} class="rounded" style="accent-color: #14b8a6;"
                                           onchange="document.getElementById('{{ $side }}_internal').style.display = this.checked ? 'none' : 'block'; document.getElementById('{{ $side }}_ext_fields').style.display = this.checked ? 'flex' : 'none';">
                                    External agency
                                </label>
                            </div>

                            {{-- External fields --}}
                            <div id="{{ $side }}_ext_fields" class="gap-3 mb-3" style="display: {{ old($side . '_external', $deal->{$side . '_external'} ?? false) ? 'flex' : 'none' }};">
                                <div class="flex-1">
                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Agency Name</label>
                                    <input type="text" name="{{ $side }}_external_agency" value="{{ old($side . '_external_agency', $deal->{$side . '_external_agency'} ?? '') }}" {{ ($locked ?? false) ? 'disabled' : '' }}
                                           class="w-full rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                                <div>
                                    <label class="block text-xs mb-1" style="color: var(--text-muted);">Our Share %</label>
                                    <input type="number" name="{{ $side }}_our_share_percent" value="{{ old($side . '_our_share_percent', $deal->{$side . '_our_share_percent'} ?? 100) }}" step="0.01" min="0" max="100" {{ ($locked ?? false) ? 'disabled' : '' }}
                                           class="w-24 rounded-md text-sm px-3 py-1.5 focus:outline-none"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                </div>
                            </div>

                            {{-- Internal agent selection --}}
                            <div id="{{ $side }}_internal" style="display: {{ old($side . '_external', $deal->{$side . '_external'} ?? false) ? 'none' : 'block' }};">
                                <select id="{{ $side }}_select" class="w-full rounded-md text-sm px-3 py-1 focus:outline-none" multiple size="5" {{ ($locked ?? false) ? 'disabled' : '' }}
                                        style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                    @foreach($agents as $agent)
                                        <option value="{{ $agent->id }}" {{ in_array((string)$agent->id, $selectedIds, true) ? 'selected' : '' }}>{{ $agent->name }}</option>
                                    @endforeach
                                </select>
                                <div class="text-xs mt-1" style="color: var(--text-muted);">Hold Ctrl / Cmd to select multiple</div>
                                <div id="{{ $side }}_selected" class="space-y-2 mt-3"></div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- SECTION: Contacts --}}
                <div class="rounded-xl p-5" style="border: 1px solid var(--border); background: var(--surface);">
                    <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Contacts</h2>
                    <div class="text-xs mb-3" style="color: var(--text-muted);">Contacts are managed on the deal tracking page.</div>
                    @if($isEdit && $deal->contacts->count() > 0)
                        <div class="flex flex-wrap gap-2">
                            @foreach($deal->contacts as $c)
                                <span class="text-xs px-2 py-1 rounded" style="background: var(--surface-2); color: var(--text-secondary);">
                                    {{ $c->full_name }} ({{ $c->pivot->role }})
                                </span>
                                <input type="hidden" name="contacts[{{ $loop->index }}][contact_id]" value="{{ $c->id }}">
                                <input type="hidden" name="contacts[{{ $loop->index }}][role]" value="{{ $c->pivot->role }}">
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- SECTION: Notes --}}
                <div class="rounded-xl p-5" style="border: 1px solid var(--border); background: var(--surface);">
                    <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Notes</h2>
                    <textarea name="notes" rows="3" class="w-full rounded-md text-sm px-3 py-2 focus:outline-none"
                              style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">{{ old('notes', $deal->notes) }}</textarea>
                </div>

                {{-- SECTION: Pipeline Steps (edit mode only) --}}
                @if($isEdit && $deal->stepInstances->count() > 0)
                <div class="rounded-xl p-5" style="border: 1px solid var(--border); background: var(--surface);">
                    <h2 class="text-sm font-semibold uppercase tracking-wider mb-4" style="color: var(--text-muted);">Pipeline Steps</h2>
                    <div class="space-y-1">
                        @foreach($deal->stepInstances as $si)
                            @php
                                $stepBorderColor = match($si->status) {
                                    'completed' => '#34d399',
                                    'active' => ($si->current_rag === 'red' || $si->current_rag === 'overdue') ? '#ef4444' : (($si->current_rag === 'amber') ? '#f59e0b' : '#22c55e'),
                                    'skipped' => 'var(--border)',
                                    default => 'var(--border)',
                                };
                            @endphp
                            <div class="flex items-center gap-3 px-3 py-2 rounded-lg" style="background: var(--surface-2); border-left: 3px solid {{ $stepBorderColor }};">
                                <span class="text-xs font-mono w-5 text-center" style="color: var(--text-muted);">{{ $si->position }}</span>
                                <span class="text-sm font-medium {{ $si->status === 'skipped' ? 'line-through' : '' }}" style="color: var(--text-primary);">{{ $si->name }}</span>
                                <span class="text-xs px-1.5 py-0.5 rounded capitalize" style="background: var(--surface); color: var(--text-muted);">{{ str_replace('_', ' ', $si->status) }}</span>
                                @if($si->status === 'completed' && $si->completed_at)
                                    <span class="text-xs ml-auto" style="color: var(--text-muted);">{{ $si->completed_at->format('d M Y') }}</span>
                                @elseif(in_array($si->status, ['active', 'not_started']))
                                    <input type="date" name="step_dates[{{ $si->id }}]" value="{{ $si->due_date ? $si->due_date->format('Y-m-d') : '' }}"
                                           class="ml-auto rounded text-xs px-2 py-1 focus:outline-none"
                                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </form>
        </div>
    </div>

    <script>
        // Agent multi-select sync — ported from V1's syncSelected()
        const listingPercents = @json($listingPercents ?? []);
        const sellingPercents = @json($sellingPercents ?? []);

        function syncSelected(selectEl, containerEl, sideName, initialPercents) {
            const selectedIds = Array.from(selectEl.selectedOptions).map(o => o.value);

            Array.from(containerEl.querySelectorAll('[data-user-id]')).forEach(row => {
                if (!selectedIds.includes(row.getAttribute('data-user-id'))) row.remove();
            });

            selectedIds.forEach(id => {
                if (containerEl.querySelector('[data-user-id="' + id + '"]')) return;

                const opt = selectEl.querySelector('option[value="' + id + '"]');
                const label = opt ? opt.textContent.trim() : ('User ' + id);
                const initial = (initialPercents && (id in initialPercents)) ? initialPercents[id] : '';

                const row = document.createElement('div');
                row.className = 'flex items-center gap-3';
                row.setAttribute('data-user-id', id);

                row.innerHTML = `
                    <input type="hidden" name="${sideName}_agents[]" value="${id}">
                    <div class="flex-1 text-sm font-medium" style="color:var(--text-primary)">${label}</div>
                    <input type="number" step="0.01" name="${sideName}_override[${id}]" placeholder="% split" class="w-24 rounded-md text-sm px-2 py-1 focus:outline-none" style="background:var(--surface);border:1px solid var(--border);color:var(--text-primary)" value="${initial ?? ''}">
                    <button type="button" class="text-xs px-2 py-1 rounded hover:bg-red-500/20" style="color:var(--text-muted)">✕</button>
                `;

                row.querySelector('button').addEventListener('click', () => {
                    Array.from(selectEl.options).forEach(o => { if (o.value === id) o.selected = false; });
                    row.remove();
                    autoFillSingle(containerEl);
                });

                containerEl.appendChild(row);
            });

            autoFillSingle(containerEl);
        }

        function autoFillSingle(containerEl) {
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

        ['listing', 'selling'].forEach(side => {
            const sel = document.getElementById(side + '_select');
            const container = document.getElementById(side + '_selected');
            const percents = side === 'listing' ? listingPercents : sellingPercents;

            if (sel && container) {
                syncSelected(sel, container, side, percents);
                sel.addEventListener('change', () => syncSelected(sel, container, side, percents));
            }
        });
    </script>
</x-app-layout>
