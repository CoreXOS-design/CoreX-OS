{{--
    One job row on the crew home page (spec §17.42). The job itself is one link; the contact block
    and "Open in Maps" sit BESIDE it, not inside it (a link inside a link is invalid and the second
    tap would open the job). Plain array $j from RentalCrewScheduleService::row().
    $group = today | upcoming | waiting | in_progress   $jobUrl = fn(id) => url
--}}
@php($access = $j['access'] ?? ['any' => false])
<div class="job" data-job-id="{{ $j['id'] }}" data-group="{{ $group }}">
    <a class="main" href="{{ $jobUrl($j['id']) }}">
        @if($group === 'today')
            <div class="when">@if($j['overdue']){{ $j['date'] }} {{ $j['time'] }}@else{{ $j['time'] ?? '' }}@endif</div>
        @elseif($group === 'upcoming')
            <div class="when">{{ $j['date'] }} · {{ $j['time'] }}</div>
        @endif
        <div class="ttl">{{ $j['title'] }}</div>
        @if($j['address'] !== '')<div class="addr">{{ $j['address'] }}</div>@endif
        <div style="margin-top:7px;">
            @if(!empty($j['why_waiting']))<span class="chip warn" data-why>{{ $j['why_waiting'] }}</span>@endif
            <span class="chip">{{ $j['status_label'] }}</span>
            @if($j['overdue'])<span class="chip warn">Overdue</span>@endif
            @if($j['crew_completed'])<span class="chip ok">Work completed</span>@endif
            @if($j['due'])<span class="chip">Due {{ $j['due'] }}</span>@endif
        </div>
    </a>

    @if(!empty($j['tenants']) || !empty($access['any']) || $j['map_url'])
        <div class="contacts">
            @foreach($j['tenants'] ?? [] as $t)
                <div class="who" data-tenant>
                    <span class="lbl">Tenant</span>
                    <span class="nm">{{ $t['name'] }}</span>
                    @if($t['tel'])<a class="tel" href="tel:{{ $t['tel'] }}">{{ $t['phone'] }}</a>@endif
                </div>
            @endforeach
            @if(!empty($access['any']))
                <div class="who" data-access>
                    <span class="lbl">Access</span>
                    @if($access['name'])<span class="nm">{{ $access['name'] }}</span>@endif
                    @if($access['tel'])<a class="tel" href="tel:{{ $access['tel'] }}">{{ $access['phone'] }}</a>@endif
                    @if($access['note'])<div class="note-line">{{ $access['note'] }}</div>@endif
                </div>
            @endif
            @if($j['map_url'])
                <a class="maps" href="{{ $j['map_url'] }}" target="_blank" rel="noopener" data-maps>Open in Maps</a>
            @endif
        </div>
    @endif
</div>
