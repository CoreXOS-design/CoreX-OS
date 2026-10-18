{{--
    rental-work-orders.md §14.29 — Lease Hub: the job cards raised on THIS tenancy,
    each with its status and its photos (the card's own plus its linked work order's,
    exactly as the card screen merges them). The office sees every photo type; what a
    tenant or landlord is allowed to see is decided separately
    (RentalJobCardClientViewService). Cards arrive already filtered by the viewer's own
    job-card scope; the panel is simply absent when there are none.
--}}
@if($jobCards->isNotEmpty())
@php
    $jcBadge = fn ($status) => match ($status) {
        'completed' => 'ds-badge-success',
        'cancelled' => 'ds-badge-danger',
        'draft' => 'ds-badge-muted',
        default => 'ds-badge-info',
    };
@endphp
<div id="lease-job-cards" class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
    <h2 class="text-sm font-semibold">Job cards <span class="ds-badge ds-badge-muted text-[10px]">{{ $jobCards->count() }}</span></h2>
    <ul class="space-y-3">
        @foreach($jobCards as $card)
            @php $cardPhotos = $card->photos->concat($card->workOrder?->photos ?? collect())->unique('id')->values(); @endphp
            <li class="space-y-1.5" style="border-bottom: 1px solid var(--border); padding-bottom: 8px;" data-job-card-row="{{ $card->id }}">
                <div class="flex items-center justify-between gap-2 text-sm">
                    <div class="min-w-0">
                        <a href="{{ route('corex.rental-job-cards.show', $card) }}" class="underline font-medium">{{ $card->title }}</a>
                        <span class="ds-badge {{ $jcBadge($card->status) }} text-[10px]">{{ ucfirst(str_replace('_', ' ', $card->status)) }}</span>
                    </div>
                    <div class="text-xs flex-shrink-0" style="color: var(--text-muted);">
                        @if($card->completed_at)
                            Completed {{ $card->completed_at->format('Y-m-d') }}
                        @elseif($card->scheduled_at)
                            Scheduled {{ $card->scheduled_at->format('Y-m-d H:i') }}
                        @else
                            Not scheduled
                        @endif
                    </div>
                </div>
                <div class="text-xs" style="color: var(--text-muted);">
                    Crew: {{ $card->crew?->name ?? 'not assigned' }}
                    @if($card->worker_signed_off_at)
                        &middot; Crew completed {{ $card->worker_signed_off_at->format('Y-m-d') }}@if($card->worker_sign_off_name) by {{ $card->worker_sign_off_name }}@endif
                    @endif
                </div>
                @if($cardPhotos->isEmpty())
                    <p class="text-xs" style="color: var(--text-muted);">No photos yet.</p>
                @else
                    <div class="flex flex-wrap gap-1.5" data-job-card-photos>
                        @foreach($cardPhotos->take(8) as $photo)
                            <a href="{{ $photo->storage_path }}" target="_blank" rel="noopener" title="{{ ucfirst(str_replace('_', ' ', $photo->photo_type)) }}">
                                <img src="{{ $photo->storage_path }}" alt="{{ ucfirst(str_replace('_', ' ', $photo->photo_type)) }} photo" class="rounded w-14 h-14 object-cover" loading="lazy">
                            </a>
                        @endforeach
                        @if($cardPhotos->count() > 8)
                            <a href="{{ route('corex.rental-job-cards.show', $card) }}" class="text-xs underline self-center">+{{ $cardPhotos->count() - 8 }} more</a>
                        @endif
                    </div>
                @endif
            </li>
        @endforeach
    </ul>
</div>
@endif
