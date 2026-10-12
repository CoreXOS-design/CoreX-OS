{{--
    .ai/specs/rentals-faults-work-orders.md section 22.4 - DECISION 1: who goes to look at this fault. The agent picks from "Set for this property", "Suits <type>",
    "Other contractors" or "Our own crew"; with someone picked there are exactly TWO equal buttons (the shared partials/contractor-inspect-choice):
      "Send <name> to inspect"        - only when the visit costs the owner nothing, or the owner's call-out permission covers it (else disabled, with the reason);
      "Send to owner to authorise"    - the fault goes to the owner on their link with this contractor proposed.
    Plus "Record the owner's decision" whenever the owner's answer is awaited. Nothing here is ever sent without one of these presses.
    Expects the fault screen's variables: $faultReport, $firstResponders, $contractorPicker, $contractorClassify, $canManageContractors, $tradeCode, $crewPicker,
    $inspectNotices, $inspectBlock, $authorityOwners, $recordBlock.
--}}
@php
    $d1Notices = $inspectNotices;
    $d1Sent = $faultReport->sent_to_owner_at !== null;
    $d1Default = (string) ($firstResponders->first()['supplier_id'] ?? ($faultReport->proposed_supplier_id ?: ''));
    if ($d1Default !== '' && ! isset($d1Notices[$d1Default])) { $d1Default = ''; }
    $d1First = $firstResponders->first();
    $d1CanInspect = auth()->user()->hasPermission('rental_fault_reports.raise_work_order');
    $d1CanSend = auth()->user()->hasPermission('rental_fault_reports.send_to_owner') && ! $d1Sent;
@endphp
<div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" id="decision-one" data-decision-one
     x-data="{
        q: '', picked: @js($d1Default), crew: '{{ ($crewPicker ?? collect())->count() === 1 ? $crewPicker->first()->id : '' }}', crewQ: '', busy: false,
        look: @js($d1Notices),
        urls: { inspect: @js(route('corex.rental-fault-reports.send-to-inspect', $faultReport)), owner: @js(route('corex.rental-fault-reports.send-to-owner-to-authorise', $faultReport)) },
        async submitLook(choice) {
            if (!this.picked || this.busy) { return; }
            const n = this.look[this.picked] || { name: 'them', text: '' };
            const crew = this.picked === 'crew';
            const f = this.$refs.look;
            f.elements['assignment_type'].value = crew ? 'internal' : 'outside_supplier';
            f.elements['agency_service_provider_id'].value = crew ? '' : this.picked;
            f.elements['rental_crew_id'].value = crew ? this.crew : '';
            const msg = choice === 'inspect'
                ? (crew ? 'The job card is made for the crew now' : n.name + ' gets the job and their private link now') + ', and the owner is told at once' + (n.text ? ' (' + n.text + ')' : '') + '. Anything they quote still has to be authorised before the work starts.'
                : 'The owner receives this fault on their link' + (crew ? '' : ' with ' + n.name + (n.text ? ' (' + n.text + ')' : '') + ' proposed') + ', and chooses \'Send someone to look and quote\' or \'Decline\'.';
            const ok = await window.corexConfirm({ title: choice === 'inspect' ? 'Send ' + n.name + ' to inspect?' : 'Send to the owner to authorise?', message: msg, confirmLabel: choice === 'inspect' ? 'Send ' + n.name + ' to inspect' : 'Send to owner' });
            if (!ok) { return; }
            this.busy = true; f.action = this.urls[choice]; f.submit();
        }
     }">
    <div class="flex items-center justify-between gap-2">
        <h2 class="text-sm font-semibold">Who goes to look first? <span class="font-normal text-xs" style="color: var(--text-muted);">&mdash; Decision 1: nothing is sent until you press a button</span></h2>
    </div>

    @if($d1First)
        <div class="text-xs" data-first-look-block>
            <strong>First to look at this:</strong> <span data-first-look-name>{{ $d1First['name'] }}</span>
            <span style="color: var(--text-muted);">({{ $d1First['kind_label'] }})</span>@if($d1First['callout_line']) <span data-first-look-callout>- {{ $d1First['callout_line'] }}</span>@endif
            <span style="color: var(--text-muted);"> &middot; {{ $d1First['source_label'] }} &middot; {{ $d1First['tell_label'] }}.@if($firstResponders->count() > 1) Then: {{ $firstResponders->slice(1)->map(fn ($r) => $r['name'] . ' (' . $r['kind_label'] . ')')->implode(', then ') }}.@endif</span>
        </div>
    @endif

    <form x-ref="look" method="POST" action="" data-decision-one-form>
        @csrf
        <input type="hidden" name="assignment_type" value="">
        <input type="hidden" name="agency_service_provider_id" value="">
        <input type="hidden" name="rental_crew_id" value="">
    </form>

    <div class="space-y-2" data-contractor-picker data-refresh-on-contractor-change="fault-picker-d1">
        @include('corex.rental-fault-reports._contractor-rows')
        <label class="flex flex-wrap items-center gap-2 text-sm rounded-md px-2 py-1" style="border: 1px solid var(--border);" data-contractor-row="crew">
            <input type="radio" name="contractor_pick" value="crew" x-model="picked" data-d1-crew>
            <span class="font-medium">Our own crew</span> <span class="text-xs" style="color: var(--text-muted);">- no call-out fee</span>
        </label>
        <div x-show="picked === 'crew'" x-cloak class="space-y-2" data-crew-picker>
            <p class="text-xs font-medium">Which crew? <span class="font-normal" style="color: var(--text-muted);">(optional - you can choose later on the job card)</span></p>
            @forelse(($crewPicker ?? collect()) as $c)
                <label class="flex items-center gap-2 text-sm rounded-md px-2 py-1" style="border: 1px solid var(--border);" data-crew-row="{{ $c->id }}">
                    <input type="radio" name="d1_crew_pick" value="{{ $c->id }}" x-model="crew"> <span class="font-medium">{{ $c->name }}</span>
                    @if($c->members->isNotEmpty())<span class="text-xs" style="color: var(--text-muted);">{{ $c->members->pluck('name')->implode(', ') }}</span>@endif
                </label>
            @empty
                <p class="text-xs" style="color: var(--text-muted);">No crews are set up yet - the job card will open without one.</p>
            @endforelse
        </div>
    </div>

    @include('partials.contractor-inspect-choice', [
        'notices' => $d1Notices, 'model' => 'picked',
        'buttonsDisabled' => '!picked || busy',
        'inspectExtraDisabled' => ($inspectBlock || ! $d1CanInspect) ? 'true' : 'false',
        'authoriseExtraDisabled' => (! $d1CanSend || $inspectBlock) ? 'true' : 'false',
        'inspectAttrs' => '@click="submitLook(\'inspect\')"',
        'authoriseAttrs' => '@click="submitLook(\'owner\')"',
    ])
    @if($inspectBlock)
        <p class="text-xs font-semibold" style="color: var(--ds-crimson);" data-inspect-block-line>{{ $inspectBlock }}</p>
    @elseif($d1Sent)
        <p class="text-xs" style="color: var(--text-muted);" data-already-with-owner>Already with the owner &mdash; they have not answered yet. You can still send someone whose visit costs the owner nothing, or record what the owner told you.</p>
    @endif

    <div class="cx-action-row" data-d1-record-row>
        @include('corex.rental-fault-reports._record-owner-decision')
    </div>
</div>
