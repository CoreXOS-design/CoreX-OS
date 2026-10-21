{{--
    AT-481 — a company's people on the reOS review screen (spec R6a), moved out of the old row unchanged in behaviour: the one-click
    "create the company and link the matched person", and the add / remove / role controls. Expects $row, $party, $m (the match), $state
    ('link-auto' when the lease still uses the matched contact), $d (decisions), $editable.
--}}
                    {{-- The company matched a PERSON (a shared phone / e-mail) and the row still uses that person: the one-click is offered
     here, before the company exists, as the full people block only applies to a company contact. --}}
@if($editable && ($m['name_flag'] ?? null) === 'person_for_company' && $state === 'link-auto')
    <div class="corex-card p-2 mt-2" data-test="{{ $party }}-convert">
        <button type="button" class="corex-btn-outline text-xs" data-person-convert data-people-party="{{ $party }}" data-test="convert-to-company">Create the company and link {{ $m['matched_name'] ?? 'this person' }} as its contact person</button>
    </div>
@endif

{{-- People for this company (spec R6a): shown for a party that is, or will be, a company contact. --}}
@if(\App\Services\Rentals\TakeOnImport\Reos\ReosCompanyPeople::isCompanyParty($row, $party))
    @php
        $addedPeople = $d[$party]['people'] ?? [];
        $linkedPeople = \App\Services\Rentals\TakeOnImport\Reos\ReosCompanyPeople::existingPeople($row, $party);
        $capacities = \App\Services\Rentals\TakeOnImport\Reos\ReosCompanyPeople::capacities();
    @endphp
    <div class="corex-card p-2 mt-2" data-test="{{ $party }}-people" data-people-party="{{ $party }}">
        <div class="font-medium">People for this company</div>
        @foreach($linkedPeople as $lp)
            <div class="text-muted" data-test="linked-person">{{ $lp['name'] }}@if($lp['capacity']) — {{ $lp['capacity'] }}@endif <span>(already linked in CoreX)</span></div>
        @endforeach
        @foreach($addedPeople as $ap)
            <div class="flex flex-wrap items-center gap-1 mt-1" data-test="added-person" data-person-key="{{ $ap['key'] }}">
                <span>{{ $ap['label'] ?? trim(($ap['first_name'] ?? '') . ' ' . ($ap['last_name'] ?? '')) }}@if(($ap['via'] ?? '') === 'matched_person') <span class="text-muted">(matched by phone / e-mail; reused)</span>@elseif(! empty($ap['contact_id'])) <span class="text-muted">(already in CoreX)</span>@else <span class="text-muted">(new)</span>@endif</span>
                @if($editable)
                    <select data-person-capacity="{{ $ap['key'] }}" class="prop-select" aria-label="Role">
                        @foreach($capacities as $cap)<option value="{{ $cap }}" @selected(($ap['capacity'] ?? '') === $cap)>{{ $cap }}</option>@endforeach
                    </select>
                    <button type="button" class="underline text-muted" data-person-remove="{{ $ap['key'] }}">remove</button>
                @else
                    <span class="text-muted">— {{ $ap['capacity'] ?? '' }}</span>
                @endif
            </div>
        @endforeach
        @if($linkedPeople === [] && $addedPeople === [])
            <div class="text-muted">No people linked yet.</div>
        @endif

        @if($editable)
            <div class="mt-2">
                <button type="button" class="underline" data-person-toggle>Add person</button>
                <div class="hidden mt-1 space-y-1" data-person-panel>
                    <input type="text" data-person-search autocomplete="off" placeholder="Find a person already in CoreX…" class="prop-input">
                    <div data-person-results class="corex-card p-1"></div>
                    <div class="text-muted">or add a new person:</div>
                    <div class="flex flex-wrap gap-1">
                        <input type="text" data-person-first placeholder="First name" class="prop-input">
                        <input type="text" data-person-last placeholder="Last name" class="prop-input">
                    </div>
                    <div class="flex flex-wrap gap-1">
                        <input type="text" data-person-phone placeholder="Phone" class="prop-input">
                        <input type="email" data-person-email placeholder="E-mail" class="prop-input">
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <select data-person-new-capacity class="prop-select" aria-label="Role">
                            @foreach($capacities as $cap)<option value="{{ $cap }}">{{ $cap }}</option>@endforeach
                        </select>
                        <button type="button" class="corex-btn-outline text-xs" data-person-add-new>Add person</button>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endif
