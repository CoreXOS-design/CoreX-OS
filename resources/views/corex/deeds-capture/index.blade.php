@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <h1 class="text-xl font-bold text-white leading-tight">Deeds Capture</h1>
        <p class="text-sm text-white/60 mt-1">
            Properties captured from CMA / deeds lookups, held here for review. These are kept separate from
            Market Intelligence. Confirm a capture to create a real property and link the owner as its owner.
        </p>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green, #16a34a) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green, #16a34a) 35%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if(session('info'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-secondary);">{{ session('info') }}</div>
    @endif

    @if($captures->isEmpty())
        <div class="rounded-md p-8 text-center" style="background: var(--surface); border: 1px solid var(--border);">
            <p class="text-sm" style="color: var(--text-muted);">No deeds captures waiting. Capture a property from CMA Info to see it here.</p>
        </div>
    @else
        <div class="space-y-3">
            @foreach($captures as $tp)
                @php
                    $addr = collect([
                        trim(($tp->street_number ?? '') . ' ' . ($tp->street_name ?? '')),
                        $tp->complex_name,
                        $tp->suburb,
                        $tp->town,
                        $tp->province,
                    ])->filter(fn ($v) => trim((string) $v) !== '')->implode(', ');
                    $owner = $tp->ownerContact;
                    $owners = $tp->owners; // multi-owner (2026-08-12) — falls back to $owner below for pre-migration captures
                @endphp
                <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        {{-- Property --}}
                        <div class="min-w-0 flex-1">
                            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">Property</div>
                            <div class="font-semibold text-sm" style="color: var(--text-primary);">{{ $addr !== '' ? $addr : ('Tracked property #' . $tp->id) }}</div>
                            <div class="text-xs mt-1" style="color: var(--text-muted);">
                                @if($tp->scheme_name)Scheme: {{ $tp->scheme_name }}@if($tp->scheme_number) ({{ $tp->scheme_number }})@endif @endif
                                @if($tp->section_number) · Section {{ $tp->section_number }}@endif
                                @if($tp->erf_number) · Erf {{ $tp->erf_number }}@endif
                                @if($tp->title_deed_number) · Deed {{ $tp->title_deed_number }}@endif
                                @if($tp->cadastral_extent) · {{ $tp->cadastral_extent }} m²@endif
                            </div>
                            <div class="text-xs mt-1" style="color: var(--text-muted);">
                                @if($tp->last_known_sold_price)Sold R {{ number_format((float) $tp->last_known_sold_price, 0, '.', ',') }}@endif
                                @if($tp->last_known_sold_date) on {{ \Illuminate\Support\Carbon::parse($tp->last_known_sold_date)->format('Y-m-d') }}@endif
                                @if($tp->bond_holder) · Bond: {{ $tp->bond_holder }}@if($tp->bond_amount) R {{ number_format((float) $tp->bond_amount, 0, '.', ',') }}@endif @endif
                                @if($tp->sale_type) · {{ $tp->sale_type }}@endif
                            </div>
                        </div>

                        {{-- Owner(s) — multi-owner (2026-08-12): CMA properties can list more than
                             one registered owner; loop tracked_property_owners when present, else
                             fall back to the single ownerContact (pre-migration captures). --}}
                        <div class="min-w-0" style="min-width: 14rem;">
                            <div class="text-[10px] uppercase tracking-wider font-semibold mb-1" style="color: var(--text-muted);">
                                Owner{{ $owners->count() > 1 ? 's' : '' }}
                            </div>
                            @if($owners->isNotEmpty())
                                @foreach($owners as $ownerRow)
                                    <div @if(!$loop->first) class="mt-2 pt-2" style="border-top:1px solid var(--border);" @endif>
                                        <div class="font-semibold text-sm" style="color: var(--text-primary);">
                                            {{ $ownerRow->contact ? trim($ownerRow->contact->first_name . ' ' . (string) $ownerRow->contact->last_name) : ($ownerRow->name ?? 'Unnamed owner') }}
                                        </div>
                                        <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                            @if($ownerRow->id_number)
                                                {{ $ownerRow->id_type === 'company_reg' ? 'Company reg' : 'ID' }}: {{ $ownerRow->id_number }}
                                            @else
                                                <span style="color: var(--ds-amber, #f59e0b);">No owner ID</span>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            @elseif($owner)
                                <div class="font-semibold text-sm" style="color: var(--text-primary);">{{ trim($owner->first_name . ' ' . (string) $owner->last_name) }}</div>
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                    @if($owner->id_number)
                                        {{ $owner->id_type === 'company_reg' ? 'Company reg' : 'ID' }}: {{ $owner->id_number }}
                                    @else
                                        <span style="color: var(--ds-amber, #f59e0b);">No owner ID</span>
                                    @endif
                                </div>
                                <div class="text-xs mt-0.5" style="color: var(--text-muted);">
                                    @if(trim((string) $owner->phone) !== '')
                                        {{ $owner->phone }}
                                    @else
                                        <span style="color: var(--text-muted);">Phone pending — Virtual Agent (phase 2)</span>
                                    @endif
                                </div>
                            @else
                                <div class="text-xs" style="color: var(--text-muted);">No owner captured.</div>
                            @endif
                        </div>

                        {{-- Action --}}
                        <div class="flex-shrink-0">
                            <form method="POST" action="{{ route('corex.deeds-capture.promote', $tp->id) }}"
                                  onsubmit="return confirm('Create a property from this deeds capture and link the owner?');">
                                @csrf
                                <button type="submit" class="text-xs font-semibold px-4 py-2 rounded-md text-white" style="background: var(--brand-button, #0ea5e9);">
                                    Promote to property + contact
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div>{{ $captures->links() }}</div>
    @endif
</div>
@endsection
