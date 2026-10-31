{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — Feature wording & videos: one feature (AT-478 §19.4). Owner-only. --}}
@extends('layouts.corex')

@section('corex-content')
@php
    $input = 'w-full mt-1 rounded-md px-3 py-2 text-sm';
    $inputStyle = 'background:var(--surface); border:1px solid var(--border); color:var(--text-primary);';
    $links = old('links', (array) ($edit?->links ?? []));
    $reverted = $edit === null && \App\Models\FeatureRollout\FeatureRolloutFeatureCopy::onlyTrashed()->where('feature_key', $feature)->exists();
@endphp
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">{{ $label }}</h1>
                <p class="text-sm text-white/60">What the rollout pop-ups say about this feature, for every agency. Leave a box empty to use the default wording.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.feature-rollout-defaults.copy.index') }}" class="corex-btn-outline">← All features</a>
            </div>
        </div>
    </div>
    @include('admin.partials.platform-flash')

    <div class="grid grid-cols-1 xl:grid-cols-5 gap-5">
        <form method="POST" action="{{ route('admin.feature-rollout-defaults.copy.update', $feature) }}"
              class="xl:col-span-3 rounded-md p-5 space-y-5" style="background: var(--surface); border: 1px solid var(--border);" data-copy-form>
            @csrf @method('PUT')

            <label class="block">
                <span class="text-xs font-semibold" style="color:var(--text-secondary);">One-line summary</span>
                <textarea name="summary" rows="2" maxlength="500" 
                          class="{{ $input }}" style="{{ $inputStyle }}">{{ old('summary', $edit?->summary ?: $defaultSummary) }}</textarea>
                <span class="text-[11px] mt-1 block" style="color:var(--text-muted);">Shown under the feature's name. Default: {{ $defaultSummary }}</span>
            </label>

            <label class="block">
                <span class="text-xs font-semibold" style="color:var(--text-secondary);">Full explanation</span>
                <textarea name="description" rows="12" maxlength="10000" 
                          class="{{ $input }}" style="{{ $inputStyle }}">{{ old('description', $edit?->description ?: $defaultDescription) }}</textarea>
                <span class="text-[11px] mt-1 block" style="color:var(--text-muted);">What the feature does and why it helps, in plain words. Leave a blank line between paragraphs. Empty, or unchanged, = the default wording. Never name an agency: every agency sees this.</span>
            </label>

            <label class="block">
                <span class="text-xs font-semibold" style="color:var(--text-secondary);">Video link</span>
                <input type="url" name="video_url" maxlength="500" value="{{ old('video_url', $edit?->video_url) }}" placeholder="https://"
                       class="{{ $input }}" style="{{ $inputStyle }}">
                <span class="text-[11px] mt-1 block" style="color:var(--text-muted);">Shows a "Watch the video" button in the pop-ups, opening in a new tab. Must start with https://.</span>
            </label>

            <div>
                <span class="text-xs font-semibold" style="color:var(--text-secondary);">Extra links</span>
                <span class="text-[11px] block" style="color:var(--text-muted);">Up to {{ $maxLinks }}: a help article, a guide or a CoreX page (start with / for a CoreX page, e.g. /corex/training).</span>
                <div class="mt-2 space-y-2">
                    @for($i = 0; $i < $maxLinks; $i++)
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-2" data-link-row="{{ $i }}">
                            <input type="text" name="links[{{ $i }}][label]" maxlength="80" value="{{ $links[$i]['label'] ?? '' }}" placeholder="Label, e.g. Read the guide"
                                   class="md:col-span-2 rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
                            <input type="text" name="links[{{ $i }}][url]" maxlength="500" value="{{ $links[$i]['url'] ?? '' }}" placeholder="https://… or /corex/…"
                                   class="md:col-span-3 rounded-md px-3 py-2 text-sm" style="{{ $inputStyle }}">
                        </div>
                    @endfor
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2 pt-2" style="border-top: 1px solid var(--border);">
                <button type="submit" class="corex-btn-primary">Save wording</button>
            </div>
        </form>

        <div class="xl:col-span-2 space-y-4">
            <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);" data-copy-preview>
                <div class="text-[11px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">What the pop-up shows now</div>
                <div class="text-lg font-bold mt-2" style="color:var(--text-primary);">{{ $preview['label'] }}</div>
                <div class="text-sm mt-1 font-medium" style="color:var(--text-secondary);">{{ $preview['summary'] }}</div>
                <div class="mt-3 space-y-2 text-sm" style="color:var(--text-primary);">
                    @foreach($preview['paragraphs'] as $p)<p>{{ $p }}</p>@endforeach
                </div>
                @if($preview['video_url'] || $preview['links'])
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if($preview['video_url'])<a href="{{ $preview['video_url'] }}" target="_blank" rel="noopener noreferrer" class="corex-btn-outline text-xs">▶ Watch the video</a>@endif
                        @foreach($preview['links'] as $l)<a href="{{ $l['url'] }}" target="_blank" rel="noopener noreferrer" class="corex-btn-outline text-xs">{{ $l['label'] }}</a>@endforeach
                    </div>
                @endif
                <div class="mt-3 text-[11px]" style="color:var(--text-muted);">{{ $preview['edited'] ? 'Using your edited wording.' : 'Using the default wording.' }}</div>
            </div>

            @if($edit)
                <form method="POST" action="{{ route('admin.feature-rollout-defaults.copy.destroy', $feature) }}" class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);"
                      onsubmit="return confirm('Go back to the default wording for {{ e(addslashes($label)) }}? Your edit is kept and can be restored.');">
                    @csrf @method('DELETE')
                    <div class="text-sm font-semibold" style="color:var(--text-primary);">Go back to the default wording</div>
                    <div class="text-xs mt-1" style="color:var(--text-muted);">Removes this edit (including the video and links) from the pop-ups. It is kept, and can be restored.</div>
                    <button type="submit" class="corex-btn-outline mt-3">Go back to the default</button>
                </form>
            @elseif($reverted)
                <form method="POST" action="{{ route('admin.feature-rollout-defaults.copy.restore', $feature) }}" class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
                    @csrf
                    <div class="text-sm font-semibold" style="color:var(--text-primary);">An earlier edit is kept</div>
                    <div class="text-xs mt-1" style="color:var(--text-muted);">This feature went back to the default wording. Restore brings the earlier edit back.</div>
                    <button type="submit" class="corex-btn-outline mt-3" data-copy-restore>Restore the edit</button>
                </form>
            @endif
        </div>
    </div>
</div>
@endsection
