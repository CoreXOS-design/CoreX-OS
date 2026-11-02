@extends('layouts.corex')

@section('corex-content')
{{-- AT-534 — create / edit a campaign (.ai/specs/campaigns.md §7.2). Steps are added on the campaign page. --}}
@php
    $isEdit = $campaign->exists;
    $days = array_map('intval', (array) old('send_days', $campaign->send_days ?? []));
    $dayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
@endphp
<div class="w-full h-full flex flex-col">
    <div class="rounded-md px-6 py-3.5 corex-page-banner flex-shrink-0 flex items-center justify-between gap-3">
        <h1 class="text-base font-bold" style="color: var(--text-primary);">{{ $isEdit ? 'Edit ' . $campaign->name : 'New campaign' }}</h1>
        <a href="{{ $isEdit ? route('admin.campaigns.show', $campaign->id) : route('admin.campaigns.index') }}" class="corex-btn-outline text-xs">Cancel</a>
    </div>

    @include('admin.campaigns._head')

    <div class="flex-1 min-h-0 overflow-y-auto corex-brand-scroll mt-3">
        @if($senders->isEmpty())
            <div class="cmp-panel p-6 text-sm" data-no-senders>
                Add a sending address first. <a href="{{ route('admin.campaigns.settings') }}" class="cmp-link">Settings → Sending addresses</a>
            </div>
        @else
        <form method="POST" action="{{ $isEdit ? route('admin.campaigns.update', $campaign->id) : route('admin.campaigns.store') }}"
              class="cmp-panel p-4 grid grid-cols-1 md:grid-cols-4 gap-3 max-w-4xl">
            @csrf
            @if($isEdit) @method('PUT') @endif

            <div class="md:col-span-2">
                <label class="cmp-label" for="name">Name</label>
                <input id="name" name="name" value="{{ old('name', $campaign->name) }}" maxlength="150" required class="cmp-input">
            </div>
            <div class="md:col-span-2">
                <label class="cmp-label" for="campaign_sender_id">Send from</label>
                <select id="campaign_sender_id" name="campaign_sender_id" class="cmp-input" required>
                    @foreach($senders as $s)
                        <option value="{{ $s->id }}" @selected((int) old('campaign_sender_id', $campaign->campaign_sender_id) === $s->id)>{{ $s->from_name }} &lt;{{ $s->email }}&gt;</option>
                    @endforeach
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="cmp-label" for="campaign_list_id">List</label>
                <select id="campaign_list_id" name="campaign_list_id" class="cmp-input" required @disabled($isEdit && $campaign->status !== 'draft')>
                    <option value="">Choose a list</option>
                    @foreach($lists as $l)
                        <option value="{{ $l->id }}" @selected((int) old('campaign_list_id', $campaign->campaign_list_id) === $l->id)>{{ $l->name }} ({{ $l->leads_count }})</option>
                    @endforeach
                </select>
                @if($isEdit && $campaign->status !== 'draft')
                    <input type="hidden" name="campaign_list_id" value="{{ $campaign->campaign_list_id }}">
                @endif
                @if($lists->isEmpty())
                    <div class="cmp-err">No free lists. <a href="{{ route('admin.campaigns.lists.index') }}" class="cmp-link">Create a list</a></div>
                @endif
            </div>
            <div>
                <label class="cmp-label" for="daily_limit">Daily limit</label>
                <input id="daily_limit" type="number" name="daily_limit" min="1" max="{{ $maxDailyLimit }}" value="{{ old('daily_limit', $campaign->daily_limit) }}" required class="cmp-input">
            </div>
            <div></div>
            <div class="md:col-span-2">
                <span class="cmp-label">Send days</span>
                <div class="flex flex-wrap gap-3 text-sm" style="color: var(--text-primary);">
                    @foreach($dayNames as $n => $label)
                        <label class="inline-flex items-center gap-1"><input type="checkbox" name="send_days[]" value="{{ $n }}" @checked(in_array($n, $days, true))> {{ $label }}</label>
                    @endforeach
                </div>
            </div>
            <div>
                <label class="cmp-label" for="send_from">From</label>
                <input id="send_from" type="time" name="send_from" value="{{ old('send_from', $campaign->timeLabel($campaign->send_from)) }}" required class="cmp-input">
            </div>
            <div>
                <label class="cmp-label" for="send_to">To</label>
                <input id="send_to" type="time" name="send_to" value="{{ old('send_to', $campaign->timeLabel($campaign->send_to)) }}" required class="cmp-input">
            </div>
            <div class="md:col-span-4 flex justify-end">
                <button class="corex-btn-primary text-xs">{{ $isEdit ? 'Save' : 'Create campaign' }}</button>
            </div>
        </form>
        @endif
    </div>
</div>
@endsection
