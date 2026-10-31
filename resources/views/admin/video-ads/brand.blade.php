{{--
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    Video ads — brand settings (CoreX's own brand, platform level). System Owner only.
    Spec: .ai/specs/training-videos.md 9.21.5
--}}
@extends('layouts.corex')

@section('title', 'Brand settings · Video ads')

@section('corex-content')
@php
    $field = 'rounded-md px-3 py-2 text-sm w-full';
    $fieldStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
    $card = 'rounded-md p-4 space-y-3';
    $cardStyle = 'background: var(--surface); border: 1px solid var(--border);';
    $lbl = 'block text-xs font-medium mb-1';
    $v = fn ($name, $default = '') => old($name, $default);
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Video ads · Brand settings</h1>
                <p class="text-xs" style="color: var(--text-muted);">CoreX's own brand, used on the end card of every ad. Leave a field empty to use the default shown in grey.</p>
            </div>
            <a href="{{ route('admin.video-ads.index') }}" class="corex-btn-outline corex-btn-on-brand text-xs">Back to Video ads</a>
        </div>
    </div>

    @if (session('status'))
        <div role="status" class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-emerald) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-emerald) 30%, transparent); color: var(--text-primary);">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('admin.video-ads.brand.update') }}" enctype="multipart/form-data" class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        @csrf @method('PUT')

        <section class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-sm font-bold" style="color: var(--text-primary);">Logo</h2>
            @if ($logoUrl)
                <div class="rounded-md p-3 inline-block" style="background: {{ $brand['colours']['primary'] }};"><img src="{{ $logoUrl }}" alt="CoreX logo" style="max-height: 56px; max-width: 240px;"></div>
            @else
                <p class="text-xs" style="color: var(--ds-crimson);">The logo file is missing - upload one.</p>
            @endif
            <div>
                <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="logo">Upload another logo (PNG, SVG or WEBP, at least 600 px wide)</label>
                <input id="logo" type="file" name="logo" accept=".png,.jpg,.jpeg,.webp,.svg" class="{{ $field }}" style="{{ $fieldStyle }}">
            </div>
            <label class="flex items-center gap-2 text-xs" style="color: var(--text-secondary);"><input type="checkbox" name="use_default_logo" value="1"> Go back to the standard CoreX logo</label>
        </section>

        <section class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-sm font-bold" style="color: var(--text-primary);">Colours</h2>
            <div class="grid grid-cols-2 gap-3">
                @foreach (['primary' => 'Main colour', 'secondary' => 'Second colour', 'accent' => 'Accent (the cyan word)', 'text' => 'Text'] as $k => $label)
                    <div>
                        <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="c-{{ $k }}">{{ $label }}</label>
                        <div class="flex items-center gap-2">
                            <span class="inline-block rounded" style="width: 28px; height: 28px; background: {{ $brand['colours'][$k] }}; border: 1px solid var(--border);"></span>
                            <input id="c-{{ $k }}" type="text" name="colours[{{ $k }}]" value="{{ $v('colours.' . $k, $brand['colours'][$k]) }}" placeholder="{{ $defaults['colours'][$k] }}" class="{{ $field }}" style="{{ $fieldStyle }}" maxlength="7">
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-sm font-bold" style="color: var(--text-primary);">Contact and lines</h2>
            @foreach (['website' => 'Website', 'email' => 'Email', 'phone' => 'Phone', 'join_line' => 'Join line', 'cta_line' => 'Call to action line', 'tagline' => 'Tagline'] as $k => $label)
                <div>
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="f-{{ $k }}">{{ $label }}</label>
                    <input id="f-{{ $k }}" type="text" name="{{ $k }}" value="{{ $v($k, $brand[$k]) }}" placeholder="{{ $defaults[$k] }}" class="{{ $field }}" style="{{ $fieldStyle }}">
                </div>
            @endforeach
        </section>

        <section class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-sm font-bold" style="color: var(--text-primary);">Follow us (social handles)</h2>
            <p class="text-xs" style="color: var(--text-muted);">Empty until you fill them in. An empty one is left off the end card, and with none filled in there is no "Follow us" line.</p>
            @foreach ($socialLabels as $k => $label)
                <div>
                    <label class="{{ $lbl }}" style="color: var(--text-secondary);" for="s-{{ $k }}">{{ $label }}</label>
                    <input id="s-{{ $k }}" type="text" name="social[{{ $k }}]" value="{{ $v('social.' . $k, $brand['social'][$k] ?? '') }}" placeholder="@handle or link" class="{{ $field }}" style="{{ $fieldStyle }}">
                </div>
            @endforeach
        </section>

        <section class="{{ $card }} lg:col-span-2" style="{{ $cardStyle }}">
            <h2 class="text-sm font-bold" style="color: var(--text-primary);">Background music</h2>
            <p class="text-xs" style="color: var(--text-muted);">None. Ads are made without music until you approve a track. Free tracks only, with a licence that allows commercial use and ads without credit (for example the Pixabay Content License).</p>
        </section>

        <div class="lg:col-span-2"><button type="submit" class="corex-btn-primary text-sm">Save brand settings</button></div>
    </form>
</div>
@endsection
