{{--
    AT-481 — the open questions of ONE lease (spec "reOS review screen rebuild" R14): only what is still open, each answered with the
    same standard CoreX button; answering saves at once. Drawn inside the lease's expanded line (_reos-line).
    Expects: $row, $agents.
--}}
@php
    use App\Services\Rentals\TakeOnImport\Reos\ReosQuestions as Q;
    $p = $row->payload_json ?? [];
    $d = $row->decisions_json ?? [];
    $open = Q::open($row, $row->blockers_json ?? []);
    $loc = $d['property'] ?? [];
    $cands = \App\Services\Rentals\TakeOnImport\Reos\ReosCandidateDetails::for((int) $row->agency_id, $loc['candidates'] ?? []);
    $partyLabel = ['owner' => 'Owner', 'tenant' => 'Tenant'];
    $q = fn ($v) => '“' . $v . '”';
@endphp
    @foreach($open as $question)
        @php $party = $question['party']; $m = $party ? Q::match($row, $party) : null; @endphp
        <div class="space-y-2" data-q="{{ $question['key'] }}" data-test="question-{{ $question['code'] }}">

            @if($question['code'] === Q::PROPERTY)
                <p class="font-medium">Which property is {{ $q($p['address'] ?? '') }}?</p>
                <div class="flex flex-col gap-2 max-w-3xl">
                    @foreach($cands as $c)
                        <button type="button" class="corex-btn-outline w-full justify-start text-left flex-wrap" data-payload="{{ json_encode(['property_choice' => $c['id']]) }}" data-test="property-candidate">
                            <span class="font-medium">{{ $c['label'] }}</span>
                            <span class="text-muted">— {{ $c['suburb'] !== '' ? $c['suburb'] : 'no suburb' }}@if($c['town']), {{ $c['town'] }}@endif @if($c['detail'] !== '') · {{ $c['detail'] }}@endif · {{ $c['leases'] === 0 ? 'no leases yet' : $c['leases'] . ($c['leases'] === 1 ? ' lease' : ' leases') }}</span>
                            @if($c['duplicate_of'])<span class="ds-badge ds-badge-warning" data-test="property-duplicate">Same property entered twice in CoreX (also #{{ implode(', #', $c['duplicate_of']) }})</span>@endif
                        </button>
                    @endforeach
                    <button type="button" class="corex-btn-outline w-full justify-start text-left" data-payload="{{ json_encode(['property_choice' => 'new']) }}" data-test="property-none">None — create new</button>
                </div>

            @elseif($question['code'] === Q::NAME && $m)
                <p class="font-medium" data-test="name-question">{{ $partyLabel[$party] }}: is CoreX’s {{ $q($m['matched_name'] ?? '') }}@if(! empty($m['matched_phone'])) ({{ $m['matched_phone'] }})@endif the same person as reOS’s {{ $q($m['name']) }}?</p>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="corex-btn-outline" data-payload="{{ json_encode(['confirm' => $party]) }}">Same person</button>
                    <button type="button" class="corex-btn-outline" data-payload="{{ json_encode([$party => ['action' => 'create']]) }}">New contact</button>
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
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="corex-btn-outline" data-payload="{{ json_encode(['person' => ['party' => $party, 'op' => 'convert']]) }}">Yes</button>
                    <button type="button" class="corex-btn-outline" data-payload="{{ json_encode(['confirm' => $party]) }}">Use {{ $m['matched_name'] ?? 'this person' }} as is</button>
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
