{{-- AT-526 (spec .ai/specs/training-videos.md 9.23) - the "Guide me" button of the NARRATED guided tour: a voice says what to do and why, and the tour waits until the agent has done it.
     Drawn only on a screen whose training script is marked `narrated` AND only when NarratedTourGate says yes (agency has Narrated guided tours on, the person holds
     training_tours.use, the script's audience fits). A pill above the Watch how / help buttons. The player is public/js/corex-guide-me.js (driver.js, the same look as the plain tour). --}}
@auth
@php
    $__gmScript = null;
    try {
        $__gmScript = app(\App\Services\TrainingVideos\Auto\NarratedTourGate::class)->forRequest(auth()->user(), optional(request()->route())->getName(), request()->path());
    } catch (\Throwable $e) { $__gmScript = null; }
    // Blade's @json splits its arguments on every comma, so each value is worked out here first.
    $__gmApi = $__gmScript ? route('api.v1.training-tours.show', ['screen' => $__gmScript->key()], false) : '';
    $__gmDriver = asset('vendor/driverjs/driver.js.iife.js');
    $__gmJs = asset('js/corex-guide-me.js') . '?v=' . (@filemtime(public_path('js/corex-guide-me.js')) ?: 1);
@endphp
@if($__gmScript)
@once
@include('layouts.partials.tour-theme')
@endonce
<div data-guide-me="{{ $__gmScript->key() }}">
    <button type="button" data-guide-me-button
            class="fixed z-40 flex items-center gap-2 text-xs font-semibold rounded-full shadow-lg px-4 py-2" style="right: 16px; bottom: 112px; background: var(--brand-icon, #0ea5e9); color: #fff;">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px;"><path d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.92V21h2v-3.08A7 7 0 0 0 19 11h-2z"/></svg>
        Guide me
    </button>
</div>
<script>
(function () {
    if (!(window.driver && window.driver.js)) {
        var d = document.createElement('script');
        d.src = @json($__gmDriver, JSON_UNESCAPED_SLASHES);
        document.head.appendChild(d);
    }
    var cfg = { screen: @json($__gmScript->key()), api: @json($__gmApi, JSON_UNESCAPED_SLASHES), title: @json($__gmScript->title()) };
    var s = document.createElement('script');
    s.src = @json($__gmJs, JSON_UNESCAPED_SLASHES);
    s.onload = function () {
        var btn = document.querySelector('[data-guide-me-button]');
        if (btn) btn.addEventListener('click', function () { window.CoreXGuideMe.start(cfg); });
        window.addEventListener('corex-guide:started', function () { if (btn) btn.style.display = 'none'; });
        window.addEventListener('corex-guide:ended', function () { if (btn) btn.style.display = ''; });
        window.addEventListener('load', function () { window.setTimeout(function () { window.CoreXGuideMe.offerResume(cfg); }, 600); });
        if (document.readyState === 'complete') window.setTimeout(function () { window.CoreXGuideMe.offerResume(cfg); }, 600);
    };
    document.head.appendChild(s);
})();
</script>
@endif
@endauth
