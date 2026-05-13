@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6" x-data="{ search: '' }">
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Select an Agency</h1>
                <p class="text-sm text-white/60">Pick which agency to work with for this session. You can switch anytime from the sidebar.</p>
            </div>
        </div>
    </div>

    <div class="max-w-5xl mx-auto w-full space-y-4">
        @if(session('intended_after_agency_select'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--brand-icon);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div class="flex-1">You will be redirected after selecting.</div>
        </div>
        @endif

        @if(session('info'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--ds-amber);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <div class="flex-1">{{ session('info') }}</div>
        </div>
        @endif

        @if($agencies->count() > 10)
        <div>
            <label for="agency-search" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Search</label>
            <input id="agency-search" type="text" x-model="search" placeholder="Search agencies..."
                   class="w-full max-w-sm rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
        </div>
        @endif

        @if($agencies->isEmpty())
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No agencies found</h3>
                <p class="text-sm" style="color: var(--text-muted);">There are no agencies available to select.</p>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($agencies as $ag)
                <div class="rounded-md p-4 flex flex-col"
                     style="background: var(--surface); border: 1px solid var(--border);"
                     x-show="!search || '{{ strtolower(addslashes($ag->name)) }}'.includes(search.toLowerCase())">
                    @php
                        $grantExpiresIso = $accessGrants[$ag->id] ?? null;
                        $hasLiveGrant = false;
                        $grantRemaining = null;
                        if ($grantExpiresIso) {
                            $expiresAt = \Illuminate\Support\Carbon::parse($grantExpiresIso);
                            if ($expiresAt->isFuture()) {
                                $hasLiveGrant = true;
                                $hoursLeft = (int) floor(now()->diffInMinutes($expiresAt) / 60);
                                $grantRemaining = $hoursLeft >= 1
                                    ? $hoursLeft . 'h'
                                    : max(1, (int) now()->diffInMinutes($expiresAt)) . 'm';
                            }
                        }
                        $requiresAuth = (bool) ($ag->require_external_access_authorization ?? false);
                        $showLock = $requiresAuth && !$hasLiveGrant;
                    @endphp
                    <div class="flex items-center gap-3 mb-3">
                        @if($ag->logo_path)
                        <img src="{{ Storage::url($ag->logo_path) }}" alt="" class="w-10 h-10 rounded-md object-cover" style="background: var(--surface-2);">
                        @else
                        <div class="w-10 h-10 rounded-md flex items-center justify-center text-sm font-bold"
                             style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                            {{ strtoupper(substr($ag->name, 0, 2)) }}
                        </div>
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold truncate flex items-center gap-1.5" style="color: var(--text-primary);">
                                <span class="truncate">{{ $ag->name }}</span>
                                @if($hasLiveGrant)
                                <span title="Access granted — {{ $grantRemaining }} remaining"
                                      class="text-[10px] px-1.5 py-0.5 rounded font-mono flex-shrink-0"
                                      style="background:color-mix(in srgb, var(--ds-green) 20%, transparent); color:var(--ds-green);">{{ $grantRemaining }}</span>
                                @elseif($showLock)
                                <span title="Requires consent"
                                      class="text-[10px] px-1.5 py-0.5 rounded flex-shrink-0"
                                      style="background:color-mix(in srgb, var(--ds-amber) 20%, transparent); color:var(--ds-amber);">🔒</span>
                                @endif
                            </div>
                            @if($ag->trading_name && $ag->trading_name !== $ag->name)
                            <div class="text-xs truncate" style="color: var(--text-secondary);">{{ $ag->trading_name }}</div>
                            @endif
                        </div>
                    </div>
                    @php
                        $branchCount = $ag->branches()->count();
                        $userCount = \App\Models\User::where('agency_id', $ag->id)->where('is_active', true)->count();
                    @endphp
                    <div class="text-xs mb-3" style="color: var(--text-muted);">
                        {{ number_format($branchCount) }} {{ Str::plural('branch', $branchCount) }} / {{ number_format($userCount) }} {{ Str::plural('user', $userCount) }}
                    </div>
                    <form method="POST" action="{{ route('agency.select.submit', $ag) }}" class="mt-auto">
                        @csrf
                        <button type="submit" class="corex-btn-primary w-full justify-center">Select</button>
                    </form>
                </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
