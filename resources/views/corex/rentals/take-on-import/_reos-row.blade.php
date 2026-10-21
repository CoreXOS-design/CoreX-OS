{{--
    AT-479 — one lease on the reOS review screen (spec "reOS Tenancy Schedule import" R4/R5). Drawn by the page AND re-drawn by
    ReosTakeOnApiController after every change (the JSON answer carries this HTML), so what is on screen is what is saved.
    Expects: $row (RentalTakeOnImportRow), $run, $agents (list of {id,name}), $canManage (bool).
    Plain markup, no Alpine: the page's own script listens for [data-field] changes (event delegation on the table).
--}}
@php
    $p = $row->payload_json ?? [];
    $d = $row->decisions_json ?? [];
    $imported = $row->status === 'confirmed';
    $fileError = $row->status === 'error';
    $editable = $canManage && ! $imported && ! $fileError;
    $existing = $row->existing_lease_id !== null;
    $matched = $row->property_match_action === 'match';
    $loc = $d['property'] ?? [];
    $owner = $row->landlord_match_json[1] ?? null;
    $tenant = $row->tenant_match_json[1] ?? null;
    $blockers = $row->blockers_json ?? [];
    $id = $row->id;
    $partyState = function (?array $m, array $dec) {
        if (! $m) return null;
        $act = $dec['action'] ?? 'none';
        if ($act === 'link') {
            return (int) ($dec['contact_id'] ?? 0) === (int) ($m['existing_contact_id'] ?? -1) ? 'link-auto' : 'link-other';
        }
        return in_array($act, ['create', 'skip'], true) ? $act : 'create';
    };
    $ownerState = $partyState($owner, $d['owner'] ?? []);
    $tenantState = $partyState($tenant, $d['tenant'] ?? []);
@endphp
<tr id="row-{{ $id }}" class="align-top" data-row-id="{{ $id }}" data-blocked="{{ $row->is_blocked ? 1 : 0 }}" data-selected="{{ $row->selected ? 1 : 0 }}"
    style="{{ $imported ? 'opacity:.75;' : '' }}">

    {{-- Tick --}}
    <td>
        <input type="checkbox" data-field="selected" aria-label="Import lease on row {{ $row->row_number }}"
               @checked($row->selected) @disabled(! $editable)>
    </td>

    {{-- The lease --}}
    <td class="min-w-[11rem]">
        <div class="font-medium">Row {{ $row->row_number }}
            <span class="ds-badge {{ in_array(mb_strtolower((string) ($p['lease_status'] ?? '')), ['active','expiring'], true) ? 'ds-badge-success' : 'ds-badge-muted' }}">{{ $p['lease_status'] ?? '—' }}</span>
        </div>
        <div class="text-muted">
            R{{ isset($p['rent']) ? number_format((float) $p['rent'], 2) : '—' }}/month
            @if(isset($p['deposit']) && $p['deposit'] !== null) · deposit R{{ number_format((float) $p['deposit'], 2) }}@endif
        </div>
        <div class="text-muted">{{ $p['lease_start_date'] ?? '—' }} → {{ $p['lease_end_date'] ?? 'no end date' }}</div>
        <div class="text-muted">
            @if(! empty($p['lease_type']))Type: {{ $p['lease_type'] }}@endif
            @if(array_key_exists('rollover', $p) && $p['rollover'] !== null) · Rollover: {{ $p['rollover'] ? 'yes' : 'no' }}@endif
        </div>
        @if($imported)
            <span class="ds-badge ds-badge-success" data-test="row-imported">{{ $row->result_action === 'updated' ? 'Updated' : 'Imported' }}</span>
            @if($row->target_lease_id)<a href="{{ route('corex.leases.show', $row->target_lease_id) }}" class="underline">Open lease</a>@endif
        @elseif($existing && collect($blockers)->contains('code', 'archived_lease'))
            <span class="ds-badge ds-badge-danger" data-test="row-existing-archived">In CoreX, but archived</span>
        @elseif($existing)
            <span class="ds-badge ds-badge-info" data-test="row-existing">In CoreX already — will be updated</span>
        @endif
    </td>

    {{-- Property --}}
    <td class="min-w-[15rem]">
        <div class="font-medium">{{ $p['address'] ?? '—' }}</div>
        @if($existing || $imported)
            <div class="text-muted">{{ $imported ? 'Property: ' . ($row->property_match_action === 'create' ? 'created' : 'existing') : 'The lease\'s property is left as it is.' }}</div>
        @else
            <span class="ds-badge {{ $matched ? 'ds-badge-success' : 'ds-badge-warning' }}" data-test="property-state">{{ $matched ? 'In CoreX' : 'Not in CoreX' }}</span>
            @if($matched)
                <div class="text-muted">{{ $row->property_match_label }}</div>
            @else
                <label class="flex items-center gap-1 mt-1">
                    <input type="checkbox" id="row-{{ $id }}-property-create" data-field="property_create" @checked($d['property']['create'] ?? true) @disabled(! $editable)>
                    Create this property
                </label>
                @if(($d['property']['create'] ?? true))
                    <div class="mt-1 relative" data-suburb-wrap>
                        @if(! empty($loc['suburb']))
                            <div data-test="suburb-chosen"><strong>{{ $loc['suburb'] }}</strong>@if(! empty($loc['town'])), {{ $loc['town'] }}@endif @if(! empty($loc['province'])) ({{ $loc['province'] }})@endif
                                @if($editable)<button type="button" class="underline text-muted" data-clear-suburb>change</button>@endif</div>
                        @elseif($editable)
                            <input type="text" id="row-{{ $id }}-suburb" data-suburb-input autocomplete="off" placeholder="Type the suburb…" class="prop-input" style="max-width:15rem;">
                            <div data-suburb-results class="hidden absolute z-20 rounded-md p-1" style="background:var(--surface); border:1px solid var(--border); min-width:15rem;"></div>
                        @endif
                    </div>
                @endif
            @endif
        @endif
    </td>

    {{-- Owner / Tenant --}}
    @foreach([['owner', $owner, $ownerState, 'Owner'], ['tenant', $tenant, $tenantState, 'Tenant']] as [$party, $m, $state, $label])
        <td class="min-w-[13rem]" data-party="{{ $party }}">
            @if(! $m)
                <span class="text-muted">No {{ strtolower($label) }} named</span>
            @else
                <div class="font-medium">{{ $m['name'] }}</div>
                @if($existing || $imported)
                    <div class="text-muted">{{ $imported && $row->result_action !== 'updated' ? ($m['action'] === 'create' ? 'New contact created' : 'Existing contact used') : 'Left as it is.' }}</div>
                @else
                    <span class="ds-badge {{ ($m['action'] ?? '') === 'match' ? 'ds-badge-success' : 'ds-badge-warning' }}" data-test="{{ $party }}-state">{{ ($m['action'] ?? '') === 'match' ? 'In CoreX' : 'Not in CoreX' }}</span>
                    @if(($m['kind'] ?? 'person') !== 'person')<span class="ds-badge ds-badge-muted">{{ $m['kind'] === 'company' ? 'Company' : 'Joint' }}</span>@endif
                    @if(($m['action'] ?? '') === 'match')<div class="text-muted">{{ $m['label'] }}</div>@endif
                    @if($editable)
                        <select data-field="{{ $party }}" data-auto-contact="{{ $m['existing_contact_id'] ?? '' }}" class="prop-select mt-1" style="max-width:14rem;">
                            @if(($m['action'] ?? '') === 'match')<option value="link-auto" @selected($state === 'link-auto')>Use the contact already in CoreX</option>@endif
                            @if($state === 'link-other')<option value="link-other" selected>Linked: {{ ($d[$party]['label'] ?? 'chosen contact') }}</option>@endif
                            <option value="create" @selected($state === 'create')>Create a new contact</option>
                            <option value="skip" @selected($state === 'skip')>Leave out</option>
                        </select>
                        <div class="mt-1 relative" data-contact-wrap="{{ $party }}">
                            <button type="button" class="underline text-muted" data-open-contact-search>Link to another existing contact…</button>
                            <div data-contact-panel class="hidden mt-1">
                                <input type="text" data-contact-input autocomplete="off" placeholder="Name, phone or e-mail…" class="prop-input" style="max-width:14rem;">
                                <div data-contact-results class="rounded-md p-1" style="background:var(--surface); border:1px solid var(--border);"></div>
                            </div>
                        </div>
                    @else
                        <div class="text-muted">{{ $state === 'skip' ? 'Left out' : ($state === 'create' ? 'Will be created' : 'Linked') }}</div>
                    @endif
                @endif
            @endif
            @if($party === 'tenant' && ! $imported)
                <div class="mt-2">
                    <label class="text-muted" for="row-{{ $id }}-wallet">Tenant wallet reference</label>
                    <input type="text" id="row-{{ $id }}-wallet" data-field="tenant_wallet_ref" maxlength="100" value="{{ $d['tenant_wallet_ref'] ?? '' }}" class="prop-input" style="max-width:14rem;" @disabled(! $editable)>
                </div>
            @endif
        </td>
    @endforeach

    {{-- Agent --}}
    <td class="min-w-[12rem]">
        <div class="text-muted">reOS: {{ $p['agent_text'] ?? '—' }}</div>
        @if($imported)
            <div class="text-muted">Agent set on the lease.</div>
        @else
            <select id="row-{{ $id }}-agent" data-field="agent_user_id" class="prop-select mt-1" style="max-width:13rem;" @disabled(! $editable)>
                <option value="" @selected(empty($d['agent_user_id']))>{{ $existing ? '— keep the lease\'s agents —' : 'Choose…' }}</option>
                @foreach($agents as $a)
                    <option value="{{ $a['id'] }}" @selected((int) ($d['agent_user_id'] ?? 0) === $a['id'])>{{ $a['name'] }}</option>
                @endforeach
            </select>
            @if(! empty($d['agent_note']) && empty($d['agent_user_id']))<div class="text-muted">{{ $d['agent_note'] }}</div>@endif
        @endif
    </td>

    {{-- What stops it / what to know --}}
    <td class="min-w-[14rem]" data-test="row-notes">
        @if($row->import_error)
            <div class="text-red-600" data-test="import-error">{{ $row->import_error }}</div>
        @endif
        @foreach($blockers as $b)
            <div class="text-red-600" data-test="blocker" data-blocker-code="{{ $b['code'] }}">
                @if(! empty($b['field']))<a href="#row-{{ $id }}-{{ $b['field'] }}" data-focus="row-{{ $id }}-{{ $b['field'] }}" class="underline">{{ $b['message'] }}</a>@else{{ $b['message'] }}@endif
            </div>
        @endforeach
        @foreach(($row->warnings_json ?? []) as $w)
            <div class="text-amber-600">{{ $w }}</div>
        @endforeach
        @if(! $row->import_error && $blockers === [] && empty($row->warnings_json) && ! $imported)
            <span class="text-muted">Nothing to fix.</span>
        @endif
    </td>
</tr>
