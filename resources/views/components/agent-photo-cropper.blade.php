@props([
    'name'       => 'agent_photo',   // the real file input name submitted with the form
    'current'    => null,            // current photo URL (edit) or null
    'autosubmit' => false,           // submit the enclosing form immediately after Apply (portal camera UX)
    'size'       => 96,              // preview avatar diameter in px
])

{{--
    Reusable agent-photo cropper. Drops in anywhere a square 1200×1200 photo is
    uploaded. Pick image → modal with pan/zoom + face guide → Apply renders a
    1200×1200 JPEG into the real (named) file input via DataTransfer. The server
    normalizer (App\Services\Images\AgentPhotoNormalizer) re-encodes to a square
    WebP, so uniformity holds even if this UI is bypassed.

    Spec: .ai/specs/agent-photo.md §3–4
--}}
<div x-data="agentPhotoCropper({{ $autosubmit ? 'true' : 'false' }})" class="agent-photo-cropper">

    {{-- The real input that the form submits. Populated by the cropper. --}}
    <input type="file" name="{{ $name }}" accept="image/jpeg,image/png,image/webp"
           x-ref="input" data-autosubmit="{{ $autosubmit ? '1' : '0' }}" class="hidden">

    {{-- Hidden source picker (chooses the image to crop; never submitted). --}}
    <input type="file" accept="image/jpeg,image/png,image/webp" x-ref="picker"
           @change="pick($event)" class="hidden">

    {{-- Trigger / preview --}}
    <div class="flex items-center gap-4">
        <button type="button" @click="$refs.picker.click()"
                class="relative shrink-0 rounded-full overflow-hidden"
                style="width:{{ $size }}px; height:{{ $size }}px; border:2px solid var(--border); background:var(--surface-2);"
                title="Upload square photo">
            {{-- Cropped preview (after Apply) --}}
            <img x-ref="previewImg" x-show="previewUrl" :src="previewUrl" alt=""
                 class="w-full h-full object-cover" style="display:none;">
            {{-- Existing photo --}}
            @if($current)
            <img x-show="!previewUrl" src="{{ $current }}" alt="Current photo"
                 class="w-full h-full object-cover">
            @else
            {{-- Empty placeholder --}}
            <span x-show="!previewUrl" class="flex items-center justify-center w-full h-full"
                  style="color:var(--text-muted);">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:40%; height:40%;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.774 48.774 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0Z" />
                </svg>
            </span>
            @endif
        </button>

        <div>
            <button type="button" @click="$refs.picker.click()"
                    class="text-sm font-medium px-3 py-1.5 rounded-md transition-colors"
                    style="background:var(--brand-button); color:#fff;">
                <span x-text="(previewUrl || @js((bool) $current)) ? 'Change photo' : 'Upload photo'"></span>
            </button>
            <div class="text-[11px] mt-1.5" style="color:var(--text-muted);">
                Square • face centered • 1200×1200 px
            </div>
        </div>
    </div>

    {{-- Cropper modal (teleported so it overlays everything, stays bound to this instance) --}}
    <template x-teleport="body">
        <div x-show="open" x-cloak
             class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
             style="background:rgba(0,0,0,0.72);"
             @keydown.escape.window="close()">
            <div class="rounded-xl overflow-hidden w-full" style="max-width:420px; background:var(--surface); border:1px solid var(--border);"
                 @click.outside="close()">
                <div class="px-5 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border);">
                    <h3 class="text-sm font-bold" style="color:var(--text-primary);">Position the face</h3>
                    <button type="button" @click="close()" style="color:var(--text-muted);" class="text-lg leading-none">&times;</button>
                </div>

                <div class="p-5">
                    {{-- Crop stage: square frame + canvas + face guide overlay --}}
                    <div class="relative mx-auto select-none touch-none"
                         style="width:360px; height:360px; max-width:100%; aspect-ratio:1/1; border-radius:8px; overflow:hidden; background:#111; cursor:grab;"
                         @mousedown="startDrag($event)" @mousemove.window="moveDrag($event)" @mouseup.window="endDrag()"
                         @touchstart.passive="startDrag($event)" @touchmove="moveDrag($event)" @touchend="endDrag()"
                         @wheel="wheel($event)">
                        <canvas x-ref="canvas" width="360" height="360" style="position:absolute; inset:0; width:100%; height:100%;"></canvas>

                        {{-- Face guide overlay (non-interactive) --}}
                        <svg viewBox="0 0 360 360" style="position:absolute; inset:0; width:100%; height:100%; pointer-events:none;"
                             fill="none" stroke="#fff" stroke-opacity="0.85">
                            <defs>
                                <filter id="apc-sh"><feDropShadow dx="0" dy="0" stdDeviation="1.2" flood-color="#000" flood-opacity="0.55"/></filter>
                            </defs>
                            <g filter="url(#apc-sh)">
                                {{-- circle-safe boundary (portal renders circular) --}}
                                <circle cx="180" cy="180" r="178" stroke-dasharray="2 6" stroke-width="1.5"/>
                                {{-- face oval — sit the face here --}}
                                <ellipse cx="180" cy="168" rx="70" ry="92" stroke-width="2"/>
                                {{-- eye line --}}
                                <line x1="118" y1="150" x2="242" y2="150" stroke-dasharray="4 5" stroke-width="1.5"/>
                                {{-- shoulders hint --}}
                                <path d="M96 360 C112 300 145 276 180 276 C215 276 248 300 264 360" stroke-dasharray="3 6" stroke-width="1.5"/>
                            </g>
                        </svg>
                    </div>

                    {{-- Zoom --}}
                    <div class="flex items-center gap-3 mt-4">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14"/></svg>
                        <input type="range" min="1" max="100" x-ref="zoom" value="1"
                               @input="onZoomSlider($event)" class="flex-1" style="accent-color:var(--brand-button);">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:16px;height:16px;color:var(--text-muted);"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14"/></svg>
                    </div>
                    <p class="text-[11px] mt-2 text-center" style="color:var(--text-muted);">Drag to move • scroll or slide to zoom • keep eyes on the line</p>
                </div>

                <div class="px-5 py-3 flex justify-end gap-2" style="border-top:1px solid var(--border);">
                    <button type="button" @click="close()"
                            class="text-sm font-medium px-3 py-1.5 rounded-md"
                            style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">Cancel</button>
                    <button type="button" @click="apply()"
                            class="text-sm font-medium px-4 py-1.5 rounded-md"
                            style="background:var(--brand-button); color:#fff;">Apply</button>
                </div>
            </div>
        </div>
    </template>
</div>

@once
@push('scripts')
<script>
function agentPhotoCropper(autosubmit) {
    const FRAME = 360;     // on-screen crop box (CSS px)
    const OUT   = 1200;    // exported edge

    return {
        open: false,
        autosubmit: autosubmit === true,
        img: null,
        scale: 1, minScale: 1, maxScale: 1,
        ox: 0, oy: 0,
        dragging: false, lastX: 0, lastY: 0,
        previewUrl: null,

        pick(e) {
            const file = e.target.files && e.target.files[0];
            e.target.value = '';
            if (!file) return;
            const url = URL.createObjectURL(file);
            const im = new Image();
            im.onload = () => {
                URL.revokeObjectURL(url);
                this.img = im;
                this.cover();
                this.open = true;
                this.$nextTick(() => { this.syncSlider(); this.draw(); });
            };
            im.onerror = () => { URL.revokeObjectURL(url); alert('Could not read that image.'); };
            im.src = url;
        },

        cover() {
            const nw = this.img.naturalWidth, nh = this.img.naturalHeight;
            this.minScale = Math.max(FRAME / nw, FRAME / nh);
            this.maxScale = this.minScale * 5;
            this.scale = this.minScale;
            this.ox = (FRAME - nw * this.scale) / 2;
            this.oy = (FRAME - nh * this.scale) / 2;
        },

        clamp() {
            const dw = this.img.naturalWidth * this.scale;
            const dh = this.img.naturalHeight * this.scale;
            this.ox = Math.min(0, Math.max(FRAME - dw, this.ox));
            this.oy = Math.min(0, Math.max(FRAME - dh, this.oy));
        },

        draw() {
            const c = this.$refs.canvas;
            if (!c || !this.img) return;
            const ctx = c.getContext('2d');
            ctx.clearRect(0, 0, FRAME, FRAME);
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(this.img, this.ox, this.oy,
                this.img.naturalWidth * this.scale, this.img.naturalHeight * this.scale);
        },

        pt(e) {
            const t = e.touches ? e.touches[0] : e;
            const r = this.$refs.canvas.getBoundingClientRect();
            const sx = FRAME / r.width, sy = FRAME / r.height; // map CSS px → canvas px
            return { x: (t.clientX - r.left) * sx, y: (t.clientY - r.top) * sy };
        },

        startDrag(e) {
            if (!this.img) return;
            this.dragging = true;
            const p = this.pt(e);
            this.lastX = p.x; this.lastY = p.y;
        },
        moveDrag(e) {
            if (!this.dragging || !this.img) return;
            if (e.cancelable) e.preventDefault();
            const p = this.pt(e);
            this.ox += p.x - this.lastX;
            this.oy += p.y - this.lastY;
            this.lastX = p.x; this.lastY = p.y;
            this.clamp(); this.draw();
        },
        endDrag() { this.dragging = false; },

        zoomTo(ns) {
            const cx = FRAME / 2, cy = FRAME / 2, old = this.scale;
            ns = Math.min(this.maxScale, Math.max(this.minScale, ns));
            this.ox = cx - (cx - this.ox) * (ns / old);
            this.oy = cy - (cy - this.oy) * (ns / old);
            this.scale = ns;
            this.clamp(); this.draw(); this.syncSlider();
        },
        wheel(e) {
            if (!this.img) return;
            if (e.cancelable) e.preventDefault();
            this.zoomTo(this.scale * (e.deltaY < 0 ? 1.08 : 0.92));
        },
        onZoomSlider(e) {
            const t = Number(e.target.value) / 100; // 0..1
            this.zoomTo(this.minScale + (this.maxScale - this.minScale) * t);
        },
        syncSlider() {
            if (!this.$refs.zoom) return;
            const range = this.maxScale - this.minScale;
            this.$refs.zoom.value = range <= 0 ? 0 : Math.round(((this.scale - this.minScale) / range) * 100);
        },

        apply() {
            if (!this.img) return;
            const f = OUT / FRAME;
            const out = document.createElement('canvas');
            out.width = OUT; out.height = OUT;
            const ctx = out.getContext('2d');
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            ctx.drawImage(this.img, this.ox * f, this.oy * f,
                this.img.naturalWidth * this.scale * f, this.img.naturalHeight * this.scale * f);

            // JPEG out = universal toBlob support; the server re-encodes to square WebP.
            out.toBlob((blob) => {
                if (!blob) { alert('Crop failed — please try again.'); return; }
                const file = new File([blob], 'agent-photo.jpg', { type: 'image/jpeg' });
                const dt = new DataTransfer();
                dt.items.add(file);
                this.$refs.input.files = dt.files;

                if (this.previewUrl) URL.revokeObjectURL(this.previewUrl);
                this.previewUrl = URL.createObjectURL(blob);

                this.close();
                if (this.autosubmit && this.$refs.input.form) {
                    this.$refs.input.form.submit();
                }
            }, 'image/jpeg', 0.92);
        },

        close() { this.open = false; this.img = null; this.dragging = false; },
    };
}
</script>
@endpush
@endonce
