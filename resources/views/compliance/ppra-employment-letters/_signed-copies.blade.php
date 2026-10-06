{{--
    PPRA employment letter — the wet-ink signed copies of ONE letter. .ai/specs/ppra-ffc-employment-letter.md §20
    Shared by My Portal (row + letter page) and the Admin register (row + detail) so both render the very same record.

    Expects: $letter (PpraEmploymentLetter), $uploadUrl (string|null — null hides the form), $scanRouteName
    (route taking [letter, file]), and optionally $showHistory (default true: list earlier uploads as Superseded).
--}}
@php
    $copyFiles   = $letter->relationLoaded('files') ? $letter->files : $letter->files()->with('uploader')->get();
    $copyCurrent = $copyFiles->first();
    $showHistory = $showHistory ?? true;
    $copyOlder   = $showHistory ? $copyFiles->slice(1)->values() : collect();
@endphp
<div class="ppra-signed-copies" data-letter-id="{{ $letter->id }}" style="font-size:0.75rem; color:var(--text-primary);">
    @if($copyCurrent)
        <div class="ppra-copy-current">
            <span style="font-weight:600;">Signed copy:</span>
            <a href="{{ route($scanRouteName, [$letter->id, $copyCurrent->id]) }}" target="_blank" rel="noopener" style="color:var(--brand-icon,#0ea5e9); text-decoration:underline;">{{ $copyCurrent->original_name }}</a>
            <span style="display:inline-block; font-size:0.625rem; font-weight:700; padding:1px 7px; border-radius:999px; background:color-mix(in srgb, var(--ds-green, #15803d) 14%, transparent); color:var(--ds-green, #15803d);">Current</span>
            <span style="color:var(--text-muted);">uploaded {{ $copyCurrent->created_at->format('d M Y H:i') }}{{ $copyCurrent->uploader ? ' by ' . $copyCurrent->uploader->name : '' }}</span>
        </div>
    @endif

    @if($copyOlder->isNotEmpty())
        <details class="ppra-copy-history" style="margin-top:4px;">
            <summary style="cursor:pointer; color:var(--text-muted);">Earlier uploads ({{ $copyOlder->count() }})</summary>
            <ul style="margin:4px 0 0; padding-left:16px;">
                @foreach($copyOlder as $i => $old)
                    @php $newer = $copyFiles[$i]; /* the upload that replaced this one — $copyFiles[0] is current, $old is $copyFiles[$i + 1] */ @endphp
                    <li class="ppra-copy-superseded">
                        <a href="{{ route($scanRouteName, [$letter->id, $old->id]) }}" target="_blank" rel="noopener" style="color:var(--brand-icon,#0ea5e9); text-decoration:underline;">{{ $old->original_name }}</a>
                        <span style="font-weight:600; color:var(--ds-amber, #b45309);">Superseded</span>
                        <span style="color:var(--text-muted);">{{ $newer->created_at->format('d M Y H:i') }}{{ $newer->uploader ? ' by ' . $newer->uploader->name : '' }}
                            &middot; originally uploaded {{ $old->created_at->format('d M Y') }}{{ $old->uploader ? ' by ' . $old->uploader->name : '' }}</span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    @if(! empty($uploadUrl) && ! $letter->trashed())
        <form method="POST" action="{{ $uploadUrl }}" enctype="multipart/form-data" style="margin-top:6px; display:flex; flex-wrap:wrap; align-items:center; gap:6px;">
            @csrf
            <input type="file" name="signed_copy" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required style="font-size:0.6875rem; max-width:220px;">
            <button type="submit" class="corex-btn-primary" style="font-size:0.6875rem; padding:4px 10px;">{{ $copyCurrent ? 'Upload replacement' : 'Upload signed letter' }}</button>
        </form>
    @endif
</div>
