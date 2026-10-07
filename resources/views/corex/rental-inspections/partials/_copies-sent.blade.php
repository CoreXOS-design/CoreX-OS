{{--
    .ai/specs/rental-inspections.md §45.6 (Build I-4) — "Copies sent": the latest outcome of the completed report's copy to
    each party (tenant(s), landlord(s), the agency's copy address, the inspector, the agent who created it), read straight
    from the delivery log. A failed or skipped copy shows WHY and has its own Resend. Plain Blade — no Alpine.
    In: $inspection.
--}}
@php
    $copiesPanel = app(\App\Services\Rentals\RentalInspectionCopiesService::class)->panelFor($inspection);
    $isCompleted = $inspection->status === \App\Models\RentalInspection::STATUS_COMPLETED;
@endphp
@if($isCompleted || count($copiesPanel) > 0)
<div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
    <h2 class="text-sm font-semibold">Copies sent</h2>
    @if(count($copiesPanel) === 0)
        <p class="text-xs" style="color: var(--text-muted);">
            No copies have gone out yet. Use "Resend report" on the property's Inspections tab to send the signed report to everyone.
            (If automatic sending is switched off in Settings → Rental Inspections, nothing is sent when an inspection completes.)
        </p>
    @else
        <table class="w-full text-xs">
            <thead>
                <tr class="text-left" style="color: var(--text-muted);">
                    <th class="py-1 pr-2 font-semibold">Who</th>
                    <th class="py-1 pr-2 font-semibold">Address</th>
                    <th class="py-1 pr-2 font-semibold">Result</th>
                    <th class="py-1 pr-2 font-semibold">When</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($copiesPanel as $row)
                    <tr style="border-top: 1px solid var(--border);">
                        <td class="py-1 pr-2">{{ $row['party'] }} <span style="color: var(--text-muted);">({{ $row['role_label'] }})</span></td>
                        <td class="py-1 pr-2">{{ $row['email'] ?: '—' }}</td>
                        <td class="py-1 pr-2">
                            <span class="ds-badge {{ $row['status'] === 'sent' ? 'ds-badge-success' : ($row['status'] === 'failed' ? 'ds-badge-danger' : 'ds-badge-warning') }}">{{ ucfirst($row['status']) }}</span>
                            @if($row['reason'])<span style="color: var(--text-muted);"> {{ $row['reason'] }}</span>@endif
                        </td>
                        <td class="py-1 pr-2" style="color: var(--text-muted);">{{ $row['when']?->format('d M Y H:i') }}@if($row['mode']) · {{ $row['mode'] === 'auto' ? 'automatic' : 'manual' }}@endif</td>
                        <td class="py-1 text-right">
                            @if($row['can_resend'])
                                @permission('rental_inspections.create')
                                    <form method="POST" action="{{ route('corex.rental-inspections.resend-recipient', $inspection) }}">
                                        @csrf
                                        <input type="hidden" name="log_id" value="{{ $row['log_id'] }}">
                                        <button type="submit" class="corex-btn-outline text-xs">Resend</button>
                                    </form>
                                @endpermission
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endif
