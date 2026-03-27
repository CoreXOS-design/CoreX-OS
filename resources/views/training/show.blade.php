@extends('layouts.corex')

@section('corex-content')
@php $userId = auth()->id(); $pct = $course->completionPercentForUser($userId); @endphp
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5" x-data="{ activeLesson: null, showAck: false }">

    {{-- Header --}}
    <div style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <div class="flex items-center gap-2 mb-1">
            <a href="{{ route('training.index') }}" class="text-xs no-underline" style="color:rgba(255,255,255,0.5);">My Training</a>
            <span style="color:rgba(255,255,255,0.3);">/</span>
        </div>
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">{{ $course->title }}</h2>
        @if($course->description)
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">{{ $course->description }}</div>
        @endif
    </div>

    @if(session('success'))
        <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;">{{ session('error') }}</div>
    @endif

    {{-- Progress bar --}}
    <div class="flex items-center gap-3 p-4 rounded-lg" style="background:var(--surface); border:1px solid var(--border);">
        <div class="flex-1 h-3 rounded-full overflow-hidden" style="background:var(--border);">
            <div class="h-full rounded-full transition-all" style="width:{{ $pct }}%; background:{{ $completion ? '#22c55e' : '#0ea5e9' }};"></div>
        </div>
        <span class="text-sm font-bold" style="color:var(--text-primary);">{{ $pct }}%</span>
        @if($completion)
        <span class="px-2 py-0.5 rounded text-xs font-bold" style="background:rgba(34,197,94,0.12); color:#22c55e;">Completed</span>
        @endif
    </div>

    {{-- Lesson list --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        @foreach($course->lessons as $idx => $lesson)
        @php
            $progress = $lesson->progressForUser($userId);
            $isLessonDone = $progress && $progress->completed_at;
            $isStarted = $progress && $progress->started_at;
        @endphp
        <div style="{{ $idx > 0 ? 'border-top:1px solid var(--border);' : '' }}">
            {{-- Lesson header --}}
            <button type="button" @click="activeLesson = activeLesson === {{ $lesson->id }} ? null : {{ $lesson->id }}"
                    class="w-full flex items-center gap-3 px-5 py-3 text-left transition-colors hover:bg-white/5">
                {{-- Status icon --}}
                <div class="flex-shrink-0 w-6 h-6 rounded-full flex items-center justify-center"
                     style="{{ $isLessonDone ? 'background:#22c55e; color:#fff;' : ($isStarted ? 'background:rgba(14,165,233,0.15); color:#0ea5e9;' : 'background:var(--border); color:var(--text-muted);') }}">
                    @if($isLessonDone)
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                    @else
                    <span class="text-[10px] font-bold">{{ $idx + 1 }}</span>
                    @endif
                </div>

                <div class="flex-1 min-w-0">
                    <div class="text-sm font-medium" style="color:var(--text-primary);">{{ $lesson->title }}</div>
                    <div class="text-xs" style="color:var(--text-muted);">{{ $lesson->duration_minutes }} min</div>
                </div>

                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                     class="w-4 h-4 transition-transform" style="color:var(--text-muted);"
                     :class="activeLesson === {{ $lesson->id }} ? 'rotate-180' : ''">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>

            {{-- Lesson content --}}
            <div x-show="activeLesson === {{ $lesson->id }}" x-cloak x-transition
                 class="px-5 pb-5" style="border-top:1px solid var(--border);">

                @if(!$isStarted)
                <form method="POST" action="{{ route('training.start-lesson', $lesson) }}" class="mb-3">
                    @csrf
                    <button type="submit" class="text-xs px-3 py-1 rounded" style="background:rgba(14,165,233,0.12); color:#0ea5e9; border:1px solid rgba(14,165,233,0.25);">Start Lesson</button>
                </form>
                @endif

                <div class="prose prose-sm max-w-none mt-3" style="color:var(--text-primary);">
                    @if($lesson->content_type === 'text' && $lesson->content)
                        <div class="text-sm leading-relaxed whitespace-pre-line" style="color:var(--text-secondary);">{!! nl2br(e($lesson->content)) !!}</div>
                    @elseif($lesson->content_type === 'video_url' && $lesson->video_url)
                        <div class="aspect-video rounded-lg overflow-hidden mb-3" style="background:#000;">
                            <iframe src="{{ $lesson->video_url }}" class="w-full h-full" frameborder="0" allowfullscreen></iframe>
                        </div>
                    @elseif($lesson->content_type === 'document' && $lesson->document_path)
                        <a href="{{ asset('storage/' . $lesson->document_path) }}" target="_blank"
                           class="inline-flex items-center gap-2 px-4 py-2 rounded-md text-sm no-underline"
                           style="background:rgba(14,165,233,0.12); color:#0ea5e9; border:1px solid rgba(14,165,233,0.25);">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                            Download Document
                        </a>
                    @elseif($lesson->content_type === 'link' && $lesson->external_link)
                        <a href="{{ $lesson->external_link }}" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-2 px-4 py-2 rounded-md text-sm no-underline"
                           style="background:rgba(14,165,233,0.12); color:#0ea5e9; border:1px solid rgba(14,165,233,0.25);">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                            Open External Resource
                        </a>
                    @endif
                </div>

                @if(!$isLessonDone && $isStarted)
                <form method="POST" action="{{ route('training.complete-lesson', $lesson) }}" class="mt-4">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-sm px-4 py-2">Mark as Complete</button>
                </form>
                @endif
            </div>
        </div>
        @endforeach
    </div>

    {{-- Acknowledgement section --}}
    @if($pct === 100 && !$completion)
    <div style="background:var(--surface); border:2px solid #22c55e; border-radius:6px; padding:20px 24px;">
        <h3 class="text-sm font-bold mb-2" style="color:var(--text-primary);">All lessons complete — Acknowledge & Finish</h3>
        <p class="text-xs mb-4" style="color:var(--text-secondary);">
            I confirm I have read and understood the material in "{{ $course->title }}".
        </p>
        <form method="POST" action="{{ route('training.acknowledge', $course) }}">
            @csrf
            <input type="hidden" name="signature" value="acknowledged">
            <button type="submit" class="text-sm px-5 py-2.5 rounded-md font-semibold" style="background:#22c55e; color:#fff;"
                    onclick="return confirm('By clicking Acknowledge, you confirm you have read and understood all course material. This acknowledgement is valid for 12 months.')">
                Acknowledge & Complete Course
            </button>
        </form>
    </div>
    @elseif($completion)
    <div class="p-4 rounded-lg" style="background:rgba(34,197,94,0.08); border:1px solid rgba(34,197,94,0.2);">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 flex-shrink-0" style="color:#22c55e;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
            <div>
                <div class="text-sm font-semibold" style="color:#22c55e;">Course Completed & Acknowledged</div>
                <div class="text-xs" style="color:var(--text-secondary);">
                    Completed {{ $completion->completed_at->format('d M Y') }}
                    @if($completion->expires_at)
                    &middot; Expires {{ $completion->expires_at->format('d M Y') }}
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
@endsection
