{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $uCol      = $users instanceof \Illuminate\Pagination\AbstractPaginator ? $users->getCollection() : $users;
    $totalUsers = is_countable($uCol) ? count($uCol) : 0;
    $roles      = $uCol->pluck('role')->filter()->unique()->sort()->values();
    $branchList = $branches ?? collect();
@endphp

<style>
.ul{--ul-row:54px}
.ul .ul-top{display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
.ul h1.ul-title{font-size:22px;font-weight:700;letter-spacing:-.01em;color:var(--text-primary);display:flex;align-items:baseline;gap:10px;margin:0}
.ul h1.ul-title span{font-size:14px;font-weight:500;color:var(--text-muted)}
.ul .ul-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;height:32px;padding:0 12px;border-radius:6px;border:1px solid var(--border);background:transparent;color:var(--text-primary);font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap}
.ul .ul-btn:hover{background:var(--surface-2)}
.ul .ul-btn.pri{background:var(--brand-button,#0ea5e9);border-color:transparent;color:#fff}
.ul .ul-btn.dng{color:var(--ds-crimson,#c41e3a);border-color:color-mix(in srgb,var(--ds-crimson,#c41e3a) 35%,transparent)}
.ul .ul-tb{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.ul .ul-in{height:34px;border-radius:6px;border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);padding:0 10px;font-size:13px;outline:none}
.ul .ul-in:focus{border-color:var(--brand-icon,#0ea5e9)}
.ul select.ul-in{appearance:none;-webkit-appearance:none;padding-right:28px;cursor:pointer;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%238b97b1' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 9px center}
.ul .ul-bulk{display:flex;align-items:center;gap:14px;padding:8px 12px;border:1px solid color-mix(in srgb,var(--brand-icon,#0ea5e9) 35%,transparent);border-radius:6px;background:color-mix(in srgb,var(--brand-icon,#0ea5e9) 10%,transparent);font-size:13px;font-weight:600;color:var(--brand-icon,#0ea5e9)}
.ul .ul-bulk button.lnk{background:none;border:0;padding:0;font:inherit;color:inherit;cursor:pointer;text-decoration:underline;text-underline-offset:3px}
.ul .ul-tbl{border:1px solid var(--border);border-radius:6px;background:var(--surface);overflow-x:auto}
.ul table{width:100%;border-collapse:collapse;min-width:980px}
.ul th{position:sticky;top:0;text-align:left;font-size:11.5px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);padding:0 12px;height:38px;background:var(--surface-2);border-bottom:1px solid var(--border);white-space:nowrap}
.ul th button.srt{all:unset;cursor:pointer;display:inline-flex;align-items:center;gap:5px}
.ul th button.srt:hover{color:var(--text-primary)}
.ul th button.srt:focus-visible{outline:2px solid var(--brand-icon,#0ea5e9);outline-offset:2px}
.ul td{padding:0 12px;height:var(--ul-row);border-bottom:1px solid var(--border);font-size:13.5px;color:var(--text-primary);white-space:nowrap;vertical-align:middle}
.ul tbody.row:last-of-type td{border-bottom:0}
.ul tr.sel td{background:color-mix(in srgb,var(--brand-icon,#0ea5e9) 8%,transparent)}
.ul .who{display:flex;gap:10px;align-items:center;min-width:0}
.ul .who .av{width:30px;height:30px;border-radius:50%;display:inline-grid;place-items:center;font-size:11px;font-weight:700;flex:none;background:color-mix(in srgb,var(--brand-icon,#0ea5e9) 18%,transparent);color:var(--brand-icon,#0ea5e9)}
.ul .who a.nm{font-weight:600;color:var(--text-primary);text-decoration:none;display:block;line-height:1.2}
.ul .who a.nm:hover{color:var(--brand-icon,#0ea5e9)}
.ul .who small{display:block;color:var(--text-muted);font-size:12.5px}
.ul .cb{width:16px;height:16px;border-radius:4px;border:1.5px solid var(--text-muted);display:inline-grid;place-items:center;background:transparent;padding:0;cursor:pointer;color:#fff}
.ul .cb.on{background:var(--brand-button,#0ea5e9);border-color:transparent}
.ul .stt{display:inline-flex;gap:7px;align-items:center;font-weight:500}
.ul .stt i{width:7px;height:7px;border-radius:50%;background:currentColor;display:inline-block}
/* mixed toward the page text colour so amber/green stay readable on the light theme too */
.ul .stt.ok{color:color-mix(in srgb,var(--ds-green,#059669) 78%,var(--text-primary))}.ul .stt.wa{color:color-mix(in srgb,var(--ds-amber,#d97706) 68%,var(--text-primary))}.ul .stt.mu{color:var(--text-muted)}.ul .stt.bad{color:var(--ds-crimson,#c41e3a)}
.ul .mono{font-family:'DM Mono',ui-monospace,monospace;font-variant-numeric:tabular-nums}
.ul .ibtn{display:inline-grid;place-items:center;width:30px;height:30px;border-radius:6px;border:1px solid transparent;background:transparent;color:var(--text-secondary);cursor:pointer}
.ul .ibtn:hover{background:var(--surface-2)}
.ul .ft{display:flex;justify-content:space-between;align-items:center;padding:4px 2px 0;font-size:13px;color:var(--text-muted)}
.ul .ul-skip{border:1px solid color-mix(in srgb,var(--ds-amber,#f59e0b) 35%,transparent);background:color-mix(in srgb,var(--ds-amber,#f59e0b) 9%,transparent);color:var(--text-primary);border-radius:6px;padding:10px 14px;font-size:13px}
.ul .ul-skip ul{margin:6px 0 0;padding-left:18px}
</style>

<div class="w-full space-y-4 ul" x-data="usersLedger()" x-ref="root">

    {{-- Page header (Ledger — AT-422; spec .ai/specs/users-pages-restyle.md) --}}
    <div class="ul-top">
        <h1 class="ul-title">Users <span>{{ number_format($totalUsers) }} {{ $totalUsers === 1 ? 'person' : 'people' }}</span></h1>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <a href="{{ route('admin.users', ['refresh_p24' => 1]) }}" class="ul-btn" title="Re-fetch the Property24 agent list">Refresh P24</a>
            @if(\Illuminate\Support\Facades\Route::has('admin.pp.agents'))
            <a href="{{ route('admin.pp.agents') }}" class="ul-btn" title="View all agent profiles on Private Property and clean up duplicates">PP Agents</a>
            @endif
            {{-- AT-278 — nav entry for Archived Agents (non-negotiable #2, same day). --}}
            <a href="{{ route('admin.users.archived') }}" class="ul-btn" title="Archived agents — restore an agent and reactivate them">Archived @if(($archivedCount ?? 0) > 0)({{ number_format($archivedCount) }})@endif</a>
            <a href="{{ route('admin.users.create') }}" class="ul-btn pri"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="M12 5v14M5 12h14"/></svg>Add user</a>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ $errors->first() }}
        </div>
    @endif

    {{-- PPRA verification due banner --}}
    @if(($ppraDueCount ?? 0) > 0 && auth()->user()->hasPermission('edit_user_ppra_status'))
    <div class="flex items-center justify-between gap-3 rounded-md px-4 py-3"
         style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);">
        <div class="flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" style="stroke: var(--ds-amber);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" /></svg>
            <span class="text-xs font-semibold" style="color: var(--ds-amber);">{{ number_format($ppraDueCount) }} agent(s) need PPRA re-verification (over 12 months or never verified)</span>
        </div>
        <a href="https://theppra.org.za/agent_agency_search" target="_blank"
           class="text-xs font-semibold px-2 py-1 rounded-md flex-shrink-0"
           style="background: color-mix(in srgb, var(--ds-amber) 15%, transparent); color: var(--ds-amber); text-decoration:none; border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);">Check PPRA Register</a>
    </div>
    @endif


    @if(session('bulk_skipped') && count(session('bulk_skipped')))
    <div class="ul-skip"><strong>Skipped:</strong>
        <ul>@foreach(session('bulk_skipped') as $__s)<li>{{ $__s }}</li>@endforeach</ul>
    </div>
    @endif

    {{-- Toolbar: search + filters (all client-side, instant) --}}
    <div class="ul-tb">
        <label style="position:relative;width:300px;max-width:100%"><span class="sr-only" style="position:absolute;left:-9999px">Search users</span>
            <input type="text" x-model="search" placeholder="Search name or email…" class="ul-in" style="width:100%;padding-left:34px">
            <span style="position:absolute;left:10px;top:9px;color:var(--text-muted)"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></span></label>
        <label><span style="position:absolute;left:-9999px">Role</span><select x-model="roleFilter" class="ul-in"><option value="">Role: All</option>@foreach($roles as $r)<option value="{{ $r }}">{{ ucwords(str_replace('_',' ',$r)) }}</option>@endforeach</select></label>
        <label><span style="position:absolute;left:-9999px">Branch</span><select x-model="branchFilter" class="ul-in"><option value="">Branch: All</option>@foreach($branchList as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select></label>
        <label><span style="position:absolute;left:-9999px">Status</span><select x-model="statusFilter" class="ul-in"><option value="">Status: Any</option><option value="active">Active</option><option value="pending">Invite pending</option><option value="inactive">Inactive</option></select></label>
        <label><span style="position:absolute;left:-9999px">FFC</span><select x-model="ffcFilter" class="ul-in"><option value="">FFC: Any</option><option value="ok">Valid</option><option value="warn">Expiring within 60 days</option><option value="bad">Expired</option><option value="none">None recorded</option></select></label>
        <button type="button" class="ul-btn" x-show="search || roleFilter || branchFilter || statusFilter || ffcFilter" x-cloak @click="clearFilters()">Clear filters</button>
    </div>

    {{-- Bulk bar — appears once anyone is ticked --}}
    <div class="ul-bulk" x-show="sel().length > 0" x-cloak role="region" aria-label="Bulk actions">
        <span x-text="sel().length + ' selected'"></span>
        <button type="button" class="lnk" @click="submitBulk('resend_invite')">Resend invitation</button>
        <span style="flex:1"></span>
        <button type="button" class="lnk" style="color:var(--ds-crimson,#c41e3a)" @click="confirmOpen = true">Deactivate</button>
        <button type="button" class="ibtn" style="width:26px;height:26px" aria-label="Clear selection" @click="selected = []"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="M6 6l12 12M18 6 6 18"/></svg></button>
    </div>

    {{-- User list --}}
    @if($totalUsers === 0)
    <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border:1px solid var(--border);">
        <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
             style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" /></svg>
        </div>
        <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No users yet</h3>
        <p class="text-sm mb-4" style="color: var(--text-muted);">Add your first team member to start managing roles and access.</p>
        <a href="{{ route('admin.users.create') }}" class="corex-btn-primary text-sm">Add User</a>
    </div>
    @endif

    <div class="ul-tbl" x-show="{{ $totalUsers }} > 0" x-cloak>
    <table x-ref="tbl">
        <thead><tr>
            <th scope="col" style="width:42px"><button type="button" class="cb" :class="allVisibleSelected() && 'on'" role="checkbox" :aria-checked="allVisibleSelected()" aria-label="Select everyone shown" @click="toggleAll()"><span x-show="allVisibleSelected()" x-cloak><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="m5 12 5 5 9-10"/></svg></span></button></th>
            <th scope="col" :aria-sort="sortKey==='name' ? (sortDir===1 ? 'ascending' : 'descending') : 'none'"><button type="button" class="srt" @click="sortBy('name')">Name<span x-show="sortKey==='name'" x-cloak x-text="sortDir===1 ? '▲' : '▼'" style="font-size:9px"></span></button></th><th scope="col" :aria-sort="sortKey==='role' ? (sortDir===1 ? 'ascending' : 'descending') : 'none'"><button type="button" class="srt" @click="sortBy('role')">Role<span x-show="sortKey==='role'" x-cloak x-text="sortDir===1 ? '▲' : '▼'" style="font-size:9px"></span></button></th><th scope="col" :aria-sort="sortKey==='branch' ? (sortDir===1 ? 'ascending' : 'descending') : 'none'"><button type="button" class="srt" @click="sortBy('branch')">Branch<span x-show="sortKey==='branch'" x-cloak x-text="sortDir===1 ? '▲' : '▼'" style="font-size:9px"></span></button></th><th scope="col" :aria-sort="sortKey==='status' ? (sortDir===1 ? 'ascending' : 'descending') : 'none'"><button type="button" class="srt" @click="sortBy('status')">Status<span x-show="sortKey==='status'" x-cloak x-text="sortDir===1 ? '▲' : '▼'" style="font-size:9px"></span></button></th><th scope="col" :aria-sort="sortKey==='ffcts' ? (sortDir===1 ? 'ascending' : 'descending') : 'none'"><button type="button" class="srt" @click="sortBy('ffcts')">FFC expiry<span x-show="sortKey==='ffcts'" x-cloak x-text="sortDir===1 ? '▲' : '▼'" style="font-size:9px"></span></button></th><th scope="col" :aria-sort="sortKey==='p24' ? (sortDir===1 ? 'ascending' : 'descending') : 'none'"><button type="button" class="srt" @click="sortBy('p24')">Property24<span x-show="sortKey==='p24'" x-cloak x-text="sortDir===1 ? '▲' : '▼'" style="font-size:9px"></span></button></th><th scope="col" style="text-align:right"><button type="button" class="srt" @click="sortBy('listings')" style="justify-content:flex-end">Listings<span x-show="sortKey==='listings'" x-cloak x-text="sortDir===1 ? '▲' : '▼'" style="font-size:9px"></span></button></th><th scope="col" :aria-sort="sortKey==='last' ? (sortDir===1 ? 'ascending' : 'descending') : 'none'"><button type="button" class="srt" @click="sortBy('last')">Last seen<span x-show="sortKey==='last'" x-cloak x-text="sortDir===1 ? '▲' : '▼'" style="font-size:9px"></span></button></th><th scope="col" style="width:92px"></th>
        </tr></thead>
        @foreach($uCol as $u)
        @php
            $uid       = (int) $u->id;
            $stKey     = ! $u->is_active ? 'inactive' : (! $u->email_verified_at ? 'pending' : 'active');
            $stLabel   = ['active' => 'Active', 'pending' => 'Invite pending', 'inactive' => 'Inactive'][$stKey];
            $stCls     = ['active' => 'ok', 'pending' => 'wa', 'inactive' => 'mu'][$stKey];
            $ffc       = $u->ffc_expiry_date ? \Carbon\Carbon::parse($u->ffc_expiry_date) : null;
            $ffcKey    = ! $ffc ? 'none' : ($ffc->isPast() ? 'bad' : ($ffc->lte(now()->addDays(60)) ? 'warn' : 'ok'));
            $ffcCls    = ['none' => 'mu', 'ok' => 'ok', 'warn' => 'wa', 'bad' => 'bad'][$ffcKey];
            $branchNm  = optional($branchList->firstWhere('id', $u->branch_id))->name;
            $p24Id     = $p24AgentMap[$u->id] ?? null;
            $nListings = (int) (($listingCounts ?? collect())[$u->id] ?? 0);
            $lastAt    = ($lastSeen ?? collect())[$u->id] ?? null;
            $lastTs    = $lastAt ? \Carbon\Carbon::parse($lastAt) : null;
            $initials  = strtoupper(substr($u->name,0,1)) . strtoupper(substr(strstr($u->name,' ') ?: '',1,1));
        @endphp
        <tbody class="row" x-data="{ open: false }" x-show="visible($el)"
               data-id="{{ $uid }}" data-name="{{ strtolower($u->name) }}" data-email="{{ strtolower($u->email) }}"
               data-role="{{ $u->role }}" data-branch="{{ $u->branch_id }}" data-branchname="{{ strtolower((string) $branchNm) }}" data-status="{{ $stKey }}"
               data-ffc="{{ $ffcKey }}" data-ffcts="{{ $ffc ? $ffc->timestamp : 9999999999 }}" data-p24="{{ $p24Id ? (int) $p24Id : 0 }}"
               data-listings="{{ $nListings }}" data-last="{{ $lastTs ? $lastTs->timestamp : 0 }}">
            <tr :class="selected.includes({{ $uid }}) && 'sel'">
                <td><button type="button" class="cb" :class="selected.includes({{ $uid }}) && 'on'" role="checkbox" :aria-checked="selected.includes({{ $uid }})" aria-label="Select {{ $u->name }}" @click="toggleRow({{ $uid }})"><span x-show="selected.includes({{ $uid }})" x-cloak><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="m5 12 5 5 9-10"/></svg></span></button></td>
                <td><div class="who"><span class="av">{{ $initials }}</span><div style="min-width:0"><a class="nm" href="{{ route('admin.users.edit', $u) }}">{{ $u->name }}</a><small>{{ $u->email }}</small></div></div></td>
                <td style="text-transform:capitalize">{{ str_replace('_',' ',$u->role) }}</td>
                <td>{{ $branchNm ?: '—' }}</td>
                <td><span class="stt {{ $stCls }}"><i></i>{{ $stLabel }}</span></td>
                <td>@if($ffc)<span class="stt {{ $ffcCls }}" title="{{ ['ok'=>'Valid','warn'=>'Expires within 60 days','bad'=>'Expired'][$ffcKey] }}"><span class="mono">{{ $ffc->format('d M Y') }}</span></span>@else<span class="stt mu">—</span>@endif</td>
                <td>@if($p24Id)<span class="mono" title="Property24 Agent ID">{{ $p24Id }}</span>@else
                    <form method="POST" action="{{ route('admin.users.sync-p24', $u) }}" class="inline">@csrf
                        <button type="submit" class="ul-btn" style="height:26px;padding:0 9px;font-size:12px;color:var(--ds-amber,#d97706);border-color:color-mix(in srgb,var(--ds-amber,#f59e0b) 35%,transparent)" title="Push this agent to Property24 to get an agent ID">Sync to P24</button>
                    </form>@endif</td>
                <td class="mono" style="text-align:right">{{ $nListings ?: '—' }}</td>
                <td style="color:var(--text-muted)" title="{{ $lastTs ? $lastTs->format('j M Y H:i') : '' }}">{{ $lastTs ? $lastTs->diffForHumans() : 'Never' }}</td>
                <td style="text-align:right;white-space:nowrap"><a href="{{ route('admin.users.edit', $u) }}" class="ibtn" title="Edit {{ $u->name }}" aria-label="Edit {{ $u->name }}"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="M4 20h4L19 9l-4-4L4 16z"/></svg></a><button type="button" class="ibtn" @click="open = !open" :aria-expanded="open" aria-label="Quick edit {{ $u->name }}" title="Quick edit"><span :style="open ? 'transform:rotate(90deg)' : ''" style="display:inline-flex;transition:transform .15s"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" ><path d="m9 6 6 6-6 6"/></svg></span></button></td>
            </tr>
            <tr x-show="open" x-cloak><td colspan="10" style="padding:0;height:auto;border-bottom:1px solid var(--border);white-space:normal">
            {{-- ── Expanded edit panel ── --}}
            <div x-show="open" x-cloak x-transition
                 style="border-top:1px solid var(--border); background:var(--surface-2);">
                <form id="roleForm-{{ $u->id }}" method="POST" action="{{ route('admin.users.role.update', $u) }}"
                      enctype="multipart/form-data"
                      class="p-4 space-y-5">
                    @csrf

                    {{-- Section: Role & Access --}}
                    <div>
                        <div class="text-xs font-bold uppercase tracking-widest mb-3"
                             style="color:var(--text-muted); border-left:2px solid var(--brand-icon, #0ea5e9); padding-left:8px;">
                            Role &amp; Access
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Role</label>
                                <select name="role" class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    @foreach(\App\Models\Role::allRoles(auth()->user()?->effectiveAgencyId()) as $role)
                                        @if(!$role->is_owner)
                                        <option value="{{ $role->name }}" {{ $u->role===$role->name?'selected':'' }}>{{ $role->label }}</option>
                                        @endif
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Branch</label>
                                <select name="branch_id" class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="">(no branch)</option>
                                    @foreach($branchList as $b)
                                    <option value="{{ $b->id }}" {{ (string)$u->branch_id===(string)$b->id?'selected':'' }}>{{ $b->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Designation</label>
                                <select name="designation" class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    @php $des = old('designation', $u->designation ?? ''); @endphp
                                    <option value="" {{ $des===''?'selected':'' }}>(none)</option>
                                    @foreach(($designations ?? []) as $d)
                                    <option value="{{ $d->name }}" {{ $des===$d->name?'selected':'' }}>{{ $d->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-4 mt-3">
                            <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-secondary);">
                                <input type="hidden" name="can_capture_rentals" value="0">
                                <input type="checkbox" name="can_capture_rentals" value="1" class="rounded"
                                       {{ old('can_capture_rentals',(int)($u->can_capture_rentals??0)) ? 'checked' : '' }}>
                                Can Capture Rentals
                            </label>
                            <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-secondary);">
                                <input type="hidden" name="counts_for_branch_split" value="0">
                                <input type="checkbox" name="counts_for_branch_split" value="1" class="rounded"
                                       {{ old('counts_for_branch_split',(int)($u->counts_for_branch_split??1)) ? 'checked' : '' }}>
                                Counts for Branch Split
                            </label>
                        </div>
                    </div>

                    {{-- Section: Finance --}}
                    <div>
                        <div class="text-xs font-bold uppercase tracking-widest mb-3"
                             style="color:var(--text-muted); border-left:2px solid var(--brand-icon, #0ea5e9); padding-left:8px;">
                            Finance
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 items-end">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Agent Cut %</label>
                                <input type="number" step="0.01" min="0" max="100" name="agent_cut_percent"
                                       value="{{ old('agent_cut_percent', $u->agent_cut_percent ?? 50) }}"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">PAYE Method</label>
                                @php $pm = old('paye_method', $u->paye_method ?? 'percentage'); @endphp
                                <select name="paye_method" class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                        style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                    <option value="percentage" {{ $pm==='percentage'?'selected':'' }}>Percentage</option>
                                    <option value="fixed"      {{ $pm==='fixed'?'selected':'' }}>Fixed</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">PAYE Value</label>
                                <input type="number" step="0.01" min="0" name="paye_value"
                                       value="{{ old('paye_value', $u->paye_value ?? 0) }}"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div class="flex items-center gap-2 pb-1">
                                <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-secondary);">
                                    <input type="hidden" name="sliding_enabled" value="0">
                                    <input type="checkbox" name="sliding_enabled" value="1" class="rounded"
                                           {{ old('sliding_enabled',(int)($u->sliding_enabled??0)) ? 'checked' : '' }}>
                                    Sliding Scale
                                </label>
                            </div>
                        </div>
                    </div>

                    {{-- Section: Contact Details --}}
                    <div>
                        <div class="text-xs font-bold uppercase tracking-widest mb-3"
                             style="color:var(--text-muted); border-left:2px solid var(--brand-icon, #0ea5e9); padding-left:8px;">
                            Contact Details
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Phone</label>
                                <input type="tel" name="phone" value="{{ old('phone', $u->phone) }}" placeholder="Landline"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Cell</label>
                                <input type="tel" name="cell" value="{{ old('cell', $u->cell) }}" placeholder="Mobile"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-xs" style="color:var(--text-secondary);">WhatsApp</label>
                                    <button type="button"
                                            onclick="const f=this.closest('form'); const w=f.querySelector('[name=whatsapp_number]'); w.value=f.querySelector('[name=cell]').value; w.dispatchEvent(new Event('input'));"
                                            class="text-xs font-medium" style="color:var(--brand-icon, #0ea5e9);">Same as cell</button>
                                </div>
                                <input type="tel" name="whatsapp_number" value="{{ old('whatsapp_number', $u->whatsapp_number) }}" placeholder="WhatsApp number"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Fax</label>
                                <input type="tel" name="fax" value="{{ old('fax', $u->fax) }}" placeholder="Fax number"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-3 mt-3">
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">FFC Number</label>
                                <input type="text" name="ffc_number" value="{{ old('ffc_number', $u->ffc_number) }}" placeholder="Certificate number"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">Website</label>
                                <input type="url" name="website" value="{{ old('website', $u->website) }}" placeholder="https://…"
                                       class="w-full rounded-md px-3 py-2 text-sm outline-none"
                                       style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                            </div>
                        </div>
                    </div>

                    {{-- Section: Files --}}
                    <div>
                        <div class="text-xs font-bold uppercase tracking-widest mb-3"
                             style="color:var(--text-muted); border-left:2px solid var(--brand-icon, #0ea5e9); padding-left:8px;">
                            Files
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            {{-- Agent Photo — same cropper component as the user-edit page
                                 (custom pan/zoom/face-alignment), so upload behaves identically
                                 whichever screen it's done from. --}}
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">
                                    Agent Photo <span style="color:var(--text-muted);">(jpg/png/webp, max 2MB)</span>
                                </label>
                                <x-agent-photo-cropper name="agent_photo" :current="$u->profilePhotoUrl()" size="64" />
                                @if($u->profilePhotoUrl())
                                <form method="POST" action="{{ route('admin.users.remove-file', $u) }}" class="inline" onsubmit="return confirm('Remove agent photo?')">
                                    @csrf
                                    <input type="hidden" name="field" value="agent_photo">
                                    <button type="submit" class="text-[11px] font-medium mt-2 px-2 py-1 rounded-md transition-colors"
                                            style="color:var(--ds-crimson, #c41e3a); background:color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);">
                                        Remove current photo
                                    </button>
                                </form>
                                @endif
                            </div>
                            {{-- FFC Certificate — icon box matches the cropper's visual weight
                                 so this column's file input lines up with the photo column's,
                                 instead of sitting higher (bare text link had no height to speak of). --}}
                            <div>
                                <label class="block text-xs mb-1" style="color:var(--text-secondary);">
                                    FFC Certificate <span style="color:var(--text-muted);">(pdf/jpg/png, max 5MB)</span>
                                </label>
                                @if($u->ffc_certificate_path)
                                <div class="flex items-center gap-3 mb-2 p-2.5 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:var(--brand-icon, #0ea5e9);"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>
                                    <a href="{{ route('admin.users.ffc-certificate.download', $u) }}" target="_blank"
                                       class="text-xs truncate flex-1" style="color:var(--brand-icon, #0ea5e9);">
                                        {{ basename($u->ffc_certificate_path) }}
                                    </a>
                                    <form method="POST" action="{{ route('admin.users.remove-file', $u) }}" class="inline" onsubmit="return confirm('Remove FFC certificate?')">
                                        @csrf
                                        <input type="hidden" name="field" value="ffc_certificate">
                                        <button type="submit" class="text-xs font-semibold flex-shrink-0" style="color:var(--ds-crimson, #c41e3a);">Remove</button>
                                    </form>
                                </div>
                                @endif
                                <input type="file" name="ffc_certificate" accept=".pdf,.jpg,.jpeg,.png"
                                       class="block w-full text-sm rounded-md px-3 py-2"
                                       style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);">
                            </div>
                        </div>
                    </div>

                </form>

                    {{-- Actions (outside main form to avoid nesting) --}}
                    <div class="flex items-center justify-between gap-3 pt-1 px-4 pb-4"
                         style="border-top:1px solid var(--border); padding-top:16px;">
                        <div class="flex items-center gap-3">
                            @php
                                // AT-278 — an inactive agent inside their seat hold cannot be
                                // reactivated. Render the reason ON the control rather than
                                // letting the admin click into a refusal (STANDARDS: No Silent Locks).
                                $hold        = ($seatHolds ?? collect())[$u->id] ?? null;
                                $seatBlocked = ! $u->is_active && $hold && $hold->isBlocking();
                            @endphp
                            @if($seatBlocked && ($canOverride ?? false))
                                {{-- System Owner only. Everyone else sees the plain
                                     disabled button below — the server re-checks the
                                     real role regardless of what renders here. --}}
                                <div x-data="{ open: false, reason: '' }" class="inline-block">
                                    <button type="button" @click="open = true"
                                            class="px-3 py-1.5 rounded-md text-sm font-medium"
                                            style="background:color-mix(in srgb, var(--ds-amber) 12%, transparent); color:var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);"
                                            title="Inactive until {{ $hold->reinstatable_at->format('j M Y') }}">
                                        Override &amp; activate now
                                    </button>

                                    <div x-show="open" x-cloak
                                         class="fixed inset-0 z-50 flex items-center justify-center px-4"
                                         style="background: rgba(0,0,0,0.6);"
                                         @keydown.escape.window="open = false">
                                        <div class="w-full max-w-lg rounded-md p-5 text-left"
                                             style="background: var(--surface); border:1px solid var(--border);"
                                             @click.outside="open = false">
                                            <h3 class="text-base font-bold mb-2" style="color: var(--text-primary);">
                                                Lift the hold on {{ $u->name }}
                                            </h3>
                                            <p class="text-sm mb-3" style="color: var(--text-secondary);">
                                                This hold runs until
                                                <strong>{{ $hold->reinstatable_at->format('j F Y') }}</strong>.
                                                Lifting it early is recorded permanently against this agency.
                                            </p>
                                            <form method="POST" action="{{ route('admin.users.toggle', $u) }}">
                                                @csrf
                                                <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">
                                                    Reason (required, at least 10 characters)
                                                </label>
                                                <textarea name="override_reason" x-model="reason" rows="3" required minlength="10"
                                                          placeholder="e.g. Deactivated in error — confirmed with the principal."
                                                          class="w-full rounded-md px-3 py-2 text-sm"
                                                          style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);"></textarea>
                                                <div class="flex items-center justify-end gap-2 mt-4">
                                                    <button type="button" @click="open = false"
                                                            class="px-3 py-1.5 rounded-md text-xs font-semibold"
                                                            style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                                                        Cancel
                                                    </button>
                                                    <button type="submit" class="corex-btn-primary text-xs"
                                                            :disabled="reason.trim().length < 10">
                                                        Lift hold &amp; activate
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            @elseif($seatBlocked)
                                <button type="button" disabled
                                        class="px-3 py-1.5 rounded-md text-sm font-medium cursor-not-allowed"
                                        style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border); opacity:.75;"
                                        title="Inactive until {{ $hold->reinstatable_at->format('j M Y') }}">
                                    Deactivated — locked until {{ $hold->reinstatable_at->format('j M Y') }}
                                </button>
                            @elseif($u->is_active)
                                {{-- Custom CoreX confirm — a native browser confirm() reads as
                                     a stray Chrome/OS dialog, not part of the product. --}}
                                <div x-data="{ open: false }" class="inline-block">
                                    <button type="button" @click="open = true"
                                            class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
                                            style="background:color-mix(in srgb, var(--ds-crimson) 12%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);">
                                        Deactivate
                                    </button>

                                    <div x-show="open" x-cloak
                                         class="fixed inset-0 z-50 flex items-center justify-center px-4"
                                         style="background: rgba(0,0,0,0.6);"
                                         @keydown.escape.window="open = false">
                                        <div class="w-full max-w-md rounded-md p-5 text-left"
                                             style="background: var(--surface); border:1px solid var(--border);"
                                             @click.outside="open = false">
                                            <h3 class="text-base font-bold mb-2" style="color: var(--text-primary);">
                                                Deactivate {{ $u->name }}?
                                            </h3>
                                            <p class="text-sm mb-4" style="color: var(--text-secondary);">
                                                This stops billing for them immediately, but they cannot be
                                                reactivated for
                                                <strong>{{ config('corex-billing.seat_release.lock_days', 30) }} days</strong>
                                                — only CoreX Dev can lift that early. They keep all their data,
                                                but cannot sign in or do anything on CoreX while deactivated.
                                            </p>
                                            <div class="flex items-center justify-end gap-2">
                                                <button type="button" @click="open = false"
                                                        class="px-3 py-1.5 rounded-md text-xs font-semibold"
                                                        style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                                                    Cancel
                                                </button>
                                                <form method="POST" action="{{ route('admin.users.toggle', $u) }}">
                                                    @csrf
                                                    <button type="submit" class="corex-btn-primary text-xs"
                                                            style="background: var(--ds-crimson); border-color: var(--ds-crimson);">
                                                        Deactivate
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @else
                            <form method="POST" action="{{ route('admin.users.toggle', $u) }}">
                                @csrf
                                <button type="submit"
                                        class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
                                        style="background:color-mix(in srgb, var(--ds-green) 12%, transparent); color:var(--ds-green); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);">
                                    Activate
                                </button>
                            </form>
                            @endif
                            <button type="button"
                                    data-agent-delete
                                    data-user-id="{{ $u->id }}"
                                    data-user-name="{{ $u->name }}"
                                    class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
                                    style="background:color-mix(in srgb, var(--ds-crimson) 12%, transparent); color:var(--ds-crimson); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);">
                                Delete
                            </button>
                        </div>
                        <button type="submit" form="roleForm-{{ $u->id }}" class="corex-btn-primary text-sm">Save Changes</button>
                    </div>
            </div>


            </td></tr>
        </tbody>
        @endforeach
        <tbody x-ref="emptyRow" x-show="visibleCount() === 0 && {{ $totalUsers }} > 0" x-cloak><tr><td colspan="10" style="height:120px;text-align:center;color:var(--text-muted)">No users match these filters. <button type="button" class="lnk" style="all:unset;cursor:pointer;color:var(--brand-icon,#0ea5e9);text-decoration:underline" @click="clearFilters()">Clear filters</button></td></tr></tbody>
    </table>
    </div>
    <div class="ft" x-show="{{ $totalUsers }} > 0" x-cloak><span>Showing <span x-text="visibleCount()"></span> of {{ number_format($totalUsers) }} users</span><span>Click a column heading to sort</span></div>

    {{-- Bulk form (outside the table: no nested forms) --}}
    <form id="bulkForm" method="POST" action="{{ route('admin.users.bulk') }}" x-ref="bulkForm">@csrf
        <input type="hidden" name="action" x-ref="bulkAction" value="">
        <template x-for="id in sel()" :key="id"><input type="hidden" name="user_ids[]" :value="id"></template>
    </form>

    {{-- Custom confirm for bulk Deactivate (a native confirm() reads as a stray browser dialog) --}}
    <div x-show="confirmOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center px-4" style="background:rgba(0,0,0,0.6)" @keydown.escape.window="confirmOpen = false">
        <div class="w-full max-w-md rounded-md p-5" style="background:var(--surface);border:1px solid var(--border)" role="dialog" aria-modal="true" aria-labelledby="bulkDeactTitle" @click.outside="confirmOpen = false">
            <h3 id="bulkDeactTitle" class="text-base font-bold mb-2" style="color:var(--text-primary)" x-text="'Deactivate ' + sel().length + (sel().length === 1 ? ' person?' : ' people?')"></h3>
            <p class="text-sm mb-4" style="color:var(--text-secondary)">They lose access immediately and stop being billed. Anyone deactivated cannot be reactivated for {{ (int) ($holdDays ?? 30) }} days. You cannot deactivate yourself, and anyone already inactive is skipped.</p>
            <div style="display:flex;gap:8px;justify-content:flex-end">
                <button type="button" class="ul-btn" @click="confirmOpen = false">Cancel</button>
                <button type="button" class="ul-btn dng" style="background:color-mix(in srgb,var(--ds-crimson,#c41e3a) 12%,transparent)" @click="confirmOpen = false; submitBulk('deactivate')">Deactivate</button>
            </div>
        </div>
    </div>

</div>

<script>
function usersLedger() {
    return {
        search: '', roleFilter: '', branchFilter: '', statusFilter: '', ffcFilter: '',
        sortKey: 'name', sortDir: 1, selected: [], confirmOpen: false,
        visible(el) {
            const d = el.dataset, q = this.search.trim().toLowerCase();
            return (q === '' || d.name.includes(q) || d.email.includes(q))
                && (this.roleFilter === '' || d.role === this.roleFilter)
                && (this.branchFilter === '' || d.branch === this.branchFilter)
                && (this.statusFilter === '' || d.status === this.statusFilter)
                && (this.ffcFilter === '' || d.ffc === this.ffcFilter);
        },
        rows() { return this.$refs.tbl ? [...this.$refs.tbl.querySelectorAll('tbody[data-id]')] : []; },
        visibleCount() { return this.rows().filter(el => this.visible(el)).length; },
        clearFilters() { this.search = this.roleFilter = this.branchFilter = this.statusFilter = this.ffcFilter = ''; },
        sortBy(key) {
            if (this.sortKey === key) { this.sortDir = -this.sortDir; } else { this.sortKey = key; this.sortDir = 1; }
            const num = ['ffcts', 'p24', 'listings', 'last'].includes(key), dir = this.sortDir;
            const attr = (el) => key === 'branch' ? el.dataset.branchname : el.dataset[key];
            const list = this.rows().sort((a, b) => num
                ? ((+attr(a) || 0) - (+attr(b) || 0)) * dir
                : String(attr(a)).localeCompare(String(attr(b)), undefined, { sensitivity: 'base' }) * dir);
            list.forEach(el => this.$refs.tbl.appendChild(el));
            this.$refs.tbl.appendChild(this.$refs.emptyRow);   // keep the "no match" row last
        },
        toggleRow(id) { this.selected = this.selected.includes(id) ? this.selected.filter(x => x !== id) : [...this.selected, id]; },
        sel() { const vis = new Set(this.rows().filter(el => this.visible(el)).map(el => +el.dataset.id)); return this.selected.filter(id => vis.has(id)); },
        allVisibleSelected() { const v = this.rows().filter(el => this.visible(el)); return v.length > 0 && v.every(el => this.selected.includes(+el.dataset.id)); },
        toggleAll() {
            const ids = this.rows().filter(el => this.visible(el)).map(el => +el.dataset.id);
            this.selected = this.allVisibleSelected() ? this.selected.filter(id => !ids.includes(id)) : [...new Set([...this.selected, ...ids])];
        },
        submitBulk(action) { if (this.sel().length === 0) return; this.$refs.bulkAction.value = action; this.$nextTick(() => this.$refs.bulkForm.submit()); },
    };
}
</script>

@include('admin.users._delete-modal')
@endsection
