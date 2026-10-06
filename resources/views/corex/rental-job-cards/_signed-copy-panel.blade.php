{{--
    .ai/specs/rental-work-orders.md §14.27.5 / §14.28 — "Signed copy": the
    wet-ink route. The crew brings the signed job card back; it is uploaded
    here and records the crew completion. Earlier copies are kept and marked
    Superseded — never deleted. Hidden on a cancelled card with nothing filed.

    Expects: $jobCard, $signedCopies (newest first).
--}}
@if($jobCard->status !== \App\Models\RentalJobCard::STATUS_CANCELLED || $signedCopies->isNotEmpty())
<div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" id="jc-signed-copy-box">
    <h2 class="text-sm font-semibold">Signed copy</h2>

    @if($errors->has('signed_copy') || $errors->has('signed_by_name'))
        <div class="text-xs p-2 rounded" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">{{ $errors->first('signed_copy') ?: $errors->first('signed_by_name') }}</div>
    @endif

    @if($signedCopies->isNotEmpty())
        <ul class="space-y-1 text-xs">
            @foreach($signedCopies as $copy)
                <li class="flex flex-wrap items-center gap-x-2" @if($copy->superseded_at) style="color: var(--text-muted);" @endif>
                    <a href="{{ route('corex.rental-job-cards.signed-copy.download', [$jobCard, $copy->id]) }}" target="_blank" class="underline">{{ \Illuminate\Support\Str::limit($copy->original_name, 28) }}</a>
                    <span>{{ $copy->signed_by_name ? 'signed by ' . $copy->signed_by_name . ', ' : '' }}{{ $copy->uploaded_at?->format('Y-m-d H:i') }}</span>
                    <span class="ds-badge {{ $copy->superseded_at ? 'ds-badge-muted' : 'ds-badge-success' }}">{{ $copy->superseded_at ? 'Superseded' : 'Current' }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    @permission('rental_job_cards.sign_off')
    @if($jobCard->status !== \App\Models\RentalJobCard::STATUS_CANCELLED)
    <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.signed-copy.store', $jobCard) }}" enctype="multipart/form-data" class="space-y-2">
        @csrf
        <input type="file" name="signed_copy" required accept=".pdf,.jpg,.jpeg,.png" aria-label="Signed job card (PDF, JPG or PNG)" class="w-full text-xs">
        <input type="text" name="signed_by_name" required maxlength="191" value="{{ old('signed_by_name', $jobCard->worker_sign_off_name) }}" list="signed-copy-names" placeholder="Name of the person who signed" aria-label="Signed by" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
        <datalist id="signed-copy-names">
            @foreach($jobCard->crew?->members ?? [] as $member)<option value="{{ $member->name }}">@endforeach
        </datalist>
        <button type="submit" class="corex-btn-outline text-xs w-full">{{ $signedCopies->isEmpty() ? 'Upload signed copy' : 'Upload a newer signed copy' }}</button>
        <p class="text-xs" style="color: var(--text-muted);">PDF, JPG or PNG, up to 10 MB.{{ $isOpen ? ' Uploading records the crew as completed.' : '' }}</p>
    </form>
    @endif
    @endpermission
</div>
@endif
