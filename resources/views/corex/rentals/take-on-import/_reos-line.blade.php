{{--
    AT-481 — one lease on the reOS review screen (spec "reOS review screen rebuild" R14): ONE line — row, property, owner → tenant, rent,
    dates, status chip — that opens IN PLACE. A lease that needs an answer opens onto its open questions (standard buttons) and what will
    happen; a ready lease onto its Change controls; a failed one onto why and how to fix it. Drawn by the page AND re-drawn by
    ReosTakeOnApiController after every change (the JSON answer carries this HTML).
    Expects: $row, $run, $agents, $defaults, $canManage.
--}}
@php
    use App\Services\Rentals\TakeOnImport\Reos\ReosQuestions as Q;
    $p = $row->payload_json ?? [];
    $d = $row->decisions_json ?? [];
    $state = Q::state($row);
    $imported = $state === Q::IMPORTED;
    $failed = $state === Q::FAILED;
    $needs = $state === Q::NEEDS;
    $hard = $failed && ($d['hard'] ?? false);
    $blockers = $row->blockers_json ?? [];
    $archived = collect($blockers)->firstWhere('code', 'archived_lease');
    $markers = Q::spellingMarkers($row);
    $agentName = collect($agents)->firstWhere('id', (int) ($d['agent_user_id'] ?? 0))['name'] ?? null;
    $ownAgent = $agentName && ! $row->existing_lease_id && (int) ($d['agent_user_id'] ?? 0) !== (int) ($defaults['agent_user_id'] ?? 0);
    // Imported leases are finished and a viewer cannot answer: a plain line. Everything else opens.
    $opens = $canManage && ! $imported;
@endphp
@if($opens)
<details id="row-{{ $row->id }}" class="corex-card" data-row-id="{{ $row->id }}" data-row-number="{{ $row->row_number }}" data-state="{{ $state }}">
    <summary class="px-3 py-2 cursor-pointer">
@else
<div id="row-{{ $row->id }}" class="corex-card px-3 py-2" data-row-id="{{ $row->id }}" data-row-number="{{ $row->row_number }}" data-state="{{ $state }}">
@endif
        <span class="inline-flex flex-wrap items-center gap-x-3 gap-y-1 text-xs align-middle" data-test="reos-line">
            <span class="text-muted tabular-nums">Row {{ $row->row_number }}</span>
            <span class="font-medium text-sm">{{ $p['address'] ?? '—' }}</span>
            <span>{{ $p['owner_name'] ?? '—' }} → {{ $p['tenant_name'] ?? '—' }}</span>
            <span class="tabular-nums">R{{ isset($p['rent']) ? number_format((float) $p['rent'], 0, '.', ' ') : '—' }}@if(! empty($p['deposit'])) · deposit R{{ number_format((float) $p['deposit'], 0, '.', ' ') }}@endif</span>
            <span class="text-muted">{{ $p['lease_start_date'] ?? '—' }} → {{ $p['lease_end_date'] ?? 'no end date' }}</span>
            @foreach($markers as $party => $mk)
                <span class="ds-badge ds-badge-muted" data-test="{{ $party }}-name-flag" data-name-flag="spelling" title="CoreX has “{{ $mk['corex'] }}”, reOS has “{{ $mk['reos'] }}”">spelling differs · {{ $party }}</span>
            @endforeach
            @if($ownAgent && ! $imported)<span class="ds-badge ds-badge-info" data-test="row-agent">{{ $agentName }}</span>@endif

            @if($imported)
                <span class="ds-badge ds-badge-success" data-test="row-imported">{{ $row->result_action === 'updated' ? 'Updated' : 'Imported' }}</span>
                @if($row->target_lease_id)<a href="{{ route('corex.leases.show', $row->target_lease_id) }}" class="underline">Open lease</a>@endif
            @elseif($failed)
                <span class="ds-badge ds-badge-danger" data-test="row-failed" data-chip>Failed</span>
            @elseif($needs)
                <span class="ds-badge ds-badge-warning" data-test="row-needs" data-chip>Needs you · {{ (int) ($d['needs_count'] ?? 0) }}</span>
            @else
                <span class="ds-badge ds-badge-success" data-test="row-ready" data-chip>Ready</span>
            @endif
        </span>
@if($opens)
    </summary>
    <div class="p-3 space-y-4 border-t">
        @if($failed)
            <div class="space-y-1" data-test="row-notes">
                @if($row->import_error)<div class="text-red-600" data-test="import-error">{{ $row->import_error }}</div>@endif
                @foreach($blockers as $b)<div class="text-red-600" data-test="blocker" data-blocker-code="{{ $b['code'] }}">{{ $b['message'] }}</div>@endforeach
                @if($archived && $row->existing_lease_id)
                    <a href="{{ route('corex.leases.show', $row->existing_lease_id) }}" class="underline">Open the lease</a>
                @elseif($hard)
                    <a href="{{ route('corex.rentals.take-on-import.index') }}" class="underline">Back to batches to upload the corrected file</a>
                @endif
            </div>
            @unless($hard)
                <div><button type="button" class="corex-btn-outline" data-payload="{{ json_encode(['retry' => true]) }}">Try again</button></div>
            @endunless
        @elseif($needs)
            @include('corex.rentals.take-on-import._reos-questions', ['row' => $row, 'agents' => $agents])
        @endif

        @unless($hard)
            <div class="text-muted" data-test="row-outcome">When imported: {{ Q::outcome($row) }}</div>
            @if($needs)
                <details>
                    <summary class="cursor-pointer underline text-muted">Change something else</summary>
                    @include('corex.rentals.take-on-import._reos-panel', ['row' => $row, 'agents' => $agents, 'defaults' => $defaults, 'editable' => true])
                </details>
            @else
                @include('corex.rentals.take-on-import._reos-panel', ['row' => $row, 'agents' => $agents, 'defaults' => $defaults, 'editable' => true])
            @endif
        @endunless
    </div>
</details>
@else
</div>
@endif
