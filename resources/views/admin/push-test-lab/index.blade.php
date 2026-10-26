{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
     Spec: .ai/specs/push-test-lab.md --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $card = 'background:var(--surface); border:1px solid var(--border);';
    $btn  = 'background:color-mix(in srgb, var(--brand-icon) 10%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 40%, transparent); color:var(--brand-icon);';
    $result = session('push_test_result');
    $levelColour = ['success' => 'var(--ds-green, #16a34a)', 'warning' => 'var(--ds-amber, #d97706)', 'error' => 'var(--ds-red, #ef4444)'];
    $ago = fn ($d) => $d ? $d->diffForHumans() : 'never';
@endphp
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Push Test Lab</h1>
        <p class="text-xs" style="color: var(--text-muted);">
            Send a test notification to one person's phone and see every notification the app is meant to receive. A test goes through the same
            sending route as a real alert, marked <strong>[TEST]</strong>, and ignores the person's own alert settings so it always tries to reach the phone.
        </p>
    </div>

    @if($result)
        <div class="rounded-md px-4 py-3 text-sm" style="border:1px solid {{ $levelColour[$result['level']] ?? 'var(--border)' }}; color:var(--text-primary); background:var(--surface-2);">
            <strong style="color: {{ $levelColour[$result['level']] ?? 'inherit' }};">{{ $result['ok'] ? 'Sent' : 'Not sent' }}.</strong> {{ $result['message'] }}
        </div>
    @endif

    @unless($ready)
        <div class="rounded-md px-4 py-3 text-sm" style="border:1px solid var(--ds-amber, #d97706); background:var(--surface-2); color:var(--text-primary);">
            <strong>Phone notifications are not connected on this server</strong> (no Firebase credentials). You can look around, but a test cannot be delivered from here.
        </div>
    @endunless

    {{-- 1. Find the person --}}
    <div class="rounded-md p-5 space-y-3" style="{{ $card }}">
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">1. Find the person</h2>
        <form method="GET" action="{{ route('admin.push-test-lab.index') }}" class="flex flex-wrap gap-2 items-center">
            <input type="text" name="q" value="{{ $q }}" placeholder="Name, cell number or email" class="text-sm rounded-md px-3 py-2 flex-1 min-w-[16rem]" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <button type="submit" class="px-3 py-2 rounded-md text-xs font-semibold" style="{{ $btn }}">Search</button>
        </form>

        @if($q !== '')
            <div class="grid md:grid-cols-2 gap-4">
                @foreach([['Agents and staff', $staffHits, 'staff'], ['Tenants and owners (app logins)', $clientHits, 'client']] as [$heading, $hits, $kind])
                    <div>
                        <div class="text-xs font-semibold mb-1" style="color:var(--text-secondary);">{{ $heading }}</div>
                        @forelse($hits as $h)
                            <a href="{{ route('admin.push-test-lab.index', ['q' => $q, 'who' => $kind . ':' . $h['id']]) }}"
                               class="block rounded-md px-3 py-2 mb-1 no-underline text-xs"
                               style="border:1px solid {{ $selected && $selected['who'] === $kind . ':' . $h['id'] ? 'var(--brand-icon)' : 'var(--border)' }}; color:var(--text-primary);">
                                <strong>{{ $h['name'] }}</strong>
                                <span style="color:var(--text-muted);"> · {{ $h['cell'] ?: 'no cell number' }} · {{ $h['detail'] }}</span><br>
                                <span style="color: {{ $h['devices'] ? 'var(--ds-green, #16a34a)' : 'var(--ds-red, #ef4444)' }};">{{ $h['devices'] ? $h['devices'] . ' phone(s) registered' : 'no phone registered' }}</span>
                            </a>
                        @empty
                            <div class="text-xs" style="color:var(--text-muted);">No match.</div>
                        @endforelse
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- 2. The chosen person's phones, and the sends --}}
    @if($selected)
        <form method="POST" action="{{ route('admin.push-test-lab.send') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="who" value="{{ $selected['who'] }}">
            <input type="hidden" name="q" value="{{ $q }}">

            <div class="rounded-md p-5 space-y-3" style="{{ $card }}">
                <h2 class="text-sm font-bold" style="color:var(--text-primary);">2. {{ $selected['name'] }} — {{ $selected['kind'] === 'client' ? 'tenant / owner app login' : 'agent / staff' }}</h2>
                @forelse($selected['devices'] as $d)
                    <div class="text-xs" style="color:var(--text-secondary);">
                        {{ strtoupper($d->platform) }} · app {{ $d->app_version ?: 'version unknown' }} · last seen {{ $ago($d->last_seen_at) }} · registered {{ $ago($d->created_at) }} · …{{ substr($d->token, -8) }}
                    </div>
                @empty
                    <div class="text-xs" style="color:var(--ds-red, #ef4444);">No phone is registered for this person. They must open the app and sign in once before a test can reach them.</div>
                @endforelse

                <div class="grid md:grid-cols-3 gap-3 pt-2">
                    <label class="text-xs" style="color:var(--text-secondary);">Open this record when tapped (optional number)
                        <input type="number" name="record_id" min="1" placeholder="e.g. the fault's number" class="mt-1 w-full text-sm rounded-md px-3 py-2" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    </label>
                    <label class="text-xs" style="color:var(--text-secondary);">Custom title
                        <input type="text" name="title" maxlength="80" placeholder="CoreX test notification" class="mt-1 w-full text-sm rounded-md px-3 py-2" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    </label>
                    <label class="text-xs" style="color:var(--text-secondary);">Custom message
                        <input type="text" name="body" maxlength="200" placeholder="If you can read this, notifications are reaching this phone." class="mt-1 w-full text-sm rounded-md px-3 py-2" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                    </label>
                </div>
                <button type="submit" name="what" value="custom" class="px-3 py-2 rounded-md text-xs font-semibold" style="{{ $btn }}">Send a plain test message</button>
            </div>

            @if($selected['kind'] === 'staff')
                <div class="rounded-md p-5 space-y-4" style="{{ $card }}">
                    <h2 class="text-sm font-bold" style="color:var(--text-primary);">3. Notifications an agent's phone receives</h2>
                    <p class="text-xs" style="color:var(--text-muted);">“For real” says whether this person would actually be buzzed by that alert today, from their own alert settings. A test is sent either way.</p>
                    @foreach($staffCatalogue as $group => $rows)
                        <div>
                            <div class="text-xs font-bold mb-1" style="color:var(--text-secondary);">{{ $group }}</div>
                            @foreach($rows as $r)
                                <div class="flex items-center gap-3 py-1.5" style="border-top:1px solid var(--border);">
                                    <div class="flex-1 text-xs" style="color:var(--text-primary);">
                                        <strong>{{ $r['label'] }}</strong>
                                        <span style="color:var(--text-muted);"> — {{ $r['description'] }}</span>
                                        @if($r['target'])<span style="color:var(--text-muted);"> · opens: {{ $r['target'] }}</span>@endif
                                    </div>
                                    <div class="text-xs w-28 text-right" style="color: {{ $r['real'] ? 'var(--ds-green, #16a34a)' : 'var(--text-muted)' }};">{{ $r['real'] === null ? '' : ($r['real'] ? 'on for real' : 'off for real') }}</div>
                                    <button type="submit" name="what" value="event:{{ $r['key'] }}" class="px-3 py-1 rounded-md text-xs font-semibold" style="{{ $btn }}">Send test</button>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @else
                <div class="rounded-md p-5 space-y-2" style="{{ $card }}">
                    <h2 class="text-sm font-bold" style="color:var(--text-primary);">3. Notifications a tenant's or owner's phone receives</h2>
                    <p class="text-xs" style="color:var(--text-muted);">Mandatory ones can never be switched off by the person; the others they can.</p>
                    @foreach($clientCatalogue as $key => $c)
                        <div class="flex items-center gap-3 py-1.5" style="border-top:1px solid var(--border);">
                            <div class="flex-1 text-xs" style="color:var(--text-primary);">
                                <strong>{{ $c['label'] }}</strong>
                                <span style="color:var(--text-muted);"> — {{ $c['description'] }} · for {{ $c['audience'] === 'both' ? 'tenants and owners' : ($c['audience'] === 'owner' ? 'owners' : 'tenants') }}</span>
                            </div>
                            <div class="text-xs w-28 text-right" style="color:var(--text-muted);">{{ $c['mandatory'] ? 'mandatory' : 'switchable' }}</div>
                            <button type="submit" name="what" value="category:{{ $key }}" class="px-3 py-1 rounded-md text-xs font-semibold" style="{{ $btn }}">Send test</button>
                        </div>
                    @endforeach
                </div>
            @endif
        </form>
    @endif

    {{-- Reference: the full picture, whoever is selected --}}
    <div class="rounded-md p-5 space-y-3" style="{{ $card }}">
        <h2 class="text-sm font-bold" style="color:var(--text-primary);">What the app is told to open</h2>
        <p class="text-xs" style="color:var(--text-muted);">Every push carries <code>target</code> and <code>target_id</code> so the app can open the right screen: {{ implode(', ', $destinations) }}. Search for a person above and pick them to send a test of each type.</p>
        <details>
            <summary class="text-xs font-semibold cursor-pointer" style="color:var(--text-secondary);">Agent alerts that do NOT go to the phone ({{ $staffNotOnPhone->count() }}) — in-app / email only</summary>
            <div class="mt-2 grid md:grid-cols-2 gap-x-6">
                @foreach($staffNotOnPhone as $t)
                    <div class="text-xs py-0.5" style="color:var(--text-muted);">{{ $t->group_label }} — {{ $t->label }}</div>
                @endforeach
            </div>
        </details>
        @if(! $selected)
            <details open>
                <summary class="text-xs font-semibold cursor-pointer" style="color:var(--text-secondary);">Tenant and owner notifications</summary>
                <div class="mt-2">
                    @foreach($clientCatalogue as $key => $c)
                        <div class="text-xs py-0.5" style="color:var(--text-muted);">{{ $c['label'] }} — {{ $c['mandatory'] ? 'mandatory' : 'switchable' }}</div>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
</div>
@endsection
