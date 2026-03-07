<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Create Ad — {{ $property->title }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800,900&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    @php
        // Strip scheme+host from any legacy absolute URLs so crossorigin="anonymous" works
        // regardless of which hostname the app is accessed from.
        $toRelative = fn($url) => $url ? (parse_url($url, PHP_URL_PATH) ?: $url) : null;
        $images     = $property->allImages();
        $img1       = $toRelative($images[0] ?? null);
        $img2       = $toRelative($images[1] ?? null);
        $img3       = $toRelative($images[2] ?? null);
        $agent      = $property->agent;
        $initial    = strtoupper(substr($agent?->name ?? 'A', 0, 1));
        $agentName  = strtoupper($agent?->name ?? '');
        $agentEmail = $agent?->email ?? '';
        $agentDesig = $agent?->designation ?? 'Property Practitioner';
        $price      = $property->formattedPrice();
        $title      = strtoupper($property->title);
        $suburb     = strtoupper($property->suburb) . ($property->city ? ', ' . strtoupper($property->city) : '');
        $type       = strtoupper(str_replace('_', ' ', $property->property_type));
        $beds       = $property->beds;
        $baths      = $property->baths;
        $garages    = $property->garages;
        $size       = $property->size_m2 ? number_format($property->size_m2) . ' M²' : null;
    @endphp
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Figtree', sans-serif; background: #060f1c; color: #f1f5f9; min-height: 100vh; overflow-x: hidden; }
        [x-cloak] { display: none !important; }

        /* ─── Template card hover ─── */
        .tpl-card { cursor: pointer; border-radius: 18px; border: 1.5px solid rgba(255,255,255,0.08); background: rgba(255,255,255,0.03); overflow: hidden; transition: all 0.18s ease; }
        .tpl-card:hover { border-color: rgba(0,180,216,0.55); background: rgba(255,255,255,0.07); transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.5); }

        /* ─── Platform btn ─── */
        .plat-btn { display: inline-flex; align-items: center; gap: 5px; padding: 6px 13px; border-radius: 9px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1.5px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.04); color: rgba(255,255,255,0.55); transition: all 0.12s; white-space: nowrap; }
        .plat-btn:hover { border-color: rgba(255,255,255,0.25); color: #fff; }
        .plat-btn.active { background: #00b4d8; border-color: #00b4d8; color: #fff; }

        /* ─── Custom template cards ─── */
        .custom-tpl-card { cursor:pointer; border-radius:12px; border:1.5px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.03); overflow:hidden; transition:all 0.18s; display:flex; align-items:center; gap:12px; padding:12px 16px; }
        .custom-tpl-card:hover { border-color:rgba(0,180,216,0.55); background:rgba(255,255,255,0.07); }
        .custom-tpl-thumb { width:100px; height:52px; background:#071325; border-radius:6px; overflow:hidden; position:relative; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:10px; color:rgba(255,255,255,0.25); }
        .custom-tpl-badge { font-size:9px;font-weight:700;background:rgba(0,180,216,0.15);color:#00b4d8;border-radius:4px;padding:2px 6px;letter-spacing:0.06em;text-transform:uppercase; }

        /* ─── Ad canvas shared ─── */
        .ad-root { position: absolute; inset: 0; font-family: 'Figtree', Arial, sans-serif; }
        .ad-img-fit { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ad-placeholder { width: 100%; height: 100%; background: linear-gradient(135deg, #0b2a4a 0%, #143d6e 100%); }
    </style>
</head>
@php
$propertyData = [
    'image_1'           => $img1 ? asset($img1) : null,
    'image_2'           => $img2 ? asset($img2) : null,
    'image_3'           => $img3 ? asset($img3) : null,
    'price'             => $price,
    'title'             => $title,
    'suburb'            => $suburb,
    'property_type'     => $type,
    'features'          => trim(($beds ? $beds . ' Bed' : '') . ($baths ? ' · ' . $baths . ' Bath' : '') . ($garages ? ' · ' . $garages . ' Garage' : ''), ' · '),
    'beds'              => (string)($beds ?? ''),
    'baths'             => (string)($baths ?? ''),
    'garages'           => (string)($garages ?? ''),
    'size_m2'           => $size,
    'agent_name'        => $agentName,
    'agent_email'       => $agentEmail,
    'agent_designation' => $agentDesig,
    'agent_avatar'      => $agent?->avatar_url ?? null,
    'logo'              => 'nexusOS',
    'watermark'         => 'HF COASTAL',
];
@endphp
<body x-data="adApp({{ Js::from($savedTemplates) }}, {{ Js::from($propertyData) }})">

{{-- ═══════════════════════════════════════════════════════
     STEP 1 — TEMPLATE PICKER
═══════════════════════════════════════════════════════════ --}}
<div x-show="step === 'pick'" style="min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:48px 24px;">

    {{-- Back --}}
    <a href="{{ route('corex.properties.index') }}" style="position:absolute;top:22px;left:24px;display:inline-flex;align-items:center;gap:6px;font-size:13px;color:rgba(255,255,255,0.35);text-decoration:none;" onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,0.35)'">
        <svg xmlns="http://www.w3.org/2000/svg" style="width:13px;height:13px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back
    </a>

    {{-- Heading --}}
    <div style="text-align:center; margin-bottom:44px;">
        <div style="font-size:11px;font-weight:700;color:#00b4d8;letter-spacing:0.14em;text-transform:uppercase;margin-bottom:10px;">{{ $suburb }} &middot; {{ $price }}</div>
        <h1 style="font-size:30px;font-weight:900;color:#fff;letter-spacing:-0.025em;">Choose a Template</h1>
        <p style="font-size:14px;color:rgba(255,255,255,0.38);margin-top:8px;">Click a design, then pick your platform and download</p>
    </div>

    {{-- Cards --}}
    <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:24px; max-width:1240px; width:100%;">

        {{-- ── TEMPLATE 1: POWER ─── --}}
        <div class="tpl-card" @click="selectTemplate('power')">
            {{-- Thumbnail — 380×199 container, real template scaled to fit --}}
            <div style="width:100%; height:199px; overflow:hidden; position:relative; background:#071325;">
                <div style="position:absolute;top:0;left:0;width:1200px;height:628px;transform:scale(0.3167);transform-origin:top left;">
                    @include('corex.properties._ad-templates', ['tpl' => 'power', 'baseFontPx' => 16])
                </div>
            </div>
            <div style="padding:18px 20px 22px;">
                <div style="font-size:15px;font-weight:800;color:#fff;margin-bottom:5px;">Power</div>
                <div style="font-size:12px;color:rgba(255,255,255,0.42);line-height:1.6;">Bold 3-photo collage with high-contrast price strip and structured info bar. Maximum impact.</div>
                <div style="margin-top:14px;display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:#00b4d8;">
                    Use Template <svg xmlns="http://www.w3.org/2000/svg" style="width:11px;height:11px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </div>
        </div>

        {{-- ── TEMPLATE 2: LUXE ─── --}}
        <div class="tpl-card" @click="selectTemplate('luxe')">
            <div style="width:100%; height:199px; overflow:hidden; position:relative; background:#071325;">
                <div style="position:absolute;top:0;left:0;width:1200px;height:628px;transform:scale(0.3167);transform-origin:top left;">
                    @include('corex.properties._ad-templates', ['tpl' => 'luxe', 'baseFontPx' => 16])
                </div>
            </div>
            <div style="padding:18px 20px 22px;">
                <div style="font-size:15px;font-weight:800;color:#fff;margin-bottom:5px;">Luxe</div>
                <div style="font-size:12px;color:rgba(255,255,255,0.42);line-height:1.6;">Full-bleed hero image with cinematic gradient overlay. Sophisticated and editorial feel.</div>
                <div style="margin-top:14px;display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:#00b4d8;">
                    Use Template <svg xmlns="http://www.w3.org/2000/svg" style="width:11px;height:11px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </div>
        </div>

        {{-- ── TEMPLATE 3: SPLIT ─── --}}
        <div class="tpl-card" @click="selectTemplate('split')">
            <div style="width:100%; height:199px; overflow:hidden; position:relative; background:#071325;">
                <div style="position:absolute;top:0;left:0;width:1200px;height:628px;transform:scale(0.3167);transform-origin:top left;">
                    @include('corex.properties._ad-templates', ['tpl' => 'split', 'baseFontPx' => 16])
                </div>
            </div>
            <div style="padding:18px 20px 22px;">
                <div style="font-size:15px;font-weight:800;color:#fff;margin-bottom:5px;">Split</div>
                <div style="font-size:12px;color:rgba(255,255,255,0.42);line-height:1.6;">Dark info panel left, dramatic full-height images right. Clean, modern, architectural.</div>
                <div style="margin-top:14px;display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:#00b4d8;">
                    Use Template <svg xmlns="http://www.w3.org/2000/svg" style="width:11px;height:11px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </div>
        </div>

    </div>

    {{-- ── Custom saved templates ── --}}
    <template x-if="savedTemplates.length > 0">
        <div style="max-width:1240px;width:100%;margin-top:40px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <div style="font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:rgba(255,255,255,0.3);">Saved Custom Templates</div>
                @if($canManageTemplates)
                <a href="{{ route('corex.ad-templates.builder') }}" style="font-size:12px;font-weight:600;color:#00b4d8;text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                    <svg style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    New Template
                </a>
                @endif
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:10px;">
                <template x-for="tpl in savedTemplates" :key="tpl.id">
                    <div class="custom-tpl-card" @click="selectCustomTemplate(tpl)">
                        <div class="custom-tpl-thumb">
                            <span x-text="tpl.name.charAt(0).toUpperCase()"></span>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:14px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="tpl.name"></div>
                            <div style="font-size:11px;color:rgba(255,255,255,0.35);margin-top:3px;" x-text="(tpl.layout_json?.elements?.length || 0) + ' elements · ' + (tpl.layout_json?.canvasW || 1200) + '×' + (tpl.layout_json?.canvasH || 628)"></div>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                            <template x-if="tpl.is_global">
                                <span class="custom-tpl-badge">Shared</span>
                            </template>
                            @if($canManageTemplates)
                            <a :href="`/nexus/ad-templates/builder/${tpl.id}`" style="font-size:10px;color:rgba(255,255,255,0.3);text-decoration:none;" @click.stop>Edit</a>
                            @endif
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>

    @if($canManageTemplates)
    <template x-if="savedTemplates.length === 0">
        <div style="max-width:1240px;width:100%;margin-top:32px;text-align:center;">
            <a href="{{ route('corex.ad-templates.builder') }}" style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:600;color:#00b4d8;border:1.5px dashed rgba(0,180,216,0.35);text-decoration:none;transition:all 0.12s;" onmouseover="this.style.borderColor='#00b4d8'" onmouseout="this.style.borderColor='rgba(0,180,216,0.35)'">
                <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Build a custom template
            </a>
        </div>
    </template>
    @endif

</div>

{{-- ═══════════════════════════════════════════════════════
     STEP 2 — GENERATOR
═══════════════════════════════════════════════════════════ --}}
<div x-show="step === 'generate'" x-cloak style="display:flex; flex-direction:column; min-height:100vh;">

    {{-- Toolbar --}}
    <div style="position:sticky;top:0;z-index:100;background:rgba(6,15,28,0.98);border-bottom:1px solid rgba(255,255,255,0.07);padding:10px 18px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">

        <button @click="step='pick'" style="display:inline-flex;align-items:center;gap:4px;color:rgba(255,255,255,0.45);font-size:12px;background:none;border:1.5px solid rgba(255,255,255,0.1);border-radius:8px;cursor:pointer;padding:5px 10px;font-family:inherit;" onmouseover="this.style.color='#fff';this.style.borderColor='rgba(255,255,255,0.3)'" onmouseout="this.style.color='rgba(255,255,255,0.45)';this.style.borderColor='rgba(255,255,255,0.1)'">
            <svg xmlns="http://www.w3.org/2000/svg" style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Templates
        </button>

        <div style="width:1px;height:18px;background:rgba(255,255,255,0.1);"></div>

        {{-- Template badge --}}
        <span x-text="template?.charAt(0).toUpperCase()+template?.slice(1)" style="font-size:11px;font-weight:700;color:rgba(255,255,255,0.35);text-transform:uppercase;letter-spacing:0.08em;background:rgba(255,255,255,0.06);padding:4px 9px;border-radius:6px;"></span>

        <div style="width:1px;height:18px;background:rgba(255,255,255,0.1);"></div>

        {{-- Platform buttons --}}
        <button class="plat-btn" :class="{active: platform==='facebook'}"  @click="platform='facebook'; onGenerate()">
            <svg style="width:13px;height:13px;" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
            Facebook <span style="opacity:.6;font-size:10px;">1200×628</span>
        </button>
        <button class="plat-btn" :class="{active: platform==='instagram'}" @click="platform='instagram'; onGenerate()">
            <svg style="width:13px;height:13px;" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
            Instagram <span style="opacity:.6;font-size:10px;">1080×1080</span>
        </button>
        <button class="plat-btn" :class="{active: platform==='story'}"     @click="platform='story'; onGenerate()">
            <svg style="width:13px;height:13px;" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
            Story <span style="opacity:.6;font-size:10px;">1080×1920</span>
        </button>
        <button class="plat-btn" :class="{active: platform==='whatsapp'}"  @click="platform='whatsapp'; onGenerate()">
            <svg style="width:13px;height:13px;" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
            WhatsApp <span style="opacity:.6;font-size:10px;">900×900</span>
        </button>

        {{-- Download --}}
        <button @click="download()" :disabled="generating || exporting"
                style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:8px 20px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;background:#e63946;border:none;color:#fff;font-family:inherit;transition:opacity 0.12s;"
                onmouseover="if(!this.disabled)this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
            <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            <span x-text="generating ? 'Generating…' : 'Download PNG'"></span>
        </button>

        {{-- Use for Marketing (only shown when coming from the hub) --}}
        <template x-if="returnMarketing">
            <button @click="exportForMarketing()" :disabled="generating || exporting"
                    style="display:inline-flex;align-items:center;gap:6px;padding:8px 20px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;background:#00b4d8;border:none;color:#fff;font-family:inherit;transition:opacity 0.12s;"
                    onmouseover="if(!this.disabled)this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                <span x-text="exporting ? 'Sending…' : 'Use for Marketing'"></span>
            </button>
        </template>
    </div>

    {{-- Preview --}}
    <div style="flex:1; display:flex; align-items:center; justify-content:center; padding:32px 20px 48px;">
        <div :style="'overflow:hidden;border-radius:4px;box-shadow:0 28px 90px rgba(0,0,0,0.75);flex-shrink:0;width:'+previewW+'px;height:'+previewH+'px;'">
            <div id="ad-scale-wrapper" :style="'transform:scale('+scale+');transform-origin:top left;width:'+cfg.w+'px;height:'+cfg.h+'px;'">

                {{-- AD CANVAS — font-size is the scaling unit --}}
                <div id="ad-canvas" :style="'width:'+cfg.w+'px;height:'+cfg.h+'px;position:relative;overflow:hidden;font-size:'+cfg.baseFontPx+'px;font-family:Figtree,Arial,sans-serif;background:#071325;'">

                    {{-- Template: POWER --}}
                    <div x-show="template==='power'" style="position:absolute;inset:0;">
                        @include('corex.properties._ad-templates', ['tpl' => 'power', 'baseFontPx' => null])
                    </div>

                    {{-- Template: LUXE --}}
                    <div x-show="template==='luxe'" style="position:absolute;inset:0;">
                        @include('corex.properties._ad-templates', ['tpl' => 'luxe', 'baseFontPx' => null])
                    </div>

                    {{-- Template: SPLIT --}}
                    <div x-show="template==='split'" style="position:absolute;inset:0;">
                        @include('corex.properties._ad-templates', ['tpl' => 'split', 'baseFontPx' => null])
                    </div>

                    {{-- Template: CUSTOM (rendered via JS) --}}
                    <div id="custom-canvas-root" x-show="template==='custom'" style="position:absolute;inset:0;"></div>

                </div>
            </div>
        </div>
    </div>

</div>

<script>
function adApp(savedTemplates, propertyData) {
    const platforms = {
        facebook:  { w:1200, h:628,  baseFontPx:16, label:'Facebook'  },
        instagram: { w:1080, h:1080, baseFontPx:28, label:'Instagram' },
        story:     { w:1080, h:1920, baseFontPx:50, label:'Story'     },
        whatsapp:  { w:900,  h:900,  baseFontPx:23, label:'WhatsApp'  },
    };

    return {
        step: 'pick',
        template: null,
        platform: 'facebook',
        generating: false,
        exporting: false,
        returnMarketing: new URLSearchParams(window.location.search).get('return_marketing') || null,
        platforms,
        savedTemplates: savedTemplates || [],
        propertyData:   propertyData   || {},
        _customLayout:  null,   // layout_json of the active custom template

        get cfg() {
            if (this.template === 'custom' && this._customLayout) {
                const preset = this._customLayout.canvasPreset || 'facebook';
                return platforms[preset] || { w: this._customLayout.canvasW || 1200, h: this._customLayout.canvasH || 628, baseFontPx: 16, label: 'Custom' };
            }
            return platforms[this.platform];
        },
        get scale() {
            const maxW = Math.min(window.innerWidth - 64, 1100);
            const maxH = window.innerHeight - 130;
            return Math.min(maxW / this.cfg.w, maxH / this.cfg.h, 1);
        },
        get previewW() { return Math.round(this.cfg.w * this.scale); },
        get previewH() { return Math.round(this.cfg.h * this.scale); },

        selectTemplate(t) {
            this.template       = t;
            this._customLayout  = null;
            this.step           = 'generate';
        },

        selectCustomTemplate(tpl) {
            this.template      = 'custom';
            this._customLayout = tpl.layout_json;
            this.step          = 'generate';
            this.$nextTick(() => this.renderCustomTemplate());
        },

        // Called whenever platform or custom layout changes in generate step
        onGenerate() {
            if (this.template === 'custom') this.$nextTick(() => this.renderCustomTemplate());
        },

        renderCustomTemplate() {
            const root     = document.getElementById('custom-canvas-root');
            if (!root || !this._customLayout) return;
            root.innerHTML = '';

            const layout   = this._customLayout;
            const prop     = this.propertyData;
            const els      = layout.elements || [];

            els.forEach(el => {
                const div = document.createElement('div');
                div.style.cssText = `position:absolute;left:${el.x}px;top:${el.y}px;width:${el.w}px;height:${el.h}px;z-index:${el.zIndex || 1};overflow:hidden;border-radius:${el.borderRadius || 0}px;`;

                const field = el.field;

                if (field.startsWith('image_') || field === 'agent_avatar') {
                    const src = prop[field];
                    if (src) {
                        const img = document.createElement('img');
                        img.src              = src;
                        img.crossOrigin      = 'anonymous';
                        img.style.cssText    = `width:100%;height:100%;object-fit:${el.objectFit || 'cover'};display:block;`;
                        div.appendChild(img);
                    } else {
                        div.style.background = 'linear-gradient(135deg,#0b2a4a,#143d6e)';
                        div.style.display    = 'flex';
                        div.style.alignItems = 'center';
                        div.style.justifyContent = 'center';
                        div.style.color      = 'rgba(255,255,255,0.2)';
                        div.style.fontSize   = '11px';
                        div.textContent      = el.label;
                    }
                } else if (field === 'color_block') {
                    div.style.background = el.bg || '#07111e';
                    div.style.opacity    = el.opacity ?? 1;
                } else if (field === 'logo') {
                    div.style.display    = 'flex';
                    div.style.alignItems = 'center';
                    div.style.fontFamily = "'Figtree',Arial,sans-serif";
                    div.style.fontWeight = '900';
                    div.style.fontSize   = (el.fontSize || 28) + 'px';
                    div.style.color      = el.color || '#fff';
                    div.style.padding    = (el.padding || 0) + 'px';
                    div.innerHTML        = 'nexus<span style="color:#33c4e0">os</span>';
                } else if (field === 'watermark') {
                    div.style.display        = 'flex';
                    div.style.alignItems     = 'center';
                    div.style.justifyContent = 'center';
                    div.style.fontFamily     = "'Figtree',Arial,sans-serif";
                    div.style.fontWeight     = '900';
                    div.style.fontSize       = (el.fontSize || 60) + 'px';
                    div.style.color          = el.color || '#fff';
                    div.style.opacity        = el.opacity ?? 0.06;
                    div.style.letterSpacing  = '0.06em';
                    div.style.textTransform  = 'uppercase';
                    div.textContent          = 'HF COASTAL';
                } else {
                    // Text field
                    const value = prop[field] !== undefined && prop[field] !== null && prop[field] !== ''
                        ? prop[field]
                        : (el.preview || el.label);
                    div.style.display        = 'flex';
                    div.style.alignItems     = 'center';
                    div.style.overflow       = 'hidden';
                    div.style.fontFamily     = "'Figtree',Arial,sans-serif";
                    div.style.fontSize       = (el.fontSize || 18) + 'px';
                    div.style.fontWeight     = el.fontWeight || '600';
                    div.style.color          = el.color || '#fff';
                    div.style.textAlign      = el.textAlign || 'left';
                    div.style.textTransform  = el.textTransform || 'none';
                    div.style.letterSpacing  = (el.letterSpacing || 0) + 'em';
                    div.style.padding        = (el.padding || 8) + 'px';
                    div.textContent          = value;
                }

                root.appendChild(div);
            });
        },

        async exportForMarketing() {
            if (!this.returnMarketing) return;
            this.exporting = true;
            try {
                const wrapper = document.getElementById('ad-scale-wrapper');
                const canvas  = document.getElementById('ad-canvas');
                const cfg     = this.cfg;
                const bgColor = (this.template === 'custom' && this._customLayout?.canvasBg) ? this._customLayout.canvasBg : '#071325';

                if (this.template === 'custom' && this._customLayout) {
                    canvas.style.width  = (this._customLayout.canvasW || 1200) + 'px';
                    canvas.style.height = (this._customLayout.canvasH || 628) + 'px';
                }

                const saved = wrapper.style.transform;
                wrapper.style.transform = 'none';
                await new Promise(r => setTimeout(r, 80));

                const c = await html2canvas(canvas, {
                    width: cfg.w, height: cfg.h, scale: 2,
                    useCORS: true, allowTaint: false,
                    backgroundColor: bgColor, logging: false,
                });

                wrapper.style.transform = saved;

                const dataUrl = c.toDataURL('image/png');
                const res = await fetch('{{ route('corex.marketing.upload-template-image') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ image: dataUrl }),
                });
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.error || 'Upload failed');
                window.location.href = `/corex/properties/${this.returnMarketing}/marketing?marketing_img=${encodeURIComponent(json.url)}&media_tab=photos`;
            } catch(err) {
                alert('Export failed: ' + (err?.message || 'unknown'));
                this.exporting = false;
            }
        },

        async download() {
            this.generating = true;
            try {
                const wrapper = document.getElementById('ad-scale-wrapper');
                const canvas  = document.getElementById('ad-canvas');
                const cfg     = this.cfg;
                const bgColor = (this.template === 'custom' && this._customLayout?.canvasBg) ? this._customLayout.canvasBg : '#071325';

                // Override canvas dimensions for custom template
                if (this.template === 'custom' && this._customLayout) {
                    canvas.style.width  = (this._customLayout.canvasW || 1200) + 'px';
                    canvas.style.height = (this._customLayout.canvasH || 628) + 'px';
                }

                const saved = wrapper.style.transform;
                wrapper.style.transform = 'none';
                await new Promise(r => setTimeout(r, 80));

                const c = await html2canvas(canvas, {
                    width:           cfg.w,
                    height:          cfg.h,
                    scale:           2,
                    useCORS:         true,
                    allowTaint:      false,
                    backgroundColor: bgColor,
                    logging:         false,
                });

                wrapper.style.transform = saved;

                const link     = document.createElement('a');
                link.download  = `hfc-ad-{{ $property->id }}-${this.template}-${this.platform}.png`;
                link.href      = c.toDataURL('image/png');
                link.click();
            } catch(err) {
                alert('Download failed: ' + (err?.message || 'unknown error'));
            } finally {
                this.generating = false;
            }
        }
    };
}
</script>
</body>
</html>
