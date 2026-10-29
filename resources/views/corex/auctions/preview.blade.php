@extends('layouts.corex')

@section('corex-content')
@php
    $field = 'w-full px-3 py-2 text-sm rounded-md';
    $fieldStyle = 'border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);';
    $label = 'block text-xs font-semibold uppercase tracking-wider mb-1';
    $hint = 'text-[11px] mt-1';
    $editing = $canEdit;
@endphp
<div class="corex-auctions w-full flex flex-col gap-4" x-data="auctionPreview()" x-init="init()">
    <div class="rounded-md px-6 py-5 corex-page-banner flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-base font-bold" style="color:var(--text-primary);">Preview page</h1>
            <p class="text-xs" style="color:var(--text-muted);">See your public auction page exactly as buyers will, and change its wording and pictures. Drafts show here, but nobody else can see them until you publish.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ $liveUrl }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs px-3 py-2" data-open-live>Open the live page ↗</a>
            <button type="button" class="corex-btn-outline text-xs px-3 py-2" @click="copy()" x-text="copied ? 'Link copied' : 'Copy link'">Copy link</button>
        </div>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    <form id="preview-form" method="POST" action="{{ route('corex.auctions.preview.save') }}" enctype="multipart/form-data" class="grid grid-cols-1 xl:grid-cols-5 gap-4">
        @csrf
        <input type="hidden" name="path" x-model="path">
        <input type="hidden" name="page" :value="pageKey">

        {{-- ── the editor ─────────────────────────────────────────────── --}}
        <div class="xl:col-span-2 flex flex-col gap-4">
            <fieldset @disabled(! $editing) class="flex flex-col gap-4">
            <section class="rounded-md p-5 space-y-3" style="background:var(--surface);border:1px solid var(--border);" data-editor-page>
                <h2 class="text-sm font-bold" style="color:var(--text-primary);">Your page</h2>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Page title</label>
                    <input name="site_title" value="{{ old('site_title', $values['site_title']) }}" placeholder="{{ $agency->name }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Introduction</label>
                    <textarea name="intro_text" rows="3" class="{{ $field }}" style="{{ $fieldStyle }}">{{ old('intro_text', $values['intro_text']) }}</textarea></div>

                <div>
                    <label class="{{ $label }}" style="color:var(--text-secondary);">Banner picture</label>
                    @if($values['hero_url'])<img src="{{ $values['hero_url'] }}" alt="" class="rounded-md mb-2" style="max-height:90px;">
                    <label class="inline-flex items-center gap-2 text-xs mb-1" style="color:var(--text-muted);"><input type="checkbox" name="remove_hero" value="1"> Remove the current banner</label>@endif
                    <input type="file" name="hero_image" accept=".jpg,.jpeg,.png,.webp" class="text-xs">
                    <p class="{{ $hint }}" style="color:var(--text-muted);">Shown across the top of your home page. JPG, PNG or WebP, up to 4 MB.</p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div><label class="{{ $label }}" style="color:var(--text-secondary);">Accent colour</label>
                        <div class="flex items-center gap-2">
                            <input type="color" x-model="accent" class="h-9 w-12 rounded-md" style="{{ $fieldStyle }}">
                            <input type="text" name="accent_color" x-model="accent" maxlength="7" placeholder="{{ $brandColor }}" class="{{ $field }}" style="{{ $fieldStyle }}">
                        </div>
                        <p class="{{ $hint }}" style="color:var(--text-muted);">Blank uses your agency's brand colour.</p></div>
                    <div class="flex items-end"><label class="inline-flex items-center gap-2 text-sm"><input type="checkbox" name="show_past_auctions" value="1" @checked(old('show_past_auctions', $values['show_past_auctions']))> Show past auctions</label></div>
                </div>

                <div><label class="{{ $label }}" style="color:var(--text-secondary);">How bidding works <span class="normal-case font-normal">— optional</span></label>
                    <textarea name="how_it_works" rows="4" placeholder="1. Create a free account&#10;2. Register for the auction and upload your documents&#10;3. Once approved, bid online" class="{{ $field }}" style="{{ $fieldStyle }}">{{ old('how_it_works', $values['how_it_works']) }}</textarea></div>
                <div><label class="{{ $label }}" style="color:var(--text-secondary);">Footer text <span class="normal-case font-normal">— optional</span></label>
                    <textarea name="footer_text" rows="2" class="{{ $field }}" style="{{ $fieldStyle }}">{{ old('footer_text', $values['footer_text']) }}</textarea></div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="{{ $label }}" style="color:var(--text-secondary);">Enquiries email</label>
                        <input type="email" name="contact_email" value="{{ old('contact_email', $values['contact_email']) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                    <div><label class="{{ $label }}" style="color:var(--text-secondary);">Enquiries phone</label>
                        <input name="contact_phone" value="{{ old('contact_phone', $values['contact_phone']) }}" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                </div>
            </section>

            <section class="rounded-md p-5 space-y-3" style="background:var(--surface);border:1px solid var(--border);" data-editor-auction>
                <h2 class="text-sm font-bold" style="color:var(--text-primary);">An auction's page</h2>
                @if($auctions->isEmpty())
                    <p class="text-xs" style="color:var(--text-muted);">You have no auctions yet. <a href="{{ route('corex.auctions.create') }}" class="underline">Create one</a> and it will appear here.</p>
                @else
                <select x-model="editAuction" class="{{ $field }}" style="{{ $fieldStyle }}" @change="goAuction(editAuction)" aria-label="Auction to edit">
                    @foreach($auctions as $a)<option value="{{ $a->id }}">{{ $a->reference }} — {{ $a->title }}@if($a->status === 'draft') (draft)@endif</option>@endforeach
                </select>
                @foreach($auctions as $a)
                <div x-show="editAuction == {{ $a->id }}" x-cloak class="space-y-3" data-auction-fields="{{ $a->id }}">
                    @unless($a->isEditable())<p class="text-xs" style="color:#b45309;">This auction is {{ strtolower($a->statusLabel()) }} and can no longer be edited.</p>@endunless
                    <fieldset @disabled(! $a->isEditable()) class="space-y-3">
                    <div><label class="{{ $label }}" style="color:var(--text-secondary);">Title</label>
                        <input name="auctions[{{ $a->id }}][title]" value="{{ old("auctions.{$a->id}.title", $a->title) }}" maxlength="200" class="{{ $field }}" style="{{ $fieldStyle }}"></div>
                    <div><label class="{{ $label }}" style="color:var(--text-secondary);">Description</label>
                        <textarea name="auctions[{{ $a->id }}][description]" rows="5" class="{{ $field }}" style="{{ $fieldStyle }}">{{ old("auctions.{$a->id}.description", $a->description) }}</textarea></div>
                    <div><label class="{{ $label }}" style="color:var(--text-secondary);">Cover picture</label>
                        @if($a->cover_image_path)<img src="{{ asset('storage/' . $a->cover_image_path) }}" alt="" class="rounded-md mb-2" style="max-height:80px;">
                        <label class="inline-flex items-center gap-2 text-xs mb-1" style="color:var(--text-muted);"><input type="checkbox" name="auctions[{{ $a->id }}][remove_cover]" value="1"> Remove the current cover</label>@endif
                        <input type="file" name="cover_image[{{ $a->id }}]" accept=".jpg,.jpeg,.png,.webp" class="text-xs">
                        <p class="{{ $hint }}" style="color:var(--text-muted);">Shown on the home page card and at the top of the auction page.</p></div>
                    <p class="text-xs"><a href="{{ route('corex.auctions.edit', $a->id) }}" class="underline" style="color:var(--text-muted);">Dates, venue, documents and lots →</a>
                        @if($a->status === 'draft')<span style="color:#b45309;"> · This auction is a draft: publish it from its page to make it public.</span>@endif</p>
                    </fieldset>
                </div>
                @endforeach
                @endif
            </section>
            </fieldset>

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="corex-btn-outline" @click="update()" data-update-preview>Update preview</button>
                @if($editing)<button type="submit" class="corex-btn-primary" data-save-page>Save changes</button>@endif
                <span class="text-xs" style="color:var(--text-muted);">{{ $editing ? '"Update preview" shows your edits without saving them.' : 'You can look, but you do not have permission to change the page.' }}</span>
            </div>
        </div>

        {{-- ── the preview ────────────────────────────────────────────── --}}
        <div class="xl:col-span-3 flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-3">
                <select x-model="pageKey" @change="pickPage()" class="px-3 py-2 text-sm rounded-md" style="{{ $fieldStyle }}" aria-label="Page to preview" data-page-picker>
                    <option value="home">Home page</option>
                    @foreach($auctions as $a)
                    <optgroup label="{{ $a->reference }} — {{ \Illuminate\Support\Str::limit($a->title, 40) }}">
                        <option value="auction:{{ $a->id }}">The auction page</option>
                        @foreach($a->lots as $lot)<option value="lot:{{ $lot->id }}" data-auction="{{ $a->id }}">Lot {{ $lot->lot_number }} — {{ \Illuminate\Support\Str::limit($lot->displayTitle(), 34) }}</option>@endforeach
                    </optgroup>
                    @endforeach
                </select>
                <div class="inline-flex rounded-md overflow-hidden" style="border:1px solid var(--border);" role="group" aria-label="Screen size">
                    <button type="button" @click="width = '100%'" :style="width === '100%' ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'background:var(--surface);color:var(--text-primary);'" class="px-3 py-2 text-xs">Desktop</button>
                    <button type="button" @click="width = '768px'" :style="width === '768px' ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'background:var(--surface);color:var(--text-primary);'" class="px-3 py-2 text-xs">Tablet</button>
                    <button type="button" @click="width = '390px'" :style="width === '390px' ? 'background:var(--brand-button,#0ea5e9);color:#fff;' : 'background:var(--surface);color:var(--text-primary);'" class="px-3 py-2 text-xs">Phone</button>
                </div>
            </div>
            <div class="rounded-md overflow-auto" style="background:var(--surface-2);border:1px solid var(--border);padding:12px;">
                <iframe name="auction-preview-frame" title="Preview of your public auction page" :style="'width:' + width + ';height:78vh;border:0;background:#fff;display:block;margin:0 auto;border-radius:8px;'" src="{{ $frameUrl }}?path={{ urlencode($startPath) }}" data-preview-frame></iframe>
            </div>
        </div>
    </form>
</div>

<script>
    function auctionPreview() {
        var frameUrl = @json($frameUrl);
        var saveUrl = @json(route('corex.auctions.preview.save'));
        var lotAuction = @json($auctions->flatMap(fn ($a) => $a->lots->mapWithKeys(fn ($l) => [$l->id => $a->id]))->all());
        return {
            path: @json($startPath), pageKey: @json(request('page', 'home')), width: '100%', accent: @json($values['accent_color'] ?? ''),
            editAuction: @json((string) ($auctions->first()?->id ?? '')), copied: false,
            init() {
                var self = this;
                window.addEventListener('message', function (e) {
                    if (!e.data || typeof e.data.auctionPreviewPath !== 'string') return;
                    self.path = e.data.auctionPreviewPath;
                    var m = self.path.match(/^\/a\/(\d+)(?:\/lots\/(\d+))?$/);
                    if (!m) { self.pageKey = 'home'; return; }
                    self.pageKey = m[2] ? 'lot:' + m[2] : 'auction:' + m[1];
                    self.editAuction = m[1];
                });
                this.pageKey = this.keyFromPath(this.path);
            },
            keyFromPath(p) { var m = p.match(/^\/a\/(\d+)(?:\/lots\/(\d+))?$/); return !m ? 'home' : (m[2] ? 'lot:' + m[2] : 'auction:' + m[1]); },
            pathFromKey(k) {
                if (k === 'home') return '/';
                var a = k.match(/^auction:(\d+)$/); if (a) return '/a/' + a[1];
                var l = k.match(/^lot:(\d+)$/); return l && lotAuction[l[1]] ? '/a/' + lotAuction[l[1]] + '/lots/' + l[1] : '/';
            },
            pickPage() { this.path = this.pathFromKey(this.pageKey); var m = this.path.match(/^\/a\/(\d+)/); if (m) this.editAuction = m[1]; this.update(); },
            goAuction(id) { if (id) { this.pageKey = 'auction:' + id; this.path = '/a/' + id; this.update(); } },
            // Re-draw the preview with everything typed so far. Posts the form into the frame; nothing is saved.
            update() {
                var f = document.getElementById('preview-form');
                var oldAction = f.action, oldTarget = f.target;
                f.action = frameUrl; f.target = 'auction-preview-frame';
                f.submit();
                f.action = oldAction; f.target = oldTarget;
            },
            copy() { var self = this; navigator.clipboard.writeText(@json($liveUrl)).then(function () { self.copied = true; setTimeout(function () { self.copied = false; }, 1500); }); },
        };
    }
</script>
@endsection
