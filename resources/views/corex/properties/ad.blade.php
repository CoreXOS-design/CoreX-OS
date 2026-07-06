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
    {{-- Agency brand tokens (UI_DESIGN_SYSTEM.md §1.4) — this standalone page does
         not load corex.css, so we declare the brand vars here for a branded header
         + brand-consistent accents. Falls back to the safe navy/sky defaults. --}}
    @php
        $_brandAgency = $property->agency
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
    {{-- Chrome palette — dark values equal the original look (dark is unchanged);
         light values added so the editor is usable in the light theme. The ad
         canvas/artwork keeps its own colours; only the surrounding chrome themes. --}}
    <style>
        :root {
            --chrome-bg:#eef1f7; --chrome-surface:#ffffff; --chrome-surface-2:#f2f4f9; --chrome-input:#ffffff;
            --chrome-border:rgba(0,0,0,0.10); --chrome-border-2:rgba(0,0,0,0.06);
            --chrome-text:#111827; --chrome-text-soft:rgba(17,24,39,0.62); --chrome-text-mute:rgba(17,24,39,0.42);
            --chrome-hover:rgba(0,0,0,0.05); --workspace:#e7ebf2;
        }
        html.dark {
            --chrome-bg:#060f1c; --chrome-surface:#07111e; --chrome-surface-2:rgba(255,255,255,0.05); --chrome-input:#0b1726;
            --chrome-border:rgba(255,255,255,0.10); --chrome-border-2:rgba(255,255,255,0.06);
            --chrome-text:#f1f5f9; --chrome-text-soft:rgba(255,255,255,0.6); --chrome-text-mute:rgba(255,255,255,0.4);
            --chrome-hover:rgba(255,255,255,0.07); --workspace:#040c15;
        }
    </style>
    @php
        // Single source of truth for the data injected into every template.
        $propertyData = $property->adData();
        // Full property photo gallery for the "change photo" picker (generator step).
        // adSafeImageUrl → host-relative "/storage/…" so a swapped image stays
        // same-origin and html2canvas can still read it into the exported PNG.
        $galleryImages = array_values(array_filter(array_map(
            fn ($u) => \App\Models\Property::adSafeImageUrl($u),
            $property->allImages(),
        )));
        $img1 = $propertyData['image_1'] ?? null;
        $img2 = $propertyData['image_2'] ?? null;
        $img3 = $propertyData['image_3'] ?? null;
        $img4 = $propertyData['image_4'] ?? null;
        $img5 = $propertyData['image_5'] ?? null;
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

        // Branding — branch logo → agency logo → CoreX wordmark fallback (handled in partial).
        $logoPath   = $property->branch?->logo_path ?: $property->agency?->logo_path;
        $logoUrl    = $logoPath ? asset('storage/' . $logoPath) : null;
        $agencyName = strtoupper($property->agency?->name ?? '');
        $website    = strtoupper($property->agency?->website_url ?? '');
        $statusBadge = match (true) {
            in_array($property->status, ['sold', 'transferred'], true)    => 'SOLD',
            in_array($property->status, ['under_offer', 'pending'], true) => 'UNDER OFFER',
            ($property->listing_type === 'rental' || $property->listing_type === 'to_let') => 'TO LET',
            default                                                       => 'FOR SALE',
        };

        // Pre-built catalogue — one row drives both the picker cards and the generator blocks.
        // `tags` widens search so "for sale", "sold", "rent" etc. find the right design.
        $prebuilt = [
            ['key' => 'power',          'name' => 'Power',          'desc' => 'Bold 3-photo collage with high-contrast price strip and structured info bar.', 'tags' => 'for sale listing collage bold'],
            ['key' => 'luxe',           'name' => 'Luxe',           'desc' => 'Full-bleed hero with cinematic gradient overlay. Sophisticated, editorial feel.', 'tags' => 'for sale luxury premium hero'],
            ['key' => 'split',          'name' => 'Split',          'desc' => 'Dark info panel left, dramatic full-height images right. Clean, architectural.', 'tags' => 'for sale modern clean'],
            ['key' => 'just_listed',    'name' => 'Just Listed',    'desc' => 'Announcement ribbon over a single hero. Maximum "new to market" impact.', 'tags' => 'for sale new to market announcement'],
            ['key' => 'open_house',     'name' => 'Open House',     'desc' => 'Viewing call-out block over the hero — invite buyers to book a viewing.', 'tags' => 'for sale viewing show day event'],
            ['key' => 'editorial',      'name' => 'Editorial',      'desc' => 'Minimalist luxury on a light canvas. Large hero, generous type, quiet confidence.', 'tags' => 'for sale luxury minimal light'],
            ['key' => 'feature_grid',   'name' => 'Feature Grid',   'desc' => 'Four-photo mosaic showcasing every room. Great for feature-rich homes.', 'tags' => 'for sale gallery mosaic rooms'],
            ['key' => 'price_spotlight','name' => 'Price Spotlight','desc' => 'Oversized price with a NEW PRICE tag. Built to stop the scroll on value.', 'tags' => 'for sale price reduced new price'],
            ['key' => 'coming_soon',    'name' => 'Coming Soon',    'desc' => 'Teaser with a dimmed hero and a COMING SOON banner. Build anticipation.', 'tags' => 'teaser coming soon pre-launch'],
            ['key' => 'sold',           'name' => 'Sold / Under Offer','desc' => 'Celebration stamp over the hero. Proof of performance for your pipeline.', 'tags' => 'sold under offer closed success'],
            ['key' => 'for_rent',       'name' => 'For Rent',       'desc' => 'Rental-focused layout with per-month price emphasis and quick features.', 'tags' => 'to let rental rent lease'],
            ['key' => 'agent_spotlight','name' => 'Agent Spotlight','desc' => 'Your headshot and name front and centre over the hero. Personal brand builder.', 'tags' => 'agent personal brand profile'],
            ['key' => 'showcase',       'name' => 'Showcase',       'desc' => 'Five-photo filmstrip carousel-style strip. Tell the whole story in one frame.', 'tags' => 'for sale gallery carousel filmstrip'],
        ];

        // Thumbnail scale to fit a 380-ish wide × 199 tall card from a 1200×628 source.
        $thumbScale = 0.3167;
    @endphp
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        {{-- Fixed-height flex column so the editor never grows past the viewport
             (UI_DESIGN_SYSTEM.md §6 — content must stay within the screen). --}}
        html, body { height: 100%; }
        body { font-family: 'Figtree', sans-serif; background: var(--chrome-bg); color: var(--chrome-text); height: 100vh; display: flex; flex-direction: column; overflow: hidden; }
        #ad-body { flex: 1; min-height: 0; display: flex; flex-direction: column; }
        [x-cloak] { display: none !important; }
        .tpl-card { cursor: pointer; border-radius: 18px; border: 1.5px solid var(--chrome-border); background: var(--chrome-surface); overflow: hidden; transition: all 0.18s ease; }
        .tpl-card:hover { border-color: var(--brand-button,#00b4d8); background: var(--chrome-surface); transform: translateY(-4px); box-shadow: 0 12px 40px rgba(0,0,0,0.18); }
        .plat-btn { display: inline-flex; align-items: center; gap: 5px; padding: 6px 13px; border-radius: 9px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1.5px solid var(--chrome-border); background: var(--chrome-surface-2); color: var(--chrome-text); transition: all 0.12s; white-space: nowrap; }
        .plat-btn .plat-size { font-size: 10px; color: var(--chrome-text-mute); }
        .plat-btn:hover { border-color: var(--brand-button,#00b4d8); }
        .plat-btn.active { background: var(--brand-button,#00b4d8); border-color: var(--brand-button,#00b4d8); color: #fff; }
        .plat-btn.active .plat-size { color: rgba(255,255,255,0.85); }
        .agent-pill { display:inline-flex; align-items:center; padding:5px 11px; border-radius:6px; font-size:12px; font-weight:600; cursor:pointer; border:none; background:transparent; color:var(--chrome-text-soft); font-family:inherit; max-width:120px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; transition:all 0.12s; }
        .agent-pill:hover { color:var(--chrome-text); background:var(--chrome-hover); }
        .agent-pill.active { background:var(--brand-button,#00b4d8); color:#fff; }
        .custom-tpl-card { cursor:pointer; border-radius:12px; border:1.5px solid var(--chrome-border); background:var(--chrome-surface); overflow:hidden; transition:all 0.18s; display:flex; align-items:center; gap:12px; padding:12px 16px; }
        .custom-tpl-card:hover { border-color:var(--brand-button,#00b4d8); }
        .custom-tpl-thumb { width:100px; height:52px; background:#071325; border-radius:6px; overflow:hidden; position:relative; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:18px; font-weight:800; color:rgba(255,255,255,0.45); }
        .custom-tpl-badge { font-size:9px;font-weight:700;background:color-mix(in srgb, var(--brand-button,#00b4d8) 16%, transparent);color:var(--brand-button,#00b4d8);border-radius:4px;padding:2px 6px;letter-spacing:0.06em;text-transform:uppercase; }
        .ad-root { position: absolute; inset: 0; font-family: 'Figtree', Arial, sans-serif; }
        .ad-img-fit { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ad-placeholder { width: 100%; height: 100%; background: linear-gradient(135deg, #0b2a4a 0%, #143d6e 100%); }

        {{-- ── "Change photo" overlay (generator step) ────────────────────────────
             These controls live OUTSIDE #ad-canvas (in #ad-img-tools, over the
             preview area), so html2canvas — which captures only #ad-canvas — can
             never render them into the downloaded PNG. One region per property
             image; hovering darkens it and reveals a centred "Change photo" pill,
             and a small camera badge marks every editable image up-front. --}}
        #ad-img-tools { position: absolute; inset: 0; pointer-events: none; z-index: 40; }
        .ad-img-region { position: absolute; pointer-events: auto; cursor: pointer; border-radius: 3px; outline: 2px solid transparent; transition: outline-color .12s; }
        .ad-img-region:hover { outline-color: var(--brand-button,#00b4d8); }
        .ad-img-region-veil { position: absolute; inset: 0; background: rgba(4,12,21,0); transition: background .12s; display: flex; align-items: center; justify-content: center; }
        .ad-img-region:hover .ad-img-region-veil { background: rgba(4,12,21,0.46); }
        .ad-img-badge { position: absolute; top: 7px; left: 7px; width: 26px; height: 26px; border-radius: 50%; background: var(--brand-button,#00b4d8); color: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(0,0,0,0.4); border: 1.5px solid rgba(255,255,255,0.85); }
        .ad-img-badge svg { width: 14px; height: 14px; }
        .ad-img-cta { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 700; font-family: 'Figtree', sans-serif; color: #fff; background: var(--brand-button,#00b4d8); box-shadow: 0 6px 20px rgba(0,0,0,0.5); opacity: 0; transform: translateY(4px); transition: opacity .12s, transform .12s; white-space: nowrap; }
        .ad-img-region:hover .ad-img-cta { opacity: 1; transform: translateY(0); }
        .ad-img-cta svg { width: 13px; height: 13px; }

        {{-- Gallery picker modal --}}
        .ad-modal-overlay { position: fixed; inset: 0; z-index: 200; background: rgba(3,8,14,0.72); backdrop-filter: blur(3px); display: flex; align-items: center; justify-content: center; padding: 24px; }
        .ad-modal { background: var(--chrome-surface); border: 1.5px solid var(--chrome-border); border-radius: 16px; width: 100%; max-width: 860px; max-height: 86vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 30px 90px rgba(0,0,0,0.6); }
        .ad-gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
        .ad-gallery-thumb { position: relative; aspect-ratio: 4/3; border-radius: 9px; overflow: hidden; cursor: pointer; border: 2.5px solid transparent; background: var(--chrome-surface-2); transition: border-color .12s, transform .12s; }
        .ad-gallery-thumb:hover { transform: translateY(-2px); border-color: var(--brand-button,#00b4d8); }
        .ad-gallery-thumb.current { border-color: var(--brand-button,#00b4d8); }
        .ad-gallery-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ad-gallery-thumb .ad-current-tag { position: absolute; top: 6px; left: 6px; font-size: 9px; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; color: #fff; background: var(--brand-button,#00b4d8); padding: 3px 7px; border-radius: 5px; }
    </style>
</head>
<body x-data="adApp({{ Js::from($savedTemplates) }}, {{ Js::from($propertyData) }}, {{ Js::from(['listing' => $listingAgentCard, 'co' => $coAgentCard]) }}, {{ Js::from($galleryImages) }})">

{{-- ═══ BRANDED HEADER (UI_DESIGN_SYSTEM.md §2.4 Pattern A) — full width ═══ --}}
<header style="background:var(--brand-default,#0b2a4a);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;gap:14px;min-width:0;">
        <a href="{{ route('corex.properties.index') }}" title="Back to properties"
           style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;background:rgba(255,255,255,0.12);color:#fff;text-decoration:none;flex-shrink:0;transition:background 0.15s;"
           onmouseover="this.style.background='rgba(255,255,255,0.22)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
            <svg style="width:16px;height:16px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        </a>
        <div style="min-width:0;">
            <h1 style="font-size:20px;font-weight:700;color:#fff;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">Create Ad</h1>
            <p style="font-size:13px;color:rgba(255,255,255,0.6);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $property->title }} &middot; {{ $suburb }} &middot; {{ $price }}</p>
        </div>
    </div>
    <a href="{{ route('corex.properties.show', $property) }}"
       style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-size:13px;font-weight:600;color:#fff;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.18);text-decoration:none;transition:background 0.15s;flex-shrink:0;"
       onmouseover="this.style.background='rgba(255,255,255,0.22)'" onmouseout="this.style.background='rgba(255,255,255,0.12)'">
        View property
    </a>
</header>

<div id="ad-body">

{{-- ═══ STEP 1 — TEMPLATE PICKER ═══ --}}
<div x-show="step === 'pick'" style="flex:1; min-height:0; overflow-y:auto; display:flex; flex-direction:column; align-items:center; padding:32px;">

    <div style="text-align:center; margin-bottom:28px;">
        <div style="font-size:11px;font-weight:700;color:var(--brand-button,#00b4d8);letter-spacing:0.14em;text-transform:uppercase;margin-bottom:10px;">{{ $suburb }} &middot; {{ $price }}</div>
        <h1 style="font-size:30px;font-weight:900;color:var(--chrome-text);letter-spacing:-0.025em;">Choose a Template</h1>
        <p style="font-size:14px;color:var(--chrome-text-mute);margin-top:8px;">Click a design, then pick your platform and download</p>
    </div>

    {{-- Search / filter --}}
    <div style="max-width:1760px;width:100%;margin-bottom:22px;display:flex;justify-content:center;">
        <div style="position:relative;width:100%;max-width:420px;">
            <svg style="position:absolute;left:14px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:var(--chrome-text-mute);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path stroke-linecap="round" d="m21 21-4.3-4.3"/></svg>
            <input type="text" x-model="searchQuery" placeholder="Search templates — e.g. for sale, sold, rent…"
                   style="width:100%;background:var(--chrome-surface);border:1.5px solid var(--chrome-border);border-radius:11px;color:var(--chrome-text);font-size:13px;font-family:inherit;padding:11px 36px 11px 38px;outline:none;transition:border-color 0.12s;"
                   onfocus="this.style.borderColor='var(--brand-button,#00b4d8)'" onblur="this.style.borderColor='var(--chrome-border)'">
            <button x-show="searchQuery" @click="searchQuery=''" x-cloak
                    style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--chrome-text-mute);font-size:16px;line-height:1;padding:4px;" title="Clear">&times;</button>
        </div>
    </div>

    {{-- ═══ FEATURED — PRINTABLE BROCHURE (always first · always A4 · true PDF) ═══ --}}
    <div style="max-width:1760px;width:100%;margin-bottom:26px;">
        <div class="tpl-card" style="display:flex;gap:24px;align-items:stretch;padding:22px;cursor:default;border-color:color-mix(in srgb, var(--brand-button,#00b4d8) 35%, transparent);">
            {{-- A4 portrait preview --}}
            <a href="{{ route('corex.properties.brochure', $property) }}" target="_blank" rel="noopener"
               style="flex-shrink:0;width:210px;text-decoration:none;">
                <div style="width:210px;aspect-ratio:794/1123;overflow:hidden;border-radius:8px;background:#fff;box-shadow:0 8px 28px rgba(0,0,0,0.45);position:relative;">
                    <div style="position:absolute;top:0;left:0;width:794px;height:1123px;transform-origin:top left;transform:scale(0.2645);">
                        @include('corex.properties._brochure', ['b' => $brochureData])
                    </div>
                </div>
            </a>
            {{-- Copy + actions --}}
            <div style="flex:1;min-width:0;display:flex;flex-direction:column;justify-content:center;">
                <div style="display:inline-flex;align-items:center;gap:8px;margin-bottom:10px;">
                    <span style="font-size:10px;font-weight:800;letter-spacing:0.12em;text-transform:uppercase;color:#04221a;background:#19c37d;padding:4px 10px;border-radius:5px;">Printable · PDF</span>
                    <span style="font-size:10px;font-weight:800;letter-spacing:0.12em;text-transform:uppercase;color:var(--chrome-text-soft);background:var(--chrome-surface-2);padding:4px 10px;border-radius:5px;">A4</span>
                </div>
                <div style="font-size:22px;font-weight:900;color:var(--chrome-text);letter-spacing:-0.02em;">Printable Brochure</div>
                <p style="font-size:13px;color:var(--chrome-text-soft);line-height:1.6;margin-top:8px;max-width:560px;">
                    A full A4 property data sheet — photos, price, features, rates &amp; levy, the full description, your agent
                    card and a scan-to-view QR code. Rendered as a true print-ready PDF, not a social graphic.
                </p>
                {{-- Co-listing choice for the brochure footer — only when co-listed. --}}
                <template x-if="hasCoAgent">
                    <div style="display:flex;align-items:center;gap:8px;margin-top:16px;flex-wrap:wrap;">
                        <span style="font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:var(--chrome-text-mute);">Agent</span>
                        <div style="display:inline-flex;align-items:center;gap:4px;background:var(--chrome-surface-2);border:1.5px solid var(--chrome-border);border-radius:9px;padding:3px 4px;">
                            <button class="agent-pill" :class="{active: agentMode==='listing'}" @click="setMode('listing')" x-text="firstName(listingAgent)" title="Listing agent only"></button>
                            <button class="agent-pill" :class="{active: agentMode==='co'}" @click="setMode('co')" x-text="firstName(coAgent)" title="Co-listing agent only"></button>
                            <button class="agent-pill" :class="{active: agentMode==='both'}" @click="setMode('both')" title="Both agents (co-listed)">Both</button>
                        </div>
                    </div>
                </template>
                <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;">
                    <a :href="brochureUrl(true)"
                       style="display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:10px;font-size:13px;font-weight:700;background:#e63946;color:#fff;text-decoration:none;transition:opacity 0.12s;"
                       onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                        <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        Download PDF
                    </a>
                    <a :href="brochureUrl(false)" target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:10px;font-size:13px;font-weight:700;background:var(--chrome-surface-2);color:var(--chrome-text);border:1.5px solid var(--chrome-border);text-decoration:none;transition:all 0.12s;"
                       onmouseover="this.style.borderColor='var(--brand-button,#00b4d8)'" onmouseout="this.style.borderColor='var(--chrome-border)'">
                        <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        Open PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    {{-- Pre-built cards — responsive fill: as many ≥300px columns as fit, thumbnails scale to card width (JS) --}}
    <div class="tpl-grid" style="display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:22px; max-width:1760px; width:100%;">
        @foreach($prebuilt as $tplDef)
        <div class="tpl-card" x-show="matchesSearch(@js(strtolower($tplDef['name'].' '.$tplDef['desc'].' '.$tplDef['tags'])))" @click="selectTemplate('{{ $tplDef['key'] }}')">
            <div class="tpl-thumb" style="width:100%; aspect-ratio:1200/628; overflow:hidden; position:relative; background:#071325;">
                <div class="tpl-thumb-inner" style="position:absolute;top:0;left:0;width:1200px;height:628px;transform-origin:top left;transform:scale(0.2667);">
                    @include('corex.properties._ad-templates', ['tpl' => $tplDef['key'], 'baseFontPx' => 16])
                </div>
            </div>
            <div style="padding:18px 20px 22px;">
                <div style="font-size:15px;font-weight:800;color:var(--chrome-text);margin-bottom:5px;">{{ $tplDef['name'] }}</div>
                <div style="font-size:12px;color:var(--chrome-text-soft);line-height:1.6;">{{ $tplDef['desc'] }}</div>
                <div style="margin-top:14px;display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;color:var(--brand-button,#00b4d8);">
                    Use Template <svg xmlns="http://www.w3.org/2000/svg" style="width:11px;height:11px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- No-results state --}}
    <div x-show="searchQuery && visiblePrebuiltCount === 0 && visibleCustomCount === 0" x-cloak
         style="max-width:1760px;width:100%;margin-top:36px;text-align:center;color:var(--chrome-text-mute);font-size:14px;">
        No templates match “<span x-text="searchQuery" style="color:var(--chrome-text);"></span>”.
        <button @click="searchQuery=''" style="background:none;border:none;color:var(--brand-button,#00b4d8);font-weight:600;cursor:pointer;font-size:14px;font-family:inherit;">Clear search</button>
    </div>

    {{-- Custom saved templates (agency-wide) --}}
    <template x-if="savedTemplates.length > 0">
        <div style="max-width:1760px;width:100%;margin-top:40px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <div style="font-size:11px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:var(--chrome-text-mute);">Agency Custom Templates</div>
                @if($canManageTemplates)
                <a href="{{ route('corex.ad-templates.builder', ['property' => $property->id]) }}" style="font-size:12px;font-weight:600;color:var(--brand-button,#00b4d8);text-decoration:none;display:inline-flex;align-items:center;gap:4px;">
                    <svg style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    New Template
                </a>
                @endif
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:10px;">
                <template x-for="tpl in savedTemplates" :key="tpl.id">
                    <div class="custom-tpl-card" x-show="matchesSearch(tpl.name || '')" @click="selectCustomTemplate(tpl)">
                        <div class="custom-tpl-thumb"><span x-text="tpl.name.charAt(0).toUpperCase()"></span></div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-size:14px;font-weight:700;color:var(--chrome-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" x-text="tpl.name"></div>
                            <div style="font-size:11px;color:var(--chrome-text-mute);margin-top:3px;" x-text="(tpl.layout_json?.elements?.length || 0) + ' elements · ' + (tpl.layout_json?.canvasW || 1200) + '×' + (tpl.layout_json?.canvasH || 628)"></div>
                        </div>
                        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                            <template x-if="tpl.can_manage">
                                <a :href="`{{ route('corex.ad-templates.builder') }}/${tpl.id}?property={{ $property->id }}`" style="font-size:10px;color:var(--chrome-text-soft);text-decoration:none;" @click.stop>Edit</a>
                            </template>
                            <template x-if="!tpl.can_manage">
                                <span style="font-size:9px;color:var(--chrome-text-mute);" title="Only the creator (or a manager) can edit this">view only</span>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>

    @if($canManageTemplates)
    <template x-if="savedTemplates.length === 0">
        <div style="max-width:1760px;width:100%;margin-top:32px;text-align:center;">
            <a href="{{ route('corex.ad-templates.builder', ['property' => $property->id]) }}" style="display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:10px;font-size:13px;font-weight:600;color:var(--brand-button,#00b4d8);border:1.5px dashed color-mix(in srgb, var(--brand-button,#00b4d8) 35%, transparent);text-decoration:none;transition:all 0.12s;" onmouseover="this.style.borderColor='var(--brand-button,#00b4d8)'" onmouseout="this.style.borderColor='color-mix(in srgb, var(--brand-button,#00b4d8) 35%, transparent)'">
                <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                Build a custom template
            </a>
        </div>
    </template>
    @endif

</div>

{{-- ═══ STEP 2 — GENERATOR ═══ --}}
<div x-show="step === 'generate'" x-cloak style="flex:1; min-height:0; display:flex; flex-direction:column;">

    <div style="position:sticky;top:0;z-index:100;background:var(--chrome-surface);border-bottom:1px solid var(--chrome-border);padding:10px 18px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">

        <button @click="step='pick'" style="display:inline-flex;align-items:center;gap:4px;color:var(--chrome-text-soft);font-size:12px;background:none;border:1.5px solid var(--chrome-border);border-radius:8px;cursor:pointer;padding:5px 10px;font-family:inherit;" onmouseover="this.style.color='var(--chrome-text)';this.style.borderColor='var(--brand-button,#00b4d8)'" onmouseout="this.style.color='var(--chrome-text-soft)';this.style.borderColor='var(--chrome-border)'">
            <svg xmlns="http://www.w3.org/2000/svg" style="width:12px;height:12px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            Templates
        </button>

        <div style="width:1px;height:18px;background:var(--chrome-border);"></div>
        <span x-text="templateLabel" style="font-size:11px;font-weight:700;color:var(--chrome-text-soft);text-transform:uppercase;letter-spacing:0.08em;background:var(--chrome-surface-2);padding:4px 9px;border-radius:6px;"></span>
        <div style="width:1px;height:18px;background:var(--chrome-border);"></div>

        {{-- Co-listing agent choice — ONLY shown when the listing has a co-agent.
             Picks who appears on the ad: listing agent, co-agent, or both. --}}
        <template x-if="hasCoAgent">
            <div style="display:inline-flex;align-items:center;gap:4px;background:var(--chrome-surface-2);border:1.5px solid var(--chrome-border);border-radius:9px;padding:3px 4px;">
                <svg style="width:13px;height:13px;color:var(--chrome-text-mute);flex-shrink:0;margin:0 2px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-3.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a3 3 0 10-2.5-1.34"/></svg>
                <button class="agent-pill" :class="{active: agentMode==='listing'}" @click="setMode('listing')" x-text="firstName(listingAgent)" title="Listing agent only"></button>
                <button class="agent-pill" :class="{active: agentMode==='co'}" @click="setMode('co')" x-text="firstName(coAgent)" title="Co-listing agent only"></button>
                <button class="agent-pill" :class="{active: agentMode==='both'}" @click="setMode('both')" title="Both agents (co-listed)">Both</button>
            </div>
        </template>
        <template x-if="hasCoAgent"><div style="width:1px;height:18px;background:var(--chrome-border);"></div></template>

        <button class="plat-btn" :class="{active: platform==='facebook'}"  @click="platform='facebook'; onGenerate()">
            <svg style="width:13px;height:13px;" viewBox="0 0 24 24" fill="currentColor"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
            Facebook <span class="plat-size">1200×628</span>
        </button>
        <button class="plat-btn" :class="{active: platform==='instagram'}" @click="platform='instagram'; onGenerate()">
            <svg style="width:13px;height:13px;" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
            Instagram <span class="plat-size">1080×1080</span>
        </button>
        <button class="plat-btn" :class="{active: platform==='story'}"     @click="platform='story'; onGenerate()">
            <svg style="width:13px;height:13px;" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/></svg>
            Story <span class="plat-size">1080×1920</span>
        </button>
        <button class="plat-btn" :class="{active: platform==='whatsapp'}"  @click="platform='whatsapp'; onGenerate()">
            <svg style="width:13px;height:13px;" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
            WhatsApp <span class="plat-size">900×900</span>
        </button>

        {{-- Custom size --}}
        <button class="plat-btn" :class="{active: platform==='custom'}" @click="platform='custom'; onGenerate()" title="Set a custom size">
            <svg style="width:13px;height:13px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4h4M16 4h4v4M20 16v4h-4M8 20H4v-4"/></svg>
            Custom
        </button>
        <template x-if="platform==='custom'">
            <div style="display:inline-flex;align-items:center;gap:5px;background:var(--chrome-surface-2);border:1.5px solid var(--chrome-border);border-radius:9px;padding:3px 7px;">
                <input type="number" min="200" max="4000" step="10" x-model.number="customW" @input="onGenerate()" title="Width (px)"
                       style="width:62px;background:var(--chrome-input);color:var(--chrome-text);border:1px solid var(--chrome-border);border-radius:5px;font-size:12px;font-weight:600;font-family:inherit;padding:4px 6px;outline:none;">
                <span style="color:var(--chrome-text-mute);font-size:11px;">×</span>
                <input type="number" min="200" max="4000" step="10" x-model.number="customH" @input="onGenerate()" title="Height (px)"
                       style="width:62px;background:var(--chrome-input);color:var(--chrome-text);border:1px solid var(--chrome-border);border-radius:5px;font-size:12px;font-weight:600;font-family:inherit;padding:4px 6px;outline:none;">
                <span style="color:var(--chrome-text-mute);font-size:10px;">px</span>
            </div>
        </template>

        <button @click="download()" :disabled="generating || exporting"
                style="margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:8px 20px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;background:#e63946;border:none;color:#fff;font-family:inherit;transition:opacity 0.12s;"
                onmouseover="if(!this.disabled)this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
            <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            <span x-text="generating ? 'Generating…' : 'Download PNG'"></span>
        </button>

        <template x-if="returnMarketing">
            <button @click="exportForMarketing()" :disabled="generating || exporting"
                    style="display:inline-flex;align-items:center;gap:6px;padding:8px 20px;border-radius:10px;font-size:13px;font-weight:700;cursor:pointer;background:var(--brand-button,#00b4d8);border:none;color:#fff;font-family:inherit;transition:opacity 0.12s;"
                    onmouseover="if(!this.disabled)this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                <svg style="width:14px;height:14px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                <span x-text="exporting ? 'Sending…' : 'Use for Marketing'"></span>
            </button>
        </template>
    </div>

    {{-- Preview — fills remaining height; scales to fit so it never leaves the screen --}}
    <div id="ad-preview-area" style="position:relative; flex:1; min-height:0; overflow:hidden; display:flex; align-items:center; justify-content:center; padding:24px 20px;">
        <div :style="'overflow:hidden;border-radius:4px;box-shadow:0 28px 90px rgba(0,0,0,0.75);flex-shrink:0;width:'+previewW+'px;height:'+previewH+'px;'">
            <div id="ad-scale-wrapper" :style="'transform:scale('+scale+');transform-origin:top left;width:'+cfg.w+'px;height:'+cfg.h+'px;'">
                <div id="ad-canvas" :style="'width:'+cfg.w+'px;height:'+cfg.h+'px;position:relative;overflow:hidden;font-size:'+cfg.baseFontPx+'px;font-family:Figtree,Arial,sans-serif;background:#071325;'">

                    @foreach($prebuilt as $tplDef)
                    <div x-show="template==='{{ $tplDef['key'] }}'" style="position:absolute;inset:0;">
                        @include('corex.properties._ad-templates', ['tpl' => $tplDef['key'], 'baseFontPx' => null])
                    </div>
                    @endforeach

                    {{-- CUSTOM (rendered via JS) --}}
                    <div id="custom-canvas-root" x-show="template==='custom'" style="position:absolute;inset:0;"></div>

                </div>
            </div>
        </div>

        {{-- "Change photo" controls — OUTSIDE #ad-canvas so the html2canvas
             export (which captures only #ad-canvas) can never include them.
             Populated by mountImageTools(); one region per property image. --}}
        <div id="ad-img-tools" data-html2canvas-ignore="true"></div>
    </div>

</div>

</div>{{-- /#ad-body --}}

{{-- ═══ GALLERY PICKER MODAL — swap the clicked image for another property photo ═══ --}}
<div class="ad-modal-overlay" x-show="picker.open" x-cloak @keydown.escape.window="closeImagePicker()" @click.self="closeImagePicker()">
    <div class="ad-modal" @click.stop>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid var(--chrome-border);flex-shrink:0;">
            <div>
                <div style="font-size:15px;font-weight:800;color:var(--chrome-text);">Choose a photo</div>
                <div style="font-size:12px;color:var(--chrome-text-mute);margin-top:2px;">Pick any of this property's photos to place in the selected slot.</div>
            </div>
            <button @click="closeImagePicker()" title="Close" style="flex-shrink:0;width:32px;height:32px;border-radius:8px;border:1.5px solid var(--chrome-border);background:var(--chrome-surface-2);color:var(--chrome-text-soft);font-size:18px;line-height:1;cursor:pointer;font-family:inherit;">&times;</button>
        </div>

        <div style="flex:1;min-height:0;overflow-y:auto;padding:18px 20px;">
            <template x-if="galleryImages.length > 0">
                <div class="ad-gallery-grid">
                    <template x-for="(url, i) in galleryImages" :key="i">
                        <div class="ad-gallery-thumb" :class="{ current: url === picker.currentSrc }" @click="chooseImage(url)">
                            <img :src="url" alt="" loading="lazy">
                            <span class="ad-current-tag" x-show="url === picker.currentSrc">In use</span>
                        </div>
                    </template>
                </div>
            </template>
            <template x-if="galleryImages.length === 0">
                <div style="text-align:center;color:var(--chrome-text-mute);font-size:13px;padding:40px 0;">
                    This property has no photos in its gallery yet.
                </div>
            </template>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 20px;border-top:1px solid var(--chrome-border);flex-shrink:0;">
            <button @click="resetImage()" x-show="picker.canReset" style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--chrome-text-soft);background:var(--chrome-surface-2);border:1.5px solid var(--chrome-border);border-radius:8px;padding:7px 13px;cursor:pointer;font-family:inherit;">
                <svg style="width:13px;height:13px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0113.657-3.657L20 8M20 15a8 8 0 01-13.657 3.657L4 16"/></svg>
                Reset to original
            </button>
            <span x-show="!picker.canReset"></span>
            <button @click="closeImagePicker()" style="font-size:12px;font-weight:600;color:var(--chrome-text-soft);background:none;border:none;cursor:pointer;font-family:inherit;">Cancel</button>
        </div>
    </div>
</div>

<script>
const PREBUILT_NAMES = @json(collect($prebuilt)->pluck('name', 'key'));
const PREBUILT_SEARCH = @json(collect($prebuilt)->map(fn($t) => strtolower($t['name'].' '.$t['desc'].' '.$t['tags']))->values());

const IMAGE_FIELDS = ['image_1','image_2','image_3','image_4','image_5','agent_avatar','agent_2_avatar','agency_logo'];
const NON_TEXT_FIELDS = [...IMAGE_FIELDS, 'logo', 'watermark', 'color_block', 'gradient', 'line', 'shape', 'custom_image', 'custom_video'];
// Shape geometry — mirrors the Ad Builder (ad-builder.blade.php SHAPE_CLIPS).
const SHAPE_CLIPS = {
    triangle: 'polygon(50% 0,100% 100%,0 100%)',
    diamond:  'polygon(50% 0,100% 50%,50% 100%,0 50%)',
    pentagon: 'polygon(50% 0,100% 38%,82% 100%,18% 100%,0 38%)',
    hexagon:  'polygon(25% 0,75% 0,100% 50%,75% 100%,25% 100%,0 50%)',
    star:     'polygon(50% 0,61% 35%,98% 35%,68% 57%,79% 91%,50% 70%,21% 91%,32% 57%,2% 35%,39% 35%)',
    chevron:  'polygon(0 0,75% 0,100% 50%,75% 100%,0 100%,25% 50%)',
};

function adApp(savedTemplates, propertyData, agentCfg, galleryImages) {
    agentCfg = agentCfg || { listing: null, co: null };
    // Kept OUT of Alpine's reactive object on purpose: a live DOM node (the image
    // being edited) and the per-custom-element image overrides. Wrapping a DOM
    // node in Alpine's reactive proxy is a known footgun, so these live in a plain
    // closure object the methods read/write directly.
    const priv = { target: null, imgOverrides: {} };
    const platforms = {
        facebook:  { w:1200, h:628,  baseFontPx:16, label:'Facebook'  },
        instagram: { w:1080, h:1080, baseFontPx:28, label:'Instagram' },
        story:     { w:1080, h:1920, baseFontPx:50, label:'Story'     },
        whatsapp:  { w:900,  h:900,  baseFontPx:23, label:'WhatsApp'  },
        linkedin:  { w:1200, h:627,  baseFontPx:16, label:'LinkedIn'  },
        pinterest: { w:1000, h:1500, baseFontPx:38, label:'Pinterest' },
    };

    return {
        step: 'pick',
        searchQuery: '',
        template: null,
        platform: 'facebook',
        generating: false,
        exporting: false,
        returnMarketing: new URLSearchParams(window.location.search).get('return_marketing') || null,
        platforms,
        savedTemplates: savedTemplates || [],
        propertyData: propertyData || {},
        // Full property photo gallery + "change photo" picker modal state.
        galleryImages: galleryImages || [],
        picker: { open: false, currentSrc: '', canReset: false },
        _customLayout: null,
        // Custom canvas size (the "Custom" size button).
        customW: 1080,
        customH: 1080,
        showCustomSize: false,
        _vp: 0,   // bumped on resize so scale getters recompute

        // ── Co-listing agent choice (ad-manager.md §"Agent identity") ──
        // Only meaningful when the listing has a co-agent; otherwise the ad just
        // shows the listing agent. Modes: 'listing' | 'co' | 'both'.
        listingAgent: agentCfg.listing || {},
        coAgent:      agentCfg.co || null,
        agentMode:    'listing',

        init() {
            const fit = () => { this._vp++; this.fitThumbs(); this.scheduleMountTools(); };
            this.$nextTick(fit);
            window.addEventListener('resize', fit);
            // Refit when returning to the picker or after filtering reflows the grid.
            this.$watch('step', v => { if (v === 'pick') this.$nextTick(() => this.fitThumbs()); else this.$nextTick(() => this._vp++); this.scheduleMountTools(); });
            this.$watch('searchQuery', () => this.$nextTick(() => this.fitThumbs()));
            // The preview re-scales/re-lays-out on template + platform + size changes;
            // the "change photo" regions must follow the images they sit on.
            this.$watch('template', () => this.scheduleMountTools());
            this.$watch('platform', () => this.scheduleMountTools());
            this.$watch('customW', () => this.scheduleMountTools());
            this.$watch('customH', () => this.scheduleMountTools());
        },

        // Thumbnails render a fixed 1200×628 design scaled to the card's real
        // width — so however many columns fit, each thumbnail fills its card
        // exactly (no dead space inside the cards).
        fitThumbs() {
            document.querySelectorAll('.tpl-thumb').forEach(w => {
                const inner = w.querySelector('.tpl-thumb-inner');
                if (inner && w.clientWidth) inner.style.transform = 'scale(' + (w.clientWidth / 1200) + ')';
            });
        },

        get templateLabel() {
            if (this.template === 'custom') return 'Custom';
            return PREBUILT_NAMES[this.template] || (this.template || '');
        },

        // ── Template search/filter (picker step) ──
        matchesSearch(haystack) {
            const q = this.searchQuery.trim().toLowerCase();
            return !q || String(haystack).toLowerCase().includes(q);
        },
        get visiblePrebuiltCount() {
            const q = this.searchQuery.trim().toLowerCase();
            return q ? PREBUILT_SEARCH.filter(h => h.includes(q)).length : PREBUILT_SEARCH.length;
        },
        get visibleCustomCount() {
            const q = this.searchQuery.trim().toLowerCase();
            return q ? this.savedTemplates.filter(t => (t.name || '').toLowerCase().includes(q)).length : this.savedTemplates.length;
        },

        get cfg() {
            if (this.template === 'custom' && this._customLayout) {
                const preset = this._customLayout.canvasPreset || 'facebook';
                return platforms[preset] || { w: this._customLayout.canvasW || 1200, h: this._customLayout.canvasH || 628, baseFontPx: 16, label: 'Custom' };
            }
            if (this.platform === 'custom') {
                const w = Math.max(200, Math.min(4000, +this.customW || 1080));
                const h = Math.max(200, Math.min(4000, +this.customH || 1080));
                // Scale the em base font with the canvas so templates stay proportional.
                return { w, h, baseFontPx: Math.max(12, Math.round(Math.min(w, h) / 38)), label: 'Custom' };
            }
            return platforms[this.platform];
        },
        // Fit the preview to the actual available area (below the header + toolbar)
        // so every size — incl. Instagram/Story — stays within the screen.
        get scale() {
            this._vp;   // reactive dependency so resize recomputes
            const area = document.getElementById('ad-preview-area');
            let maxW, maxH;
            if (area) {
                const rect = area.getBoundingClientRect();
                maxW = (area.clientWidth || rect.width) - 44;
                maxH = (window.innerHeight - rect.top) - 44;
            } else {
                maxW = Math.min(window.innerWidth - 64, 1100);
                maxH = window.innerHeight - 200;
            }
            maxW = Math.max(140, maxW);
            maxH = Math.max(140, maxH);
            return Math.min(maxW / this.cfg.w, maxH / this.cfg.h, 1);
        },
        get previewW() { return Math.round(this.cfg.w * this.scale); },
        get previewH() { return Math.round(this.cfg.h * this.scale); },

        selectTemplate(t) { this.template = t; this._customLayout = null; this.step = 'generate'; this.$nextTick(() => this.applyAgent()); },
        selectCustomTemplate(tpl) {
            this.template = 'custom';
            this._customLayout = tpl.layout_json;
            this.step = 'generate';
            this.$nextTick(() => this.applyAgent());
        },
        onGenerate() { this.$nextTick(() => { this.applyAgent(); this.scheduleMountTools(); }); },

        // ── "Change photo" overlay ───────────────────────────────────────────
        // Rebuild the click regions after any layout change. rAF-after-nextTick so
        // the preview has scaled/re-rendered before we measure image rects.
        scheduleMountTools() { this.$nextTick(() => requestAnimationFrame(() => this.mountImageTools())); },

        // An image is editable (a property photo) if it is not a logo or an agent
        // avatar. Prebuilt templates tag logos js-ad-logo; renderCustomTemplate tags
        // logo/avatar imgs so both paths share this one rule.
        _isEditablePhoto(img) {
            return !img.classList.contains('js-ad-logo') && !img.classList.contains('js-ad-avatar');
        },

        mountImageTools() {
            const tools = document.getElementById('ad-img-tools');
            const area  = document.getElementById('ad-preview-area');
            const canvas = document.getElementById('ad-canvas');
            if (!tools || !area || !canvas) return;
            tools.innerHTML = '';
            if (this.step !== 'generate') return;

            const areaRect = area.getBoundingClientRect();
            const imgs = Array.from(canvas.querySelectorAll('img'))
                .filter(img => img.offsetParent !== null && this._isEditablePhoto(img));
            // Largest first → added earliest → sits UNDER the smaller images that are
            // visually on top (e.g. Luxe thumbnails over the hero), so each click
            // lands on the photo the user actually sees.
            const rectOf = img => img.getBoundingClientRect();
            imgs.sort((a, b) => { const ra = rectOf(a), rb = rectOf(b); return (rb.width * rb.height) - (ra.width * ra.height); });

            imgs.forEach(img => {
                const r = rectOf(img);
                if (r.width < 8 || r.height < 8) return;
                const region = document.createElement('div');
                region.className = 'ad-img-region';
                region.style.left   = (r.left - areaRect.left) + 'px';
                region.style.top    = (r.top  - areaRect.top)  + 'px';
                region.style.width  = r.width + 'px';
                region.style.height = r.height + 'px';
                region.title = 'Change photo';
                region.innerHTML =
                    '<div class="ad-img-region-veil">' +
                        '<span class="ad-img-cta">' +
                            '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="3" width="14" height="14" rx="2"/><circle cx="11" cy="7.5" r="1.3"/><path d="M21 13l-4-4-6 6"/><path d="M17 21H5a2 2 0 01-2-2V7"/></svg>' +
                            'Change photo' +
                        '</span>' +
                    '</div>' +
                    '<span class="ad-img-badge">' +
                        '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="7" y="3" width="14" height="14" rx="2"/><circle cx="11" cy="7.5" r="1.3"/><path d="M21 13l-4-4-6 6"/><path d="M17 21H5a2 2 0 01-2-2V7"/></svg>' +
                    '</span>';
                region.addEventListener('click', (e) => { e.stopPropagation(); this.openImagePicker(img); });
                tools.appendChild(region);
            });
        },

        openImagePicker(img) {
            priv.target = img;
            // Remember the original src once, so "Reset to original" can restore it.
            if (img.dataset.origSrc === undefined) img.dataset.origSrc = img.getAttribute('src') || '';
            const cur = img.getAttribute('src') || '';
            this.picker.currentSrc = cur;
            this.picker.canReset = cur !== (img.dataset.origSrc || '');
            this.picker.open = true;
        },

        chooseImage(url) {
            const img = priv.target;
            if (img) {
                img.setAttribute('src', url);
                img.src = url;
                // Custom-template images are re-rendered from layout_json; persist the
                // choice against the element id so re-renders keep it.
                const elId = img.dataset.elId;
                if (elId) priv.imgOverrides[elId] = url;
            }
            this.closeImagePicker();
            this.scheduleMountTools();
        },

        resetImage() {
            const img = priv.target;
            if (img) {
                const orig = img.dataset.origSrc || '';
                img.setAttribute('src', orig);
                img.src = orig;
                const elId = img.dataset.elId;
                if (elId) delete priv.imgOverrides[elId];
            }
            this.closeImagePicker();
            this.scheduleMountTools();
        },

        closeImagePicker() { this.picker.open = false; priv.target = null; },

        // ── Co-listing agent choice ──────────────────────────────────────────
        get hasCoAgent() { return !!(this.coAgent && this.coAgent.id); },
        firstName(card) { return ((card && card.name) || '').trim().split(/\s+/)[0] || 'Agent'; },
        setMode(m) { this.agentMode = m; this.applyAgent(); },

        // Resolve the two display slots for the current mode.
        //   listing → slot1 = listing agent, no slot2
        //   co      → slot1 = co-agent,      no slot2
        //   both    → slot1 = listing agent, slot2 = co-agent (two SPLIT blocks)
        agentSlots() {
            const L = this.listingAgent || {}, C = this.coAgent || {};
            const showBoth = this.agentMode === 'both' && this.hasCoAgent;
            const primary  = (this.agentMode === 'co' && this.hasCoAgent) ? C : L;
            return { primary, secondary: showBoth ? C : null, showBoth };
        },

        // Re-point every agent-bound node. Pre-built templates are server-rendered
        // Blade: slot-1 nodes tagged js-ad-{name,email,desig,initial}, slot-2 nodes
        // js-ad-{…}-2 inside a js-ad-agent2 wrapper that we show only in "both".
        // Custom templates read propertyData (agent_* + agent_2_*), so we update it
        // and re-render. html2canvas captures the live DOM → PNG reflects it.
        applyAgent() {
            const { primary, secondary, showBoth } = this.agentSlots();
            const canvas = document.getElementById('ad-canvas');
            if (canvas) {
                const set = (sel, val) => canvas.querySelectorAll(sel).forEach(n => n.textContent = val);
                set('.js-ad-name', (primary.name || '').toUpperCase());
                set('.js-ad-email', primary.email || '');
                set('.js-ad-desig', primary.designation || '');
                set('.js-ad-initial', primary.initial || '');
                if (secondary) {
                    set('.js-ad-name-2', (secondary.name || '').toUpperCase());
                    set('.js-ad-email-2', secondary.email || '');
                    set('.js-ad-desig-2', secondary.designation || '');
                    set('.js-ad-initial-2', secondary.initial || '');
                }
                // Show / hide the second agent block (each wrapper carries its own
                // shown-display via data-disp so each template's layout is preserved).
                canvas.querySelectorAll('.js-ad-agent2').forEach(el => {
                    el.style.display = showBoth ? (el.dataset.disp || 'flex') : 'none';
                });
            }
            // Keep propertyData (custom-template source) in lock-step — slot 1 + slot 2.
            this.propertyData.agent_name        = (primary.name || '').toUpperCase();
            this.propertyData.agent_email       = primary.email || '';
            this.propertyData.agent_designation = primary.designation || '';
            this.propertyData.agent_phone       = primary.phone || '';
            this.propertyData.agent_avatar      = primary.avatar || null;
            const s = secondary || {};
            this.propertyData.agent_2_name        = (s.name || '').toUpperCase();
            this.propertyData.agent_2_email       = s.email || '';
            this.propertyData.agent_2_designation = s.designation || '';
            this.propertyData.agent_2_phone       = s.phone || '';
            this.propertyData.agent_2_avatar      = s.avatar || null;
            this.propertyData.agent_2_initial     = s.initial || '';
            if (this.template === 'custom' && this._customLayout) this.renderCustomTemplate();
        },

        // Brochure PDF URL carrying the co-listing choice (?co=1 = both agents,
        // ?ad_agent=<co id> = co-agent only). Server validates / falls back.
        brochureUrl(dl) {
            const base = '{{ route('corex.properties.brochure', $property) }}';
            const parts = [];
            if (dl) parts.push('dl=1');
            if (this.agentMode === 'both' && this.hasCoAgent) parts.push('co=1');
            else if (this.agentMode === 'co' && this.hasCoAgent) parts.push('ad_agent=' + this.coAgent.id);
            return parts.length ? base + '?' + parts.join('&') : base;
        },

        isImageField(f) { return IMAGE_FIELDS.includes(f); },
        isTextField(f)  { return !NON_TEXT_FIELDS.includes(f); },

        hexToRgba(hex, a) {
            const m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
            if (!m) return hex;
            return `rgba(${parseInt(m[1],16)},${parseInt(m[2],16)},${parseInt(m[3],16)},${a})`;
        },

        renderCustomTemplate() {
            const root = document.getElementById('custom-canvas-root');
            if (!root || !this._customLayout) return;
            root.innerHTML = '';
            const layout = this._customLayout;
            const prop = this.propertyData;
            (layout.elements || []).forEach(el => {
                const div = document.createElement('div');
                let css = `position:absolute;left:${el.x}px;top:${el.y}px;width:${el.w}px;height:${el.h}px;z-index:${el.zIndex || 1};overflow:hidden;border-radius:${el.borderRadius || 0}px;`;
                if (el.rotation) css += `transform:rotate(${el.rotation}deg);`;
                if (el.frameBorderWidth) css += `border:${el.frameBorderWidth}px solid ${el.frameBorderColor || '#fff'};`;
                div.style.cssText = css;
                const field = el.field;

                if (this.isImageField(field)) {
                    const isPhoto = /^image_[1-5]$/.test(field);
                    const baseSrc = field === 'agency_logo' ? prop.logo : prop[field];
                    // A gallery swap wins over the slot's default (persists re-renders).
                    const src = (isPhoto && priv.imgOverrides[el.id]) ? priv.imgOverrides[el.id] : baseSrc;
                    if (src) {
                        const img = document.createElement('img');
                        img.src = src;
                        img.style.cssText = `width:100%;height:100%;object-fit:${el.objectFit || 'cover'};display:block;`;
                        // Tag so the "change photo" overlay targets property photos only
                        // (a logo or an agent avatar is not swapped from the gallery).
                        if (field === 'agency_logo') img.classList.add('js-ad-logo');
                        else if (field.endsWith('avatar')) img.classList.add('js-ad-avatar');
                        else if (isPhoto) { img.dataset.elId = el.id; img.dataset.origSrc = baseSrc || ''; }
                        div.appendChild(img);
                    } else if (String(field).startsWith('agent_2')) {
                        // Co-agent absent (single-agent listing) → leave the slot empty,
                        // never a placeholder box on a real ad.
                    } else {
                        div.style.background = 'linear-gradient(135deg,#0b2a4a,#143d6e)';
                        Object.assign(div.style, { display:'flex', alignItems:'center', justifyContent:'center', color:'rgba(255,255,255,0.2)', fontSize:'11px' });
                        div.textContent = el.label;
                    }
                } else if (field === 'custom_image') {
                    // Editable like a photo slot: a gallery swap overrides the uploaded
                    // image and persists across re-renders; reset restores the upload.
                    const src = priv.imgOverrides[el.id] || el.src;
                    if (src) {
                        const img = document.createElement('img');
                        img.src = src;
                        img.style.cssText = `width:100%;height:100%;object-fit:${el.objectFit || 'cover'};display:block;`;
                        img.dataset.elId = el.id; img.dataset.origSrc = el.src || '';
                        div.appendChild(img);
                    }
                } else if (field === 'custom_video') {
                    if (el.src) {
                        const v = document.createElement('video');
                        v.src = el.src; v.muted = true; v.loop = true; v.autoplay = true; v.playsInline = true;
                        v.setAttribute('playsinline', ''); v.setAttribute('muted', '');
                        v.style.cssText = `width:100%;height:100%;object-fit:${el.objectFit || 'cover'};display:block;`;
                        div.appendChild(v);
                        v.play?.().catch(() => {});
                    }
                } else if (field === 'color_block') {
                    div.style.background = el.bg || '#07111e';
                    div.style.opacity = el.opacity ?? 1;
                } else if (field === 'shape') {
                    div.style.background = el.bg || '#00b4d8';
                    div.style.opacity = el.opacity ?? 1;
                    const t = el.shapeType;
                    if (!t) { div.style.borderRadius = (el.borderRadius ?? 50) + '%'; }  // legacy %
                    else if (SHAPE_CLIPS[t]) { div.style.clipPath = SHAPE_CLIPS[t]; div.style.borderRadius = '0'; }
                    else if (t === 'circle') div.style.borderRadius = '50%';
                    else if (t === 'pill')   div.style.borderRadius = '9999px';
                    else if (t === 'rounded') div.style.borderRadius = (el.borderRadius ?? 24) + 'px';
                    else div.style.borderRadius = '0';
                } else if (field === 'gradient') {
                    div.style.background = `linear-gradient(${el.gradAngle || 180}deg, ${el.gradFrom || '#071325'}, ${el.gradTo || 'rgba(7,19,37,0)'})`;
                    div.style.opacity = el.opacity ?? 1;
                } else if (field === 'line') {
                    const bar = document.createElement('div');
                    bar.style.cssText = `width:100%;height:${el.borderWidth || 3}px;background:${el.color || '#00b4d8'};border-radius:2px;`;
                    Object.assign(div.style, { display:'flex', alignItems:'center' });
                    div.appendChild(bar);
                } else if (field === 'logo') {
                    Object.assign(div.style, { display:'flex', alignItems:'center', padding:(el.padding || 0) + 'px' });
                    if (prop.logo) {
                        const img = document.createElement('img');
                        img.src = prop.logo;
                        img.style.cssText = 'max-height:100%;max-width:100%;object-fit:contain;object-position:left center;';
                        div.appendChild(img);
                    } else {
                        div.style.fontFamily = "'Figtree',Arial,sans-serif";
                        div.style.fontWeight = '900';
                        div.style.fontSize = (el.fontSize || 28) + 'px';
                        div.style.color = el.color || '#fff';
                        div.innerHTML = 'corex<span style="color:#33c4e0">os</span>';
                    }
                } else if (field === 'watermark') {
                    Object.assign(div.style, { display:'flex', alignItems:'center', justifyContent:'center', fontFamily:"'Figtree',Arial,sans-serif", fontWeight:'900', letterSpacing:'0.06em', textTransform:'uppercase' });
                    div.style.fontSize = (el.fontSize || 60) + 'px';
                    div.style.color = el.color || '#fff';
                    div.style.opacity = el.opacity ?? 0.06;
                    div.textContent = prop.watermark || el.text || 'COREX';
                } else {
                    // Text field
                    let value;
                    if (field === 'custom_text' || field === 'badge') {
                        value = el.text || el.label;
                    } else if (field === 'features') {
                        // Show the chosen amenities (null selection = all of them).
                        const all = Array.isArray(prop.features_list) ? prop.features_list : [];
                        const chosen = (el.selectedFeatures == null) ? all : all.filter(f => el.selectedFeatures.includes(f));
                        value = chosen.length ? chosen.join('  ·  ') : (prop.features || el.preview || '');
                    } else {
                        value = (prop[field] !== undefined && prop[field] !== null && prop[field] !== '') ? prop[field] : (String(field).startsWith('agent_2') ? '' : (el.preview || el.label));
                    }
                    Object.assign(div.style, { display:'flex', alignItems:'center', overflow:'hidden', fontFamily:"'Figtree',Arial,sans-serif" });
                    div.style.fontSize = (el.fontSize || 18) + 'px';
                    div.style.fontWeight = el.fontWeight || '600';
                    div.style.color = el.color || '#fff';
                    div.style.textAlign = el.textAlign || 'left';
                    div.style.textTransform = el.textTransform || 'none';
                    div.style.letterSpacing = (el.letterSpacing || 0) + 'em';
                    div.style.lineHeight = el.lineHeight ?? 1.2;
                    div.style.padding = (el.padding || 8) + 'px';
                    const op = el.bgOpacity ?? 0;
                    if (op > 0) {
                        div.style.background = this.hexToRgba(el.bgColor || '#000000', op);
                        if (el.textAlign === 'center') div.style.justifyContent = 'center';
                        if (el.textAlign === 'right')  div.style.justifyContent = 'flex-end';
                    }
                    const span = document.createElement('span');
                    span.style.width = '100%';
                    span.textContent = value;
                    div.appendChild(span);
                }
                root.appendChild(div);
            });
            this.scheduleMountTools();
        },

        _canvasBg() {
            if (this.template === 'custom' && this._customLayout) {
                const l = this._customLayout;
                if (l.canvasBgMode === 'gradient') return l.canvasBgFrom || '#071325';
                return l.canvasBg || '#071325';
            }
            return '#071325';
        },

        async _capture() {
            const wrapper = document.getElementById('ad-scale-wrapper');
            const canvas  = document.getElementById('ad-canvas');
            const cfg     = this.cfg;
            if (this.template === 'custom' && this._customLayout) {
                canvas.style.width  = (this._customLayout.canvasW || 1200) + 'px';
                canvas.style.height = (this._customLayout.canvasH || 628) + 'px';
                const l = this._customLayout;
                canvas.style.background = (l.canvasBgMode === 'gradient')
                    ? `linear-gradient(${l.canvasBgAngle ?? 160}deg, ${l.canvasBgFrom}, ${l.canvasBgTo})`
                    : (l.canvasBg || '#071325');
            }
            const saved = wrapper.style.transform;
            wrapper.style.transform = 'none';
            await new Promise(r => setTimeout(r, 80));
            const c = await html2canvas(canvas, {
                width: cfg.w, height: cfg.h, scale: 2,
                useCORS: true, allowTaint: false, backgroundColor: this._canvasBg(), logging: false,
            });
            wrapper.style.transform = saved;
            // The capture untransformed the wrapper (and resized the canvas for a
            // custom layout); realign the "change photo" regions to the images.
            this.scheduleMountTools();
            return c;
        },

        async exportForMarketing() {
            if (!this.returnMarketing) return;
            this.exporting = true;
            try {
                const c = await this._capture();
                const dataUrl = c.toDataURL('image/png');
                const res = await fetch('{{ route('corex.marketing.upload-template-image') }}', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ image: dataUrl }),
                });
                const json = await res.json();
                if (!res.ok || !json.ok) throw new Error(json.error || 'Upload failed');
                window.location.href = `/corex/properties/${this.returnMarketing}/marketing?marketing_img=${encodeURIComponent(json.url)}&media_tab=photos`;
            } catch (err) {
                alert('Export failed: ' + (err?.message || 'unknown'));
                this.exporting = false;
            }
        },

        async download() {
            this.generating = true;
            try {
                const c = await this._capture();
                const link = document.createElement('a');
                link.download = `hfc-ad-{{ $property->id }}-${this.template}-${this.platform}.png`;
                link.href = c.toDataURL('image/png');
                link.click();
            } catch (err) {
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
