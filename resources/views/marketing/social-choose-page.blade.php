@extends('layouts.corex')

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-xl font-bold text-white leading-tight">Choose a {{ $platform === 'instagram' ? 'Instagram account' : 'Facebook Page' }}</h1>
        <p class="text-sm text-white/60">
            Your Facebook account manages more than one Page. Pick the one CoreX should
            {{ $platform === 'instagram' ? 'connect for Instagram posting' : 'connect and post ads to' }} — usually
            your agency's Page, not a personal or unrelated one.
        </p>
    </div>

    <div class="rounded-md p-2 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
        @foreach($pages as $page)
            <form method="POST" action="{{ route('corex.social.oauth.choose-page.save') }}">
                @csrf
                <input type="hidden" name="page_id" value="{{ $page['id'] }}">
                <button type="submit"
                        class="w-full flex items-center gap-3 px-4 py-3 rounded-md text-left transition"
                        style="background: var(--surface-2); border: 1px solid var(--border);"
                        onmouseover="this.style.borderColor='var(--brand-icon, #0ea5e9)'"
                        onmouseout="this.style.borderColor='var(--border)'">
                    @if($page['picture'])
                        <img src="{{ $page['picture'] }}" alt="" class="w-10 h-10 rounded-md flex-shrink-0" style="object-fit: cover;">
                    @else
                        <div class="w-10 h-10 rounded-md flex-shrink-0 flex items-center justify-center" style="background: #1877f222;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#1877f2" style="width:18px; height:18px;"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                        </div>
                    @endif
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-semibold truncate" style="color: var(--text-primary);">{{ $page['name'] }}</div>
                        @if($platform === 'instagram')
                            <div class="text-xs" style="color: var(--text-muted);">Instagram Business Account linked</div>
                        @endif
                    </div>
                    <span class="text-xs font-semibold flex-shrink-0" style="color: var(--brand-icon, #0ea5e9);">Connect →</span>
                </button>
            </form>
        @endforeach
    </div>

    <a href="{{ route('agent.portal') }}" class="inline-block text-sm" style="color: var(--text-muted);">Cancel</a>

</div>
@endsection
