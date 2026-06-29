<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $template ? 'Edit Template — ' . $template->name : 'New Ad Template' }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800,900&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; overflow: hidden; }
        body { font-family: 'Figtree', sans-serif; background: #060f1c; color: #f1f5f9; display: flex; flex-direction: column; }
        [x-cloak] { display: none !important; }

        /* ─── TOOLBAR ─── */
        #toolbar {
            flex-shrink: 0; min-height: 52px;
            background: #07111e; border-bottom: 1px solid rgba(255,255,255,0.07);
            display: flex; align-items: center; gap: 8px; padding: 0 14px; flex-wrap: wrap;
        }
        .tb-btn {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 5px 12px; border-radius: 8px; font-size: 12px; font-weight: 600;
            cursor: pointer; border: 1.5px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.04); color: rgba(255,255,255,0.55);
            font-family: inherit; transition: all 0.12s; text-decoration: none;
        }
        .tb-btn:hover { border-color: rgba(255,255,255,0.25); color: #fff; }
        .tb-btn.primary { background: #00b4d8; border-color: #00b4d8; color: #fff; }
        .tb-btn.primary:hover { background: #0090b0; border-color: #0090b0; }
        .tb-btn.danger { background: rgba(230,57,70,0.15); border-color: rgba(230,57,70,0.35); color: #e63946; }
        .tb-btn.danger:hover { background: #e63946; border-color: #e63946; color: #fff; }
        #tpl-name-input {
            background: rgba(255,255,255,0.06); border: 1.5px solid rgba(255,255,255,0.1);
            color: #fff; border-radius: 8px; padding: 5px 12px; font-size: 13px; font-weight: 600;
            font-family: inherit; width: 200px; outline: none;
        }
        #tpl-name-input:focus { border-color: #00b4d8; }
        .ctx-chip {
            display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 7px;
            font-size: 11px; font-weight: 600; background: rgba(0,180,216,0.12); color: #5fd2ec;
            border: 1px solid rgba(0,180,216,0.25);
        }

        /* ─── 3-COLUMN LAYOUT ─── */
        #workspace { flex: 1; display: flex; overflow: hidden; }

        /* ─── LEFT SIDEBAR: field catalogue ─── */
        #sidebar {
            width: 208px; flex-shrink: 0;
            background: #07111e; border-right: 1px solid rgba(255,255,255,0.07);
            overflow-y: auto; padding: 12px 8px;
        }
        .sb-group { margin-bottom: 14px; }
        .sb-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.14em; color: rgba(255,255,255,0.3); padding: 0 6px; margin-bottom: 6px; }
        .sb-field {
            display: flex; align-items: center; gap: 8px;
            padding: 6px 10px; border-radius: 8px; cursor: grab;
            font-size: 12px; font-weight: 500; color: rgba(255,255,255,0.7);
            border: 1px solid transparent; margin-bottom: 3px;
            transition: all 0.12s; user-select: none;
        }
        .sb-field:hover { background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.08); color: #fff; }
        .sb-field:active { cursor: grabbing; }
        .sb-icon { width: 22px; height: 22px; border-radius: 5px; display:flex; align-items:center; justify-content:center; font-size: 11px; flex-shrink: 0; }

        /* ─── CANVAS AREA ─── */
        #canvas-area {
            flex: 1; overflow: auto; display: flex; align-items: center; justify-content: center;
            background: #040c15; padding: 32px;
        }
        #canvas-wrapper { position: relative; flex-shrink: 0; box-shadow: 0 24px 80px rgba(0,0,0,0.8); }
        #canvas { width: 1200px; height: 628px; background: #071325; position: relative; overflow: hidden; cursor: default; }
        .canvas-el { position: absolute; cursor: move; user-select: none; outline: none; }
        .canvas-el.selected { outline: 2px solid #00b4d8; }
        .canvas-el .resize-handle {
            position: absolute; right: -5px; bottom: -5px;
            width: 10px; height: 10px; background: #00b4d8;
            border: 2px solid #fff; border-radius: 2px; cursor: se-resize; z-index: 999;
        }
        .img-placeholder {
            width: 100%; height: 100%; background: linear-gradient(135deg,#0b2a4a,#143d6e);
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            color: rgba(255,255,255,0.3); font-size: 12px; gap: 6px; pointer-events:none;
        }
        .img-placeholder svg { opacity:0.35; }
        .color-block { width:100%; height:100%; }
        .logo-el { display:flex; align-items:center; justify-content:flex-start; font-family:'Figtree',sans-serif; font-weight:900; line-height:1; color:#fff; }
        .logo-el span { color:#33c4e0; }
        .watermark-el { width:100%; height:100%; display:flex; align-items:center; justify-content:center; font-weight:900; letter-spacing:0.06em; text-transform:uppercase; opacity:0.08; color:#fff; }

        /* ─── RIGHT PANEL: properties ─── */
        #prop-panel {
            width: 248px; flex-shrink: 0;
            background: #07111e; border-left: 1px solid rgba(255,255,255,0.07);
            overflow-y: auto; padding: 14px 12px;
        }
        #prop-panel h3 { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.12em; color: rgba(255,255,255,0.3); margin-bottom: 12px; }
        .pp-row { margin-bottom: 10px; }
        .pp-row label { display: block; font-size: 11px; color: rgba(255,255,255,0.45); margin-bottom: 4px; }
        .pp-row input[type=text], .pp-row input[type=number], .pp-row select, .pp-row textarea {
            width: 100%; background: rgba(255,255,255,0.06); border: 1.5px solid rgba(255,255,255,0.1);
            color: #fff; border-radius: 7px; padding: 6px 9px; font-size: 12px; font-family: inherit; outline: none;
        }
        .pp-row input:focus, .pp-row select:focus, .pp-row textarea:focus { border-color: #00b4d8; }
        .pp-row input[type=color] { width: 100%; height: 30px; border: 1.5px solid rgba(255,255,255,0.1); border-radius: 7px; cursor: pointer; padding: 2px; background: rgba(255,255,255,0.06); }
        .pp-row select option { background: #07111e; }
        .pp-sep { border: none; border-top: 1px solid rgba(255,255,255,0.07); margin: 14px 0; }
        .pp-row .pp-inline { display:flex; gap:6px; }
        .pp-row .pp-inline input { flex:1; }
        #no-selection { display:flex; flex-direction:column; align-items:center; justify-content:center; height:200px; gap:10px; opacity:0.3; font-size:12px; }
        #canvas-scale { transform-origin: top left; }
        .canvas-el:not(.selected) .resize-handle { display: none; }

        /* ─── Status toast ─── */
        #toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:#00b4d8; color:#fff; font-size:13px; font-weight:700; padding:10px 22px; border-radius:10px; opacity:0; pointer-events:none; transition:opacity 0.3s; z-index:9999; }
        #toast.show { opacity:1; }
    </style>
</head>
<body x-data="builder()" @mouseup.window="dragEnd($event)" @mousemove.window="dragMove($event)">

{{-- ═══ TOOLBAR ═══ --}}
<div id="toolbar">
    <a href="javascript:history.back()" class="tb-btn">
        <svg style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back
    </a>

    <div style="width:1px;height:20px;background:rgba(255,255,255,0.08);"></div>

    <input id="tpl-name-input" type="text" x-model="name" placeholder="Template name…">

    {{-- Property context chip --}}
    <template x-if="propertyData">
        <span class="ctx-chip" title="Live preview uses this property's real data">
            <svg style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
            <span x-text="propertyData.title || 'This property'"></span>
        </span>
    </template>

    <div style="margin-left:auto;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">

        {{-- Canvas size --}}
        <select x-model="canvasPreset" @change="applyPreset()" class="tb-btn" style="padding:5px 8px;font-size:11px;color:rgba(255,255,255,0.65);">
            <option value="facebook">1200×628 (Facebook)</option>
            <option value="instagram">1080×1080 (Instagram)</option>
            <option value="story">1080×1920 (Story)</option>
            <option value="whatsapp">900×900 (WhatsApp)</option>
            <option value="linkedin">1200×627 (LinkedIn)</option>
            <option value="pinterest">1000×1500 (Pinterest)</option>
        </select>

        {{-- Clear all --}}
        <button class="tb-btn danger" @click="if(confirm('Clear all elements?')) { elements = []; selectedIndex = -1; }">
            <svg style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            Clear
        </button>

        {{-- Save --}}
        <button class="tb-btn primary" @click="save()" :disabled="saving">
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
            <a :href="useOnPropertyUrl" class="tb-btn" style="color:rgba(255,255,255,0.6);">
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

{{-- ═══ WORKSPACE ═══ --}}
<div id="workspace">

    {{-- ── LEFT: FIELD CATALOGUE ── --}}
    <div id="sidebar">
        <template x-for="grp in fieldGroups" :key="grp.key">
            <div class="sb-group">
                <div class="sb-label" x-text="grp.label"></div>
                <template x-for="f in fields.filter(x => x.group===grp.key)" :key="f.type">
                    <div class="sb-field" draggable="true" @dragstart="sidebarDragStart($event, f)" @click="addFieldAt(f, 60, 60)">
                        <span class="sb-icon" :style="'background:'+f.iconBg" x-html="grp.icon"></span>
                        <span x-text="f.label"></span>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- ── CENTRE: CANVAS AREA ── --}}
    <div id="canvas-area" @dragover.prevent @drop="canvasDrop($event)">
        <div id="canvas-wrapper">
            <div id="canvas-scale" :style="'transform:scale('+canvasScale+');width:'+canvasW+'px;height:'+canvasH+'px;'">

                <div id="canvas"
                     :style="'width:'+canvasW+'px;height:'+canvasH+'px;background:'+canvasBackground+';'"
                     @click.self="selectedIndex = -1">

                    <template x-for="(el, idx) in elements" :key="el.id">
                        <div class="canvas-el"
                             :class="{ selected: selectedIndex === idx }"
                             :style="elStyle(el)"
                             @mousedown.prevent="dragStart($event, idx)"
                             @click.stop="selectedIndex = idx">

                            <div class="resize-handle" @mousedown.prevent.stop="resizeStart($event, idx)"></div>

                            {{-- IMAGE fields (image_*, agent_avatar, agency_logo) --}}
                            <template x-if="isImageField(el.field)">
                                <div style="width:100%;height:100%;">
                                    <template x-if="livePreviewSrc(el)">
                                        <img :src="livePreviewSrc(el)"                                             :style="'width:100%;height:100%;object-fit:'+(el.objectFit||'cover')+';display:block;'">
                                    </template>
                                    <template x-if="!livePreviewSrc(el)">
                                        <div class="img-placeholder">
                                            <svg style="width:28px;height:28px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                                            <span x-text="el.label"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- COLOR BLOCK --}}
                            <template x-if="el.field === 'color_block'">
                                <div class="color-block" :style="'background:'+el.bg+';opacity:'+el.opacity+';'"></div>
                            </template>

                            {{-- GRADIENT overlay --}}
                            <template x-if="el.field === 'gradient'">
                                <div style="width:100%;height:100%;" :style="'background:linear-gradient('+(el.gradAngle||180)+'deg,'+(el.gradFrom||'#071325')+','+(el.gradTo||'rgba(7,19,37,0)')+');opacity:'+(el.opacity ?? 1)+';'"></div>
                            </template>

                            {{-- LINE / divider --}}
                            <template x-if="el.field === 'line'">
                                <div style="width:100%;height:100%;display:flex;align-items:center;">
                                    <div :style="'width:100%;height:'+(el.borderWidth||3)+'px;background:'+(el.color||'#00b4d8')+';border-radius:2px;'"></div>
                                </div>
                            </template>

                            {{-- SHAPE --}}
                            <template x-if="el.field === 'shape'">
                                <div :style="'width:100%;height:100%;background:'+(el.bg||'#00b4d8')+';opacity:'+(el.opacity ?? 1)+';border-radius:'+(el.borderRadius ?? 50)+'%;'"></div>
                            </template>

                            {{-- LOGO (agency logo image, else CoreX wordmark) --}}
                            <template x-if="el.field === 'logo'">
                                <div style="width:100%;height:100%;display:flex;align-items:center;" :style="'padding:'+(el.padding||0)+'px;'">
                                    <template x-if="propertyData && propertyData.logo">
                                        <img :src="propertyData.logo" crossorigin="anonymous" style="max-height:100%;max-width:100%;object-fit:contain;object-position:left center;">
                                    </template>
                                    <template x-if="!propertyData || !propertyData.logo">
                                        <div class="logo-el" :style="'font-size:'+el.fontSize+'px;color:'+el.color+';'">corex<span>os</span></div>
                                    </template>
                                </div>
                            </template>

                            {{-- WATERMARK --}}
                            <template x-if="el.field === 'watermark'">
                                <div class="watermark-el" :style="'font-size:'+el.fontSize+'px;color:'+el.color+';opacity:'+el.opacity+';'"
                                     x-text="(propertyData && propertyData.watermark) || el.text || 'COREX'"></div>
                            </template>

                            {{-- TEXT fields (everything else) --}}
                            <template x-if="isTextField(el.field)">
                                <div style="width:100%;height:100%;display:flex;align-items:center;overflow:hidden;"
                                     :style="textStyle(el)">
                                    <span x-text="textValue(el)" style="width:100%;"></span>
                                </div>
                            </template>

                        </div>
                    </template>

                    {{-- Empty state --}}
                    <template x-if="elements.length === 0">
                        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;opacity:0.18;pointer-events:none;">
                            <svg style="width:48px;height:48px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                            <span style="font-size:14px;font-weight:600;">Drag fields from the left panel</span>
                        </div>
                    </template>

                </div>
            </div>
        </div>
    </div>

    {{-- ── RIGHT: PROPERTIES PANEL ── --}}
    <div id="prop-panel">

        <template x-if="selectedIndex < 0 || selectedIndex >= elements.length">
            <div>
                <h3>Canvas</h3>
                <div class="pp-row">
                    <label>Background style</label>
                    <select :value="canvasBgMode" @input="canvasBgMode = $event.target.value">
                        <option value="solid">Solid colour</option>
                        <option value="gradient">Gradient</option>
                    </select>
                </div>
                <template x-if="canvasBgMode === 'solid'">
                    <div class="pp-row">
                        <label>Background colour</label>
                        <input type="color" :value="canvasBg" @input="canvasBg = $event.target.value">
                    </div>
                </template>
                <template x-if="canvasBgMode === 'gradient'">
                    <div>
                        <div class="pp-row">
                            <label>From</label>
                            <input type="color" :value="canvasBgFrom" @input="canvasBgFrom = $event.target.value">
                        </div>
                        <div class="pp-row">
                            <label>To</label>
                            <input type="color" :value="canvasBgTo" @input="canvasBgTo = $event.target.value">
                        </div>
                        <div class="pp-row">
                            <label>Angle (deg)</label>
                            <input type="number" :value="canvasBgAngle" @input="canvasBgAngle = +$event.target.value">
                        </div>
                    </div>
                </template>
                <hr class="pp-sep">
                <div id="no-selection">
                    <svg style="width:24px;height:24px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 15l-6-6m0 0l6-6m-6 6h12"/></svg>
                    <span>Select an element</span>
                </div>
            </div>
        </template>

        <template x-if="selectedIndex >= 0 && selectedIndex < elements.length">
            <div>
                <h3 x-text="elements[selectedIndex].label + ' Properties'"></h3>

                {{-- Position & Size --}}
                <div class="pp-row">
                    <label>Position (X, Y)</label>
                    <div class="pp-inline">
                        <input type="number" :value="Math.round(elements[selectedIndex].x)" @input="mutate('x', +$event.target.value)" placeholder="X">
                        <input type="number" :value="Math.round(elements[selectedIndex].y)" @input="mutate('y', +$event.target.value)" placeholder="Y">
                    </div>
                </div>
                <div class="pp-row">
                    <label>Size (W, H)</label>
                    <div class="pp-inline">
                        <input type="number" :value="Math.round(elements[selectedIndex].w)" @input="mutate('w', +$event.target.value)" placeholder="W">
                        <input type="number" :value="Math.round(elements[selectedIndex].h)" @input="mutate('h', +$event.target.value)" placeholder="H">
                    </div>
                </div>
                <div class="pp-row">
                    <label>Rotation (deg)</label>
                    <input type="number" :value="elements[selectedIndex].rotation || 0" @input="mutate('rotation', +$event.target.value)">
                </div>

                <hr class="pp-sep">

                {{-- Image fields --}}
                <template x-if="isImageField(elements[selectedIndex].field)">
                    <div>
                        <div class="pp-row">
                            <label>Object Fit</label>
                            <select :value="elements[selectedIndex].objectFit" @input="mutate('objectFit', $event.target.value)">
                                <option value="cover">Cover</option>
                                <option value="contain">Contain</option>
                                <option value="fill">Fill</option>
                            </select>
                        </div>
                        <div class="pp-row">
                            <label>Border Radius (px)</label>
                            <input type="number" :value="elements[selectedIndex].borderRadius" @input="mutate('borderRadius', +$event.target.value)" min="0">
                        </div>
                    </div>
                </template>

                {{-- Editable literal text (custom_text, badge) --}}
                <template x-if="elements[selectedIndex].field === 'custom_text' || elements[selectedIndex].field === 'badge' || elements[selectedIndex].field === 'watermark'">
                    <div class="pp-row">
                        <label>Text</label>
                        <input type="text" :value="elements[selectedIndex].text || ''" @input="mutate('text', $event.target.value)" placeholder="Your text">
                    </div>
                </template>

                {{-- Text fields --}}
                <template x-if="isTextField(elements[selectedIndex].field) || elements[selectedIndex].field === 'watermark'">
                    <div>
                        <template x-if="isTextField(elements[selectedIndex].field) && elements[selectedIndex].field !== 'custom_text' && elements[selectedIndex].field !== 'badge'">
                            <div class="pp-row">
                                <label>Preview override</label>
                                <input type="text" :value="elements[selectedIndex].preview || ''" @input="mutate('preview', $event.target.value)" placeholder="(uses property value)">
                            </div>
                        </template>
                        <div class="pp-row">
                            <label>Font size (px)</label>
                            <input type="number" :value="elements[selectedIndex].fontSize" @input="mutate('fontSize', +$event.target.value)" min="8" max="300">
                        </div>
                        <div class="pp-row">
                            <label>Font weight</label>
                            <select :value="elements[selectedIndex].fontWeight" @input="mutate('fontWeight', $event.target.value)">
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
                            <input type="number" step="0.05" :value="elements[selectedIndex].lineHeight ?? 1.2" @input="mutate('lineHeight', +$event.target.value)">
                        </div>
                        <div class="pp-row">
                            <label>Text align</label>
                            <select :value="elements[selectedIndex].textAlign" @input="mutate('textAlign', $event.target.value)">
                                <option value="left">Left</option>
                                <option value="center">Center</option>
                                <option value="right">Right</option>
                            </select>
                        </div>
                        <div class="pp-row">
                            <label>Transform</label>
                            <select :value="elements[selectedIndex].textTransform" @input="mutate('textTransform', $event.target.value)">
                                <option value="none">None</option>
                                <option value="uppercase">Uppercase</option>
                                <option value="lowercase">Lowercase</option>
                                <option value="capitalize">Capitalize</option>
                            </select>
                        </div>
                        <div class="pp-row">
                            <label>Letter spacing (em)</label>
                            <input type="number" step="0.01" :value="elements[selectedIndex].letterSpacing" @input="mutate('letterSpacing', +$event.target.value)">
                        </div>
                        <div class="pp-row">
                            <label>Padding (px)</label>
                            <input type="number" :value="elements[selectedIndex].padding" @input="mutate('padding', +$event.target.value)" min="0">
                        </div>
                        <div class="pp-row">
                            <label>Colour</label>
                            <input type="color" :value="elements[selectedIndex].color" @input="mutate('color', $event.target.value)">
                        </div>
                        <div class="pp-row">
                            <label>Background pill colour</label>
                            <input type="color" :value="elements[selectedIndex].bgColor || '#000000'" @input="mutate('bgColor', $event.target.value)">
                        </div>
                        <div class="pp-row">
                            <label>Background pill opacity (0–1)</label>
                            <input type="number" step="0.05" min="0" max="1" :value="elements[selectedIndex].bgOpacity ?? 0" @input="mutate('bgOpacity', +$event.target.value)">
                        </div>
                    </div>
                </template>

                {{-- Color block / shape --}}
                <template x-if="elements[selectedIndex].field === 'color_block' || elements[selectedIndex].field === 'shape'">
                    <div>
                        <div class="pp-row">
                            <label>Fill colour</label>
                            <input type="color" :value="elements[selectedIndex].bg" @input="mutate('bg', $event.target.value)">
                        </div>
                        <div class="pp-row">
                            <label>Opacity (0–1)</label>
                            <input type="number" step="0.05" min="0" max="1" :value="elements[selectedIndex].opacity" @input="mutate('opacity', +$event.target.value)">
                        </div>
                        <div class="pp-row">
                            <label x-text="elements[selectedIndex].field === 'shape' ? 'Roundness (%)' : 'Border Radius (px)'"></label>
                            <input type="number" :value="elements[selectedIndex].borderRadius" @input="mutate('borderRadius', +$event.target.value)" min="0">
                        </div>
                    </div>
                </template>

                {{-- Gradient overlay --}}
                <template x-if="elements[selectedIndex].field === 'gradient'">
                    <div>
                        <div class="pp-row">
                            <label>From colour</label>
                            <input type="color" :value="elements[selectedIndex].gradFrom || '#071325'" @input="mutate('gradFrom', $event.target.value)">
                        </div>
                        <div class="pp-row">
                            <label>To colour</label>
                            <input type="color" :value="elements[selectedIndex].gradTo || '#071325'" @input="mutate('gradTo', $event.target.value)">
                        </div>
                        <div class="pp-row">
                            <label>Angle (deg)</label>
                            <input type="number" :value="elements[selectedIndex].gradAngle ?? 180" @input="mutate('gradAngle', +$event.target.value)">
                        </div>
                        <div class="pp-row">
                            <label>Opacity (0–1)</label>
                            <input type="number" step="0.05" min="0" max="1" :value="elements[selectedIndex].opacity ?? 1" @input="mutate('opacity', +$event.target.value)">
                        </div>
                    </div>
                </template>

                {{-- Line / divider --}}
                <template x-if="elements[selectedIndex].field === 'line'">
                    <div>
                        <div class="pp-row">
                            <label>Colour</label>
                            <input type="color" :value="elements[selectedIndex].color || '#00b4d8'" @input="mutate('color', $event.target.value)">
                        </div>
                        <div class="pp-row">
                            <label>Thickness (px)</label>
                            <input type="number" min="1" :value="elements[selectedIndex].borderWidth || 3" @input="mutate('borderWidth', +$event.target.value)">
                        </div>
                    </div>
                </template>

                <hr class="pp-sep">

                {{-- Border (frame) for any element --}}
                <div class="pp-row">
                    <label>Border width (px)</label>
                    <input type="number" min="0" :value="elements[selectedIndex].frameBorderWidth || 0" @input="mutate('frameBorderWidth', +$event.target.value)">
                </div>
                <div class="pp-row">
                    <label>Border colour</label>
                    <input type="color" :value="elements[selectedIndex].frameBorderColor || '#ffffff'" @input="mutate('frameBorderColor', $event.target.value)">
                </div>

                {{-- Z-index & Delete --}}
                <div class="pp-row">
                    <label>Layer (z-index)</label>
                    <input type="number" :value="elements[selectedIndex].zIndex" @input="mutate('zIndex', +$event.target.value)" min="0" max="999">
                </div>
                <div class="pp-row" style="display:flex;gap:6px;">
                    <button class="tb-btn" style="flex:1;justify-content:center;" @click="duplicateSelected()">Duplicate</button>
                </div>
                <div class="pp-row" style="margin-top:6px;">
                    <button class="tb-btn danger" style="width:100%;justify-content:center;" @click="deleteSelected()">
                        <svg style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        Delete Element
                    </button>
                </div>
            </div>
        </template>

    </div>

</div>

<div id="toast" x-ref="toast"></div>

<script>
const CANVAS_PRESETS = {
    facebook:  { w: 1200, h: 628  },
    instagram: { w: 1080, h: 1080 },
    story:     { w: 1080, h: 1920 },
    whatsapp:  { w: 900,  h: 900  },
    linkedin:  { w: 1200, h: 627  },
    pinterest: { w: 1000, h: 1500 },
};

const FIELD_DEFAULTS = {
    image_1:          { w: 600, h: 314, objectFit: 'cover', borderRadius: 0 },
    image_2:          { w: 400, h: 250, objectFit: 'cover', borderRadius: 0 },
    image_3:          { w: 400, h: 250, objectFit: 'cover', borderRadius: 0 },
    image_4:          { w: 400, h: 250, objectFit: 'cover', borderRadius: 0 },
    image_5:          { w: 400, h: 250, objectFit: 'cover', borderRadius: 0 },
    price:            { w: 400, h: 70,  fontSize: 42, fontWeight: '800', color: '#e63946', textTransform: 'none', textAlign: 'left', letterSpacing: -0.02, padding: 8 },
    title:            { w: 500, h: 60,  fontSize: 22, fontWeight: '700', color: '#ffffff', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.04, padding: 8 },
    suburb:           { w: 400, h: 36,  fontSize: 14, fontWeight: '600', color: 'rgba(255,255,255,0.7)', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.1, padding: 8 },
    property_type:    { w: 200, h: 30,  fontSize: 12, fontWeight: '600', color: '#00b4d8', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.1, padding: 6 },
    features:         { w: 320, h: 36,  fontSize: 14, fontWeight: '600', color: 'rgba(255,255,255,0.8)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 8, preview: '4 Bed · 3 Bath · 2 Garage' },
    beds:             { w: 80,  h: 36,  fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'center', letterSpacing: 0, padding: 4, preview: '4' },
    baths:            { w: 80,  h: 36,  fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'center', letterSpacing: 0, padding: 4, preview: '3' },
    garages:          { w: 80,  h: 36,  fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'center', letterSpacing: 0, padding: 4, preview: '2' },
    size_m2:          { w: 120, h: 36,  fontSize: 14, fontWeight: '600', color: 'rgba(255,255,255,0.7)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '450 m²' },
    reference:        { w: 160, h: 28,  fontSize: 12, fontWeight: '600', color: 'rgba(255,255,255,0.55)', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.08, padding: 4, preview: 'REF 12345' },
    address:          { w: 360, h: 32,  fontSize: 13, fontWeight: '500', color: 'rgba(255,255,255,0.7)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '12 Marine Drive' },
    status_badge:     { w: 200, h: 40,  fontSize: 16, fontWeight: '800', color: '#ffffff', textTransform: 'uppercase', textAlign: 'center', letterSpacing: 0.08, padding: 8, bgColor: '#e63946', bgOpacity: 1, borderRadius: 6, preview: 'FOR SALE' },
    agent_name:       { w: 280, h: 40,  fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.06, padding: 6 },
    agent_email:      { w: 300, h: 30,  fontSize: 12, fontWeight: '400', color: 'rgba(255,255,255,0.55)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6 },
    agent_phone:      { w: 220, h: 30,  fontSize: 13, fontWeight: '600', color: 'rgba(255,255,255,0.7)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '082 000 0000' },
    agent_designation:{ w: 260, h: 28,  fontSize: 11, fontWeight: '500', color: '#00b4d8', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.1, padding: 6 },
    agent_avatar:     { w: 80,  h: 80,  objectFit: 'cover', borderRadius: 50 },
    // Agent 2 — the co-listing agent, for building dual-agent templates.
    agent_2_name:        { w: 280, h: 40,  fontSize: 16, fontWeight: '700', color: '#ffffff', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.06, padding: 6, preview: 'CO-AGENT NAME' },
    agent_2_email:       { w: 300, h: 30,  fontSize: 12, fontWeight: '400', color: 'rgba(255,255,255,0.55)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: 'co.agent@agency.co.za' },
    agent_2_phone:       { w: 220, h: 30,  fontSize: 13, fontWeight: '600', color: 'rgba(255,255,255,0.7)', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 6, preview: '082 000 0000' },
    agent_2_designation: { w: 260, h: 28,  fontSize: 11, fontWeight: '500', color: '#00b4d8', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.1, padding: 6, preview: 'PROPERTY PRACTITIONER' },
    agent_2_avatar:      { w: 80,  h: 80,  objectFit: 'cover', borderRadius: 50 },
    agency_name:      { w: 280, h: 32,  fontSize: 15, fontWeight: '800', color: '#ffffff', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.06, padding: 6 },
    website:          { w: 260, h: 26,  fontSize: 11, fontWeight: '700', color: 'rgba(255,255,255,0.4)', textTransform: 'uppercase', textAlign: 'left', letterSpacing: 0.12, padding: 4, preview: 'WWW.AGENCY.CO.ZA' },
    logo:             { w: 180, h: 56,  fontSize: 28, color: '#ffffff', padding: 0 },
    agency_logo:      { w: 200, h: 70,  objectFit: 'contain', borderRadius: 0 },
    custom_text:      { w: 300, h: 50,  fontSize: 20, fontWeight: '700', color: '#ffffff', textTransform: 'none', textAlign: 'left', letterSpacing: 0, padding: 8, text: 'Your text' },
    badge:            { w: 180, h: 44,  fontSize: 16, fontWeight: '800', color: '#ffffff', textTransform: 'uppercase', textAlign: 'center', letterSpacing: 0.08, padding: 8, bgColor: '#00b4d8', bgOpacity: 1, borderRadius: 22, text: 'JUST LISTED' },
    line:             { w: 300, h: 12,  color: '#00b4d8', borderWidth: 3 },
    shape:            { w: 120, h: 120, bg: '#00b4d8', opacity: 1, borderRadius: 50 },
    color_block:      { w: 400, h: 100, bg: '#07111e', opacity: 1, borderRadius: 0 },
    gradient:         { w: 600, h: 300, gradFrom: '#071325', gradTo: 'rgba(7,19,37,0)', gradAngle: 0, opacity: 1 },
    watermark:        { w: 600, h: 120, fontSize: 60, color: '#ffffff', opacity: 0.06, text: '' },
};

const IMAGE_FIELDS = ['image_1','image_2','image_3','image_4','image_5','agent_avatar','agent_2_avatar','agency_logo'];
const NON_TEXT_FIELDS = [...IMAGE_FIELDS, 'logo', 'watermark', 'color_block', 'gradient', 'line', 'shape'];

function builder() {
    const existingTemplate = @json($template ? $template->toArray() : null);
    const lj = existingTemplate?.layout_json || {};

    return {
        name:         existingTemplate?.name || 'My Template',
        elements:     lj.elements || [],
        canvasW:      lj.canvasW || 1200,
        canvasH:      lj.canvasH || 628,
        canvasBg:     lj.canvasBg || '#071325',
        canvasBgMode: lj.canvasBgMode || 'solid',
        canvasBgFrom: lj.canvasBgFrom || '#071325',
        canvasBgTo:   lj.canvasBgTo || '#0b2a4a',
        canvasBgAngle: lj.canvasBgAngle ?? 160,
        canvasPreset: lj.canvasPreset || 'facebook',
        savedId:      existingTemplate?.id || null,
        saving:       false,
        exporting:    false,
        propertyData: @json($propertyData ?? null),
        propertyId:   @json($property?->id ?? null),
        propertyAdUrl: @json($property ? route('corex.properties.ad', $property) : null),
        returnMarketingPropertyId: new URLSearchParams(window.location.search).get('return_marketing') || null,
        selectedIndex: -1,

        _ds: null,
        _dropField: null,

        get useOnPropertyUrl() {
            if (this.propertyAdUrl) return this.propertyAdUrl;
            return @json(route('corex.properties.index'));
        },

        get canvasBackground() {
            if (this.canvasBgMode === 'gradient') {
                return `linear-gradient(${this.canvasBgAngle}deg, ${this.canvasBgFrom}, ${this.canvasBgTo})`;
            }
            return this.canvasBg;
        },

        get canvasScale() {
            const area = document.getElementById('canvas-area');
            if (!area) return 0.5;
            const maxW = (area.offsetWidth  || 800) - 64;
            const maxH = (area.offsetHeight || 600) - 64;
            return Math.min(maxW / this.canvasW, maxH / this.canvasH, 1);
        },

        get fieldGroups() {
            const house = '<svg style="width:12px;height:12px;color:#fff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
            const img   = '<svg style="width:12px;height:12px;color:#fff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>';
            const person= '<svg style="width:12px;height:12px;color:#fff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>';
            const star  = '<svg style="width:12px;height:12px;color:#fff" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>';
            return [
                { key:'image',      label:'Images',          icon: img },
                { key:'property',   label:'Property',        icon: house },
                { key:'agent',      label:'Agent',           icon: person },
                { key:'branding',   label:'Branding',        icon: star },
                { key:'decorative', label:'Decorative',      icon: star },
            ];
        },

        get fields() {
            return [
                { type:'image_1', group:'image', label:'Image 1', iconBg:'#1d4ed8' },
                { type:'image_2', group:'image', label:'Image 2', iconBg:'#1d4ed8' },
                { type:'image_3', group:'image', label:'Image 3', iconBg:'#1d4ed8' },
                { type:'image_4', group:'image', label:'Image 4', iconBg:'#1d4ed8' },
                { type:'image_5', group:'image', label:'Image 5', iconBg:'#1d4ed8' },
                { type:'price',         group:'property',  label:'Price',        iconBg:'#e63946' },
                { type:'title',         group:'property',  label:'Title',        iconBg:'#6d28d9' },
                { type:'suburb',        group:'property',  label:'Suburb',       iconBg:'#047857' },
                { type:'property_type', group:'property',  label:'Type',         iconBg:'#0369a1' },
                { type:'features',      group:'property',  label:'Features',     iconBg:'#b45309' },
                { type:'beds',          group:'property',  label:'Beds',         iconBg:'#0369a1' },
                { type:'baths',         group:'property',  label:'Baths',        iconBg:'#0369a1' },
                { type:'garages',       group:'property',  label:'Garages',      iconBg:'#0369a1' },
                { type:'size_m2',       group:'property',  label:'Size m²',      iconBg:'#065f46' },
                { type:'reference',     group:'property',  label:'Reference',    iconBg:'#475569' },
                { type:'address',       group:'property',  label:'Address',      iconBg:'#475569' },
                { type:'status_badge',  group:'property',  label:'Status Badge', iconBg:'#e63946' },
                { type:'agent_name',        group:'agent', label:'Agent 1 · Name',  iconBg:'#7c3aed' },
                { type:'agent_email',       group:'agent', label:'Agent 1 · Email', iconBg:'#7c3aed' },
                { type:'agent_phone',       group:'agent', label:'Agent 1 · Phone', iconBg:'#7c3aed' },
                { type:'agent_designation', group:'agent', label:'Agent 1 · Designation', iconBg:'#7c3aed' },
                { type:'agent_avatar',      group:'agent', label:'Agent 1 · Avatar',      iconBg:'#7c3aed' },
                { type:'agent_2_name',        group:'agent', label:'Agent 2 · Name',  iconBg:'#9333ea' },
                { type:'agent_2_email',       group:'agent', label:'Agent 2 · Email', iconBg:'#9333ea' },
                { type:'agent_2_phone',       group:'agent', label:'Agent 2 · Phone', iconBg:'#9333ea' },
                { type:'agent_2_designation', group:'agent', label:'Agent 2 · Designation', iconBg:'#9333ea' },
                { type:'agent_2_avatar',      group:'agent', label:'Agent 2 · Avatar',      iconBg:'#9333ea' },
                { type:'logo',          group:'branding',  label:'CoreX / Agency Logo', iconBg:'#00b4d8' },
                { type:'agency_logo',   group:'branding',  label:'Agency Logo (image)', iconBg:'#00b4d8' },
                { type:'agency_name',   group:'branding',  label:'Agency Name', iconBg:'#0b2a4a' },
                { type:'website',       group:'branding',  label:'Website',     iconBg:'#0b2a4a' },
                { type:'watermark',     group:'branding',  label:'Watermark',   iconBg:'#334155' },
                { type:'custom_text',   group:'decorative',label:'Custom Text', iconBg:'#6d28d9' },
                { type:'badge',         group:'decorative',label:'Badge / Pill',iconBg:'#00b4d8' },
                { type:'line',          group:'decorative',label:'Divider Line',iconBg:'#334155' },
                { type:'shape',         group:'decorative',label:'Shape',       iconBg:'#334155' },
                { type:'color_block',   group:'decorative',label:'Colour Block',iconBg:'#334155' },
                { type:'gradient',      group:'decorative',label:'Gradient',    iconBg:'#334155' },
            ];
        },

        isImageField(f)  { return IMAGE_FIELDS.includes(f); },
        isTextField(f)   { return !NON_TEXT_FIELDS.includes(f); },

        // Live preview: real property value > preview override > label
        textValue(el) {
            if (el.field === 'custom_text' || el.field === 'badge') return el.text || el.label;
            const pd = this.propertyData;
            if (pd && pd[el.field] !== undefined && pd[el.field] !== null && pd[el.field] !== '') return pd[el.field];
            return el.preview || el.label;
        },

        livePreviewSrc(el) {
            const pd = this.propertyData;
            if (!pd) return null;
            if (el.field === 'agency_logo') return pd.logo || null;
            return pd[el.field] || null;
        },

        textStyle(el) {
            let s = `font-size:${el.fontSize}px;font-weight:${el.fontWeight};color:${el.color};`
                  + `text-align:${el.textAlign};text-transform:${el.textTransform};`
                  + `letter-spacing:${el.letterSpacing}em;line-height:${el.lineHeight ?? 1.2};padding:${el.padding}px;`;
            const op = el.bgOpacity ?? 0;
            if (op > 0) {
                s += `background:${this.hexToRgba(el.bgColor || '#000000', op)};border-radius:${el.borderRadius || 0}px;`;
                if (el.textAlign === 'center') s += 'justify-content:center;';
                if (el.textAlign === 'right')  s += 'justify-content:flex-end;';
            }
            return s;
        },

        hexToRgba(hex, a) {
            const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
            if (!m) return hex;
            return `rgba(${parseInt(m[1],16)},${parseInt(m[2],16)},${parseInt(m[3],16)},${a})`;
        },

        applyPreset() {
            const p = CANVAS_PRESETS[this.canvasPreset];
            this.canvasW = p.w;
            this.canvasH = p.h;
        },

        makeElement(fieldType, x, y) {
            const fieldDef = this.fields.find(f => f.type === fieldType);
            const d = FIELD_DEFAULTS[fieldType] || {};
            return {
                id:            Date.now() + Math.random(),
                field:         fieldType,
                label:         fieldDef?.label || fieldType,
                x, y,
                w:             d.w || 200,
                h:             d.h || 60,
                zIndex:        this.elements.length + 1,
                rotation:      0,
                fontSize:      d.fontSize || 18,
                fontWeight:    d.fontWeight || '600',
                color:         d.color || '#ffffff',
                textAlign:     d.textAlign || 'left',
                textTransform: d.textTransform || 'none',
                letterSpacing: d.letterSpacing ?? 0,
                lineHeight:    d.lineHeight ?? 1.2,
                padding:       d.padding ?? 8,
                preview:       d.preview || '',
                text:          d.text || '',
                bgColor:       d.bgColor || '#000000',
                bgOpacity:     d.bgOpacity ?? 0,
                objectFit:     d.objectFit || 'cover',
                borderRadius:  d.borderRadius ?? 0,
                bg:            d.bg || '#07111e',
                opacity:       d.opacity ?? 1,
                gradFrom:      d.gradFrom || '#071325',
                gradTo:        d.gradTo || 'rgba(7,19,37,0)',
                gradAngle:     d.gradAngle ?? 180,
                borderWidth:   d.borderWidth ?? 3,
                frameBorderWidth: 0,
                frameBorderColor: '#ffffff',
            };
        },

        addFieldAt(field, x, y) {
            this.elements.push(this.makeElement(field.type, x, y));
            this.selectedIndex = this.elements.length - 1;
        },

        elStyle(el) {
            let s = `left:${el.x}px;top:${el.y}px;width:${el.w}px;height:${el.h}px;z-index:${el.zIndex};overflow:hidden;border-radius:${el.borderRadius || 0}px;`;
            if (el.rotation) s += `transform:rotate(${el.rotation}deg);`;
            if (el.frameBorderWidth) s += `border:${el.frameBorderWidth}px solid ${el.frameBorderColor || '#fff'};`;
            return s;
        },

        mutate(key, value) {
            if (this.selectedIndex < 0) return;
            this.elements[this.selectedIndex] = { ...this.elements[this.selectedIndex], [key]: value };
        },

        deleteSelected() {
            if (this.selectedIndex < 0) return;
            this.elements.splice(this.selectedIndex, 1);
            this.selectedIndex = -1;
        },

        duplicateSelected() {
            if (this.selectedIndex < 0) return;
            const copy = { ...this.elements[this.selectedIndex], id: Date.now() + Math.random(), x: this.elements[this.selectedIndex].x + 16, y: this.elements[this.selectedIndex].y + 16, zIndex: this.elements.length + 1 };
            this.elements.push(copy);
            this.selectedIndex = this.elements.length - 1;
        },

        sidebarDragStart(e, field) {
            this._dropField = field;
            e.dataTransfer.effectAllowed = 'copy';
        },

        canvasDrop(e) {
            if (!this._dropField) return;
            const canvas = document.getElementById('canvas');
            const rect = canvas.getBoundingClientRect();
            const scale = this.canvasScale;
            const x = (e.clientX - rect.left) / scale - (FIELD_DEFAULTS[this._dropField.type]?.w || 200) / 2;
            const y = (e.clientY - rect.top)  / scale - (FIELD_DEFAULTS[this._dropField.type]?.h || 60) / 2;
            this.addFieldAt(this._dropField, Math.max(0, Math.round(x)), Math.max(0, Math.round(y)));
            this._dropField = null;
        },

        dragStart(e, idx) {
            this.selectedIndex = idx;
            const el = this.elements[idx];
            this._ds = { type:'move', idx, startMouseX:e.clientX, startMouseY:e.clientY, startElX:el.x, startElY:el.y, scale:this.canvasScale };
        },

        resizeStart(e, idx) {
            this.selectedIndex = idx;
            const el = this.elements[idx];
            this._ds = { type:'resize', idx, startMouseX:e.clientX, startMouseY:e.clientY, startElW:el.w, startElH:el.h, scale:this.canvasScale };
        },

        dragMove(e) {
            if (!this._ds) return;
            const ds = this._ds;
            const dx = (e.clientX - ds.startMouseX) / ds.scale;
            const dy = (e.clientY - ds.startMouseY) / ds.scale;
            const idx = ds.idx;
            if (ds.type === 'move') {
                this.elements[idx] = { ...this.elements[idx], x: Math.round(Math.max(0, ds.startElX + dx)), y: Math.round(Math.max(0, ds.startElY + dy)) };
            } else {
                this.elements[idx] = { ...this.elements[idx], w: Math.round(Math.max(20, ds.startElW + dx)), h: Math.round(Math.max(20, ds.startElH + dy)) };
            }
        },

        dragEnd() { this._ds = null; },

        async save() {
            if (!this.name.trim()) { this.toast('Enter a template name'); return; }
            this.saving = true;
            try {
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
                    _token: document.querySelector('meta[name="csrf-token"]').content,
                };

                const templateBase = @json(route('corex.ad-templates.store')); // /corex/ad-templates
                let url    = templateBase;
                let method = 'POST';
                if (this.savedId) {
                    url = templateBase + '/' + this.savedId;   // PUT /corex/ad-templates/{id}
                    payload._method = 'PUT';
                }

                const res = await fetch(url, {
                    method,
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': payload._token },
                    body: JSON.stringify(payload),
                });
                const json = await res.json();
                if (!res.ok) throw new Error(json.message || 'Save failed');

                if (!this.savedId) {
                    this.savedId = json.id;
                    const base = @json(route('corex.ad-templates.builder')); // /corex/ad-templates/builder
                    const qs   = this.propertyId ? ('?property=' + this.propertyId) : '';
                    history.replaceState({}, '', base + '/' + json.id + qs);
                }
                this.toast('Template saved!');
            } catch (err) {
                this.toast('Error: ' + (err?.message || 'unknown'));
            } finally {
                this.saving = false;
            }
        },

        async exportForMarketing() {
            if (!this.savedId || !this.returnMarketingPropertyId) return;
            this.exporting = true;
            try {
                const canvas = await html2canvas(document.getElementById('canvas'), {
                    useCORS: true, allowTaint: false, scale: 1, logging: false,
                    backgroundColor: this.canvasBgMode === 'gradient' ? this.canvasBgFrom : (this.canvasBg || '#071325'),
                });
                const dataUrl = canvas.toDataURL('image/png');
                const res = await fetch(@json(route('corex.marketing.upload-template-image')), {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ image: dataUrl }),
                });
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.error || 'Upload failed');
                window.location.href = '/corex/properties/' + this.returnMarketingPropertyId + '/marketing?marketing_img=' + encodeURIComponent(json.url) + '&media_tab=photos';
            } catch (err) {
                this.toast('Export failed: ' + (err?.message || 'unknown'));
                this.exporting = false;
            }
        },

        toast(msg) {
            const el = document.getElementById('toast');
            el.textContent = msg;
            el.classList.add('show');
            setTimeout(() => el.classList.remove('show'), 2500);
        },
    };
}
</script>
</body>
</html>
