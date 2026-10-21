{{--
    AT-481 — one lease as ONE line on the reOS review screen (spec "reOS review screen rebuild"): a lease with nothing open (ready), one
    that has been imported, or one that failed. Leases with questions open are not lines — they are asked in the queue card.
    Drawn by the page AND re-drawn by ReosTakeOnApiController after every change (the JSON answer carries this HTML).
    Expects: $row, $run, $agents, $defaults, $canManage.
--}}
@php
    use App\Services\Rentals\TakeOnImport\Reos\ReosQuestions as Q;
    $p = $row->payload_json ?? [];
    $d = $row->decisions_json ?? [];
    $state = Q::state($row);
    $imported = $state === Q::IMPORTED;
    $failed = $state === Q::FAILED;
    // Only a ready lease opens into its Change controls; a failed one is put right from its card ("Fix it"), an imported one is finished.
    $expandable = $canManage && $state === Q::READY;
    $markers = Q::spellingMarkers($row);
    $agentName = collect($agents)->firstWhere('id', (int) ($d['agent_user_id'] ?? 0))['name'] ?? null;
    $defaultAgent = $defaults['agent_user_id'] ?? null;
    $ownAgent = $agentName && ! $row->existing_lease_id && (int) ($d['agent_user_id'] ?? 0) !== (int) $defaultAgent;
    $blockers = $row->blockers_json ?? [];
    $archived = collect($blockers)->firstWhere('code', 'archived_lease');
@endphp
@if(! $expandable)
<div id="row-{{ $row->id }}" class="corex-card px-3 py-2" data-row-id="{{ $row->id }}" data-row-number="{{ $row->row_number }}" data-state="{{ $state }}">
@else
<details id="row-{{ $row->id }}" class="corex-card" data-row-id="{{ $row->id }}" data-row-number="{{ $row->row_number }}" data-state="{{ $state }}">
    <summary class="px-3 py-2 cursor-pointer">
@endif
        <span class="inline-flex flex-wrap items-center gap-x-3 gap-y-1 text-xs align-middle" data-test="reos-line">
            <span class="text-muted tabular-nums">Row {{ $row->row_number }}</span>
            <span class="font-medium text-sm">{{ $p['address'] ?? '—' }}</span>
            <span>{{ $p['owner_name'] ?? '—' }} → {{ $p['tenant_name'] ?? '—' }}</span>
            <span class="tabular-nums">R{{ isset($p['rent']) ? number_format((float) $p['rent'], 0, '.', ' ') : '—' }}@if(! empty($p['deposit'])) · deposit R{{ number_format((float) $p['deposit'], 0, '.', ' ') }}@endif</span>
            <span class="text-muted">{{ $p['lease_start_date'] ?? '—' }} → {{ $p['lease_end_date'] ?? 'no end date' }}</span>

            @if($imported)
                <span class="ds-badge ds-badge-success" data-test="row-imported">{{ $row->result_action === 'updated' ? 'Updated' : 'Imported' }}</span>
                @if($row->target_lease_id)<a href="{{ route('corex.leases.show', $row->target_lease_id) }}" class="underline">Open lease</a>@endif
            @elseif($failed)
                <span class="ds-badge ds-badge-danger" data-test="row-failed">Failed</span>
            @else
                <span class="text-muted" data-test="row-outcome">{{ Q::outcome($row) }}</span>
                @foreach($markers as $party => $mk)
                    <span class="ds-badge ds-badge-muted" data-test="{{ $party }}-name-flag" data-name-flag="spelling" title="CoreX has “{{ $mk['corex'] }}”, reOS has “{{ $mk['reos'] }}”">spelling differs · {{ $party }}</span>
                @endforeach
                @if($ownAgent)<span class="ds-badge ds-badge-info" data-test="row-agent">{{ $agentName }}</span>@endif
            @endif
        </span>

        @if($failed)
            <span class="block text-xs mt-1" data-test="row-notes">
                @if($row->import_error)
                    <span class="text-red-600" data-test="import-error">{{ $row->import_error }}</span>
                @endif
                @foreach($blockers as $b)
                    <span class="text-red-600" data-test="blocker" data-blocker-code="{{ $b['code'] }}">{{ $b['message'] }}</span>
                @endforeach
                @if($archived && $row->existing_lease_id)
                    <a href="{{ route('corex.leases.show', $row->existing_lease_id) }}" class="underline">Open the lease</a>
                @elseif(($d['hard'] ?? false))
                    <a href="{{ route('corex.rentals.take-on-import.index') }}" class="underline">Back to batches to upload the corrected file</a>
                @elseif($canManage)
                    <button type="button" class="underline" data-fix="{{ $row->id }}">Fix it</button>
                    <button type="button" class="underline" data-payload="{{ json_encode(['retry' => true]) }}">Try again</button>
                @endif
            </span>
        @endif
@if(! $expandable)
</div>
@else
    </summary>
    @include('corex.rentals.take-on-import._reos-panel', ['row' => $row, 'agents' => $agents, 'defaults' => $defaults, 'editable' => true])
</details>
@endif
