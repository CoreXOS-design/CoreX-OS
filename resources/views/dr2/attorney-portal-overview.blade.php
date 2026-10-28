@extends('layouts.corex')
@section('title', 'Attorney Portal')

{{--
    .ai/specs/attorney-portal.md 16 - the one-page overview: is the portal on, how it works in three lines, and every attorney contact
    with their access (who, firm, deals, last sign-in, link status) and Send / Resend / Revoke. Page standard: menu + header fixed, the list
    is the content block and scrolls inside its own frame (header row pinned) so a laptop screen never scrolls the page.
--}}
@section('corex-content')
@php
    $sortUrl = function (string $col) use ($sort, $dir) {
        $next = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
        return request()->fullUrlWithQuery(['sort' => $col, 'dir' => $next, 'page' => null]);
    };
    $arrow = fn (string $col) => $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    $filtered = $q !== '' || $status !== 'all';
    $labels = ['live' => ['Link active', 'po-on'], 'expired' => ['Link expired', 'po-off'], 'revoked' => ['Access removed', 'po-arch'], 'none' => ['Not invited yet', 'po-arch']];
@endphp
<style>
    .po-page { height: 100%; display: flex; flex-direction: column; gap: .6rem; min-height: 0; }
    .po-frame { flex: 1 1 auto; min-height: 0; overflow: auto; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; }
    .po-grid { border-collapse: separate; border-spacing: 0; width: 100%; min-width: 860px; font-size: .8rem; }
    .po-grid th, .po-grid td { padding: .38rem .6rem; border-bottom: 1px solid var(--border); background: var(--surface); text-align: left; vertical-align: middle; overflow-wrap: anywhere; }
    .po-grid thead th { position: sticky; top: 0; z-index: 3; background: var(--surface-2, #f3f4f6); font-weight: 600; color: var(--text-secondary); font-size: .72rem; white-space: nowrap; }
    .po-grid thead th a { color: inherit; text-decoration: none; }
    .po-grid tbody tr:hover td { background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 6%, var(--surface)); }
    .po-grid td.act { white-space: nowrap; text-align: right; }
    .po-sub { display: block; font-size: .68rem; color: var(--text-muted); }
    .po-pill { display: inline-block; padding: .05rem .5rem; border-radius: 99px; font-size: .68rem; font-weight: 600; white-space: nowrap; }
    .po-on { background: color-mix(in srgb, #059669 14%, transparent); color: #047857; }
    .po-off { background: color-mix(in srgb, #f59e0b 18%, transparent); color: #92400e; }
    .po-arch { background: color-mix(in srgb, #6b7280 16%, transparent); color: #4b5563; }
    .po-how { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .6rem; }
    .po-how > div { background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: .45rem .7rem; font-size: .78rem; color: var(--text-secondary); line-height: 1.35; }
    .po-how b { color: var(--text-primary); }
    @media (max-width: 900px) { .po-how { grid-template-columns: 1fr; } }
</style>

<div class="po-page" data-po-page>

    {{-- Header (stays in view): the switch, in one line --}}
    <div class="rounded-md corex-page-banner" style="padding:.7rem 1.1rem; flex: 0 0 auto;">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-2">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Attorney Portal</h1>
                <p class="text-xs" style="color: var(--text-muted); max-width: 62rem;">
                    Give the attorneys on your deals their own link, so they can see their deals and download the documents you would otherwise email them.
                </p>
            </div>
            <div class="text-xs flex items-center gap-3" style="color: var(--text-secondary);" data-po-switch>
                <span class="po-pill {{ $enabled ? 'po-on' : 'po-off' }}" data-po-state>{{ $enabled ? 'Switched on' : 'Switched off' }}</span>
                <span>
                    @if($enabled)
                        Access ends {{ $settings->graceDays() }} {{ \Illuminate\Support\Str::plural('day', $settings->graceDays()) }} after a deal is registered or cancelled.
                        New-document emails: {{ $settings->notifyMode() === 'daily' ? 'once a day' : 'off' }}.
                    @else
                        Attorneys cannot sign in while this is off.
                    @endif
                </span>
                @if($canSettings)
                    <a href="{{ route('corex.settings.attorney-portal.edit') }}" class="corex-btn-outline" style="font-size:.72rem;padding:.2rem .6rem;" data-po-settings>{{ $enabled ? 'Settings' : 'Switch it on in Settings' }}</a>
                @endif
            </div>
        </div>
    </div>

    {{-- How it works, three plain lines --}}
    <div class="po-how" style="flex: 0 0 auto;" data-po-how>
        <div><b>1. You send a link.</b> Press <em>Send link</em> next to an attorney below, or from a deal's Email Parties.</div>
        <div><b>2. They confirm it is them.</b> We email them a one-time code when they open the link. There is no password.</div>
        <div><b>3. They see only their own deals.</b> They can download the documents set on <a href="{{ route('admin.settings.document-distribution') }}" style="color: var(--brand-icon);">Deal Document Distribution</a>. Every look and download is recorded.</div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-3 py-2 text-sm" style="flex:0 0 auto; background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--text-primary);" data-po-flash>{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-3 py-2 text-sm" style="flex:0 0 auto; background: color-mix(in srgb, var(--ds-crimson, #dc2626) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #dc2626) 30%, transparent); color: var(--text-primary);" data-po-error>{{ $errors->first() }}</div>
    @endif

    {{-- Toolbar --}}
    <form method="GET" action="{{ route('deals-v2.attorney-portal.index') }}" class="flex flex-wrap items-center gap-2" style="flex: 0 0 auto;" data-po-filters>
        <input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">
        <input type="search" name="q" value="{{ $q }}" placeholder="Find an attorney or firm..." class="corex-input" style="font-size:.8rem; width: 16rem;" aria-label="Find an attorney or firm" data-po-search>
        <select name="status" class="corex-input" style="font-size:.8rem; width: auto;" aria-label="Show" onchange="this.form.submit()" data-po-status>
            <option value="all" @selected($status === 'all')>Everyone</option>
            <option value="live" @selected($status === 'live')>Link active</option>
            <option value="expired" @selected($status === 'expired')>Link expired</option>
            <option value="revoked" @selected($status === 'revoked')>Access removed</option>
            <option value="none" @selected($status === 'none')>Not invited yet</option>
        </select>
        <button type="submit" class="corex-btn-outline" style="font-size:.75rem;padding:.3rem .7rem;">Search</button>
        @if($filtered)<a href="{{ route('deals-v2.attorney-portal.index') }}" class="text-xs" style="color: var(--brand-icon);">Clear</a>@endif
        <span class="text-xs flex-1" style="color: var(--text-muted); text-align:right;">{{ $live }} with an active link &middot; {{ $rows->total() }} shown</span>
    </form>

    {{-- The attorneys --}}
    <div class="po-frame" data-po-frame>
        @if($rows->total() === 0)
            <div style="padding: 2.5rem 1.5rem; text-align: center;" data-po-empty>
                @if($filtered)
                    <h3 class="text-base font-semibold" style="color: var(--text-primary);">No attorneys match</h3>
                    <p class="text-sm mt-1" style="color: var(--text-muted);"><a href="{{ route('deals-v2.attorney-portal.index') }}" style="color: var(--brand-icon);">Show everyone</a>.</p>
                @else
                    <h3 class="text-base font-semibold" style="color: var(--text-primary);">No attorney contacts yet</h3>
                    <p class="text-sm mt-1" style="color: var(--text-muted);">Add the attorney firm and the person you deal with in the <a href="{{ route('deals-v2.suppliers.index') }}" style="color: var(--brand-icon);">Supplier Directory</a>, then come back here to send them their link.</p>
                @endif
            </div>
        @else
            <table class="po-grid" data-po-grid>
                <thead>
                    <tr>
                        <th scope="col"><a href="{{ $sortUrl('name') }}">Attorney{{ $arrow('name') }}</a></th>
                        <th scope="col"><a href="{{ $sortUrl('firm') }}">Firm{{ $arrow('firm') }}</a></th>
                        <th scope="col"><a href="{{ $sortUrl('deals') }}">Deals they can see{{ $arrow('deals') }}</a></th>
                        <th scope="col"><a href="{{ $sortUrl('login') }}">Last signed in{{ $arrow('login') }}</a></th>
                        <th scope="col"><a href="{{ $sortUrl('status') }}">Link{{ $arrow('status') }}</a></th>
                        <th scope="col" style="text-align:right;">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                        @php [$label, $cls] = $labels[$r['status']]; @endphp
                        <tr data-contact-id="{{ $r['contact_id'] }}">
                            <td style="color: var(--text-primary); font-weight: 500;">{{ $r['name'] }}<span class="po-sub">{{ $r['email'] !== '' ? $r['email'] : 'No email address yet' }}</span></td>
                            <td>{{ $r['firm'] !== '' ? $r['firm'] : '—' }}</td>
                            <td>{{ $r['deals'] }}</td>
                            <td>{{ $r['last_login'] ? $r['last_login']->format('j M Y H:i') : ($r['status'] === 'none' ? '—' : 'Not yet') }}</td>
                            <td>
                                <span class="po-pill {{ $cls }}">{{ $label }}</span>
                                @if($r['status'] === 'live' && $r['expires_at'])<span class="po-sub">Works until {{ $r['expires_at']->format('j M Y') }}</span>@endif
                            </td>
                            <td class="act">
                                @if($canInvite)
                                    @if($r['can_send'])
                                        <form method="POST" action="{{ route('deals-v2.attorney-portal.invite', ['contact' => $r['contact_id']]) }}" style="display:inline;">
                                            @csrf
                                            <button type="submit" class="corex-btn-outline" style="font-size:.72rem;padding:.15rem .55rem;" data-po-send
                                                    title="{{ $r['status'] === 'none' ? 'Email this attorney their personal link.' : 'Email a new link. Any earlier link stops working.' }}">{{ $r['status'] === 'none' ? 'Send link' : 'Send a new link' }}</button>
                                        </form>
                                    @else
                                        <span class="text-xs" style="color: var(--text-muted);" title="{{ $r['why_not'] }}" data-po-cant>{{ $r['why_not'] }}</span>
                                    @endif
                                    @if($r['access_id'] && $r['status'] !== 'revoked')
                                        <form method="POST" action="{{ route('deals-v2.attorney-portal.revoke', ['access' => $r['access_id']]) }}" style="display:inline;" onsubmit="return confirm('Remove this attorney\'s access? Their link and any open page stop working straight away.');">
                                            @csrf
                                            <button type="submit" class="corex-btn-outline" style="font-size:.72rem;padding:.15rem .55rem;" data-po-revoke>Remove access</button>
                                        </form>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Pager --}}
    <div class="flex items-center justify-between gap-3 text-xs" style="flex: 0 0 auto; color: var(--text-muted);" data-po-pager>
        <span>@if($rows->total() > 0) Showing {{ $rows->firstItem() }}&ndash;{{ $rows->lastItem() }} of {{ $rows->total() }} @else 0 attorneys @endif</span>
        @if($rows->hasPages())
            <span class="flex items-center gap-2">
                @if($rows->onFirstPage()) <span style="opacity:.45;">Previous</span> @else <a href="{{ $rows->previousPageUrl() }}" style="color: var(--brand-icon);">Previous</a> @endif
                <span>Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }}</span>
                @if($rows->hasMorePages()) <a href="{{ $rows->nextPageUrl() }}" style="color: var(--brand-icon);">Next</a> @else <span style="opacity:.45;">Next</span> @endif
            </span>
        @endif
    </div>
</div>
@endsection
