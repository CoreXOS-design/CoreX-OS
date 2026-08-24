@extends('layouts.corex')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:var(--brand-default);" class="rounded-2xl px-6 py-4">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <h2 class="text-xl font-bold text-white leading-tight">
                {{ $rental->id ? 'Edit Rental' : 'Create Rental' }}
            </h2>
            <a href="{{ route('rentals.index') }}" class="corex-btn-outline text-sm">&larr; Back to Rentals</a>
        </div>
    </div>

    <form method="POST"
          action="{{ $rental->id ? route('rentals.update', $rental->id) : route('rentals.store') }}">
        @csrf

        <div class="ds-status-card p-5 space-y-6">

            <div>
                <h3 class="ds-section-header mb-3">Lease</h3>

                <div class="space-y-4">

                    <div>
                        <label class="block text-xs mb-1" style="color:var(--text-muted)">Branch</label>
                        <select name="branch_id" class="w-full rounded-lg border px-3 py-2 text-sm" style="background:var(--surface); border-color:var(--border); color:var(--text-primary)">
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}"
                                    {{ old('branch_id', $rental->branch_id) == $branch->id ? 'selected' : '' }}>
                                    {{ $branch->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs mb-1" style="color:var(--text-muted)">Address</label>
                        <input type="text"
                               name="lease_address"
                               value="{{ old('lease_address', $rental->lease_address) }}"
                               class="w-full rounded-lg border px-3 py-2 text-sm" style="background:var(--surface); border-color:var(--border); color:var(--text-primary)">
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs mb-1" style="color:var(--text-muted)">Lease Start</label>
                            <input type="date"
                                   name="lease_start_date"
                                   value="{{ old('lease_start_date', optional($rental->lease_start_date)->format('Y-m-d')) }}"
                                   class="w-full rounded-lg border px-3 py-2 text-sm" style="background:var(--surface); border-color:var(--border); color:var(--text-primary)">
                        </div>
                        <div>
                            <label class="block text-xs mb-1" style="color:var(--text-muted)">Lease End</label>
                            <input type="date"
                                   name="lease_end_date"
                                   value="{{ old('lease_end_date', optional($rental->lease_end_date)->format('Y-m-d')) }}"
                                   class="w-full rounded-lg border px-3 py-2 text-sm" style="background:var(--surface); border-color:var(--border); color:var(--text-primary)">
                        </div>
                    </div>

                    <div class="flex gap-6 text-sm" style="color:var(--text-secondary)">
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="is_month_to_month" value="1"
                                   {{ old('is_month_to_month', $rental->is_month_to_month) ? 'checked' : '' }}
                                   class="rounded" style="border-color:var(--border)">
                            Month-to-month
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="is_active" value="1"
                                   {{ old('is_active', $rental->is_active ?? true) ? 'checked' : '' }}
                                   class="rounded" style="border-color:var(--border)">
                            Active
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input type="checkbox" name="is_rental_assist" value="1"
                                   {{ old('is_rental_assist', $rental->is_rental_assist) ? 'checked' : '' }}
                                   class="rounded" style="border-color:var(--border)">
                            Rental assist
                        </label>
                    </div>

                    <div>
                        <label class="block text-xs mb-1" style="color:var(--text-muted)">Agents</label>
                        <select name="rental_agents[]" multiple
                                class="w-full rounded-lg border px-3 py-2 text-sm h-32" style="background:var(--surface); border-color:var(--border); color:var(--text-primary)">
                            @foreach($agents as $agent)
                                <option value="{{ $agent->id }}"
                                    {{ collect(old('rental_agents', $rental->agents->pluck('id') ?? []))->contains($agent->id) ? 'selected' : '' }}>
                                    {{ $agent->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                </div>
            </div>

            <div>
                <h3 class="ds-section-header mb-3">
                    {{ $rental->id ? 'Add Amount Version (optional)' : 'Initial Amount Version' }}
                </h3>

                @if($rental->id && $rental->currentAmountVersion)
                    @php($v = $rental->currentAmountVersion)
                    <div class="ds-status-card p-4 mb-4">
                        <div class="flex items-center justify-between mb-2">
                            <div class="text-sm font-semibold" style="color:var(--text-primary)">Current Amount (latest version)</div>
                            <div class="text-sm" style="color:var(--text-muted)">
                                Effective: {{ optional($v->effective_from)->format('Y-m-d') }}
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div class="flex items-center justify-between">
                                <span style="color:var(--text-secondary)">Rent (incl)</span>
                                <span class="font-medium" style="color:var(--text-primary)">{{ number_format($v->rent_incl, 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span style="color:var(--text-secondary)">Rent (excl)</span>
                                <span class="font-medium" style="color:var(--text-primary)">{{ number_format($v->rent_excl, 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span style="color:var(--text-secondary)">Commission (incl)</span>
                                <span class="font-medium" style="color:var(--text-primary)">{{ number_format($v->commission_incl, 2) }}</span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span style="color:var(--text-secondary)">Commission (excl)</span>
                                <span class="font-medium" style="color:var(--text-primary)">{{ number_format($v->commission_excl, 2) }}</span>
                            </div>
                        </div>
                        <div class="text-xs mt-3" style="color:var(--text-muted)">
                            To change amounts, add a new version below (we keep history).
                        </div>
                    </div>
                @endif

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs mb-1" style="color:var(--text-muted)">Effective From</label>
                        <input type="date" name="effective_from" value="{{ old('effective_from') }}"
                               class="w-full rounded-lg border px-3 py-2 text-sm" style="background:var(--surface); border-color:var(--border); color:var(--text-primary)">
                    </div>
                    <div>
                        <label class="block text-xs mb-1" style="color:var(--text-muted)">Rent (incl)</label>
                        <input type="number" step="0.01" name="rent_incl" id="rent_incl" value="{{ old('rent_incl') }}"
                               class="w-full rounded-lg border px-3 py-2 text-sm" style="background:var(--surface); border-color:var(--border); color:var(--text-primary)">
                    </div>
                    <div>
                        <label class="block text-xs mb-1" style="color:var(--text-muted)">Rent (excl)</label>
                        <input type="number" step="0.01" name="rent_excl" id="rent_excl" value="{{ old('rent_excl') }}"
                               class="w-full rounded-lg border px-3 py-2 text-sm" style="background:var(--surface); border-color:var(--border); color:var(--text-primary)">
                    </div>
                    <div>
                        <label class="block text-xs mb-1" style="color:var(--text-muted)">Commission (incl)</label>
                        <input type="number" step="0.01" name="commission_incl" id="commission_incl" value="{{ old('commission_incl') }}"
                               class="w-full rounded-lg border px-3 py-2 text-sm" style="background:var(--surface); border-color:var(--border); color:var(--text-primary)">
                    </div>
                    <div>
                        <label class="block text-xs mb-1" style="color:var(--text-muted)">Commission (excl)</label>
                        <input type="number" step="0.01" name="commission_excl" id="commission_excl" value="{{ old('commission_excl') }}"
                               class="w-full rounded-lg border px-3 py-2 text-sm" style="background:var(--surface); border-color:var(--border); color:var(--text-primary)">
                    </div>
                </div>
            </div>

            @if($rental->id && $rental->amountVersions->count())
                <div>
                    <h3 class="ds-section-header mb-3">Amount History</h3>
                    <div class="rounded-2xl border overflow-hidden" style="border-color:var(--border)">
                        <table class="min-w-full text-sm ds-table">
                            <thead>
                                <tr class="border-b" style="color:var(--text-secondary); background:var(--surface-2); border-color:var(--border)">
                                    <th class="text-left px-4 py-3">Effective</th>
                                    <th class="text-right px-4 py-3">Rent excl</th>
                                    <th class="text-right px-4 py-3">Commission excl</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y" style="border-color:var(--border)">
                                @foreach($rental->amountVersions as $version)
                                    <tr class="hover:opacity-80">
                                        <td class="px-4 py-3" style="color:var(--text-primary)">{{ $version->effective_from->format('Y-m-d') }}</td>
                                        <td class="px-4 py-3 text-right" style="color:var(--text-primary)">{{ number_format($version->rent_excl, 2) }}</td>
                                        <td class="px-4 py-3 text-right" style="color:var(--text-primary)">{{ number_format($version->commission_excl, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            <div class="flex justify-end">
                <button type="submit" class="corex-btn-primary">Save Rental</button>
            </div>

        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const vat = 1.15;

    const rentIncl = document.getElementById('rent_incl');
    const rentExcl = document.getElementById('rent_excl');
    const commIncl = document.getElementById('commission_incl');
    const commExcl = document.getElementById('commission_excl');

    let isSyncing = false;

    function parseNumber(val) {
        if (val === null || val === undefined) return NaN;
        const s = String(val).trim().replace(/\s+/g, '').replace(',', '.');
        if (s === '') return NaN;
        const n = Number(s);
        return Number.isFinite(n) ? n : NaN;
    }

    function fmt(n) {
        return Number.isFinite(n) ? n.toFixed(2) : '';
    }

    function bindPair(inclEl, exclEl) {
        if (!inclEl || !exclEl) return;

        inclEl.addEventListener('input', function () {
            if (isSyncing) return;
            if (document.activeElement === exclEl) return;

            const incl = parseNumber(inclEl.value);
            if (!Number.isFinite(incl)) {
                if (document.activeElement !== exclEl) {
                    isSyncing = true;
                    exclEl.value = '';
                    isSyncing = false;
                }
                return;
            }

            isSyncing = true;
            exclEl.value = fmt(incl / vat);
            isSyncing = false;
        });

        exclEl.addEventListener('input', function () {
            if (isSyncing) return;
            if (document.activeElement === inclEl) return;

            const excl = parseNumber(exclEl.value);
            if (!Number.isFinite(excl)) {
                if (document.activeElement !== inclEl) {
                    isSyncing = true;
                    inclEl.value = '';
                    isSyncing = false;
                }
                return;
            }

            isSyncing = true;
            inclEl.value = fmt(excl * vat);
            isSyncing = false;
        });
    }

    bindPair(rentIncl, rentExcl);
    bindPair(commIncl, commExcl);
});
</script>

@endsection
