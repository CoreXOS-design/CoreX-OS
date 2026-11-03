@extends('layouts.corex')

@section('corex-content')
{{-- AT-534 — Campaigns → Settings (.ai/specs/campaigns.md §4, §7.5): defaults + sending addresses with signatures. --}}
@php
    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
    $test = session('sender_test');
    $newBag = $errors->getBag('newSender');
    $health = fn ($s) => match ($s) { 'healthy' => 'ds-badge-success', 'failing' => 'ds-badge-danger', default => 'ds-badge-muted' };
@endphp
<div class="w-full h-full flex flex-col" x-data="{ adding: {{ $newBag->any() ? 'true' : 'false' }} }">
    <div class="rounded-md px-6 py-3.5 corex-page-banner flex-shrink-0">
        <h1 class="text-base font-bold" style="color: var(--text-primary);">Settings</h1>
    </div>

    @include('admin.campaigns._head')

    <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-3 grid grid-cols-1 xl:grid-cols-3 gap-3 items-start">
        {{-- Defaults --}}
        <form method="POST" action="{{ route('admin.campaigns.settings.update') }}" class="cmp-panel p-4 space-y-3" data-settings-defaults>
            @csrf @method('PUT')
            <div class="text-sm font-semibold" style="color: var(--text-primary);">Defaults for new campaigns</div>
            <div class="grid grid-cols-2 gap-2">
                <div><label class="cmp-label">Daily limit</label><input type="number" name="daily_limit" min="1" max="{{ $maxDailyLimit }}" value="{{ old('daily_limit', $settings['daily_limit']) }}" required class="cmp-input"></div>
                <div><label class="cmp-label">Minimum days between steps</label><input type="number" name="min_day_gap" min="1" max="30" value="{{ old('min_day_gap', $settings['min_day_gap']) }}" required class="cmp-input"></div>
                <div><label class="cmp-label">Send from</label><input type="time" name="send_from" value="{{ old('send_from', $settings['send_from']) }}" required class="cmp-input"></div>
                <div><label class="cmp-label">Send to</label><input type="time" name="send_to" value="{{ old('send_to', $settings['send_to']) }}" required class="cmp-input"></div>
            </div>
            <div><span class="cmp-label">Send days</span>
                <div class="flex flex-wrap gap-3 text-sm" style="color: var(--text-primary);">
                    @foreach($dayNames as $n => $label)
                        <label class="inline-flex items-center gap-1"><input type="checkbox" name="send_days[]" value="{{ $n }}" @checked(in_array($n, array_map('intval', (array) old('send_days', $settings['send_days'])), true))> {{ $label }}</label>
                    @endforeach
                </div>
            </div>
            <div><label class="cmp-label">Opt-out line (every email ends with it, then the unsubscribe link)</label>
                <textarea name="opt_out_footer" rows="3" maxlength="500" required class="cmp-input">{{ old('opt_out_footer', $settings['opt_out_footer']) }}</textarea></div>
            <div class="flex justify-end"><button class="corex-btn-primary text-xs">Save</button></div>
        </form>

        {{-- Sending addresses --}}
        <div class="cmp-panel p-4 xl:col-span-2 space-y-3" data-senders>
            <div class="flex items-center justify-between">
                <div class="text-sm font-semibold" style="color: var(--text-primary);">Sending addresses</div>
                <button type="button" @click="adding = !adding" class="corex-btn-primary text-xs">Add address</button>
            </div>

            <div x-show="adding" x-cloak>
                @include('admin.campaigns._sender-form', ['sender' => null, 'bag' => $newBag])
            </div>

            @forelse($senders as $s)
                @php $bag = $errors->getBag('sender_' . $s->id); @endphp
                <div class="rounded-md p-3" style="border: 1px solid var(--border);" x-data="{ edit: {{ $bag->any() ? 'true' : 'false' }} }" data-sender="{{ $s->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="min-w-0">
                            <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $s->from_name }} &lt;{{ $s->email }}&gt;
                                @if($s->is_default)<span class="ds-badge ds-badge-info">Default</span>@endif</div>
                            <div class="text-xs cmp-muted">{{ $s->smtp_host }}:{{ $s->smtp_port }} ·
                                Sending <span class="ds-badge {{ $health($s->sendHealthLabel()) }}">{{ $s->sendHealthLabel() }}</span>
                                Inbox <span class="ds-badge {{ $health($s->inboxHealthLabel()) }}">{{ $s->inboxHealthLabel() }}</span>
                                · last sent {{ $s->last_sent_at?->format('Y-m-d H:i') ?? '—' }}</div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="{{ route('admin.campaigns.senders.preview', $s->id) }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs" data-sender-preview>Preview</a>
                            <form method="POST" action="{{ route('admin.campaigns.senders.test', $s->id) }}">@csrf<button class="corex-btn-outline text-xs">Test</button></form>
                            @unless($s->is_default)
                                <form method="POST" action="{{ route('admin.campaigns.senders.default', $s->id) }}">@csrf<button class="corex-btn-outline text-xs">Make default</button></form>
                            @endunless
                            <button type="button" @click="edit = !edit" class="corex-btn-outline text-xs">Edit</button>
                            <form method="POST" action="{{ route('admin.campaigns.senders.archive', $s->id) }}" onsubmit="return confirm('Archive {{ $s->email }}?')">@csrf @method('DELETE')<button class="corex-btn-outline text-xs">Archive</button></form>
                        </div>
                    </div>
                    @if($test && (int) $test['sender_id'] === $s->id)
                        <div class="text-xs mt-2 space-y-0.5" data-sender-test>
                            <div style="color: {{ $test['send_ok'] ? 'var(--ds-green)' : 'var(--ds-crimson)' }};">{{ $test['send_ok'] ? '✓' : '✗' }} {{ $test['send_text'] }}</div>
                            <div style="color: {{ $test['inbox_ok'] ? 'var(--ds-green)' : 'var(--ds-crimson)' }};">{{ $test['inbox_ok'] ? '✓' : '✗' }} {{ $test['inbox_text'] }}</div>
                        </div>
                    @endif
                    @if($s->signature)
                        <pre class="text-xs mt-2 whitespace-pre-wrap cmp-muted" style="font-family: inherit;">{{ $s->signature }}</pre>
                    @endif
                    <div x-show="edit" x-cloak class="mt-2">
                        @include('admin.campaigns._sender-form', ['sender' => $s, 'bag' => $bag])
                    </div>
                </div>
            @empty
                <div class="text-sm cmp-muted py-4 text-center">No sending addresses yet. <button type="button" class="cmp-link" @click="adding = true">Add address</button></div>
            @endforelse

            <div class="text-xs">
                @if($showArchived)
                    @foreach($archivedSenders as $a)
                        <div class="flex items-center justify-between gap-2 py-1 cmp-row">
                            <span class="cmp-muted">{{ $a->from_name }} &lt;{{ $a->email }}&gt; — archived</span>
                            <form method="POST" action="{{ route('admin.campaigns.senders.restore', $a->id) }}">@csrf<button class="corex-btn-outline text-xs">Restore</button></form>
                        </div>
                    @endforeach
                    <a class="cmp-link" href="{{ route('admin.campaigns.settings') }}">Hide archived</a>
                @elseif($archivedCount > 0)
                    <a class="cmp-link" href="{{ route('admin.campaigns.settings', ['archived' => 1]) }}">Show archived ({{ $archivedCount }})</a>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
