<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $template ? 'Edit Template — ' . $template->name : 'New Ad Template' }}</title>
    @include('corex.properties._ad-fonts')
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    {{-- The shared render kernel — frameStyle/contentHtml here are the SAME ones the
         generator and the bulk Ad Manager use, so a design can never look different
         in the builder than it does on the finished ad. Spec: ad-manager.md §12. --}}
    <script src="{{ asset('js/corex-ad-render.js') }}?v=1"></script>
    {{-- Agency brand tokens (UI_DESIGN_SYSTEM.md §1.4) — standalone page; declare
         the brand vars so the header + accents use the agency colour. --}}
    @php
        $_brandAgency = ($property?->agency)
            ?? (auth()->user()?->effectiveAgencyId() ? \App\Models\Agency::find(auth()->user()->effectiveAgencyId()) : null);
    @endphp
    <style id="agency-brand">
        :root {
            --brand-icon:    {{ $_brandAgency->icon_color    ?? '#0ea5e9' }};
            --brand-default: {{ $_brandAgency->default_color ?? '#0b2a4a' }};
            --brand-button:  {{ $_brandAgency->button_color  ?? '#0ea5e9' }};
        }
    </style>
    {{-- Follow the user's theme (UI_DESIGN_SYSTEM.md §7). Apply before paint. --}}
    <script>
        (function(){
            var theme = @json(auth()->user()->theme ?? 'dark');
            if (theme === 'dark') document.documentElement.classList.add('dark');
            try { localStorage.setItem('corex-theme', theme); } catch (e) {}
        })();
    </script>
    {{-- Chrome palette — dark values equal the original look (dark unchanged); light
         values added so the builder is usable in the light theme. The canvas itself
         (the artwork being designed) keeps its own colours. --}}
    <style>
        :root {
            --chrome-bg:#eef1f7; --chrome-surface:#ffffff; --chrome-surface-2:#f2f4f9; --chrome-input:#ffffff;
            --chrome-border:rgba(0,0,0,0.10); --chrome-border-2:rgba(0,0,0,0.06);
            --chrome-text:#111827; --chrome-text-soft:rgba(17,24,39,0.62); --chrome-text-mute:rgba(17,24,39,0.42);
            --chrome-hover:rgba(0,0,0,0.05); --workspace:#dde3ec;
        }
        html.dark {
            --chrome-bg:#060f1c; --chrome-surface:#07111e; --chrome-surface-2:rgba(255,255,255,0.06); --chrome-input:#0b1726;
            --chrome-border:rgba(255,255,255,0.10); --chrome-border-2:rgba(255,255,255,0.06);
            --chrome-text:#f1f5f9; --chrome-text-soft:rgba(255,255,255,0.6); --chrome-text-mute:rgba(255,255,255,0.4);
            --chrome-hover:rgba(255,255,255,0.06); --workspace:#040c15;
        }
    </style>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; }
        body { font-family: 'Figtree', sans-serif; background: var(--chrome-bg); color: var(--chrome-text); display: flex; flex-direction: column; }
        [x-cloak] { display: none !important; }

        /* ─── TOOLBAR (document actions) ─── */
        #toolbar {
            flex-shrink: 0; min-height: 52px;
            background: var(--chrome-surface); border-bottom: 1px solid var(--chrome-border);
            display: flex; align-items: center; gap: 8px; padding: 0 14px; flex-wrap: wrap;
        }
        .tb-btn {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 5px 12px; border-radius: 8px; font-size: 12px; font-weight: 600;
            cursor: pointer; border: 1.5px solid var(--chrome-border);
            background: var(--chrome-surface-2); color: var(--chrome-text-soft);
            font-family: inherit; transition: all 0.12s; text-decoration: none;
        }
        .tb-btn:hover { border-color: var(--brand-button,#00b4d8); color: var(--chrome-text); }
        .tb-btn.primary { background: var(--brand-button,#00b4d8); border-color: var(--brand-button,#00b4d8); color: #fff; }
        .tb-btn.primary:hover { filter: brightness(0.92); }
        .tb-btn.danger { background: rgba(230,57,70,0.15); border-color: rgba(230,57,70,0.35); color: #e63946; }
        .tb-btn.danger:hover { background: #e63946; border-color: #e63946; color: #fff; }
        .tb-btn:disabled { opacity: 0.35; cursor: not-allowed; }
        .tb-btn:disabled:hover { border-color: var(--chrome-border); color: var(--chrome-text-soft); }
        #tpl-name-input {
            background: var(--chrome-input); border: 1.5px solid var(--chrome-border);
            color: var(--chrome-text); border-radius: 8px; padding: 5px 12px; font-size: 13px; font-weight: 600;
            font-family: inherit; width: 200px; outline: none;
        }
        #tpl-name-input:focus { border-color: var(--brand-button,#00b4d8); }
        .ctx-chip {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 7px;
            font-size: 11px; font-weight: 600; background: color-mix(in srgb, var(--brand-button,#00b4d8) 14%, transparent); color: var(--brand-button,#00b4d8);
            border: 1px solid color-mix(in srgb, var(--brand-button,#00b4d8) 28%, transparent);
        }
        .dirty-dot { width:7px; height:7px; border-radius:50%; background:#f59e0b; flex-shrink:0; }

        /* ─── ACTION BAR (edit actions on the selection / canvas) ─── */
        #actionbar {
            flex-shrink: 0; min-height: 40px;
            background: var(--chrome-surface); border-bottom: 1px solid var(--chrome-border);
            display: flex; align-items: center; gap: 3px; padding: 4px 14px; flex-wrap: wrap;
        }
        .ab-btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 5px;
            height: 28px; min-width: 28px; padding: 0 6px;
            border: 1px solid transparent; background: transparent; color: var(--chrome-text-soft);
            border-radius: 6px; cursor: pointer; font-family: inherit; font-size: 11px; font-weight: 600;
            transition: all 0.1s;
        }
        .ab-btn svg { width: 15px; height: 15px; }
        .ab-btn:hover:not(:disabled) { background: var(--chrome-hover); color: var(--chrome-text); }
        .ab-btn:disabled { opacity: 0.3; cursor: not-allowed; }
        .ab-btn.on { background: color-mix(in srgb, var(--brand-button,#00b4d8) 16%, transparent); color: var(--brand-button,#00b4d8); border-color: color-mix(in srgb, var(--brand-button,#00b4d8) 34%, transparent); }
        .ab-sep { width: 1px; height: 20px; background: var(--chrome-border); margin: 0 5px; flex-shrink: 0; }
        .ab-num {
            width: 46px; height: 24px; background: var(--chrome-input); border: 1px solid var(--chrome-border);
            color: var(--chrome-text); border-radius: 5px; font-size: 11px; font-weight: 600;
            font-family: inherit; padding: 0 5px; outline: none; text-align: center;
        }
        .ab-zoom { font-size: 11px; font-weight: 700; color: var(--chrome-text-soft); min-width: 42px; text-align: center; font-variant-numeric: tabular-nums; }

        /* ─── 3-COLUMN LAYOUT ─── */
        #workspace { flex: 1; display: flex; overflow: hidden; }

        /* ─── LEFT SIDEBAR: field catalogue ─── */
        #sidebar {
            width: 208px; flex-shrink: 0;
            background: var(--chrome-surface); border-right: 1px solid var(--chrome-border);
            overflow-y: auto; padding: 12px 8px;
        }
        .sb-group { margin-bottom: 14px; }
        .sb-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.14em; color: var(--chrome-text-mute); padding: 0 6px; margin-bottom: 6px; }
        .sb-field {
            display: flex; align-items: center; gap: 8px;
            padding: 6px 10px; border-radius: 8px; cursor: grab;
            font-size: 12px; font-weight: 500; color: var(--chrome-text-soft);
            border: 1px solid transparent; margin-bottom: 3px;
            transition: all 0.12s; user-select: none;
        }
        .sb-field:hover { background: var(--chrome-hover); border-color: var(--chrome-border); color: var(--chrome-text); }
        .sb-field:active { cursor: grabbing; }
        .sb-icon { width: 22px; height: 22px; border-radius: 5px; display:flex; align-items:center; justify-content:center; font-size: 11px; flex-shrink: 0; }

        /* ─── CANVAS AREA ─── */
        #canvas-area {
            flex: 1; overflow: auto; display: flex; align-items: center; justify-content: center;
            background: var(--workspace); padding: 32px;
        }
        #canvas-wrapper { position: relative; flex-shrink: 0; box-shadow: 0 24px 80px rgba(0,0,0,0.8); }
        #canvas { width: 1200px; height: 628px; background: #071325; position: relative; overflow: hidden; cursor: default; }
        #canvas-scale { transform-origin: top left; }

        .canvas-el { cursor: move; user-select: none; outline: none; }
        .canvas-el.selected { outline: 2px solid var(--brand-button,#00b4d8); outline-offset: 0; }
        .canvas-el.locked { cursor: default; }
        /* During capture (and in preview mode) every trace of the editor is gone —
           what html2canvas sees is exactly the artwork. */
        #canvas.capturing .canvas-el.selected { outline: none; }

        /* Selection overlay — handles live OUTSIDE the element, because the element
           box is overflow:hidden and would clip anything sitting on its edge. */
        #sel-overlay { position: absolute; pointer-events: none; z-index: 99998; }
        #sel-overlay.single { outline: 2px solid var(--brand-button,#00b4d8); }
        #sel-overlay.multi  { outline: 1.5px dashed var(--brand-button,#00b4d8); }
        .handle {
            position: absolute; width: 10px; height: 10px;
            background: #fff; border: 2px solid var(--brand-button,#00b4d8);
            border-radius: 2px; pointer-events: auto; z-index: 2;
        }
        .rot-handle {
            position: absolute; width: 12px; height: 12px;
            background: var(--brand-button,#00b4d8); border: 2px solid #fff;
            border-radius: 50%; pointer-events: auto; cursor: grab; z-index: 2;
        }
        .rot-stem { position: absolute; width: 1px; background: var(--brand-button,#00b4d8); pointer-events: none; }

        .guide { position: absolute; background: #ff2d78; pointer-events: none; z-index: 99995; }
        #marquee { position: absolute; border: 1px solid var(--brand-button,#00b4d8); background: color-mix(in srgb, var(--brand-button,#00b4d8) 12%, transparent); pointer-events: none; z-index: 99996; }
        #grid-overlay { position: absolute; inset: 0; pointer-events: none; z-index: 99990; }

        .el-toolbar { position:absolute; display:flex; gap:2px; background:#0b1220; border:1px solid rgba(255,255,255,0.14); border-radius:8px; padding:3px; box-shadow:0 6px 20px rgba(0,0,0,0.5); pointer-events:auto; z-index:3; }
        .el-toolbar button { display:flex; align-items:center; justify-content:center; width:28px; height:28px; border:none; background:transparent; color:rgba(255,255,255,0.75); border-radius:5px; cursor:pointer; }
        .el-toolbar button svg { width:15px; height:15px; }
        .el-toolbar button:hover { background:rgba(255,255,255,0.1); color:#fff; }
        .el-toolbar button.danger:hover { background:#e63946; color:#fff; }

        /* ─── RIGHT PANEL ─── */
        #prop-panel {
            width: 258px; flex-shrink: 0;
            background: var(--chrome-surface); border-left: 1px solid var(--chrome-border);
            overflow-y: auto; display: flex; flex-direction: column;
        }
        .pp-tabs { display: flex; border-bottom: 1px solid var(--chrome-border); flex-shrink: 0; position: sticky; top: 0; background: var(--chrome-surface); z-index: 5; }
        .pp-tab {
            flex: 1; padding: 10px 0; text-align: center; font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.1em; color: var(--chrome-text-mute);
            background: none; border: none; border-bottom: 2px solid transparent; cursor: pointer; font-family: inherit;
        }
        .pp-tab.on { color: var(--brand-button,#00b4d8); border-bottom-color: var(--brand-button,#00b4d8); }
        .pp-body { padding: 14px 12px; }
        #prop-panel h3 { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: var(--chrome-text-mute); margin-bottom: 12px; }
        .pp-row { margin-bottom: 10px; }
        .pp-row label { display: block; font-size: 11px; color: var(--chrome-text-soft); margin-bottom: 4px; }
        .pp-row input[type=text], .pp-row input[type=number], .pp-row select, .pp-row textarea {
            width: 100%; background: var(--chrome-input); border: 1.5px solid var(--chrome-border);
            color: var(--chrome-text); border-radius: 7px; padding: 6px 9px; font-size: 12px; font-family: inherit; outline: none;
        }
        .pp-row input:focus, .pp-row select:focus, .pp-row textarea:focus { border-color: var(--brand-button,#00b4d8); }
        .pp-row input[type=color] { width: 100%; height: 30px; border: 1.5px solid var(--chrome-border); border-radius: 7px; cursor: pointer; padding: 2px; background: var(--chrome-input); }
        .pp-row input[type=range] { width: 100%; accent-color: var(--brand-button,#00b4d8); }
        .pp-row select option { background: var(--chrome-surface); color: var(--chrome-text); }
        .pp-sep { border: none; border-top: 1px solid var(--chrome-border); margin: 14px 0; }
        .pp-row .pp-inline { display:flex; gap:6px; }
        .pp-row .pp-inline input { flex:1; }
        .pp-hint { font-size:10px; color:var(--chrome-text-mute); line-height:1.5; margin:-4px 0 8px; }
        #no-selection { display:flex; flex-direction:column; align-items:center; justify-content:center; height:160px; gap:10px; opacity:0.3; font-size:12px; text-align:center; }

        /* ─── LAYERS ─── */
        .layer-row {
            display: flex; align-items: center; gap: 6px; padding: 6px 7px; border-radius: 7px;
            cursor: pointer; border: 1px solid transparent; margin-bottom: 2px; font-size: 12px;
            color: var(--chrome-text-soft); user-select: none;
        }
        .layer-row:hover { background: var(--chrome-hover); }
        .layer-row.on { background: color-mix(in srgb, var(--brand-button,#00b4d8) 14%, transparent); border-color: color-mix(in srgb, var(--brand-button,#00b4d8) 32%, transparent); color: var(--chrome-text); }
        .layer-row.is-hidden { opacity: 0.42; }
        .layer-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-weight:500; }
        .layer-ico { width:22px; height:22px; display:flex; align-items:center; justify-content:center; border-radius:5px; border:none; background:transparent; color:inherit; cursor:pointer; flex-shrink:0; opacity:0.65; }
        .layer-ico:hover { background: var(--chrome-hover); opacity:1; }
        .layer-ico svg { width:13px; height:13px; }
        .layer-swatch { width:16px; height:16px; border-radius:4px; flex-shrink:0; }
        .layer-drop { height:3px; border-radius:2px; background:var(--brand-button,#00b4d8); margin:-1px 0; opacity:0; }
        .layer-drop.on { opacity:1; }

        /* ─── Toast + shortcuts modal ─── */
        #toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:var(--brand-button,#00b4d8); color:#fff; font-size:13px; font-weight:700; padding:10px 22px; border-radius:10px; opacity:0; pointer-events:none; transition:opacity 0.3s; z-index:99999; }
        #toast.show { opacity:1; }
        #sc-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; z-index:100000; }
        #sc-modal { background:var(--chrome-surface); border:1px solid var(--chrome-border); border-radius:14px; padding:22px 24px; width:520px; max-width:92vw; max-height:82vh; overflow-y:auto; box-shadow:0 30px 80px rgba(0,0,0,0.5); }
        #sc-modal h2 { font-size:15px; font-weight:800; margin-bottom:14px; color:var(--chrome-text); }
        .sc-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px 24px; }
        .sc-sec-t { font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:0.14em; color:var(--chrome-text-mute); margin-bottom:7px; }
        .sc-item { display:flex; align-items:center; justify-content:space-between; gap:10px; font-size:12px; color:var(--chrome-text-soft); padding:3px 0; }
        kbd { background:var(--chrome-surface-2); border:1px solid var(--chrome-border); border-bottom-width:2px; border-radius:5px; padding:1px 6px; font-family:'JetBrains Mono',ui-monospace,monospace; font-size:10px; font-weight:600; color:var(--chrome-text); white-space:nowrap; }
    </style>
</head>
<body x-data="builder()" x-init="init()"
      @mouseup.window="pointerUp($event)"
      @mousemove.window="pointerMove($event)"
      @keydown.window="onKeyDown($event)"
      @keyup.window="onKeyUp($event)"
      @resize.window="onResize()"
      @beforeunload.window="onBeforeUnload($event)">

{{-- ═══ BRANDED HEADER (UI_DESIGN_SYSTEM.md §2.4 Pattern A) — full width ═══ --}}
<header style="flex-shrink:0;background:var(--brand-default,#0b2a4a);padding:11px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
    <div style="display:flex;align-items:center;gap:12px;min-width:0;">
        <a href="{{ $property ? route('corex.properties.ad', $property) : route('corex.properties.index') }}" title="Back"
           style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,0.12);color:#fff;text-decoration:none;flex-shrink:0;transition:background 0.15s;"
           onmouseover="this.style.background='rgba(255,255,255,0.22)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
            <svg style="width:15px;height:15px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        </a>
        <div style="min-width:0;">
            <h1 style="font-size:18px;font-weight:700;color:#fff;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $template ? 'Edit Ad Template' : 'Ad Builder' }}</h1>
            <p style="font-size:12px;color:rgba(255,255,255,0.6);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $property ? $property->title : 'Design a reusable marketing template' }}</p>
        </div>
    </div>
    @if($property)
    <a href="{{ route('corex.properties.ad', $property) }}"
       style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:12px;font-weight:600;color:#fff;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.18);text-decoration:none;flex-shrink:0;transition:background 0.15s;"
       onmouseover="this.style.background='rgba(255,255,255,0.22)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
        Property ad page
    </a>
    @endif
</header>

{{-- ═══ TOOLBAR — document-level actions ═══ --}}
<div id="toolbar">
    <a href="{{ $property ? route('corex.properties.ad', $property) : route('corex.properties.index') }}" class="tb-btn">
        <svg style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back
    </a>

    <div style="width:1px;height:20px;background:var(--chrome-border);"></div>

    <input id="tpl-name-input" type="text" x-model="name" placeholder="Template name…">
    <span class="dirty-dot" x-show="dirty" title="Unsaved changes"></span>

    {{-- Property context chip --}}
    <template x-if="propertyData">
        <span class="ctx-chip" title="Live preview uses this property's real data">
            <svg style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            <span x-text="propertyData.title || 'This property'"></span>
        </span>
    </template>

    <div style="margin-left:auto;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">

        {{-- Canvas size (theme-aware; option colours set so the native dropdown is readable) --}}
        <select x-model="canvasPreset" @change="applyPreset()" class="tb-btn" style="padding:5px 8px;font-size:11px;background:var(--chrome-input);color:var(--chrome-text);">
            <template x-for="(p, key) in CoreXAd.CANVAS_PRESETS" :key="key">
                <option style="background:var(--chrome-surface);color:var(--chrome-text);" :value="key" x-text="p.label"></option>
            </template>
            <option style="background:var(--chrome-surface);color:var(--chrome-text);" value="custom">Custom size…</option>
        </select>
        {{-- Custom W×H --}}
        <template x-if="canvasPreset==='custom'">
            <span style="display:inline-flex;align-items:center;gap:4px;background:var(--chrome-surface-2);border:1.5px solid var(--chrome-border);border-radius:8px;padding:3px 7px;">
                <input type="number" min="200" max="4000" step="10" :value="canvasW" @change="setCanvasSize(+$event.target.value, canvasH)" title="Width (px)"
                       style="width:58px;background:var(--chrome-input);color:var(--chrome-text);border:1px solid var(--chrome-border);border-radius:5px;font-size:11px;font-weight:600;font-family:inherit;padding:4px 5px;outline:none;">
                <span style="color:var(--chrome-text-mute);font-size:11px;">×</span>
                <input type="number" min="200" max="4000" step="10" :value="canvasH" @change="setCanvasSize(canvasW, +$event.target.value)" title="Height (px)"
                       style="width:58px;background:var(--chrome-input);color:var(--chrome-text);border:1px solid var(--chrome-border);border-radius:5px;font-size:11px;font-weight:600;font-family:inherit;padding:4px 5px;outline:none;">
                <span style="color:var(--chrome-text-mute);font-size:10px;">px</span>
            </span>
        </template>

        {{-- Clear all (undoable — no confirm needed, Ctrl+Z brings it back) --}}
        <button class="tb-btn danger" @click="clearAll()" :disabled="!elements.length" title="Clear the canvas (undoable)">
            <svg style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            Clear
        </button>

        {{-- Save --}}
        <button class="tb-btn primary" @click="save()" :disabled="saving" title="Save (Ctrl+S)">
            <svg style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            <span x-text="saving ? 'Saving…' : (savedId ? 'Save' : 'Save Template')"></span>
        </button>

        {{-- Use on this property (only if saved + property context) --}}
        <template x-if="savedId && propertyId">
            <a :href="useOnPropertyUrl" class="tb-btn primary" style="background:#0b8f53;border-color:#0b8f53;">
                Use on this property →
            </a>
        </template>
        {{-- Generic use (saved, no property context) --}}
        <template x-if="savedId && !propertyId">
            <a :href="useOnPropertyUrl" class="tb-btn" style="color:var(--chrome-text-soft);">
                Use on Property →
            </a>
        </template>

        {{-- Export for Marketing (only when opened from marketing hub) --}}
        <template x-if="savedId && returnMarketingPropertyId">
            <button class="tb-btn primary" @click="exportForMarketing()" :disabled="exporting">
                <svg style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282"/></svg>
                <span x-text="exporting ? 'Exporting…' : 'Use for Marketing'"></span>
            </button>
        </template>
    </div>
</div>

{{-- ═══ ACTION BAR — undo/redo, align, order, snap, zoom ═══ --}}
<div id="actionbar">
    {{-- History --}}
    <button class="ab-btn" @click="undo()" :disabled="!past.length" title="Undo (Ctrl+Z)">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"/><path d="M21 17a9 9 0 0 0-9-9 9 9 0 0 0-6 2.3L3 13"/></svg>
    </button>
    <button class="ab-btn" @click="redo()" :disabled="!future.length" title="Redo (Ctrl+Shift+Z)">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 7v6h-6"/><path d="M3 17a9 9 0 0 1 9-9 9 9 0 0 1 6 2.3L21 13"/></svg>
    </button>

    <div class="ab-sep"></div>

    {{-- Align — to the canvas when one element is selected, to the selection bounds when several are --}}
    <button class="ab-btn" @click="align('left')" :disabled="!selCount" :title="alignHint('Align left')">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 3v18"/><rect x="7" y="6" width="12" height="4" rx="1"/><rect x="7" y="14" width="7" height="4" rx="1"/></svg>
    </button>
    <button class="ab-btn" @click="align('hcenter')" :disabled="!selCount" :title="alignHint('Align centre')">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 3v18"/><rect x="5" y="6" width="14" height="4" rx="1"/><rect x="8" y="14" width="8" height="4" rx="1"/></svg>
    </button>
    <button class="ab-btn" @click="align('right')" :disabled="!selCount" :title="alignHint('Align right')">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 3v18"/><rect x="5" y="6" width="12" height="4" rx="1"/><rect x="10" y="14" width="7" height="4" rx="1"/></svg>
    </button>
    <button class="ab-btn" @click="align('top')" :disabled="!selCount" :title="alignHint('Align top')">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 4h18"/><rect x="6" y="7" width="4" height="12" rx="1"/><rect x="14" y="7" width="4" height="7" rx="1"/></svg>
    </button>
    <button class="ab-btn" @click="align('vmiddle')" :disabled="!selCount" :title="alignHint('Align middle')">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 12h18"/><rect x="6" y="5" width="4" height="14" rx="1"/><rect x="14" y="8" width="4" height="8" rx="1"/></svg>
    </button>
    <button class="ab-btn" @click="align('bottom')" :disabled="!selCount" :title="alignHint('Align bottom')">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 20h18"/><rect x="6" y="5" width="4" height="12" rx="1"/><rect x="14" y="10" width="4" height="7" rx="1"/></svg>
    </button>

    <button class="ab-btn" @click="distribute('h')" :disabled="selCount < 3" title="Distribute horizontally (needs 3+)">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4v16M20 4v16"/><rect x="10" y="8" width="4" height="8" rx="1"/></svg>
    </button>
    <button class="ab-btn" @click="distribute('v')" :disabled="selCount < 3" title="Distribute vertically (needs 3+)">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 4h16M4 20h16"/><rect x="8" y="10" width="8" height="4" rx="1"/></svg>
    </button>
    <button class="ab-btn" @click="fillCanvas()" :disabled="!selCount" title="Stretch to fill the canvas">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6v6H9z"/><path d="M9 3v3M15 3v3M9 18v3M15 18v3M3 9h3M3 15h3M18 9h3M18 15h3"/></svg>
    </button>

    <div class="ab-sep"></div>

    {{-- Stacking order --}}
    <button class="ab-btn" @click="zOrder('front')" :disabled="!selCount" title="Bring to front (Ctrl+Shift+])">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="8" y="3" width="13" height="13" rx="2"/><path d="M16 16v3a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-9a2 2 0 0 1 2-2h3"/></svg>
    </button>
    <button class="ab-btn" @click="zOrder('up')" :disabled="!selCount" title="Bring forward (Ctrl+])">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>
    </button>
    <button class="ab-btn" @click="zOrder('down')" :disabled="!selCount" title="Send backward (Ctrl+[)">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14"/><path d="m19 12-7 7-7-7"/></svg>
    </button>
    <button class="ab-btn" @click="zOrder('back')" :disabled="!selCount" title="Send to back (Ctrl+Shift+[)">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linejoin="round"><rect x="3" y="8" width="13" height="13" rx="2"/><path d="M8 8V5a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-3"/></svg>
    </button>

    <div class="ab-sep"></div>

    {{-- Snapping --}}
    <button class="ab-btn" :class="{ on: snapObjects }" @click="snapObjects = !snapObjects" title="Snap to other elements & canvas centre (hold Alt to suspend)">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 2v20M2 12h20"/><circle cx="12" cy="12" r="3"/></svg>
        Guides
    </button>
    <button class="ab-btn" :class="{ on: snapGrid }" @click="snapGrid = !snapGrid" title="Snap to grid (hold Alt to suspend)">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="1"/><path d="M9 3v18M15 3v18M3 9h18M3 15h18"/></svg>
        Snap
    </button>
    <button class="ab-btn" :class="{ on: showGrid }" @click="showGrid = !showGrid" title="Show the grid">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 9h18M3 15h18M9 3v18M15 3v18"/></svg>
    </button>
    <input class="ab-num" type="number" min="2" max="200" step="1" :value="gridSize"
           @change="gridSize = Math.min(200, Math.max(2, +$event.target.value || 10))" title="Grid size (px)">

    <div class="ab-sep"></div>

    {{-- Zoom --}}
    <button class="ab-btn" @click="zoomBy(-1)" title="Zoom out (Ctrl+−)">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5M8 11h6"/></svg>
    </button>
    <span class="ab-zoom" x-text="Math.round(zoom * 100) + '%'"></span>
    <button class="ab-btn" @click="zoomBy(1)" title="Zoom in (Ctrl++)">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5M11 8v6M8 11h6"/></svg>
    </button>
    <button class="ab-btn" :class="{ on: zoomMode === 'fit' }" @click="fitZoom()" title="Fit to window (Ctrl+0)">Fit</button>
    <button class="ab-btn" @click="setZoom(1)" title="Actual size (Ctrl+1)">100%</button>

    <div class="ab-sep"></div>

    <button class="ab-btn" :class="{ on: previewMode }" @click="previewMode = !previewMode" title="Preview — hide every editor guide and see the artwork alone">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
        Preview
    </button>

    <div style="margin-left:auto;display:flex;align-items:center;gap:3px;">
        <span style="font-size:11px;color:var(--chrome-text-mute);" x-show="selCount > 1" x-text="selCount + ' selected'"></span>
        <button class="ab-btn" @click="showShortcuts = true" title="Keyboard shortcuts (?)">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M8 14h8"/></svg>
        </button>
    </div>
</div>

{{-- ═══ WORKSPACE ═══ --}}
<div id="workspace">

    {{-- ── LEFT: FIELD CATALOGUE ── --}}
    <div id="sidebar">
        <template x-for="grp in CoreXAd.FIELD_GROUPS" :key="grp.key">
            <div class="sb-group">
                <div class="sb-label" x-text="grp.label"></div>
                <template x-for="f in CoreXAd.FIELDS.filter(x => x.group === grp.key)" :key="f.type">
                    <div class="sb-field" draggable="true" @dragstart="sidebarDragStart($event, f)" @click="addField(f)">
                        <span class="sb-icon" :style="'background:' + f.iconBg" x-html="groupIcon(grp.key)"></span>
                        <span x-text="f.label"></span>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- ── CENTRE: CANVAS AREA ── --}}
    <div id="canvas-area" @dragover.prevent @drop="canvasDrop($event)" @wheel="onWheel($event)">
        {{-- The wrapper occupies the SCALED footprint (transform:scale doesn't shrink the
             layout box) so the canvas stays centred at any zoom instead of pinning to the
             top-left with a giant empty box around it. --}}
        <div id="canvas-wrapper" :style="'width:' + (canvasW * zoom) + 'px;height:' + (canvasH * zoom) + 'px;'">
            <div id="canvas-scale" :style="'transform:scale(' + zoom + ');width:' + canvasW + 'px;height:' + canvasH + 'px;'">

                <div id="canvas" :class="{ capturing: capturing || previewMode }"
                     :style="'width:' + canvasW + 'px;height:' + canvasH + 'px;background:' + CoreXAd.canvasBackground(layoutJson) + ';'"
                     @mousedown.self="canvasMouseDown($event)">

                    {{-- Elements. frameStyle() + contentHtml() come from the shared kernel,
                         so what you see here is literally what the generator renders. --}}
                    <template x-for="el in elements" :key="el.id">
                        <div class="canvas-el"
                             :class="{ selected: !previewMode && !capturing && isSelected(el), locked: el.locked }"
                             :style="CoreXAd.frameStyle(el)"
                             @mousedown.stop.prevent="elMouseDown($event, el)">
                            <div style="width:100%;height:100%;pointer-events:none;" x-html="content(el)"></div>
                        </div>
                    </template>

                    {{-- Grid. Visibility is baked INTO the style string, never x-show:
                         Alpine's :style with a string rewrites the style attribute and
                         would wipe out the display:none x-show had just set. --}}
                    <div id="grid-overlay" data-html2canvas-ignore :style="gridStyle()"></div>

                    {{-- Smart alignment guides (shown while dragging/resizing) --}}
                    <template x-for="(g, i) in guides" :key="i">
                        <div class="guide" data-html2canvas-ignore :style="guideStyle(g)"></div>
                    </template>

                    {{-- Marquee select --}}
                    <div id="marquee" data-html2canvas-ignore :style="marqueeStyle()"></div>

                    {{-- Selection overlay: handles + the element toolbar. Lives OUTSIDE the
                         element box (which is overflow:hidden and would clip edge handles). --}}
                    <template x-if="selCount > 0 && !previewMode && !capturing">
                        <div id="sel-overlay" data-html2canvas-ignore
                             :class="selCount === 1 ? 'single' : 'multi'" :style="selOverlayStyle()">

                            {{-- Action toolbar, counter-scaled so it stays a constant on-screen size --}}
                            <div class="el-toolbar" :style="elToolbarStyle()">
                                <button title="Duplicate (Ctrl+D)" @mousedown.stop @click.stop="duplicateSelected()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                </button>
                                <button title="Rotate 45°" @mousedown.stop @click.stop="rotate45()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/></svg>
                                </button>
                                <button :title="allLocked ? 'Unlock' : 'Lock'" @mousedown.stop @click.stop="toggleLock()">
                                    <template x-if="allLocked">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                                    </template>
                                    <template x-if="!allLocked">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/></svg>
                                    </template>
                                </button>
                                <button title="Delete (Del)" class="danger" @mousedown.stop @click.stop="deleteSelected()">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6m5 0V4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v2"/></svg>
                                </button>
                            </div>

                            {{-- 8 resize handles + a free rotate handle (single, unlocked selection) --}}
                            <template x-if="selCount === 1 && sel && !sel.locked">
                                <div>
                                    <template x-for="h in HANDLES" :key="h">
                                        <div class="handle" :style="handleStyle(h)"
                                             @mousedown.stop.prevent="resizeStart($event, h)"></div>
                                    </template>
                                    <div class="rot-stem" :style="rotStemStyle()"></div>
                                    <div class="rot-handle" :style="rotHandleStyle()"
                                         @mousedown.stop.prevent="rotateStart($event)"></div>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Empty state --}}
                    <template x-if="elements.length === 0">
                        <div data-html2canvas-ignore style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;opacity:0.18;pointer-events:none;">
                            <svg style="width:48px;height:48px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                            <span style="font-size:14px;font-weight:600;">Drag fields from the left panel</span>
                        </div>
                    </template>

                </div>
            </div>
        </div>
    </div>

    {{-- ── RIGHT: PROPERTIES / LAYERS ── --}}
    <div id="prop-panel">
        <div class="pp-tabs">
            <button class="pp-tab" :class="{ on: tab === 'design' }" @click="tab = 'design'">Design</button>
            <button class="pp-tab" :class="{ on: tab === 'layers' }" @click="tab = 'layers'">
                Layers<span x-show="elements.length" x-text="' (' + elements.length + ')'"></span>
            </button>
        </div>

        {{-- ══ DESIGN TAB ══ --}}
        <div class="pp-body" x-show="tab === 'design'">

            {{-- No selection → canvas settings --}}
            <template x-if="!sel">
                <div>
                    <h3>Canvas</h3>
                    <div class="pp-row">
                        <label>Background style</label>
                        <select :value="canvasBgMode" @input="setCanvas('canvasBgMode', $event.target.value)">
                            <option value="solid">Solid colour</option>
                            <option value="gradient">Gradient</option>
                        </select>
                    </div>
                    <template x-if="canvasBgMode === 'solid'">
                        <div class="pp-row">
                            <label>Background colour</label>
                            <input type="color" :value="canvasBg" @input="setCanvas('canvasBg', $event.target.value)">
                        </div>
                    </template>
                    <template x-if="canvasBgMode === 'gradient'">
                        <div>
                            <div class="pp-row">
                                <label>From</label>
                                <input type="color" :value="canvasBgFrom" @input="setCanvas('canvasBgFrom', $event.target.value)">
                            </div>
                            <div class="pp-row">
                                <label>To</label>
                                <input type="color" :value="canvasBgTo" @input="setCanvas('canvasBgTo', $event.target.value)">
                            </div>
                            <div class="pp-row">
                                <label>Angle (deg)</label>
                                <input type="number" :value="canvasBgAngle" @input="setCanvas('canvasBgAngle', +$event.target.value)">
                            </div>
                        </div>
                    </template>
                    <hr class="pp-sep">
                    <div id="no-selection">
                        <svg style="width:24px;height:24px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 15l-6-6m0 0l6-6m-6 6h12"/></svg>
                        <span>Select an element,<br>or drag a field in</span>
                    </div>
                </div>
            </template>

            {{-- Selection → element properties --}}
            <template x-if="sel">
                <div>
                    <h3>
                        <span x-show="selCount === 1" x-text="sel.label + ' Properties'"></span>
                        <span x-show="selCount > 1" x-text="selCount + ' elements — shared properties'"></span>
                    </h3>
                    <div class="pp-hint" x-show="selCount > 1">Every change below applies to all selected elements at once.</div>

                    {{-- Position & Size --}}
                    <div class="pp-row">
                        <label>Position (X, Y)</label>
                        <div class="pp-inline">
                            <input type="number" :value="Math.round(sel.x)" @input="mutate('x', +$event.target.value)" placeholder="X">
                            <input type="number" :value="Math.round(sel.y)" @input="mutate('y', +$event.target.value)" placeholder="Y">
                        </div>
                    </div>
                    <div class="pp-row">
                        <label>Size (W, H)</label>
                        <div class="pp-inline">
                            <input type="number" min="8" :value="Math.round(sel.w)" @input="mutate('w', Math.max(8, +$event.target.value))" placeholder="W">
                            <input type="number" min="8" :value="Math.round(sel.h)" @input="mutate('h', Math.max(8, +$event.target.value))" placeholder="H">
                        </div>
                    </div>
                    <div class="pp-row">
                        <label>Rotation (deg)</label>
                        <input type="number" :value="sel.rotation || 0" @input="mutate('rotation', +$event.target.value)">
                    </div>
                    <div class="pp-row">
                        <label>Opacity — <span x-text="Math.round((sel.elOpacity ?? 1) * 100) + '%'"></span></label>
                        <input type="range" min="0" max="1" step="0.01" :value="sel.elOpacity ?? 1" @input="mutate('elOpacity', +$event.target.value)">
                    </div>

                    <hr class="pp-sep">

                    {{-- Image fields --}}
                    <template x-if="CoreXAd.isImageField(sel.field)">
                        <div>
                            <div class="pp-row">
                                <label>Object Fit</label>
                                <select :value="sel.objectFit" @input="mutate('objectFit', $event.target.value)">
                                    <option value="cover">Cover</option>
                                    <option value="contain">Contain</option>
                                    <option value="fill">Fill</option>
                                </select>
                            </div>
                            <div class="pp-row">
                                <label>Border Radius (px)</label>
                                <input type="number" :value="sel.borderRadius" @input="mutate('borderRadius', +$event.target.value)" min="0">
                            </div>
                        </div>
                    </template>

                    {{-- Custom image / video — upload + fit --}}
                    <template x-if="sel.field === 'custom_image' || sel.field === 'custom_video'">
                        <div>
                            <div class="pp-row">
                                <label x-text="sel.field === 'custom_video' ? 'Video file' : 'Image file'"></label>
                                <input type="file" :accept="sel.field === 'custom_video' ? 'video/*' : 'image/*'" @change="uploadMedia($event)"
                                       style="font-size:11px;color:var(--chrome-text-soft);">
                            </div>
                            <div class="pp-hint" style="color:#19c37d;" x-show="sel.src">✓ Uploaded — drag to resize on the canvas.</div>
                            <div class="pp-hint" x-show="sel.field === 'custom_video'">Video plays in the live preview. A downloaded PNG captures a single still frame.</div>
                            <div class="pp-row">
                                <label>Object Fit</label>
                                <select :value="sel.objectFit" @input="mutate('objectFit', $event.target.value)">
                                    <option value="cover">Cover</option>
                                    <option value="contain">Contain</option>
                                    <option value="fill">Fill</option>
                                </select>
                            </div>
                            <div class="pp-row">
                                <label>Border Radius (px)</label>
                                <input type="number" :value="sel.borderRadius" @input="mutate('borderRadius', +$event.target.value)" min="0">
                            </div>
                        </div>
                    </template>

                    {{-- Features — pick which amenities to display --}}
                    <template x-if="sel.field === 'features'">
                        <div>
                            <div class="pp-row" style="align-items:flex-start;">
                                <label>Show features</label>
                                <template x-if="featuresList.length === 0">
                                    <div class="pp-hint">This property has no listed features. The element falls back to the summary (e.g. beds · baths).</div>
                                </template>
                                <template x-if="featuresList.length > 0">
                                    <div style="display:flex;flex-direction:column;gap:5px;max-height:200px;overflow-y:auto;width:100%;">
                                        <template x-for="f in featuresList" :key="f">
                                            <label style="display:flex;align-items:center;gap:7px;font-size:12px;color:var(--chrome-text);cursor:pointer;font-weight:500;">
                                                <input type="checkbox" :checked="isFeatureOn(sel, f)" @change="toggleFeature(f)" style="accent-color:var(--brand-button,#00b4d8);cursor:pointer;">
                                                <span x-text="f"></span>
                                            </label>
                                        </template>
                                    </div>
                                </template>
                            </div>
                            <hr class="pp-sep">
                        </div>
                    </template>

                    {{-- Editable literal text (custom_text, badge, watermark) --}}
                    <template x-if="sel.field === 'custom_text' || sel.field === 'badge' || sel.field === 'watermark'">
                        <div class="pp-row">
                            <label>Text</label>
                            <input type="text" :value="sel.text || ''" @input="mutate('text', $event.target.value)" placeholder="Your text">
                        </div>
                    </template>

                    {{-- Text fields --}}
                    <template x-if="CoreXAd.isTextField(sel.field) || sel.field === 'watermark' || sel.field === 'logo'">
                        <div>
                            <template x-if="CoreXAd.isTextField(sel.field) && sel.field !== 'custom_text' && sel.field !== 'badge' && sel.field !== 'features'">
                                <div class="pp-row">
                                    <label>Preview override</label>
                                    <input type="text" :value="sel.preview || ''" @input="mutate('preview', $event.target.value)" placeholder="(uses property value)">
                                </div>
                            </template>
                            <div class="pp-row">
                                <label>Font</label>
                                <select :value="sel.fontFamily || 'Figtree'" @input="mutate('fontFamily', $event.target.value)">
                                    <template x-for="f in CoreXAd.FONTS" :key="f.name">
                                        <option :value="f.name" :style="'font-family:' + f.stack" x-text="f.name"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="pp-row">
                                <label>Font size (px)</label>
                                <input type="number" :value="sel.fontSize" @input="mutate('fontSize', +$event.target.value)" min="8" max="300">
                            </div>
                            <template x-if="sel.field !== 'logo' && sel.field !== 'watermark'">
                                <div>
                                    <div class="pp-row">
                                        <label>Font weight</label>
                                        <select :value="sel.fontWeight" @input="mutate('fontWeight', $event.target.value)">
                                            <option value="400">Normal</option>
                                            <option value="500">Medium</option>
                                            <option value="600">Semi Bold</option>
                                            <option value="700">Bold</option>
                                            <option value="800">Extra Bold</option>
                                            <option value="900">Black</option>
                                        </select>
                                    </div>
                                    <div class="pp-row">
                                        <label>Line height</label>
                                        <input type="number" step="0.05" :value="sel.lineHeight ?? 1.2" @input="mutate('lineHeight', +$event.target.value)">
                                    </div>
                                    <div class="pp-row">
                                        <label>Text align</label>
                                        <select :value="sel.textAlign" @input="mutate('textAlign', $event.target.value)">
                                            <option value="left">Left</option>
                                            <option value="center">Center</option>
                                            <option value="right">Right</option>
                                        </select>
                                    </div>
                                    <div class="pp-row">
                                        <label>Vertical align</label>
                                        <select :value="sel.verticalAlign || 'middle'" @input="mutate('verticalAlign', $event.target.value)">
                                            <option value="top">Top</option>
                                            <option value="middle">Middle</option>
                                            <option value="bottom">Bottom</option>
                                        </select>
                                    </div>
                                    <div class="pp-row">
                                        <label>Transform</label>
                                        <select :value="sel.textTransform" @input="mutate('textTransform', $event.target.value)">
                                            <option value="none">None</option>
                                            <option value="uppercase">Uppercase</option>
                                            <option value="lowercase">Lowercase</option>
                                            <option value="capitalize">Capitalize</option>
                                        </select>
                                    </div>
                                    <div class="pp-row">
                                        <label>Letter spacing (em)</label>
                                        <input type="number" step="0.01" :value="sel.letterSpacing" @input="mutate('letterSpacing', +$event.target.value)">
                                    </div>
                                    <div class="pp-row">
                                        <label>Padding (px)</label>
                                        <input type="number" :value="sel.padding" @input="mutate('padding', +$event.target.value)" min="0">
                                    </div>
                                </div>
                            </template>
                            <div class="pp-row">
                                <label>Colour</label>
                                <input type="color" :value="sel.color" @input="mutate('color', $event.target.value)">
                            </div>
                            <template x-if="sel.field === 'watermark'">
                                <div class="pp-row">
                                    <label>Watermark opacity — <span x-text="Math.round((sel.opacity ?? 0.06) * 100) + '%'"></span></label>
                                    <input type="range" min="0" max="1" step="0.01" :value="sel.opacity ?? 0.06" @input="mutate('opacity', +$event.target.value)">
                                </div>
                            </template>
                            <template x-if="CoreXAd.isTextField(sel.field)">
                                <div>
                                    <div class="pp-row">
                                        <label>Background pill colour</label>
                                        <input type="color" :value="sel.bgColor || '#000000'" @input="mutate('bgColor', $event.target.value)">
                                    </div>
                                    <div class="pp-row">
                                        <label>Background pill opacity — <span x-text="Math.round((sel.bgOpacity ?? 0) * 100) + '%'"></span></label>
                                        <input type="range" min="0" max="1" step="0.05" :value="sel.bgOpacity ?? 0" @input="mutate('bgOpacity', +$event.target.value)">
                                    </div>
                                    <div class="pp-row">
                                        <label>Pill corner radius (px)</label>
                                        <input type="number" min="0" :value="sel.borderRadius || 0" @input="mutate('borderRadius', +$event.target.value)">
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Shape — pick from the shape list, fill + opacity --}}
                    <template x-if="sel.field === 'shape'">
                        <div>
                            <div class="pp-row" style="align-items:flex-start;">
                                <label>Shape</label>
                                <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:5px;width:100%;">
                                    <template x-for="s in CoreXAd.SHAPES" :key="s.type">
                                        <button type="button" @click="mutate('shapeType', s.type)" :title="s.label"
                                                :style="'aspect-ratio:1;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:6px;background:' + (sel.shapeType === s.type ? 'color-mix(in srgb, var(--brand-button,#00b4d8) 18%, transparent)' : 'var(--chrome-surface-2)') + ';border:1.5px solid ' + (sel.shapeType === s.type ? 'var(--brand-button,#00b4d8)' : 'var(--chrome-border)') + ';'">
                                            <span :style="CoreXAd.shapeCss({ shapeType: s.type, bg: '#9fb4c9', opacity: 1, borderRadius: 9 })"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                            <div class="pp-row">
                                <label>Fill colour</label>
                                <input type="color" :value="sel.bg" @input="mutate('bg', $event.target.value)">
                            </div>
                            <div class="pp-row">
                                <label>Fill opacity — <span x-text="Math.round((sel.opacity ?? 1) * 100) + '%'"></span></label>
                                <input type="range" min="0" max="1" step="0.05" :value="sel.opacity ?? 1" @input="mutate('opacity', +$event.target.value)">
                            </div>
                            <template x-if="sel.shapeType === 'rounded'">
                                <div class="pp-row">
                                    <label>Corner radius (px)</label>
                                    <input type="number" min="0" :value="sel.borderRadius ?? 24" @input="mutate('borderRadius', +$event.target.value)">
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Colour block (legacy — kept so existing templates still edit) --}}
                    <template x-if="sel.field === 'color_block'">
                        <div>
                            <div class="pp-row">
                                <label>Fill colour</label>
                                <input type="color" :value="sel.bg" @input="mutate('bg', $event.target.value)">
                            </div>
                            <div class="pp-row">
                                <label>Fill opacity — <span x-text="Math.round((sel.opacity ?? 1) * 100) + '%'"></span></label>
                                <input type="range" min="0" max="1" step="0.05" :value="sel.opacity ?? 1" @input="mutate('opacity', +$event.target.value)">
                            </div>
                            <div class="pp-row">
                                <label>Border Radius (px)</label>
                                <input type="number" :value="sel.borderRadius" @input="mutate('borderRadius', +$event.target.value)" min="0">
                            </div>
                        </div>
                    </template>

                    {{-- Gradient overlay --}}
                    <template x-if="sel.field === 'gradient'">
                        <div>
                            <div class="pp-row">
                                <label>From colour</label>
                                <input type="color" :value="sel.gradFrom || '#071325'" @input="mutate('gradFrom', $event.target.value)">
                            </div>
                            <div class="pp-row">
                                <label>To colour</label>
                                <input type="color" :value="sel.gradTo || '#071325'" @input="mutate('gradTo', $event.target.value)">
                            </div>
                            <div class="pp-row">
                                <label>Angle (deg)</label>
                                <input type="number" :value="sel.gradAngle ?? 180" @input="mutate('gradAngle', +$event.target.value)">
                            </div>
                            <div class="pp-row">
                                <label>Fill opacity — <span x-text="Math.round((sel.opacity ?? 1) * 100) + '%'"></span></label>
                                <input type="range" min="0" max="1" step="0.05" :value="sel.opacity ?? 1" @input="mutate('opacity', +$event.target.value)">
                            </div>
                        </div>
                    </template>

                    {{-- Line / divider --}}
                    <template x-if="sel.field === 'line'">
                        <div>
                            <div class="pp-row">
                                <label>Colour</label>
                                <input type="color" :value="sel.color || '#00b4d8'" @input="mutate('color', $event.target.value)">
                            </div>
                            <div class="pp-row">
                                <label>Thickness (px)</label>
                                <input type="number" min="1" :value="sel.borderWidth || 3" @input="mutate('borderWidth', +$event.target.value)">
                            </div>
                        </div>
                    </template>

                    <hr class="pp-sep">

                    {{-- Shadow — text elements get a text-shadow, everything else a box-shadow.
                         Both are html2canvas-safe, so the downloaded PNG matches the preview. --}}
                    <div class="pp-row">
                        <label style="display:flex;align-items:center;gap:7px;cursor:pointer;">
                            <input type="checkbox" :checked="!!sel.shadowOn" @change="mutate('shadowOn', $event.target.checked)"
                                   style="accent-color:var(--brand-button,#00b4d8);cursor:pointer;" :disabled="!CoreXAd.canShadow(sel)">
                            <span style="font-weight:600;color:var(--chrome-text);">Drop shadow</span>
                        </label>
                    </div>
                    <template x-if="!CoreXAd.canShadow(sel)">
                        <div class="pp-hint">A clip-path shape (triangle, star, hexagon…) cuts its own shadow away, so shadows are unavailable for this shape. Use a Rectangle, Rounded, Circle or Pill.</div>
                    </template>
                    <template x-if="sel.shadowOn && CoreXAd.canShadow(sel)">
                        <div>
                            <div class="pp-row">
                                <label>Offset (X, Y)</label>
                                <div class="pp-inline">
                                    <input type="number" :value="sel.shadowX ?? 0" @input="mutate('shadowX', +$event.target.value)">
                                    <input type="number" :value="sel.shadowY ?? 4" @input="mutate('shadowY', +$event.target.value)">
                                </div>
                            </div>
                            <div class="pp-row">
                                <label>Blur (px)</label>
                                <input type="number" min="0" :value="sel.shadowBlur ?? 12" @input="mutate('shadowBlur', +$event.target.value)">
                            </div>
                            <div class="pp-row">
                                <label>Shadow colour</label>
                                <input type="color" :value="sel.shadowColor || '#000000'" @input="mutate('shadowColor', $event.target.value)">
                            </div>
                            <div class="pp-row">
                                <label>Shadow opacity — <span x-text="Math.round((sel.shadowOpacity ?? 0.45) * 100) + '%'"></span></label>
                                <input type="range" min="0" max="1" step="0.05" :value="sel.shadowOpacity ?? 0.45" @input="mutate('shadowOpacity', +$event.target.value)">
                            </div>
                        </div>
                    </template>

                    <hr class="pp-sep">

                    {{-- Border (frame) for any element --}}
                    <div class="pp-row">
                        <label>Border width (px)</label>
                        <input type="number" min="0" :value="sel.frameBorderWidth || 0" @input="mutate('frameBorderWidth', +$event.target.value)">
                    </div>
                    <div class="pp-row">
                        <label>Border colour</label>
                        <input type="color" :value="sel.frameBorderColor || '#ffffff'" @input="mutate('frameBorderColor', $event.target.value)">
                    </div>

                    <div class="pp-row" style="display:flex;gap:6px;">
                        <button class="tb-btn" style="flex:1;justify-content:center;" @click="duplicateSelected()">Duplicate</button>
                        <button class="tb-btn" style="flex:1;justify-content:center;" @click="toggleLock()" x-text="allLocked ? 'Unlock' : 'Lock'"></button>
                    </div>
                    <div class="pp-row" style="margin-top:6px;">
                        <button class="tb-btn danger" style="width:100%;justify-content:center;" @click="deleteSelected()">
                            <svg style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            <span x-text="selCount > 1 ? 'Delete ' + selCount + ' Elements' : 'Delete Element'"></span>
                        </button>
                    </div>
                </div>
            </template>
        </div>

        {{-- ══ LAYERS TAB ══ --}}
        <div class="pp-body" x-show="tab === 'layers'">
            <h3>Layers — top of the ad first</h3>
            <template x-if="elements.length === 0">
                <div id="no-selection"><span>Nothing on the canvas yet</span></div>
            </template>

            <template x-for="(el, i) in layers" :key="el.id">
                <div>
                    <div class="layer-drop" :class="{ on: dragLayerOverId === el.id && dragLayerId !== el.id }"></div>
                    <div class="layer-row"
                         :class="{ on: isSelected(el), 'is-hidden': el.hidden }"
                         draggable="true"
                         @dragstart="layerDragStart($event, el)"
                         @dragover.prevent="dragLayerOverId = el.id"
                         @dragleave="dragLayerOverId === el.id && (dragLayerOverId = null)"
                         @drop.prevent="layerDrop(el)"
                         @dragend="dragLayerId = null; dragLayerOverId = null"
                         @click="selectFromLayers($event, el)">
                        <span class="layer-swatch" :style="'background:' + layerSwatch(el)"></span>
                        <span class="layer-name" x-text="el.label" :title="el.label"></span>
                        <button class="layer-ico" @click.stop="toggleHidden(el)" :title="el.hidden ? 'Show' : 'Hide'">
                            <template x-if="!el.hidden">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                            </template>
                            <template x-if="el.hidden">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.6 10.6a3 3 0 1 0 4.2 4.2"/><path d="M16.7 16.7A9.9 9.9 0 0 1 12 19c-6.4 0-10-7-10-7a18 18 0 0 1 5.1-5.9"/><path d="M9.9 5.2A9 9 0 0 1 12 5c6.4 0 10 7 10 7a18 18 0 0 1-2.2 3.2"/><path d="m2 2 20 20"/></svg>
                            </template>
                        </button>
                        <button class="layer-ico" @click.stop="toggleLockOne(el)" :title="el.locked ? 'Unlock' : 'Lock'">
                            <template x-if="el.locked">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                            </template>
                            <template x-if="!el.locked">
                                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="opacity:0.5;"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 7.5-2"/></svg>
                            </template>
                        </button>
                    </div>
                </div>
            </template>
            <div class="pp-hint" x-show="elements.length" style="margin-top:10px;">Drag a layer to restack it. The top row paints over everything below it.</div>
        </div>
    </div>

</div>

<div id="toast"></div>

{{-- ═══ KEYBOARD SHORTCUTS ═══ --}}
<div id="sc-backdrop" x-show="showShortcuts" x-cloak @click.self="showShortcuts = false">
    <div id="sc-modal">
        <h2>Keyboard shortcuts</h2>
        <div class="sc-grid">
            <div>
                <div class="sc-sec-t">Edit</div>
                <div class="sc-item"><span>Undo</span><kbd>Ctrl Z</kbd></div>
                <div class="sc-item"><span>Redo</span><kbd>Ctrl Shift Z</kbd></div>
                <div class="sc-item"><span>Duplicate</span><kbd>Ctrl D</kbd></div>
                <div class="sc-item"><span>Copy / Cut / Paste</span><kbd>Ctrl C / X / V</kbd></div>
                <div class="sc-item"><span>Delete</span><kbd>Del</kbd></div>
                <div class="sc-item"><span>Save</span><kbd>Ctrl S</kbd></div>
            </div>
            <div>
                <div class="sc-sec-t">Select</div>
                <div class="sc-item"><span>Select all</span><kbd>Ctrl A</kbd></div>
                <div class="sc-item"><span>Add to selection</span><kbd>Shift click</kbd></div>
                <div class="sc-item"><span>Marquee select</span><kbd>Drag canvas</kbd></div>
                <div class="sc-item"><span>Deselect</span><kbd>Esc</kbd></div>
            </div>
            <div>
                <div class="sc-sec-t">Move &amp; arrange</div>
                <div class="sc-item"><span>Nudge 1px</span><kbd>Arrows</kbd></div>
                <div class="sc-item"><span>Nudge 10px</span><kbd>Shift Arrows</kbd></div>
                <div class="sc-item"><span>Suspend snapping</span><kbd>Hold Alt</kbd></div>
                <div class="sc-item"><span>Keep aspect ratio</span><kbd>Shift resize</kbd></div>
                <div class="sc-item"><span>Rotate in 15° steps</span><kbd>Shift rotate</kbd></div>
                <div class="sc-item"><span>Forward / Backward</span><kbd>Ctrl ] / [</kbd></div>
                <div class="sc-item"><span>To front / To back</span><kbd>Ctrl Shift ] / [</kbd></div>
            </div>
            <div>
                <div class="sc-sec-t">View</div>
                <div class="sc-item"><span>Zoom in / out</span><kbd>Ctrl + / −</kbd></div>
                <div class="sc-item"><span>Zoom to fit</span><kbd>Ctrl 0</kbd></div>
                <div class="sc-item"><span>Actual size</span><kbd>Ctrl 1</kbd></div>
                <div class="sc-item"><span>Zoom at pointer</span><kbd>Ctrl scroll</kbd></div>
                <div class="sc-item"><span>This panel</span><kbd>?</kbd></div>
            </div>
        </div>
        <div style="margin-top:18px;text-align:right;">
            <button class="tb-btn" @click="showShortcuts = false">Close</button>
        </div>
    </div>
</div>

<script>
const HANDLE_POS = {
    nw: [0, 0],   n: [50, 0],   ne: [100, 0],
    w:  [0, 50],                 e: [100, 50],
    sw: [0, 100], s: [50, 100], se: [100, 100],
};
const MAX_HISTORY = 120;

function builder() {
    const existingTemplate = @json($template ? $template->toArray() : null);
    const lj = existingTemplate?.layout_json || {};

    return {
        CoreXAd: window.CoreXAd,
        HANDLES: Object.keys(HANDLE_POS),

        name:          existingTemplate?.name || 'My Template',
        elements:      lj.elements || [],
        canvasW:       lj.canvasW || 1200,
        canvasH:       lj.canvasH || 628,
        canvasBg:      lj.canvasBg || '#071325',
        canvasBgMode:  lj.canvasBgMode || 'solid',
        canvasBgFrom:  lj.canvasBgFrom || '#071325',
        canvasBgTo:    lj.canvasBgTo || '#0b2a4a',
        canvasBgAngle: lj.canvasBgAngle ?? 160,
        canvasPreset:  lj.canvasPreset || 'facebook',

        savedId:   existingTemplate?.id || null,
        saving:    false,
        exporting: false,
        capturing: false,
        dirty:     false,

        propertyData:  @json($propertyData ?? null),
        propertyId:    @json($property?->id ?? null),
        propertyAdUrl: @json($property ? route('corex.properties.ad', $property) : null),
        returnMarketingPropertyId: new URLSearchParams(window.location.search).get('return_marketing') || null,

        // ── Selection (ids, not indices — survives restacking and undo) ──────
        selIds: [],

        // ── View ────────────────────────────────────────────────────────────
        zoom: 1,
        zoomMode: 'fit',
        previewMode: false,
        tab: 'design',
        showShortcuts: false,

        // ── Snapping ────────────────────────────────────────────────────────
        snapObjects: true,
        snapGrid: true,
        showGrid: false,
        gridSize: 10,
        guides: [],
        marquee: null,

        // ── History ─────────────────────────────────────────────────────────
        past: [],
        future: [],

        // ── Layer drag ──────────────────────────────────────────────────────
        dragLayerId: null,
        dragLayerOverId: null,

        // Non-reactive scratch — a live drag/resize/rotate gesture, the clipboard,
        // the coalescing timer and the Alt key. None of it should trigger a render.
        _ds: null,
        _dropField: null,
        _clip: [],
        _coalesceKey: null,
        _coalesceTimer: null,
        _altDown: false,

        init() {
            this.normalizeZ(false);
            this.$nextTick(() => this.fitZoom());
            // Keep the fit honest when the canvas size changes under it.
            this.$watch('canvasW', () => { if (this.zoomMode === 'fit') this.fitZoom(); });
            this.$watch('canvasH', () => { if (this.zoomMode === 'fit') this.fitZoom(); });
        },

        /* ═══ Derived state ═══════════════════════════════════════════════ */

        get layoutJson() {
            return {
                canvasBg: this.canvasBg, canvasBgMode: this.canvasBgMode,
                canvasBgFrom: this.canvasBgFrom, canvasBgTo: this.canvasBgTo,
                canvasBgAngle: this.canvasBgAngle,
            };
        },

        get useOnPropertyUrl() {
            return this.propertyAdUrl || @json(route('corex.properties.index'));
        },

        get selIdx() {
            const out = [];
            this.elements.forEach((e, i) => { if (this.selIds.includes(e.id)) out.push(i); });
            return out;
        },
        get selCount() { return this.selIdx.length; },
        get sel() {
            const i = this.elements.findIndex(e => e.id === this.selIds[0]);
            return i < 0 ? null : this.elements[i];
        },
        get allLocked() {
            const s = this.selIdx;
            return s.length > 0 && s.every(i => this.elements[i].locked);
        },
        /** Layers list — TOP of the ad first, which is how a designer reads a stack. */
        get layers() {
            return [...this.elements].sort((a, b) => (b.zIndex || 0) - (a.zIndex || 0));
        },
        get featuresList() { return (this.propertyData && this.propertyData.features_list) || []; },

        /** The bounding box of the current selection, in canvas pixels. */
        get selBox() {
            const els = this.selIdx.map(i => this.elements[i]);
            if (!els.length) return null;
            return {
                x: Math.min(...els.map(e => e.x)),
                y: Math.min(...els.map(e => e.y)),
                r: Math.max(...els.map(e => e.x + e.w)),
                b: Math.max(...els.map(e => e.y + e.h)),
            };
        },

        isSelected(el) { return this.selIds.includes(el.id); },
        content(el) { return this.CoreXAd.contentHtml(el, this.propertyData, { placeholders: true }); },

        /* ═══ History ═════════════════════════════════════════════════════ */

        _snapshot() {
            return JSON.stringify({
                elements: this.elements,
                canvasW: this.canvasW, canvasH: this.canvasH, canvasPreset: this.canvasPreset,
                canvasBg: this.canvasBg, canvasBgMode: this.canvasBgMode,
                canvasBgFrom: this.canvasBgFrom, canvasBgTo: this.canvasBgTo, canvasBgAngle: this.canvasBgAngle,
            });
        },

        _restore(json) {
            const o = JSON.parse(json);
            this.elements      = o.elements;
            this.canvasW       = o.canvasW;
            this.canvasH       = o.canvasH;
            this.canvasPreset  = o.canvasPreset;
            this.canvasBg      = o.canvasBg;
            this.canvasBgMode  = o.canvasBgMode;
            this.canvasBgFrom  = o.canvasBgFrom;
            this.canvasBgTo    = o.canvasBgTo;
            this.canvasBgAngle = o.canvasBgAngle;
            // Drop any selection that no longer exists (undo of an "add").
            const ids = this.elements.map(e => e.id);
            this.selIds = this.selIds.filter(id => ids.includes(id));
        },

        /** Record the state BEFORE a discrete change. Every mutator calls this first. */
        commit(snapshot) {
            this.past.push(snapshot === undefined ? this._snapshot() : snapshot);
            if (this.past.length > MAX_HISTORY) this.past.shift();
            this.future = [];
            this.dirty = true;
        },

        /**
         * Continuous changes (dragging a slider, typing in a number box) would other-
         * wise push one history entry per keystroke. Coalesce them: the first change of
         * a burst records the pre-state, and the burst stays open for 600ms of quiet.
         */
        commitCoalesced(key) {
            if (this._coalesceKey !== key) {
                this.commit();
                this._coalesceKey = key;
            } else {
                this.dirty = true;
            }
            clearTimeout(this._coalesceTimer);
            this._coalesceTimer = setTimeout(() => { this._coalesceKey = null; }, 600);
        },

        undo() {
            if (!this.past.length) return;
            this._coalesceKey = null;
            this.future.push(this._snapshot());
            this._restore(this.past.pop());
            this.dirty = true;
        },

        redo() {
            if (!this.future.length) return;
            this._coalesceKey = null;
            this.past.push(this._snapshot());
            this._restore(this.future.pop());
            this.dirty = true;
        },

        /* ═══ Elements ════════════════════════════════════════════════════ */

        addField(field, x, y) {
            this.commit();
            const z = this.elements.length ? Math.max(...this.elements.map(e => e.zIndex || 0)) + 1 : 1;
            const el = this.CoreXAd.makeElement(field.type, x ?? 60, y ?? 60, z);
            this.elements.push(el);
            this.selIds = [el.id];
            this.tab = 'design';
        },

        /** Write `key` on EVERY selected element — so restyling six labels is one action. */
        mutate(key, value) {
            const idxs = this.selIdx;
            if (!idxs.length) return;
            this.commitCoalesced('mut:' + key + ':' + this.selIds.join(','));
            idxs.forEach(i => { this.elements[i] = { ...this.elements[i], [key]: value }; });
        },

        setCanvas(key, value) {
            this.commitCoalesced('canvas:' + key);
            this[key] = value;
        },

        setCanvasSize(w, h) {
            this.commit();
            this.canvasW = Math.min(4000, Math.max(200, w || 200));
            this.canvasH = Math.min(4000, Math.max(200, h || 200));
        },

        applyPreset() {
            const p = this.CoreXAd.CANVAS_PRESETS[this.canvasPreset];
            if (!p) return;                       // "custom" keeps the current W/H
            this.commit();
            this.canvasW = p.w;
            this.canvasH = p.h;
        },

        deleteSelected() {
            if (!this.selCount) return;
            this.commit();
            this.elements = this.elements.filter(e => !this.selIds.includes(e.id));
            this.selIds = [];
            this.normalizeZ(false);
        },

        duplicateSelected() {
            if (!this.selCount) return;
            this.commit();
            let z = this.elements.length ? Math.max(...this.elements.map(e => e.zIndex || 0)) : 0;
            const copies = this.selIdx.map(i => ({
                ...JSON.parse(JSON.stringify(this.elements[i])),
                id: Date.now() + Math.random(),
                x: this.elements[i].x + 16,
                y: this.elements[i].y + 16,
                zIndex: ++z,
            }));
            this.elements.push(...copies);
            this.selIds = copies.map(c => c.id);
        },

        clearAll() {
            if (!this.elements.length) return;
            this.commit();
            this.elements = [];
            this.selIds = [];
            this.toast('Canvas cleared — Ctrl+Z to undo');
        },

        rotate45() {
            if (!this.selCount) return;
            const cur = this.sel.rotation || 0;
            const next = (Math.round(cur / 45) * 45 + 45) % 360;
            this.commit();
            this.selIdx.forEach(i => { this.elements[i] = { ...this.elements[i], rotation: next }; });
        },

        toggleLock() {
            if (!this.selCount) return;
            const lock = !this.allLocked;
            this.commit();
            this.selIdx.forEach(i => { this.elements[i] = { ...this.elements[i], locked: lock }; });
        },

        toggleLockOne(el) {
            this.commit();
            const i = this.elements.findIndex(e => e.id === el.id);
            this.elements[i] = { ...this.elements[i], locked: !this.elements[i].locked };
        },

        /** Hidden in the builder = absent from the ad. The generator skips it too. */
        toggleHidden(el) {
            this.commit();
            const i = this.elements.findIndex(e => e.id === el.id);
            this.elements[i] = { ...this.elements[i], hidden: !this.elements[i].hidden };
        },

        /* ═══ Clipboard ═══════════════════════════════════════════════════ */

        copy() {
            if (!this.selCount) return;
            this._clip = this.selIdx.map(i => JSON.parse(JSON.stringify(this.elements[i])));
            this.toast(this._clip.length + (this._clip.length === 1 ? ' element copied' : ' elements copied'));
        },

        cut() {
            if (!this.selCount) return;
            this.copy();
            this.deleteSelected();
        },

        paste() {
            if (!this._clip.length) return;
            this.commit();
            let z = this.elements.length ? Math.max(...this.elements.map(e => e.zIndex || 0)) : 0;
            const copies = this._clip.map(c => ({
                ...JSON.parse(JSON.stringify(c)),
                id: Date.now() + Math.random(),
                x: c.x + 20,
                y: c.y + 20,
                zIndex: ++z,
            }));
            this.elements.push(...copies);
            this.selIds = copies.map(c => c.id);
            // Paste again → step further down, never stack on the same spot.
            this._clip = this._clip.map(c => ({ ...c, x: c.x + 20, y: c.y + 20 }));
        },

        selectAll() {
            this.selIds = this.elements.filter(e => !e.hidden && !e.locked).map(e => e.id);
        },

        /* ═══ Stacking order ══════════════════════════════════════════════ */

        /** Re-seat zIndex as a dense 1..n run — keeps it positive (a negative z-index
         *  would paint an element BEHIND the canvas background) and gaps-free. */
        normalizeZ(record = true) {
            if (!this.elements.length) return;
            if (record) this.commit();
            const order = [...this.elements].sort((a, b) => (a.zIndex || 0) - (b.zIndex || 0));
            order.forEach((el, i) => {
                const idx = this.elements.findIndex(e => e.id === el.id);
                if (this.elements[idx].zIndex !== i + 1) {
                    this.elements[idx] = { ...this.elements[idx], zIndex: i + 1 };
                }
            });
        },

        zOrder(dir) {
            if (!this.selCount) return;
            this.commit();
            const order = [...this.elements].sort((a, b) => (a.zIndex || 0) - (b.zIndex || 0));
            const picked = order.filter(e => this.selIds.includes(e.id));
            const rest   = order.filter(e => !this.selIds.includes(e.id));

            let next;
            if (dir === 'front')     next = [...rest, ...picked];
            else if (dir === 'back') next = [...picked, ...rest];
            else {
                // Step the whole selection one slot up or down, preserving its internal order.
                next = [...order];
                const step = dir === 'up' ? 1 : -1;
                const idxs = picked.map(e => next.indexOf(e));
                if (step === 1) idxs.reverse();
                idxs.forEach(i => {
                    const j = i + step;
                    if (j < 0 || j >= next.length) return;
                    if (this.selIds.includes(next[j].id)) return;   // don't swap with our own
                    [next[i], next[j]] = [next[j], next[i]];
                });
            }
            next.forEach((el, i) => {
                const idx = this.elements.findIndex(e => e.id === el.id);
                this.elements[idx] = { ...this.elements[idx], zIndex: i + 1 };
            });
        },

        /* ═══ Align / distribute ══════════════════════════════════════════ */

        alignHint(base) {
            return this.selCount > 1 ? base + ' (within the selection)' : base + ' (to the canvas)';
        },

        /** One element aligns to the CANVAS; several align to their shared bounding box. */
        align(mode) {
            const idxs = this.selIdx;
            if (!idxs.length) return;
            this.commit();

            const box = this.selCount === 1
                ? { x: 0, y: 0, r: this.canvasW, b: this.canvasH }
                : this.selBox;

            idxs.forEach(i => {
                const e = this.elements[i];
                const p = {};
                if (mode === 'left')         p.x = Math.round(box.x);
                else if (mode === 'hcenter') p.x = Math.round((box.x + box.r) / 2 - e.w / 2);
                else if (mode === 'right')   p.x = Math.round(box.r - e.w);
                else if (mode === 'top')     p.y = Math.round(box.y);
                else if (mode === 'vmiddle') p.y = Math.round((box.y + box.b) / 2 - e.h / 2);
                else if (mode === 'bottom')  p.y = Math.round(box.b - e.h);
                this.elements[i] = { ...e, ...p };
            });
        },

        distribute(axis) {
            const els = this.selIdx.map(i => this.elements[i])
                .sort((a, b) => (axis === 'h' ? a.x - b.x : a.y - b.y));
            if (els.length < 3) return;
            this.commit();

            const key   = axis === 'h' ? 'x' : 'y';
            const start = els[0][key];
            const end   = els[els.length - 1][key];
            const step  = (end - start) / (els.length - 1);

            els.forEach((e, k) => {
                const i = this.elements.findIndex(x => x.id === e.id);
                this.elements[i] = { ...this.elements[i], [key]: Math.round(start + step * k) };
            });
        },

        fillCanvas() {
            if (!this.selCount) return;
            this.commit();
            this.selIdx.forEach(i => {
                this.elements[i] = { ...this.elements[i], x: 0, y: 0, w: this.canvasW, h: this.canvasH };
            });
        },

        /* ═══ Snapping ════════════════════════════════════════════════════ */

        get snapping() { return (this.snapObjects || this.snapGrid) && !this._altDown; },

        /** Candidate snap lines on one axis: the canvas edges + centre, and every
         *  UNSELECTED element's near edge, centre and far edge. */
        snapTargets(axis) {
            const isX = axis === 'x';
            const out = [0, (isX ? this.canvasW : this.canvasH) / 2, isX ? this.canvasW : this.canvasH];
            this.elements.forEach(e => {
                if (e.hidden || this.selIds.includes(e.id)) return;
                const a = isX ? e.x : e.y;
                const s = isX ? e.w : e.h;
                out.push(a, a + s / 2, a + s);
            });
            return out;
        },

        /**
         * Snap a proposed box. Object guides win over the grid (a designer means the
         * other element, not the nearest 10px), and each axis resolves independently.
         * Returns the corrected x/y and the guide lines to draw.
         */
        snapBox(x, y, w, h) {
            const out = { x, y, guides: [] };
            if (!this.snapping) return out;

            const T = 6 / Math.max(this.zoom, 0.05);   // a constant ~6px on SCREEN
            let bestX = null, bestY = null;

            if (this.snapObjects) {
                const tx = this.snapTargets('x');
                const ty = this.snapTargets('y');
                // Each moving edge, paired with its offset from the box origin.
                const mx = [[0, x], [w / 2, x + w / 2], [w, x + w]];
                const my = [[0, y], [h / 2, y + h / 2], [h, y + h]];
                let dx = T + 1, dy = T + 1;

                mx.forEach(([off, val]) => tx.forEach(t => {
                    const d = Math.abs(t - val);
                    if (d <= T && d < dx) { dx = d; bestX = t - off; out.guides = out.guides.filter(g => g.axis !== 'v'); out.guides.push({ axis: 'v', pos: t }); }
                }));
                my.forEach(([off, val]) => ty.forEach(t => {
                    const d = Math.abs(t - val);
                    if (d <= T && d < dy) { dy = d; bestY = t - off; out.guides = out.guides.filter(g => g.axis !== 'h'); out.guides.push({ axis: 'h', pos: t }); }
                }));
            }

            if (bestX === null && this.snapGrid) bestX = Math.round(x / this.gridSize) * this.gridSize;
            if (bestY === null && this.snapGrid) bestY = Math.round(y / this.gridSize) * this.gridSize;

            if (bestX !== null) out.x = bestX;
            if (bestY !== null) out.y = bestY;
            return out;
        },

        /* ═══ Pointer gestures ════════════════════════════════════════════ */

        canvasPoint(e) {
            const c = document.getElementById('canvas');
            const r = c.getBoundingClientRect();
            return { x: (e.clientX - r.left) / this.zoom, y: (e.clientY - r.top) / this.zoom };
        },

        elMouseDown(e, el) {
            if (this.previewMode) return;
            if (el.locked) { this.selIds = [el.id]; return; }

            if (e.shiftKey) {
                this.selIds = this.isSelected(el)
                    ? this.selIds.filter(id => id !== el.id)
                    : [...this.selIds, el.id];
                if (!this.isSelected(el)) return;
            } else if (!this.isSelected(el)) {
                this.selIds = [el.id];
            }

            this._ds = {
                type: 'move',
                mx: e.clientX, my: e.clientY,
                snapshot: this._snapshot(),
                moved: false,
                origins: this.selIdx.map(i => ({ id: this.elements[i].id, x: this.elements[i].x, y: this.elements[i].y })),
                lead: { x: el.x, y: el.y, w: el.w, h: el.h },
            };
        },

        resizeStart(e, dir) {
            const el = this.sel;
            if (!el || el.locked) return;
            this._ds = {
                type: 'resize', dir,
                mx: e.clientX, my: e.clientY,
                snapshot: this._snapshot(),
                moved: false,
                id: el.id,
                x: el.x, y: el.y, w: el.w, h: el.h,
                ratio: el.w / Math.max(el.h, 1),
            };
        },

        rotateStart(e) {
            const el = this.sel;
            if (!el || el.locked) return;
            const c = document.getElementById('canvas').getBoundingClientRect();
            this._ds = {
                type: 'rotate',
                snapshot: this._snapshot(),
                moved: false,
                id: el.id,
                cx: c.left + (el.x + el.w / 2) * this.zoom,
                cy: c.top  + (el.y + el.h / 2) * this.zoom,
            };
        },

        canvasMouseDown(e) {
            if (this.previewMode) return;
            if (!e.shiftKey) this.selIds = [];
            const p = this.canvasPoint(e);
            this._ds = { type: 'marquee', ox: p.x, oy: p.y, additive: e.shiftKey, base: [...this.selIds], moved: false };
            this.marquee = { x: p.x, y: p.y, w: 0, h: 0 };
        },

        pointerMove(e) {
            const ds = this._ds;
            if (!ds) return;
            ds.moved = true;

            if (ds.type === 'move') {
                const dx = (e.clientX - ds.mx) / this.zoom;
                const dy = (e.clientY - ds.my) / this.zoom;

                // Snap the element under the cursor; the rest of the selection follows
                // by the SAME delta, so their relative layout is preserved.
                const snapped = this.snapBox(ds.lead.x + dx, ds.lead.y + dy, ds.lead.w, ds.lead.h);
                this.guides = snapped.guides;
                const adjX = snapped.x - (ds.lead.x + dx);
                const adjY = snapped.y - (ds.lead.y + dy);

                ds.origins.forEach(o => {
                    const i = this.elements.findIndex(el => el.id === o.id);
                    if (i < 0) return;
                    this.elements[i] = {
                        ...this.elements[i],
                        x: Math.round(o.x + dx + adjX),
                        y: Math.round(o.y + dy + adjY),
                    };
                });
                return;
            }

            if (ds.type === 'resize') {
                const i = this.elements.findIndex(el => el.id === ds.id);
                if (i < 0) return;
                let dx = (e.clientX - ds.mx) / this.zoom;
                let dy = (e.clientY - ds.my) / this.zoom;

                let { x, y, w, h } = ds;
                const d = ds.dir;
                if (d.includes('e')) w = ds.w + dx;
                if (d.includes('s')) h = ds.h + dy;
                if (d.includes('w')) { w = ds.w - dx; x = ds.x + dx; }
                if (d.includes('n')) { h = ds.h - dy; y = ds.y + dy; }

                // Shift on a corner keeps the aspect ratio.
                if (e.shiftKey && d.length === 2) {
                    if (w / Math.max(h, 1) > ds.ratio) w = h * ds.ratio;
                    else h = w / Math.max(ds.ratio, 0.0001);
                    if (d.includes('w')) x = ds.x + (ds.w - w);
                    if (d.includes('n')) y = ds.y + (ds.h - h);
                }

                w = Math.max(8, w);
                h = Math.max(8, h);

                // Snap the moving EDGES, not the origin: dragging the east handle should
                // snap the right edge to its neighbour, not the untouched left edge.
                if (this.snapping) {
                    const T = 6 / Math.max(this.zoom, 0.05);
                    const g = [];
                    const snapEdge = (val, axis) => {
                        let best = null, bd = T + 1;
                        if (this.snapObjects) {
                            this.snapTargets(axis).forEach(t => {
                                const dd = Math.abs(t - val);
                                if (dd <= T && dd < bd) { bd = dd; best = t; }
                            });
                        }
                        if (best !== null) { g.push({ axis: axis === 'x' ? 'v' : 'h', pos: best }); return best; }
                        if (this.snapGrid) return Math.round(val / this.gridSize) * this.gridSize;
                        return val;
                    };
                    if (d.includes('e')) { const r = snapEdge(x + w, 'x'); w = Math.max(8, r - x); }
                    if (d.includes('w')) { const l = snapEdge(x, 'x');     w = Math.max(8, (ds.x + ds.w) - l); x = l; }
                    if (d.includes('s')) { const b = snapEdge(y + h, 'y'); h = Math.max(8, b - y); }
                    if (d.includes('n')) { const t = snapEdge(y, 'y');     h = Math.max(8, (ds.y + ds.h) - t); y = t; }
                    this.guides = g;
                }

                this.elements[i] = {
                    ...this.elements[i],
                    x: Math.round(x), y: Math.round(y),
                    w: Math.round(w), h: Math.round(h),
                };
                return;
            }

            if (ds.type === 'rotate') {
                const i = this.elements.findIndex(el => el.id === ds.id);
                if (i < 0) return;
                let a = Math.atan2(e.clientY - ds.cy, e.clientX - ds.cx) * 180 / Math.PI + 90;
                if (e.shiftKey) a = Math.round(a / 15) * 15;
                this.elements[i] = { ...this.elements[i], rotation: Math.round((a + 360) % 360) };
                return;
            }

            if (ds.type === 'marquee') {
                const p = this.canvasPoint(e);
                this.marquee = {
                    x: Math.min(ds.ox, p.x), y: Math.min(ds.oy, p.y),
                    w: Math.abs(p.x - ds.ox), h: Math.abs(p.y - ds.oy),
                };
                const m = this.marquee;
                const hit = this.elements.filter(el =>
                    !el.hidden && !el.locked &&
                    el.x < m.x + m.w && el.x + el.w > m.x &&
                    el.y < m.y + m.h && el.y + el.h > m.y
                ).map(el => el.id);
                this.selIds = ds.additive ? [...new Set([...ds.base, ...hit])] : hit;
            }
        },

        pointerUp() {
            const ds = this._ds;
            this._ds = null;
            this.guides = [];
            this.marquee = null;
            if (!ds) return;

            // A gesture that actually changed something becomes ONE history entry —
            // recorded from the snapshot taken before the drag began.
            if (ds.moved && ds.snapshot && ds.type !== 'marquee') {
                if (this._snapshot() !== ds.snapshot) this.commit(ds.snapshot);
            }
        },

        /* ═══ Drag from the field catalogue ═══════════════════════════════ */

        sidebarDragStart(e, field) {
            this._dropField = field;
            e.dataTransfer.effectAllowed = 'copy';
        },

        canvasDrop(e) {
            if (!this._dropField) return;
            const p = this.canvasPoint(e);
            const d = this.CoreXAd.FIELD_DEFAULTS[this._dropField.type] || {};
            this.addField(
                this._dropField,
                Math.max(0, Math.round(p.x - (d.w || 200) / 2)),
                Math.max(0, Math.round(p.y - (d.h || 60) / 2)),
            );
            this._dropField = null;
        },

        /* ═══ Layers ══════════════════════════════════════════════════════ */

        selectFromLayers(e, el) {
            if (e.shiftKey) {
                this.selIds = this.isSelected(el)
                    ? this.selIds.filter(id => id !== el.id)
                    : [...this.selIds, el.id];
            } else {
                this.selIds = [el.id];
            }
        },

        layerSwatch(el) {
            const f = el.field;
            if (f === 'shape' || f === 'color_block') return el.bg || '#00b4d8';
            if (f === 'gradient') return 'linear-gradient(135deg,' + (el.gradFrom || '#071325') + ',' + (el.gradTo || '#0b2a4a') + ')';
            if (this.CoreXAd.isImageField(f) || f === 'custom_image' || f === 'custom_video') return 'linear-gradient(135deg,#0b2a4a,#143d6e)';
            return el.color || '#94a3b8';
        },

        layerDragStart(e, el) {
            this.dragLayerId = el.id;
            e.dataTransfer.effectAllowed = 'move';
        },

        /** Drop `dragLayerId` directly above `target` in the visual stack. */
        layerDrop(target) {
            const dragId = this.dragLayerId;
            this.dragLayerId = null;
            this.dragLayerOverId = null;
            if (!dragId || dragId === target.id) return;

            this.commit();
            // `layers` is top-first; rebuild that order with the dragged row re-seated,
            // then write it back as a bottom-first 1..n zIndex run.
            const top = this.layers.filter(e => e.id !== dragId);
            const moving = this.elements.find(e => e.id === dragId);
            const at = top.findIndex(e => e.id === target.id);
            top.splice(at, 0, moving);

            const bottomFirst = [...top].reverse();
            bottomFirst.forEach((el, i) => {
                const idx = this.elements.findIndex(e => e.id === el.id);
                this.elements[idx] = { ...this.elements[idx], zIndex: i + 1 };
            });
        },

        /* ═══ Zoom / view ═════════════════════════════════════════════════ */

        fitZoom() {
            const area = document.getElementById('canvas-area');
            if (!area) return;
            const maxW = (area.clientWidth  || 800) - 64;
            const maxH = (area.clientHeight || 600) - 64;
            this.zoomMode = 'fit';
            this.zoom = Math.max(0.05, Math.min(maxW / this.canvasW, maxH / this.canvasH, 1));
        },

        setZoom(z) {
            this.zoomMode = 'manual';
            this.zoom = Math.max(0.1, Math.min(4, z));
        },

        zoomBy(dir) {
            const steps = [0.1, 0.25, 0.5, 0.75, 1, 1.5, 2, 3, 4];
            const cur = this.zoom;
            const next = dir > 0
                ? (steps.find(s => s > cur + 0.001) ?? 4)
                : ([...steps].reverse().find(s => s < cur - 0.001) ?? 0.1);
            this.setZoom(next);
        },

        onWheel(e) {
            if (!e.ctrlKey && !e.metaKey) return;   // plain scroll still scrolls the workspace
            e.preventDefault();
            this.setZoom(this.zoom * (e.deltaY < 0 ? 1.12 : 0.89));
        },

        onResize() { if (this.zoomMode === 'fit') this.fitZoom(); },

        /* ═══ Overlay geometry (all counter-scaled to stay constant on screen) ═══ */

        gridStyle() {
            if (!this.showGrid || this.previewMode || this.capturing) return 'display:none;';
            const line = 'rgba(0,180,216,0.18)';
            const px = 1 / Math.max(this.zoom, 0.05);
            return 'background-image:linear-gradient(to right,' + line + ' ' + px + 'px, transparent ' + px + 'px),'
                 + 'linear-gradient(to bottom,' + line + ' ' + px + 'px, transparent ' + px + 'px);'
                 + 'background-size:' + this.gridSize + 'px ' + this.gridSize + 'px;';
        },

        guideStyle(g) {
            const px = 1 / Math.max(this.zoom, 0.05);
            return g.axis === 'v'
                ? 'left:' + g.pos + 'px;top:0;width:' + px + 'px;height:100%;'
                : 'top:' + g.pos + 'px;left:0;height:' + px + 'px;width:100%;';
        },

        marqueeStyle() {
            const m = this.marquee;
            if (!m) return 'display:none;';
            const px = 1 / Math.max(this.zoom, 0.05);
            return 'left:' + m.x + 'px;top:' + m.y + 'px;width:' + m.w + 'px;height:' + m.h + 'px;border-width:' + px + 'px;';
        },

        selOverlayStyle() {
            const b = this.selBox;
            if (!b) return 'display:none;';
            let s = 'left:' + b.x + 'px;top:' + b.y + 'px;width:' + (b.r - b.x) + 'px;height:' + (b.b - b.y) + 'px;';
            s += 'outline-width:' + (2 / Math.max(this.zoom, 0.05)) + 'px;';
            // A rotated element's chrome must rotate with it, or the handles lie.
            if (this.selCount === 1 && this.sel?.rotation) s += 'transform:rotate(' + this.sel.rotation + 'deg);';
            return s;
        },

        handleStyle(h) {
            const [px, py] = HANDLE_POS[h];
            const inv = 1 / Math.max(this.zoom, 0.05);
            const cursor = (h === 'n' || h === 's') ? 'ns-resize'
                         : (h === 'e' || h === 'w') ? 'ew-resize'
                         : (h === 'nw' || h === 'se') ? 'nwse-resize' : 'nesw-resize';
            return 'left:' + px + '%;top:' + py + '%;'
                 + 'transform:translate(-50%,-50%) scale(' + inv + ');cursor:' + cursor + ';';
        },

        rotHandleStyle() {
            const inv = 1 / Math.max(this.zoom, 0.05);
            return 'left:50%;top:0;transform:translate(-50%,-' + (26 * inv) + 'px) scale(' + inv + ');';
        },

        rotStemStyle() {
            const inv = 1 / Math.max(this.zoom, 0.05);
            return 'left:50%;top:' + (-20 * inv) + 'px;height:' + (20 * inv) + 'px;width:' + inv + 'px;';
        },

        elToolbarStyle() {
            const inv = 1 / Math.max(this.zoom, 0.05);
            return 'left:0;top:0;transform:translateY(calc(-100% - ' + (10 * inv) + 'px)) scale(' + inv + ');transform-origin:bottom left;';
        },

        groupIcon(key) {
            const icons = {
                image:      '<svg style="width:12px;height:12px;color:#fff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>',
                property:   '<svg style="width:12px;height:12px;color:#fff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>',
                agent:      '<svg style="width:12px;height:12px;color:#fff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>',
                branding:   '<svg style="width:12px;height:12px;color:#fff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 3 2.7 5.6 6.3.9-4.5 4.4 1 6.1-5.5-3-5.5 3 1-6.1L3 9.5l6.3-.9z"/></svg>',
                decorative: '<svg style="width:12px;height:12px;color:#fff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>',
            };
            return icons[key] || icons.decorative;
        },

        /* ═══ Keyboard ════════════════════════════════════════════════════ */

        _typing(e) {
            const t = e.target;
            return !!t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
        },

        onKeyUp(e) { if (e.key === 'Alt') this._altDown = false; },

        onKeyDown(e) {
            if (e.key === 'Alt') this._altDown = true;

            // Esc and Ctrl+S work even from inside a field (you just typed the name).
            if (e.key === 'Escape') { this.showShortcuts = false; if (!this._typing(e)) this.selIds = []; return; }
            const meta = e.ctrlKey || e.metaKey;
            if (meta && e.key.toLowerCase() === 's') { e.preventDefault(); this.save(); return; }
            if (this._typing(e)) return;

            if (meta) {
                const k = e.key.toLowerCase();
                if (k === 'z') { e.preventDefault(); e.shiftKey ? this.redo() : this.undo(); return; }
                if (k === 'y') { e.preventDefault(); this.redo(); return; }
                if (k === 'd') { e.preventDefault(); this.duplicateSelected(); return; }
                if (k === 'c') { e.preventDefault(); this.copy(); return; }
                if (k === 'x') { e.preventDefault(); this.cut(); return; }
                if (k === 'v') { e.preventDefault(); this.paste(); return; }
                if (k === 'a') { e.preventDefault(); this.selectAll(); return; }
                if (e.key === ']') { e.preventDefault(); this.zOrder(e.shiftKey ? 'front' : 'up'); return; }
                if (e.key === '[') { e.preventDefault(); this.zOrder(e.shiftKey ? 'back' : 'down'); return; }
                if (e.key === '0') { e.preventDefault(); this.fitZoom(); return; }
                if (e.key === '1') { e.preventDefault(); this.setZoom(1); return; }
                if (e.key === '+' || e.key === '=') { e.preventDefault(); this.zoomBy(1); return; }
                if (e.key === '-') { e.preventDefault(); this.zoomBy(-1); return; }
                return;
            }

            if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); this.deleteSelected(); return; }
            if (e.key === '?') { e.preventDefault(); this.showShortcuts = !this.showShortcuts; return; }
            if (e.key.startsWith('Arrow')) { e.preventDefault(); this.nudge(e.key, e.shiftKey); return; }
        },

        nudge(key, big) {
            const idxs = this.selIdx;
            if (!idxs.length) return;
            const step = big ? (this.snapGrid ? this.gridSize : 10) : 1;
            const dx = key === 'ArrowLeft' ? -step : key === 'ArrowRight' ? step : 0;
            const dy = key === 'ArrowUp'   ? -step : key === 'ArrowDown'  ? step : 0;

            this.commitCoalesced('nudge:' + this.selIds.join(','));
            idxs.forEach(i => {
                const e = this.elements[i];
                if (e.locked) return;
                this.elements[i] = { ...e, x: Math.round(e.x + dx), y: Math.round(e.y + dy) };
            });
        },

        onBeforeUnload(e) {
            if (!this.dirty) return;
            e.preventDefault();
            e.returnValue = '';
        },

        /* ═══ Features chooser ════════════════════════════════════════════ */

        isFeatureOn(el, f) {
            // null selection = show all (the default); otherwise only the chosen ones.
            return el.selectedFeatures == null ? true : el.selectedFeatures.includes(f);
        },

        toggleFeature(f) {
            const el = this.sel;
            if (!el) return;
            let cur = el.selectedFeatures == null ? [...this.featuresList] : [...el.selectedFeatures];
            cur = cur.includes(f) ? cur.filter(x => x !== f) : [...cur, f];
            this.mutate('selectedFeatures', cur);
        },

        /* ═══ Media upload ════════════════════════════════════════════════ */

        async uploadMedia(e) {
            const file = e.target.files?.[0];
            if (!file || !this.sel) return;
            const fd = new FormData();
            fd.append('file', file);
            this.toast('Uploading…');
            try {
                const res = await fetch(@json(route('corex.ad-templates.upload-media')), {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                    body: fd,
                });
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.message || json.error || 'Upload failed');
                this.commit();
                const i = this.elements.findIndex(x => x.id === this.sel.id);
                this.elements[i] = { ...this.elements[i], src: json.url, mediaKind: json.kind };
                this.toast('Uploaded');
            } catch (err) {
                this.toast('Upload failed: ' + (err?.message || 'unknown'));
            } finally {
                e.target.value = '';
            }
        },

        /* ═══ Persistence ═════════════════════════════════════════════════ */

        async save() {
            if (!this.name.trim()) { this.toast('Enter a template name'); return; }
            this.saving = true;
            try {
                const token = document.querySelector('meta[name="csrf-token"]').content;
                const payload = {
                    name: this.name.trim(),
                    layout_json: {
                        elements:      this.elements,
                        canvasW:       this.canvasW,
                        canvasH:       this.canvasH,
                        canvasBg:      this.canvasBg,
                        canvasBgMode:  this.canvasBgMode,
                        canvasBgFrom:  this.canvasBgFrom,
                        canvasBgTo:    this.canvasBgTo,
                        canvasBgAngle: this.canvasBgAngle,
                        canvasPreset:  this.canvasPreset,
                    },
                    _token: token,
                };

                const templateBase = @json(route('corex.ad-templates.store'));   // /corex/ad-templates
                let url = templateBase;
                if (this.savedId) {
                    url = templateBase + '/' + this.savedId;                     // PUT /corex/ad-templates/{id}
                    payload._method = 'PUT';
                }

                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                    body: JSON.stringify(payload),
                });
                const json = await res.json();
                if (!res.ok) throw new Error(json.message || 'Save failed');

                if (!this.savedId) {
                    this.savedId = json.id;
                    const base = @json(route('corex.ad-templates.builder'));      // /corex/ad-templates/builder
                    const qs   = this.propertyId ? ('?property=' + this.propertyId) : '';
                    history.replaceState({}, '', base + '/' + json.id + qs);
                }
                this.dirty = false;
                this.toast('Template saved!');
            } catch (err) {
                this.toast('Error: ' + (err?.message || 'unknown'));
            } finally {
                this.saving = false;
            }
        },

        /** Rasterise the canvas with every trace of the editor removed. */
        async capture() {
            this.capturing = true;
            this.selIds = [];
            await this.$nextTick();
            // Webfonts must be resolved or html2canvas rasterises the fallback face.
            if (document.fonts?.ready) { try { await document.fonts.ready; } catch (_) {} }
            await new Promise(r => requestAnimationFrame(() => setTimeout(r, 40)));
            try {
                return await html2canvas(document.getElementById('canvas'), {
                    useCORS: true, allowTaint: false, scale: 1, logging: false,
                    backgroundColor: this.CoreXAd.canvasBgSolid(this.layoutJson),
                });
            } finally {
                this.capturing = false;
            }
        },

        async exportForMarketing() {
            if (!this.savedId || !this.returnMarketingPropertyId) return;
            this.exporting = true;
            try {
                const canvas = await this.capture();
                const res = await fetch(@json(route('corex.marketing.upload-template-image')), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ image: canvas.toDataURL('image/png') }),
                });
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.error || 'Upload failed');
                this.dirty = false;   // don't fight the navigation with an unsaved-changes prompt
                window.location.href = '/corex/properties/' + this.returnMarketingPropertyId
                    + '/marketing?marketing_img=' + encodeURIComponent(json.url) + '&media_tab=photos';
            } catch (err) {
                this.toast('Export failed: ' + (err?.message || 'unknown'));
                this.exporting = false;
            }
        },

        toast(msg) {
            const el = document.getElementById('toast');
            el.textContent = msg;
            el.classList.add('show');
            clearTimeout(this._toastTimer);
            this._toastTimer = setTimeout(() => el.classList.remove('show'), 2500);
        },
    };
}
</script>
</body>
</html>
