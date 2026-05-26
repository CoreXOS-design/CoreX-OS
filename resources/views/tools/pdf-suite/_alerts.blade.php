@if(session('status'))
    <div class="mb-4 px-3 py-2 rounded-md text-sm" style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--text-primary);">
        {{ session('status') }}
    </div>
@endif
@if($errors->any())
    <div class="mb-4 px-3 py-2 rounded-md text-sm" style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent); color: var(--text-primary);">
        <ul class="list-disc pl-5 m-0">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif
