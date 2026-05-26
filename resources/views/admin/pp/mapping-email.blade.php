@extends('layouts.corex')

@section('title', 'Private Property — Mapping Email')

@php
    $agentLines = ["PP External Ref\tPP Encrypted Agent ID\tCoreX User ID\tName\tEmail"];
    foreach ($agents as $a) {
        $agentLines[] = implode("\t", [
            $a->pp_external_ref ?? '',
            $a->pp_unique_agent_id ?? '',
            $a->id,
            $a->name ?? '',
            $a->email ?? '',
        ]);
    }
    $agentBlock = implode("\n", $agentLines);

    $listingLines = ["CoreX Property ID\tPP Ref (External)\tPP Listing Feed Ref\tListing Type\tStatus\tAgent\tAddress"];
    foreach ($listings as $p) {
        $addr = trim(($p->address ?? '') . ', ' . ($p->suburb ?? '') . ' ' . ($p->town ?? ''), ', ');
        $listingLines[] = implode("\t", [
            $p->id,
            $p->pp_ref ?? '',
            $p->pp_listing_feed_ref ?? '',
            $p->listing_type ?? '',
            $p->pp_syndication_status ?? '',
            optional($p->agent)->name ?? '',
            $addr,
        ]);
    }
    $listingBlock = implode("\n", $listingLines);

    $emailBody = "Hi,\n\nAs requested, below are the CoreX-side IDs for the mapping exercise.\n\n"
               . "=== AGENTS ===\n" . $agentBlock . "\n\n"
               . "=== LISTINGS ===\n" . $listingBlock . "\n\n"
               . "Agents: " . count($agents) . " | Listings: " . count($listings) . "\n\n"
               . "Regards,\nAndre";
@endphp

@section('corex-content')
<div class="p-6 space-y-6" x-data="{ copied: '' }">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold" style="color:var(--text-primary);">Private Property — Mapping Email</h1>
            <p class="text-sm mt-1" style="color:var(--text-muted);">
                Copy-paste block for PP's stock-file mapping request. Tab-separated so it pastes cleanly into Excel/Sheets.
                {{ count($agents) }} agents · {{ count($listings) }} listings.
            </p>
        </div>
        <a href="{{ route('admin.pp.agents') }}"
           class="px-4 py-2 rounded-md text-sm font-medium"
           style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">
            ← Back to PP Agents
        </a>
    </div>

    <div class="rounded-xl p-4 space-y-3" style="background:var(--surface); border:1px solid var(--border);">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold uppercase tracking-wider" style="color:var(--text-secondary);">Full email body</h2>
            <button type="button"
                    @click="navigator.clipboard.writeText($refs.fullEmail.value); copied='email'; setTimeout(()=>copied='',2000)"
                    class="px-3 py-1.5 rounded-md text-xs font-medium"
                    style="background:var(--brand-button); color:#fff; cursor:pointer;">
                <span x-show="copied!=='email'">Copy email body</span>
                <span x-show="copied==='email'" x-cloak>Copied!</span>
            </button>
        </div>
        <textarea x-ref="fullEmail" readonly rows="14"
                  class="w-full font-mono text-xs p-3 rounded-md"
                  style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">{{ $emailBody }}</textarea>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="rounded-xl p-4 space-y-3" style="background:var(--surface); border:1px solid var(--border);">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase tracking-wider" style="color:var(--text-secondary);">Agents ({{ count($agents) }})</h2>
                <button type="button"
                        @click="navigator.clipboard.writeText($refs.agentBlock.value); copied='agents'; setTimeout(()=>copied='',2000)"
                        class="px-3 py-1.5 rounded-md text-xs font-medium"
                        style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border); cursor:pointer;">
                    <span x-show="copied!=='agents'">Copy agents</span>
                    <span x-show="copied==='agents'" x-cloak>Copied!</span>
                </button>
            </div>
            <textarea x-ref="agentBlock" readonly rows="14"
                      class="w-full font-mono text-xs p-3 rounded-md"
                      style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">{{ $agentBlock }}</textarea>
        </div>

        <div class="rounded-xl p-4 space-y-3" style="background:var(--surface); border:1px solid var(--border);">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase tracking-wider" style="color:var(--text-secondary);">Listings ({{ count($listings) }})</h2>
                <button type="button"
                        @click="navigator.clipboard.writeText($refs.listingBlock.value); copied='listings'; setTimeout(()=>copied='',2000)"
                        class="px-3 py-1.5 rounded-md text-xs font-medium"
                        style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border); cursor:pointer;">
                    <span x-show="copied!=='listings'">Copy listings</span>
                    <span x-show="copied==='listings'" x-cloak>Copied!</span>
                </button>
            </div>
            <textarea x-ref="listingBlock" readonly rows="14"
                      class="w-full font-mono text-xs p-3 rounded-md"
                      style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">{{ $listingBlock }}</textarea>
        </div>
    </div>
</div>
@endsection
