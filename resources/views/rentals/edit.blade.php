@extends('layouts.app')

@section('content')

<div class="max-w-4xl mx-auto px-4 py-6">

    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold">
            {{ $rental->id ? 'Edit Rental' : 'Create Rental' }}
        </h1>

        <a href="{{ route('rentals.index') }}"
           class="text-sm px-3 py-2 border rounded hover:bg-gray-50">
            ← Back to Rentals
        </a>
    </div>

    <form method="POST"
          action="{{ $rental->id ? route('rentals.update', $rental->id) : route('rentals.store') }}">

        @csrf

        <div class="bg-white shadow rounded p-6 space-y-6">

            {{-- Lease Section --}}
            <div>

                <h2 class="text-lg font-semibold mb-3">Lease</h2>

                <div class="space-y-4">

                    <div>
                        <label class="block text-sm font-medium">Branch</label>

                        <select name="branch_id" class="w-full border rounded px-3 py-2">

                            @foreach($branches as $branch)

                                <option value="{{ $branch->id }}"
                                    {{ old('branch_id', $rental->branch_id) == $branch->id ? 'selected' : '' }}>
                                    {{ $branch->name }}
                                </option>

                            @endforeach

                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Address</label>

                        <input type="text"
                               name="lease_address"
                               value="{{ old('lease_address', $rental->lease_address) }}"
                               class="w-full border rounded px-3 py-2">
                    </div>

                    <div class="grid grid-cols-2 gap-4">

                        <div>
                            <label class="block text-sm font-medium">Lease Start</label>

                            <input type="date"
                                   name="lease_start_date"
                                   value="{{ old('lease_start_date', optional($rental->lease_start_date)->format('Y-m-d')) }}"
                                   class="w-full border rounded px-3 py-2">
                        </div>

                        <div>
                            <label class="block text-sm font-medium">Lease End</label>

                            <input type="date"
                                   name="lease_end_date"
                                   value="{{ old('lease_end_date', optional($rental->lease_end_date)->format('Y-m-d')) }}"
                                   class="w-full border rounded px-3 py-2">
                        </div>

                    </div>

                    <div class="flex gap-6">

                        <label>
                            <input type="checkbox"
                                   name="is_month_to_month"
                                   value="1"
                                   {{ old('is_month_to_month', $rental->is_month_to_month) ? 'checked' : '' }}>
                            Month-to-month
                        </label>

                        <label>
                            <input type="checkbox"
                                   name="is_active"
                                   value="1"
                                   {{ old('is_active', $rental->is_active ?? true) ? 'checked' : '' }}>
                            Active
                        </label>

                        <label>
                            <input type="checkbox"
                                   name="is_rental_assist"
                                   value="1"
                                   {{ old('is_rental_assist', $rental->is_rental_assist) ? 'checked' : '' }}>
                            Rental assist
                        </label>

                    </div>

                    <div>

                        <label class="block text-sm font-medium">Agents</label>

                        <select name="rental_agents[]"
                                multiple
                                class="w-full border rounded px-3 py-2 h-32">

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


            {{-- Amount Section --}}
            <div>

                <h2 class="text-lg font-semibold mb-3">

                    {{ $rental->id ? 'Add Amount Version (optional)' : 'Initial Amount Version' }}

                </h2>

                @if($rental->id && $rental->currentAmountVersion)
                    @php($v = $rental->currentAmountVersion)

                    <div class="bg-gray-50 border rounded p-4 mb-4">

                        <div class="flex items-center justify-between mb-2">
                            <div class="text-sm font-semibold">Current Amount (latest version)</div>
                            <div class="text-sm text-gray-600">
                                Effective: {{ optional($v->effective_from)->format('Y-m-d') }}
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3 text-sm">

                            <div class="flex items-center justify-between">
                                <span class="text-gray-700">Rent (incl)</span>
                                <span class="font-medium">{{ number_format($v->rent_incl, 2) }}</span>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-gray-700">Rent (excl)</span>
                                <span class="font-medium">{{ number_format($v->rent_excl, 2) }}</span>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-gray-700">Commission (incl)</span>
                                <span class="font-medium">{{ number_format($v->commission_incl, 2) }}</span>
                            </div>

                            <div class="flex items-center justify-between">
                                <span class="text-gray-700">Commission (excl)</span>
                                <span class="font-medium">{{ number_format($v->commission_excl, 2) }}</span>
                            </div>

                        </div>

                        <div class="text-xs text-gray-600 mt-3">
                            To change amounts, add a new version below (we keep history).
                        </div>

                    </div>
                @endif

                <div class="grid grid-cols-2 gap-4">

                    <div>
                        <label class="block text-sm font-medium">Effective From</label>

                        <input type="date"
                               name="effective_from"
                               value="{{ old('effective_from') }}"
                               class="w-full border rounded px-3 py-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Rent (incl)</label>

                        <input type="number"
                               step="0.01"
                               name="rent_incl"
                               id="rent_incl"
                               value="{{ old('rent_incl') }}"
                               class="w-full border rounded px-3 py-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Rent (excl)</label>

                        <input type="number"
                               step="0.01"
                               name="rent_excl"
                               id="rent_excl"
                               value="{{ old('rent_excl') }}"
                               class="w-full border rounded px-3 py-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Commission (incl)</label>

                        <input type="number"
                               step="0.01"
                               name="commission_incl"
                               id="commission_incl"
                               value="{{ old('commission_incl') }}"
                               class="w-full border rounded px-3 py-2">
                    </div>

                    <div>
                        <label class="block text-sm font-medium">Commission (excl)</label>

                        <input type="number"
                               step="0.01"
                               name="commission_excl"
                               id="commission_excl"
                               value="{{ old('commission_excl') }}"
                               class="w-full border rounded px-3 py-2">
                    </div>

                </div>

            </div>


            {{-- Existing Versions --}}
            @if($rental->id && $rental->amountVersions->count())

                <div>

                    <h2 class="text-lg font-semibold mb-3">
                        Amount History
                    </h2>

                    <table class="min-w-full border">

                        <thead>
                            <tr>
                                <th class="border px-3 py-2">Effective</th>
                                <th class="border px-3 py-2">Rent excl</th>
                                <th class="border px-3 py-2">Commission excl</th>
                            </tr>
                        </thead>

                        <tbody>

                            @foreach($rental->amountVersions as $version)

                                <tr>

                                    <td class="border px-3 py-2">
                                        {{ $version->effective_from->format('Y-m-d') }}
                                    </td>

                                    <td class="border px-3 py-2 text-right">
                                        {{ number_format($version->rent_excl, 2) }}
                                    </td>

                                    <td class="border px-3 py-2 text-right">
                                        {{ number_format($version->commission_excl, 2) }}
                                    </td>

                                </tr>

                            @endforeach

                        </tbody>

                    </table>

                </div>

            @endif


            {{-- Save --}}
            <div>

                <button type="submit"
                        class="bg-blue-600 text-white px-6 py-2 rounded hover:bg-blue-700">

                    Save Rental

                </button>

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
