@extends('layouts.corex')

@section('corex-content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold" style="color:var(--text-primary);">Market Intelligence</h1>
    </div>

    @if(session('success'))
        <div class="px-4 py-3 rounded-lg text-sm font-medium" style="background:rgba(16,185,129,0.1); color:#10b981; border:1px solid rgba(16,185,129,0.2);">{{ session('success') }}</div>
    @endif

    {{-- Add form --}}
    <details>
        <summary class="text-xs font-medium cursor-pointer px-3 py-1.5 rounded" style="color: #00d4aa; background: color-mix(in srgb, #00d4aa 8%, transparent);">+ Add Market Intelligence</summary>
        <form method="POST" action="{{ route('command-center.settings.market-intelligence.store') }}" class="mt-3 p-4 rounded space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
            @csrf
            <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Address*</label>
                    <input type="text" name="address" required class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Suburb*</label>
                    <input type="text" name="suburb" required class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Area</label>
                    <input type="text" name="area" class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Sold Price (R)*</label>
                    <input type="number" name="sold_price" required class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Sold Date*</label>
                    <input type="date" name="sold_date" required value="{{ now()->toDateString() }}" class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Property Type</label>
                    <select name="property_type" class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="">—</option>
                        @foreach(['house','apartment','townhouse','vacant_land','commercial','farm'] as $t)
                            <option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Bedrooms</label>
                    <input type="number" name="bedrooms" min="0" class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Sqm</label>
                    <input type="number" name="sqm" step="0.01" class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
                <div>
                    <label class="block text-[10px] font-medium mb-1" style="color: var(--text-secondary);">Source Reference*</label>
                    <input type="text" name="source_reference" required placeholder="e.g. P24 URL, agent intel…" class="w-full rounded px-2 py-1.5 text-xs" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                </div>
            </div>
            <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded text-white" style="background: var(--brand-button);">Save Record</button>
        </form>
    </details>

    {{-- Existing records --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <table class="w-full text-sm">
            <thead>
                <tr style="background: var(--surface-2);">
                    <th class="text-left px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Address</th>
                    <th class="text-left px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Suburb</th>
                    <th class="text-right px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Sold Price</th>
                    <th class="text-left px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Sold Date</th>
                    <th class="text-left px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Source</th>
                    <th class="text-center px-4 py-2 text-xs font-medium" style="color: var(--text-muted);">Verified</th>
                </tr>
            </thead>
            <tbody>
                @forelse($records as $record)
                    <tr style="border-bottom: 1px solid var(--border);">
                        <td class="px-4 py-2 text-xs" style="color: var(--text-primary);">{{ $record->address }}</td>
                        <td class="px-4 py-2 text-xs" style="color: var(--text-secondary);">{{ $record->suburb }}</td>
                        <td class="px-4 py-2 text-xs text-right" style="color: var(--text-primary);">R {{ number_format($record->sold_price) }}</td>
                        <td class="px-4 py-2 text-xs" style="color: var(--text-muted);">{{ \Carbon\Carbon::parse($record->sold_date)->format('d M Y') }}</td>
                        <td class="px-4 py-2 text-xs" style="color: var(--text-muted);">{{ $record->source }}</td>
                        <td class="px-4 py-2 text-xs text-center">
                            @if($record->verified)
                                <span style="color: #10b981;">✓</span>
                            @else
                                <form method="POST" action="{{ route('command-center.settings.market-intelligence.verify', $record->id) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-[10px] px-1.5 py-0.5 rounded" style="background: var(--surface-2); color: var(--text-muted);">Verify</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-sm" style="color: var(--text-muted);">No market intelligence records yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        @if(method_exists($records, 'links'))
            <div class="px-4 py-3" style="border-top: 1px solid var(--border);">{{ $records->links() }}</div>
        @endif
    </div>
</div>
@endsection
