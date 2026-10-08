{{--
    leases.md §17 — one agent dropdown (owner's agent / tenant's agent): the active users of the lease's agency, those of the
    lease's own branch first. Used by the capture screen and the lease screen's "Change agents" form, so both offer the same list.

    Expects: $name (field name), $label, $selected (user id or null), $agentOptions (LeaseAgentService::selectableAgents()),
    $required (bool). Optional: $attrs (extra attributes, e.g. an Alpine hook), $help.
--}}
@php
    $inBranch = collect($agentOptions)->where('in_branch', true);
    $elsewhere = collect($agentOptions)->where('in_branch', false);
    $selected = $selected !== null && $selected !== '' ? (int) $selected : null;
@endphp
<div>
    <label class="prop-label" for="{{ $name }}">{{ $label }}</label>
    <select name="{{ $name }}" id="{{ $name }}" class="prop-select" @if($required ?? false) required @endif {!! $attrs ?? '' !!}>
        <option value="" @selected($selected === null)>{{ ($required ?? false) ? 'Choose…' : '—' }}</option>
        @if($inBranch->isNotEmpty() && $elsewhere->isNotEmpty())
            <optgroup label="This branch">
                @foreach($inBranch as $agent)
                    <option value="{{ $agent['id'] }}" @selected($selected === $agent['id'])>{{ $agent['name'] }}</option>
                @endforeach
            </optgroup>
            <optgroup label="Other branches">
                @foreach($elsewhere as $agent)
                    <option value="{{ $agent['id'] }}" @selected($selected === $agent['id'])>{{ $agent['name'] }}</option>
                @endforeach
            </optgroup>
        @else
            @foreach($agentOptions as $agent)
                <option value="{{ $agent['id'] }}" @selected($selected === $agent['id'])>{{ $agent['name'] }}</option>
            @endforeach
        @endif
    </select>
    @error($name)<p class="text-xs mt-0.5" style="color: var(--ds-crimson);">{{ $message }}</p>@enderror
    @if(!empty($help))<p class="text-[11px] mt-0.5" style="color: var(--text-muted);">{{ $help }}</p>@endif
</div>
