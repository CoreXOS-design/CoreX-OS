{{--
    The full-size viewer of an AI pop-up (shared by both pop-ups). Must sit inside the pop-up's x-data (it uses the
    corexAiDialogShell() methods). $withVersions shows the Default / property-type switch (property templates only).
--}}
    {{-- Full-screen viewer (decision: previews open full size). Teleported to <body>, above the pop-up; never available while a design is being made. --}}
    <template x-teleport="body">
        <div x-show="viewer.open" x-cloak class="aitv-overlay" data-aitv="overlay"
             role="dialog" aria-modal="true" aria-label="Full-size preview"
             @keydown.arrow-left.window="viewerStep(-1)" @keydown.arrow-right.window="viewerStep(1)"
             @keydown.tab="trapTab($event, $refs.viewerBox)" @resize.window="fitViewer()" x-ref="viewerBox" tabindex="-1">
            <div class="aitv-backdrop" data-aitv="backdrop" @click="closeViewer()"></div>

            <div class="aitv-top">
                <div class="aitv-title" aria-live="polite" data-aitv="title" x-text="viewerTitle()"></div>
                <button type="button" class="aitv-btn aitv-x" data-aitv="close" x-ref="viewerClose" @click="closeViewer()" aria-label="Close full-size preview" title="Close (Esc)">&times;</button>
            </div>

            @if($withVersions ?? false)
            <div class="aitv-switch" role="group" aria-label="Version to preview" x-show="viewerOpt() && variantNames(viewerOpt()).length">
                <button type="button" class="aitv-pill" data-aitv="version" :aria-pressed="viewerOpt() && viewerOpt().view === '' ? 'true' : 'false'" @click="showView(viewerOpt(), '')">Default</button>
                <template x-for="t in (viewerOpt() ? variantNames(viewerOpt()) : [])" :key="t">
                    <button type="button" class="aitv-pill" data-aitv="version" :aria-pressed="viewerOpt().view === t ? 'true' : 'false'" :title="t" @click="showView(viewerOpt(), t)" x-text="t"></button>
                </template>
            </div>
            @endif

            <div class="aitv-stage" x-ref="viewerStage" data-aitv="stage">
                <div class="aitv-frame" x-ref="viewerFrame" data-aitv="frame"><div class="aitv-inner" x-ref="viewerInner"></div></div>
            </div>

            <div class="aitv-bottom" x-show="options.length > 1">
                <button type="button" class="aitv-btn" data-aitv="prev" @click="viewerStep(-1)" aria-label="Previous design">&#8249; Previous</button>
                <button type="button" class="aitv-btn" data-aitv="next" @click="viewerStep(1)" aria-label="Next design">Next &#8250;</button>
            </div>
        </div>
    </template>
