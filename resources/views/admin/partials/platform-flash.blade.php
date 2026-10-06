{{-- AT-447 — flash + validation messages for the System Developer timeline/contract screens. --}}
@foreach(['success' => 'green', 'warning' => 'amber', 'error' => 'crimson'] as $k => $tone)
    @if(session($k))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-{{ $tone }}) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-{{ $tone }}) 30%, transparent); color: var(--text-primary);">
            {{ session($k) }}
        </div>
    @endif
@endforeach
@if($errors->any())
    <div class="rounded-md px-4 py-3 text-sm"
         style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
        <ul class="list-disc pl-5 space-y-0.5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif
