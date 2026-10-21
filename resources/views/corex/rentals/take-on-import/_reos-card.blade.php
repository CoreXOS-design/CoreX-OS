{{--
    AT-481 — the queue card of the reOS review screen (spec "reOS review screen rebuild"): ONE lease, showing only what is still open, each
    question answered with buttons; answering saves at once and the page moves on to the next lease. A failed lease gets the same card
    with its reason, a "Try again" and the Change controls. Re-drawn by ReosTakeOnApiController after every answer.
    Expects: $row, $run, $agents, $defaults, $canManage.
--}}
@php
    use App\Services\Rentals\TakeOnImport\Reos\ReosQuestions as Q;
    $p = $row->payload_json ?? [];
    $d = $row->decisions_json ?? [];
    $state = Q::state($row);
    $failed = $state === Q::FAILED;
    $blockers = $row->blockers_json ?? [];
    $open = Q::open($row, $blockers);
    $loc = $d['property'] ?? [];
    $cands = \App\Services\Rentals\TakeOnImport\Reos\ReosCandidateDetails::for((int) $row->agency_id, $loc['candidates'] ?? []);
    $partyLabel = ['owner' => 'Owner', 'tenant' => 'Tenant'];
    $q = fn ($v) => '“' . $v . '”';
@endphp
<div class="corex-card p-4 space-y-4" id="reos-card" data-row-id="{{ $row->id }}" data-state="{{ $state }}" data-test="reos-card">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="text-sm">
            @if($failed)
                <span class="ds-badge ds-badge-danger">Failed</span>
            @else
                <strong>Lease <span data-queue-pos>1</span> of <span data-queue-total>1</span></strong>
            @endif
            <span class="text-muted">· Row {{ $row->row_number }} · {{ $p['address'] ?? '—' }} · {{ $p['owner_name'] ?? '—' }} → {{ $p['tenant_name'] ?? '—' }} · R{{ isset($p['rent']) ? number_format((float) $p['rent'], 0, '.', ' ') : '—' }}</span>
        </div>
        <div class="flex items-center gap-2">
            @if($failed)
                <button type="button" class="corex-btn-outline corex-btn-xs" data-card-close>Close</button>
            @else
                <button type="button" class="corex-btn-outline corex-btn-xs" data-queue-step="-1" data-test="queue-back">Back</button>
                <button type="button" class="corex-btn-outline corex-btn-xs" data-queue-step="1" data-test="queue-skip">Skip</button>
            @endif
        </div>
    </div>

    @if($failed)
        <div class="space-y-1" data-test="row-notes">
            @if($row->import_error)<div class="text-red-600" data-test="import-error">{{ $row->import_error }}</div>@endif
            @foreach($blockers as $b)<div class="text-red-600" data-test="blocker" data-blocker-code="{{ $b['code'] }}">{{ $b['message'] }}</div>@endforeach
        </div>
        <div><button type="button" class="corex-btn-primary corex-btn-xs" data-payload="{{ json_encode(['retry' => true]) }}">Try again</button></div>
        @include('corex.rentals.take-on-import._reos-panel', ['row' => $row, 'agents' => $agents, 'defaults' => $defaults, 'editable' => $canManage])
    @endif

    @foreach($open as $question)
        @php $party = $question['party']; $m = $party ? Q::match($row, $party) : null; @endphp
        <div class="space-y-2" data-q="{{ $question['key'] }}" data-test="question-{{ $question['code'] }}">

            @if($question['code'] === Q::PROPERTY)
                <p class="font-medium">Which property is {{ $q($p['address'] ?? '') }}?</p>
                <div class="flex flex-col gap-2 items-start">
                    @foreach($cands as $c)
                        <button type="button" class="corex-btn-secondary text-left" data-payload="{{ json_encode(['property_choice' => $c['id']]) }}" data-test="property-candidate">
                            <span class="font-medium">{{ $c['label'] }}</span>
                            <span class="text-muted">— {{ $c['suburb'] !== '' ? $c['suburb'] : 'no suburb' }}@if($c['town']), {{ $c['town'] }}@endif @if($c['detail'] !== '') · {{ $c['detail'] }}@endif · {{ $c['leases'] === 0 ? 'no leases yet' : $c['leases'] . ($c['leases'] === 1 ? ' lease' : ' leases') }}</span>
                            @if($c['duplicate_of'])<span class="ds-badge ds-badge-warning" data-test="property-duplicate">Same property entered twice in CoreX (also #{{ implode(', #', $c['duplicate_of']) }})</span>@endif
                        </button>
                    @endforeach
                    <button type="button" class="corex-btn-outline" data-payload="{{ json_encode(['property_choice' => 'new']) }}" data-test="property-none">None — create new</button>
                </div>

            @elseif($question['code'] === Q::NAME && $m)
                <p class="font-medium" data-test="name-question">{{ $partyLabel[$party] }}: is CoreX’s {{ $q($m['matched_name'] ?? '') }}@if(! empty($m['matched_phone'])) ({{ $m['matched_phone'] }})@endif the same person as reOS’s {{ $q($m['name']) }}?</p>
                <div class="flex flex-wrap items-start gap-2">
                    <button type="button" class="corex-btn-primary" data-payload="{{ json_encode(['confirm' => $party]) }}">Same person</button>
                    <button type="button" class="corex-btn-secondary" data-payload="{{ json_encode([$party => ['action' => 'create']]) }}">New contact</button>
                    <div class="relative" data-contact-wrap="{{ $party }}">
                        <button type="button" class="corex-btn-outline" data-open-contact-search>Pick another contact…</button>
                        <div data-contact-panel class="hidden mt-1">
                            <input type="text" data-contact-input autocomplete="off" placeholder="Name, phone or e-mail…" class="prop-input w-full">
                            <div data-contact-results class="corex-card p-1"></div>
                        </div>
                    </div>
                </div>

            @elseif($question['code'] === Q::COMPANY && $m)
                <p class="font-medium" data-test="company-question">{{ $partyLabel[$party] }}: create company {{ $q($m['name']) }} with {{ $m['matched_name'] ?? 'this person' }} as contact person?</p>
                <div class="flex flex-wrap items-start gap-2">
                    <button type="button" class="corex-btn-primary" data-payload="{{ json_encode(['person' => ['party' => $party, 'op' => 'convert']]) }}">Yes</button>
                    <button type="button" class="corex-btn-secondary" data-payload="{{ json_encode(['confirm' => $party]) }}">Use {{ $m['matched_name'] ?? 'this person' }} as is</button>
                    <button type="button" class="corex-btn-outline" data-payload="{{ json_encode([$party => ['action' => 'create']]) }}">New contact</button>
                </div>

            @elseif($question['code'] === Q::SUBURB)
                <p class="font-medium">Which suburb is the new property {{ $q($p['address'] ?? '') }} in?</p>
                <div class="relative" data-suburb-wrap>
                    <input type="text" data-suburb-input autocomplete="off" placeholder="Type the suburb…" class="prop-input w-full">
                    <div data-suburb-results class="hidden absolute z-20 corex-card p-1 w-full"></div>
                </div>

            @elseif($question['code'] === Q::AGENT)
                <p class="font-medium">Who is the agent for this lease?@if(! empty($p['agent_text'])) <span class="text-muted">(reOS: {{ $p['agent_text'] }})</span>@endif</p>
                <select data-field="agent_user_id" class="prop-select w-full" data-test="agent-question">
                    <option value="" selected>Choose the agent…</option>
                    @foreach($agents as $a)<option value="{{ $a['id'] }}">{{ $a['name'] }}</option>@endforeach
                </select>
            @endif
        </div>
    @endforeach
</div>
