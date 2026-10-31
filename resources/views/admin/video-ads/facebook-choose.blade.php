{{--
    Video ads -> Facebook page: pick which Facebook page is the CoreX page (only when the Facebook account runs more than one). System Owner only. Spec 9.22
--}}
@extends('layouts.corex')

@section('title', 'Choose the CoreX Facebook page')

@section('corex-content')
<div class="max-w-2xl mx-auto space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold" style="color: var(--text-primary);">Choose the CoreX Facebook page</h1>
        <p class="text-xs" style="color: var(--text-muted);">Your Facebook account runs more than one page. Pick the CoreX page the video ads will be posted to.</p>
    </div>
    <div class="rounded-md p-2 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
        @foreach ($pages as $page)
            <form method="POST" action="{{ route('admin.video-ads.facebook.choose-page.save') }}">@csrf
                <input type="hidden" name="page_id" value="{{ $page['id'] }}">
                <button type="submit" class="w-full flex items-center gap-3 px-4 py-3 rounded-md text-left" style="background: var(--surface-2); border: 1px solid var(--border);">
                    @if (! empty($page['picture']))<img src="{{ $page['picture'] }}" alt="" class="w-10 h-10 rounded-md" style="object-fit: cover;">@endif
                    <span class="flex-1 text-sm font-semibold" style="color: var(--text-primary);">{{ $page['name'] }}</span>
                    <span class="text-xs font-semibold" style="color: var(--corex-accent);">Use this page →</span>
                </button>
            </form>
        @endforeach
    </div>
    <a href="{{ route('admin.video-ads.facebook') }}" class="inline-block text-sm" style="color: var(--text-muted);">Cancel</a>
</div>
@endsection
