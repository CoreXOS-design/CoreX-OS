<!DOCTYPE html>
<html lang="en">
@php
    // Agency brand colours (Company Settings → Design). Fall back to CoreX defaults.
    $brandDefault = optional($agency)->default_color ?: '#0b2a4a';
    $brandIcon    = optional($agency)->icon_color    ?: '#00b4d8';
    $brandButton  = optional($agency)->button_color  ?: '#00b4d8';
    $brandSidebar = optional($agency)->sidebar_color ?: $brandIcon;

    // A contact with more than one saved wishlist gets a header/dropdown per
    // wishlist; a contact with exactly one renders identically to before.
    $showMatchHeaders = $matchGroups->count() > 1;
@endphp
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ 'Your Property Matches' . (!empty($agency) ? ' — ' . $agency->name : '') }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --bg: #f4f6fb;
            --surface: #ffffff;
            --surface-2: #f0f2f8;
            --border: rgba(0,0,0,0.07);
            --border-hover: rgba(0,0,0,0.14);
            --text-primary: #111827;
            --text-secondary: #4b5563;
            --text-muted: #9ca3af;
            /* Agency brand colours — injected from Company Settings */
            --brand-default: {{ $brandDefault }};
            --brand-icon: {{ $brandIcon }};
            --brand-button: {{ $brandButton }};
            --brand-sidebar: {{ $brandSidebar }};
            --ds-green: #059669;
            --ds-amber: #f59e0b;
            --ds-crimson: #c41e3a;
            --ds-navy: #0b2a4a;
        }
        * { box-sizing: border-box; }
        html, body { font-family: 'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--bg); color: var(--text-primary); margin: 0; }
        a { text-decoration: none; }
        input, select, textarea { outline: none; font-family: inherit; }
        input:focus, select:focus, textarea:focus {
            border-color: var(--brand-button) !important;
            box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button) 15%, transparent);
        }
        .surface-card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; }
        .btn-primary {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            background: var(--brand-button); color: #fff; border: 1px solid var(--brand-button);
            border-radius: 8px; padding: 0.5rem 0.875rem; font-size: 0.8125rem; font-weight: 600;
            cursor: pointer; transition: all 200ms ease;
            box-shadow: 0 4px 12px color-mix(in srgb, var(--brand-button) 25%, transparent);
        }
        .btn-primary:hover { box-shadow: 0 6px 16px color-mix(in srgb, var(--brand-button) 35%, transparent); transform: translateY(-1px); }
        .btn-outline {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            background: var(--surface); color: var(--text-secondary);
            border: 1px solid var(--border); border-radius: 8px;
            padding: 0.5rem 0.875rem; font-size: 0.8125rem; font-weight: 600;
            cursor: pointer; transition: all 200ms ease;
        }
        .btn-outline:hover { border-color: var(--brand-button); color: var(--brand-button); }
        .btn-outline[aria-disabled="true"] { opacity: 0.4; cursor: not-allowed; pointer-events: none; }
        .field-label { display: block; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary); margin-bottom: 0.375rem; }
        .field-helper { display: block; font-size: 0.6875rem; color: var(--text-muted); margin-bottom: 0.25rem; }
        .field-input {
            width: 100%; border: 1px solid var(--border); border-radius: 8px;
            padding: 0.5rem 0.75rem; font-size: 0.8125rem; color: var(--text-primary);
            background: var(--surface); transition: all 200ms ease;
        }
        .field-input::placeholder { color: var(--text-muted); }
        .ds-badge {
            display: inline-flex; align-items: center; white-space: nowrap;
            border-radius: 9999px; padding: 0.125rem 0.5rem;
            font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
            border: 1px solid transparent;
        }
        .ds-badge-success { background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green); border-color: color-mix(in srgb, var(--ds-green) 28%, transparent); }
        .ds-badge-warning { background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber); border-color: color-mix(in srgb, var(--ds-amber) 28%, transparent); }
        .ds-badge-info    { background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon); border-color: color-mix(in srgb, var(--brand-icon) 28%, transparent); }
        .ds-badge-default { background: var(--surface-2); color: var(--text-secondary); border-color: var(--border); }
        .feedback-btn {
            display: inline-flex; align-items: center; gap: 0.25rem;
            font-size: 0.6875rem; font-weight: 600;
            padding: 0.375rem 0.625rem; border-radius: 8px;
            background: var(--surface); color: var(--text-secondary);
            border: 1px solid var(--border); cursor: pointer; transition: all 200ms ease;
        }
        .feedback-btn:hover { border-color: var(--border-hover); }
        .feedback-btn.is-active { color: #fff; }

        /* Range sliders (refine bar) — branded thumb + filled track */
        .range { -webkit-appearance: none; appearance: none; width: 100%; height: 4px; border-radius: 9999px;
                 background: var(--surface-2); outline: none; margin: 0; }
        .range::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 16px; height: 16px; border-radius: 9999px;
                 background: var(--brand-button); cursor: pointer; box-shadow: 0 1px 4px rgba(0,0,0,.25); border: 2px solid #fff; }
        .range::-moz-range-thumb { width: 16px; height: 16px; border-radius: 9999px; background: var(--brand-button);
                 cursor: pointer; border: 2px solid #fff; box-shadow: 0 1px 4px rgba(0,0,0,.25); }
        .refine-select {
            width: 100%; border: 1px solid var(--border); border-radius: 8px; background: var(--surface);
            padding: 0.5rem 0.75rem; font-size: 0.8125rem; font-weight: 500; color: var(--text-primary); cursor: pointer;
        }
        .match-card { transition: opacity 200ms ease; }

        /* <summary>'s native disclosure triangle renders unstyled in WebKit
           (Chrome/Safari/Edge) — list-style:none alone doesn't suppress it
           there, only ::-webkit-details-marker does. Applies to both the
           per-wishlist dropdown headers and the "change search criteria"
           accordion below the results. */
        summary { list-style: none; }
        summary::-webkit-details-marker { display: none; }
        summary::marker { content: ""; }
    </style>
</head>
<body>

    {{-- Top bar --}}
    <header class="sticky top-0 z-30" style="background: var(--brand-default); border-bottom: 3px solid var(--brand-icon);">
        <div class="max-w-5xl mx-auto px-4 lg:px-6 py-3.5 flex items-center justify-between gap-3">
            @if(!empty($agency) && $agency->logo_path)
                <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}"
                     style="max-height: 38px; max-width: 190px; object-fit: contain;">
            @else
                <div class="text-lg font-bold tracking-tight text-white">{{ $agency->name ?? 'Property Matches' }}</div>
            @endif
            <span class="ds-badge" style="background: rgba(255,255,255,0.1); color: #fff; border-color: rgba(255,255,255,0.2);">
                Property Matches
            </span>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-4 lg:px-6 py-6 space-y-6">

        {{-- Hero — personalised greeting + agent --}}
        <section class="rounded-xl px-6 py-6 relative overflow-hidden"
                 style="background: linear-gradient(135deg, var(--brand-default) 0%, color-mix(in srgb, var(--brand-default) 82%, #000) 100%);">
            <div class="absolute -top-16 -right-16 w-56 h-56 rounded-full opacity-20"
                 style="background: radial-gradient(circle, var(--brand-icon) 0%, transparent 70%);"></div>
            <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex items-center gap-4 min-w-0">
                    <div class="w-14 h-14 rounded-full flex items-center justify-center flex-shrink-0 text-lg font-bold text-white shadow-lg"
                         style="background: var(--brand-icon);">
                        {{ strtoupper(substr($contact->first_name,0,1).substr($contact->last_name,0,1)) }}
                    </div>
                    <div class="min-w-0">
                        <p class="text-[0.6875rem] font-semibold uppercase tracking-[0.15em]" style="color: color-mix(in srgb, var(--brand-icon) 85%, #fff);">Handpicked for you</p>
                        <h1 class="text-2xl font-extrabold leading-tight text-white mt-0.5">{{ $contact->full_name }}</h1>
                        <p class="text-sm" style="color: rgba(255,255,255,0.65);">A personalised property selection from your agent.</p>
                    </div>
                </div>

                @if($match->createdBy)
                <div class="flex items-center gap-3 flex-shrink-0 rounded-lg px-3.5 py-2.5"
                     style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.14);">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                         style="background: var(--brand-icon);">
                        {{ strtoupper(substr($match->createdBy->name, 0, 2)) }}
                    </div>
                    <div class="text-left">
                        <div class="text-[0.6875rem] font-semibold uppercase tracking-wider" style="color: rgba(255,255,255,0.55);">Your Agent</div>
                        <div class="text-sm font-semibold text-white leading-tight">{{ $match->createdBy->name }}</div>
                        @if($match->createdBy->cell || $match->createdBy->phone)
                        <a href="tel:{{ $match->createdBy->cell ?? $match->createdBy->phone }}"
                           class="text-xs font-medium" style="color: color-mix(in srgb, var(--brand-icon) 85%, #fff);">
                            {{ $match->createdBy->cell ?? $match->createdBy->phone }}
                        </a>
                        @endif
                    </div>
                </div>
                @endif
            </div>

            @if($contact->phone || $contact->email)
            <div class="relative flex items-center gap-4 mt-4 pt-4 flex-wrap text-sm" style="border-top: 1px solid rgba(255,255,255,0.12); color: rgba(255,255,255,0.7);">
                @if($contact->phone)
                <a href="tel:{{ $contact->phone }}" class="inline-flex items-center gap-1.5" style="color: rgba(255,255,255,0.7);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                    {{ $contact->phone }}
                </a>
                @endif
                @if($contact->email)
                <a href="mailto:{{ $contact->email }}" class="inline-flex items-center gap-1.5" style="color: rgba(255,255,255,0.7);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                    {{ $contact->email }}
                </a>
                @endif
            </div>
            @endif
        </section>

        {{-- Property results — one section per saved wishlist. A contact with
             one wishlist sees it plainly (unchanged); more than one and each
             gets its own header/dropdown. --}}
        @foreach($matchGroups as $group)
            @include('shared._match-group', ['group' => $group, 'showHeader' => $showMatchHeaders, 'token' => $token])
        @endforeach

    </main>

    {{-- "Not for me" reason modal --}}
    <div id="reasonModal" class="fixed inset-0 z-50 items-center justify-center p-4" style="display:none; background: rgba(0,0,0,0.5);">
        <div class="w-full max-w-md rounded-xl overflow-hidden"
             style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 10px 30px rgba(0,0,0,0.18);"
             onclick="event.stopPropagation()">
            <div class="px-5 py-4" style="border-bottom: 1px solid var(--border);">
                <div class="text-base font-semibold" style="color: var(--text-primary);">Quick feedback (optional)</div>
                <div class="text-xs mt-0.5" style="color: var(--text-muted);">Tell your agent why this property isn't for you — it helps us show you better matches. Or skip and close.</div>
            </div>
            <div class="px-5 py-4">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Reason</label>
                <textarea id="reasonText" rows="4" placeholder="e.g. Too far from town, no garden, kitchen feels small…"
                          class="field-input" style="resize: vertical; line-height: 1.5;"></textarea>
            </div>
            <div class="px-5 pb-4 flex items-center justify-end gap-2">
                <button type="button" id="reasonSkip" class="btn-outline">Skip</button>
                <button type="button" id="reasonSubmit" class="btn-primary">Submit feedback</button>
            </div>
        </div>
    </div>

    {{-- Footer --}}
    <footer class="mt-8 py-5 text-center text-xs" style="background: var(--brand-default); color: rgba(255,255,255,0.55);">
        <div class="font-semibold text-white">
            {{ $agency->name ?? 'Property Matches' }}
        </div>
        <div class="mt-0.5">Registered with the PPRA</div>
        <div class="mt-1">
            @if(!empty($agency) && $agency->city){{ $agency->city }}@endif
            @if($match->createdBy) · {{ $match->createdBy->name }} @endif
        </div>
    </footer>

    <script>
        /* ---- Record view + feedback (unchanged behaviour) ---- */
        document.querySelectorAll('.property-card-link').forEach(function(link) {
            link.addEventListener('click', function() {
                var url = this.dataset.recordView;
                if (url) fetch(url, {keepalive: true});
            });
        });

        var reasonModal   = document.getElementById('reasonModal');
        var reasonText    = document.getElementById('reasonText');
        var reasonSkip    = document.getElementById('reasonSkip');
        var reasonSubmit  = document.getElementById('reasonSubmit');
        var pendingCtx    = null;

        function openReasonModal(ctx) {
            pendingCtx = ctx;
            reasonText.value = '';
            reasonModal.style.display = 'flex';
            setTimeout(function () { reasonText.focus(); }, 50);
        }
        function closeReasonModal() { reasonModal.style.display = 'none'; pendingCtx = null; }
        reasonModal.addEventListener('click', function (e) {
            if (e.target === reasonModal) {
                if (pendingCtx) submitReaction(pendingCtx, null);
                closeReasonModal();
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && reasonModal.style.display === 'flex') {
                if (pendingCtx) submitReaction(pendingCtx, null);
                closeReasonModal();
            }
        });
        reasonSkip.addEventListener('click', function () {
            if (pendingCtx) submitReaction(pendingCtx, null);
            closeReasonModal();
        });
        reasonSubmit.addEventListener('click', function () {
            var note = (reasonText.value || '').trim();
            if (pendingCtx) submitReaction(pendingCtx, note || null);
            closeReasonModal();
        });

        function applyActive(wrap, clicked) {
            wrap.querySelectorAll('.feedback-btn').forEach(function (b) {
                b.classList.remove('is-active'); b.style.background = ''; b.style.borderColor = ''; b.style.color = '';
            });
            var col = clicked.dataset.colour || 'var(--brand-icon)';
            clicked.classList.add('is-active');
            clicked.style.background = col; clicked.style.borderColor = col; clicked.style.color = '#fff';
        }

        function submitReaction(ctx, note) {
            var body = { reaction: ctx.reaction };
            if (note) body.note = note;
            fetch(ctx.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
                },
                body: JSON.stringify(body),
                credentials: 'same-origin'
            }).then(function (r) { if (r.ok) applyActive(ctx.wrap, ctx.clicked); });
        }

        document.querySelectorAll('.feedback-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                var wrap = this.closest('[data-feedback-url]');
                if (!wrap) return;
                var ctx = { wrap: wrap, clicked: this, url: wrap.dataset.feedbackUrl, reaction: this.dataset.reaction };
                if (ctx.reaction === 'not_interested') openReasonModal(ctx);
                else submitReaction(ctx, null);
            });
        });

        /* ---- Refine bar (instant client-side filtering) + Load more ----
           Runs once per wishlist section (.match-group-root) so a contact with
           several saved searches gets independent filtering per dropdown,
           scoped entirely by class lookups inside that section's own root —
           never by page-global ids. */
        document.querySelectorAll('.match-group-root').forEach(function (root) {
            var bar = root.querySelector('.js-refine-bar');
            if (!bar) return;

            var PAGE  = 10;   // initial + per-click reveal count
            var limit = PAGE; // how many eligible cards are currently shown

            // Cards are rendered in server order = match_score descending, so the
            // strongest (e.g. 100%) matches naturally come first unless filtered.
            var cards       = Array.prototype.slice.call(root.querySelectorAll('.js-match-list .match-card'));
            var shownCount  = root.querySelector('.js-shown-count');
            var emptyState  = root.querySelector('.js-filtered-empty');
            var loadWrap    = root.querySelector('.js-load-more-wrap');
            var loadBtn     = root.querySelector('.js-load-more-btn');
            var loadRemain  = root.querySelector('.js-load-more-remain');

            var fLocation = root.querySelector('.js-f-location');
            var fBeds     = root.querySelector('.js-f-beds');
            var fMatch    = root.querySelector('.js-f-match');
            var fMatchVal = root.querySelector('.js-f-match-val');
            var fPriceMin = root.querySelector('.js-f-price-min');
            var fPriceMax = root.querySelector('.js-f-price-max');
            var fPriceMinVal = root.querySelector('.js-f-price-min-val');
            var fPriceMaxVal = root.querySelector('.js-f-price-max-val');

            function zar(n) { return new Intl.NumberFormat('en-ZA').format(Math.round(n || 0)); }

            function passes(c) {
                var loc  = fLocation ? fLocation.value : '';
                var beds = fBeds ? parseInt(fBeds.value, 10) : 0;
                var minS = fMatch ? parseInt(fMatch.value, 10) : 0;
                var pMin = fPriceMin ? parseInt(fPriceMin.value, 10) : null;
                var pMax = fPriceMax ? parseInt(fPriceMax.value, 10) : null;

                var price = c.dataset.price ? parseInt(c.dataset.price, 10) : null;
                var score = parseInt(c.dataset.score || '0', 10);
                var sub   = c.dataset.suburb || '';
                var b     = parseInt(c.dataset.beds || '0', 10);

                if (loc && sub !== loc) return false;
                if (beds && b < beds) return false;
                if (minS && score < minS) return false;
                // Listings without a price are never hidden by the price band.
                if (price !== null && pMin !== null && pMax !== null && (price < pMin || price > pMax)) return false;
                return true;
            }

            function render() {
                var eligible = 0, visible = 0;
                cards.forEach(function (c) {
                    if (passes(c)) {
                        eligible++;
                        if (visible < limit) { c.style.display = ''; visible++; }
                        else { c.style.display = 'none'; }
                    } else {
                        c.style.display = 'none';
                    }
                });

                if (shownCount) shownCount.textContent = 'Showing ' + visible + ' of ' + eligible;
                if (emptyState) emptyState.style.display = eligible === 0 ? 'block' : 'none';

                var remaining = eligible - visible;
                if (loadWrap) loadWrap.style.display = remaining > 0 ? 'flex' : 'none';
                if (loadRemain) loadRemain.textContent = remaining > 0 ? ('(' + remaining + ' more)') : '';
            }

            // A filter change resets paging back to the first page.
            function refilter() { limit = PAGE; render(); }

            // Keep the two price thumbs from crossing.
            if (fPriceMin && fPriceMax) {
                var syncPrice = function () {
                    var lo = parseInt(fPriceMin.value, 10);
                    var hi = parseInt(fPriceMax.value, 10);
                    if (lo > hi) {
                        if (document.activeElement === fPriceMin) fPriceMax.value = lo;
                        else fPriceMin.value = hi;
                    }
                    fPriceMinVal.textContent = zar(fPriceMin.value);
                    fPriceMaxVal.textContent = zar(fPriceMax.value);
                };
                fPriceMin.addEventListener('input', function () { syncPrice(); refilter(); });
                fPriceMax.addEventListener('input', function () { syncPrice(); refilter(); });
                syncPrice();
            }
            if (fMatch)    fMatch.addEventListener('input', function () { fMatchVal.textContent = fMatch.value; refilter(); });
            if (fLocation) fLocation.addEventListener('change', refilter);
            if (fBeds)     fBeds.addEventListener('change', refilter);
            if (loadBtn)   loadBtn.addEventListener('click', function () { limit += PAGE; render(); });

            function clearAll() {
                if (fLocation) fLocation.value = '';
                if (fBeds)     fBeds.value = '0';
                if (fMatch)  { fMatch.value = '0'; fMatchVal.textContent = '0'; }
                if (fPriceMin && fPriceMax) {
                    fPriceMin.value = fPriceMin.min;
                    fPriceMax.value = fPriceMax.max;
                    fPriceMinVal.textContent = zar(fPriceMin.value);
                    fPriceMaxVal.textContent = zar(fPriceMax.value);
                }
                refilter();
            }
            var clearBtn  = root.querySelector('.js-refine-clear');
            var clearBtn2 = root.querySelector('.js-filtered-clear');
            if (clearBtn)  clearBtn.addEventListener('click', clearAll);
            if (clearBtn2) clearBtn2.addEventListener('click', clearAll);

            bar.style.display = '';   // reveal (was hidden to avoid FOUC)
            render();
        });
    </script>
</body>
</html>
