@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Import Document Template</h1>
                <p class="text-sm text-white/60">Upload a Word document (.docx). CoreX detects fillable fields and converts it to a web template automatically.</p>
            </div>
        </div>
    </div>

    {{-- Flash messages (§3.9 alert pattern) --}}
    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <span class="flex-1">{{ session('success') }}</span>
        </div>
    @endif

    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <span class="flex-1">{{ session('error') }}</span>
        </div>
    @endif

    {{-- Resume drafts section --}}
    @if(isset($drafts) && $drafts->count() > 0)
        <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border);"
             x-data="{ csrfToken: '{{ csrf_token() }}' }">
            <h2 class="text-xs font-semibold uppercase tracking-wider mb-3" style="color: var(--text-secondary);">Drafts in Progress</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                @foreach($drafts as $draft)
                    <div class="flex items-center justify-between rounded-md px-4 py-3"
                         style="background: var(--surface-2); border: 1px solid var(--border);"
                         id="draft-row-{{ $draft->id }}">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium truncate" style="color: var(--text-primary);">{{ $draft->template_name }}</p>
                            <p class="text-xs" style="color: var(--text-muted);">
                                {{ number_format($draft->linked_count) }} of {{ number_format($draft->tag_count) }} linked
                                &middot;
                                {{ $draft->updated_at->diffForHumans() }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3 ml-4">
                            <a href="{{ route('docuperfect.import.review', ['draft_id' => $draft->id]) }}"
                               class="text-xs font-semibold whitespace-nowrap"
                               style="color: var(--brand-icon);">
                                Resume &rarr;
                            </a>
                            <button type="button"
                                    class="text-xs font-semibold whitespace-nowrap"
                                    style="color: var(--ds-crimson);"
                                    @click="if(confirm('Delete this draft?')) {
                                        fetch('{{ route('docuperfect.import.draft.destroy', $draft->id) }}', {
                                            method: 'DELETE',
                                            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
                                        }).then(r => {
                                            if(r.ok) document.getElementById('draft-row-{{ $draft->id }}').remove();
                                        });
                                    }">
                                Delete
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Two upload methods side-by-side on large screens --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">

        {{-- Standard upload --}}
        <div class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border);"
             x-data="{
                fileName: '',
                dragging: false,
                submitting: false,
                progress: '',
                error: '',

                // AT-262 — zero-field guard state.
                zeroFields: false,
                acceptedMarkers: [],
                pendingRedirect: '',

                async submitForm() {
                    const fileInput = this.$refs.fileInput;
                    const templateName = this.$refs.templateName.value.trim();

                    if (!fileInput.files.length) {
                        this.error = 'Please select a file.';
                        return;
                    }
                    if (!templateName) {
                        this.error = 'Please enter a template name.';
                        return;
                    }

                    this.submitting = true;
                    this.progress = 'Uploading and converting...';
                    this.error = '';

                    const formData = new FormData();
                    formData.append('document', fileInput.files[0]);
                    formData.append('template_name', templateName);
                    formData.append('_token', document.querySelector('meta[name=csrf-token]').content);

                    try {
                        const response = await fetch('{{ route("docuperfect.import.parse") }}', {
                            method: 'POST',
                            headers: { 'Accept': 'application/json' },
                            body: formData,
                        });

                        let data;
                        const contentType = response.headers.get('content-type') || '';
                        if (contentType.includes('application/json')) {
                            data = await response.json();
                        } else {
                            const text = await response.text();
                            console.error('Non-JSON response:', response.status, text.substring(0, 500));
                            this.error = 'Server error (HTTP ' + response.status + '). Check logs for details.';
                            this.submitting = false;
                            this.progress = '';
                            return;
                        }

                        if (!response.ok || data.error) {
                            this.error = data.error || 'An error occurred. Please try again.';
                            console.error('Parse error:', data);
                            this.submitting = false;
                            this.progress = '';
                            return;
                        }

                        if (data.warnings && data.warnings.length) {
                            console.warn('Mammoth warnings:', data.warnings);
                        }

                        // AT-262 — zero-field guard. Never say "ready" when we found
                        // nothing. Warn, teach the accepted markers, and let the author
                        // decide: go back and mark the document, or continue anyway (a
                        // document with no fill-ins is a legitimate template).
                        if (data.zero_fields) {
                            this.zeroFields = true;
                            this.acceptedMarkers = data.accepted_markers || [];
                            this.pendingRedirect = data.redirect;
                            this.submitting = false;
                            this.progress = '';
                            return;
                        }

                        this.progress = 'Complete! Redirecting...';
                        window.location.href = data.redirect;

                    } catch (err) {
                        console.error('Fetch exception:', err);
                        this.error = 'Request failed: ' + err.message;
                        this.submitting = false;
                        this.progress = '';
                    }
                },
            }">

            <p class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Standard import</p>
            <p class="text-xs mb-4" style="color: var(--text-muted);">Detects fillable fields from a .docx and builds a web template.</p>

            {{-- Template Name --}}
            <div class="mb-5">
                <label for="template_name" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Template Name <span class="text-red-500">*</span></label>
                <input type="text" x-ref="templateName" id="template_name"
                       value="{{ old('template_name') }}"
                       placeholder="e.g. Residential Lease Agreement v2"
                       class="w-full rounded-md px-3 py-2 text-sm"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                       :disabled="submitting"
                       required>
                @error('template_name')
                    <p class="mt-1 text-xs" style="color: var(--ds-crimson);">{{ $message }}</p>
                @enderror
            </div>

            {{-- File Upload --}}
            <div class="mb-6">
                <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Document File</label>
                <div class="relative border-2 border-dashed rounded-md p-8 text-center transition-colors"
                     :style="dragging
                        ? 'border-color: var(--brand-button); background: color-mix(in srgb, var(--brand-button) 8%, transparent);'
                        : 'border-color: var(--border);'"
                     @dragover.prevent="dragging = true"
                     @dragleave.prevent="dragging = false"
                     @drop.prevent="dragging = false; $refs.fileInput.files = $event.dataTransfer.files; fileName = $event.dataTransfer.files[0]?.name || ''">
                    <input type="file" accept=".docx,.pdf" class="hidden" x-ref="fileInput"
                           @change="fileName = $event.target.files[0]?.name || ''">

                    <div x-show="!fileName" class="space-y-2">
                        <svg class="mx-auto h-10 w-10" style="color: var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m6.75 12-3-3m0 0-3 3m3-3v6m-1.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                        </svg>
                        <p class="text-sm" style="color: var(--text-secondary);">
                            <button type="button" @click="$refs.fileInput.click()"
                                    class="font-semibold"
                                    style="color: var(--brand-icon);"
                                    :disabled="submitting">
                                Click to upload
                            </button>
                            or drag and drop
                        </p>
                        <p class="text-xs" style="color: var(--text-muted);">.docx or .pdf, max 10MB</p>
                    </div>

                    <div x-show="fileName" x-cloak class="space-y-2">
                        <svg class="mx-auto h-10 w-10" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        <p class="text-sm font-medium" style="color: var(--text-primary);" x-text="fileName"></p>
                        <button type="button" @click="fileName = ''; $refs.fileInput.value = ''"
                                class="text-xs font-semibold"
                                style="color: var(--ds-crimson);"
                                :disabled="submitting">Remove</button>
                    </div>
                </div>
                @error('docx_file')
                    <p class="mt-1 text-xs" style="color: var(--ds-crimson);">{{ $message }}</p>
                @enderror
            </div>

            {{-- Progress / Error Messages --}}
            <div x-show="submitting && progress" x-cloak
                 class="mb-4 rounded-md px-4 py-3 flex items-center gap-3 text-sm"
                 style="background: color-mix(in srgb, var(--brand-icon) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--brand-icon) 30%, transparent);
                        color: var(--text-primary);">
                <svg class="animate-spin h-4 w-4 flex-shrink-0" style="color: var(--brand-icon);" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span x-text="progress"></span>
            </div>

            <div x-show="error" x-cloak
                 class="mb-4 rounded-md px-4 py-3 text-sm"
                 style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                        color: var(--text-primary);"
                 x-text="error"></div>

            {{-- AT-262 — ZERO-FIELD WARNING. Warn AND teach: an import that found
                 nothing is never reported as ready, and the author is shown the exact
                 syntaxes the parser accepts (rendered from the parser's own list) so
                 they can go and mark the document. Continuing is still allowed — a
                 document with no fill-ins is a legitimate template — but it is now a
                 deliberate choice rather than a silent one. --}}
            <div x-show="zeroFields" x-cloak
                 class="mb-4 rounded-md px-4 py-3 text-sm"
                 style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                        border: 1px solid color-mix(in srgb, var(--ds-amber) 35%, transparent);
                        color: var(--text-primary);">
                <p class="font-semibold mb-1" style="color: var(--ds-amber);">
                    No fields were found in this document.
                </p>
                <p class="mb-2" style="color: var(--text-muted);">
                    The document imported fine — but it contains none of the markers the importer
                    looks for, so there is nothing to map. Add the markers below in Word and upload
                    again. (If this document genuinely has no fill-in areas, you can continue.)
                </p>

                <ul class="space-y-1 mb-3">
                    <template x-for="m in acceptedMarkers" :key="m.token">
                        <li class="flex items-start gap-2">
                            <code class="font-mono px-1.5 py-0.5 rounded text-xs shrink-0"
                                  style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);"
                                  x-text="m.token"></code>
                            <span class="text-xs" style="color: var(--text-muted);">
                                <span class="font-semibold" style="color: var(--text-primary);" x-text="m.label"></span>
                                — <span x-text="m.hint"></span>
                            </span>
                        </li>
                    </template>
                </ul>

                <div class="flex items-center gap-2">
                    <button type="button" @click="zeroFields = false; fileName = ''; $refs.fileInput.value = '';"
                            class="corex-btn-primary text-xs">
                        Go back and mark the document
                    </button>
                    <button type="button" @click="window.location.href = pendingRedirect"
                            class="text-xs underline" style="color: var(--text-muted);">
                        Continue anyway
                    </button>
                </div>
            </div>

            {{-- Submit --}}
            <button type="button"
                    @click="submitForm()"
                    :disabled="submitting"
                    class="corex-btn-primary w-full justify-center disabled:opacity-40 disabled:cursor-not-allowed">
                <span x-text="submitting ? 'Processing...' : 'Parse Document'"></span>
            </button>
        </div>

        {{-- CDS Engine Import --}}
        <form method="POST" action="{{ route('docuperfect.import.cds') }}" enctype="multipart/form-data"
              class="rounded-md p-6" style="background: var(--surface); border: 1px solid var(--border);"
              x-data="{ cdsFile: '', cdsSubmitting: false }"
              @submit="cdsSubmitting = true">
            @csrf
            <p class="text-sm font-semibold mb-1" style="color: var(--text-primary);">Import with CoreX Document Engine</p>
            <p class="text-xs mb-3" style="color: var(--text-muted);">
                Uses the new CDS parser for structured document import with field detection.
            </p>
            <div class="flex items-center gap-3">
                <div class="flex-1">
                    <input type="file" name="document" accept=".docx" class="hidden" x-ref="cdsFileInput"
                           @change="cdsFile = $event.target.files[0]?.name || ''">
                    <div @click="$refs.cdsFileInput.click()"
                         class="cursor-pointer border border-dashed rounded-md px-4 py-2 text-center text-sm transition-colors"
                         style="border-color: var(--border); color: var(--text-secondary);">
                        <span x-text="cdsFile || 'Choose .docx file...'"></span>
                    </div>
                </div>
                <button type="submit" :disabled="!cdsFile || cdsSubmitting"
                        class="corex-btn-primary disabled:opacity-40 disabled:cursor-not-allowed">
                    <span x-text="cdsSubmitting ? 'Importing...' : 'Import CDS'"></span>
                </button>
            </div>

            {{-- Marker guide --}}
            <div class="mt-3 rounded-md p-4"
                 style="background: var(--surface-2); border: 1px solid var(--border);">
                <h4 class="text-xs font-semibold uppercase tracking-wider mb-2" style="color: var(--text-secondary);">
                    Preparing your document
                </h4>
                <p class="text-xs mb-3" style="color: var(--text-muted);">
                    Before uploading, replace fill-in areas in your Word document with these markers:
                </p>
                <div class="space-y-2">
                    <div class="flex items-center gap-2 text-xs">
                        <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                              style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">@@@@</code>
                        <span style="color: var(--text-secondary);">Input field &mdash; where data needs to be filled in</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                              style="background: color-mix(in srgb, var(--ds-amber) 12%, transparent); color: var(--ds-amber);">%%%%</code>
                        <span style="color: var(--text-secondary);">Signature block &mdash; where parties sign</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                              style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">####</code>
                        <span style="color: var(--text-secondary);">Initial block &mdash; where parties initial</span>
                    </div>

                    {{-- ES-9 / ES-6 — insertable-block marker family --}}
                    <div class="mt-3 pt-3 rounded-md p-3"
                         style="border-top: 1px solid var(--border);
                                background: color-mix(in srgb, var(--brand-icon) 5%, transparent);">
                        <p class="text-[0.6875rem] font-semibold uppercase tracking-wider mb-2"
                           style="color: var(--brand-icon);">
                            Insertable blocks (<span class="font-mono">~~~~</span>)
                        </p>
                        <div class="space-y-1.5">
                            <div class="flex items-start gap-2 text-xs">
                                <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                                      style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">~~~~OTHER_CONDITIONS~~~~</code>
                            </div>
                            <p class="text-[0.6875rem] pl-1" style="color: var(--text-secondary);">
                                Other Conditions &mdash; numbered list for additional clauses (max 1 per template)
                            </p>

                            <div class="flex items-start gap-2 text-xs pt-1">
                                <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                                      style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">~~~~INCLUDED_ITEMS~~~~</code>
                            </div>
                            <p class="text-[0.6875rem] pl-1" style="color: var(--text-secondary);">
                                Included items &mdash; fixtures, fittings, items in sale
                            </p>

                            <div class="flex items-start gap-2 text-xs pt-1">
                                <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                                      style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">~~~~EXCLUDED_ITEMS~~~~</code>
                            </div>
                            <p class="text-[0.6875rem] pl-1" style="color: var(--text-secondary);">
                                Excluded items &mdash; what's NOT part of the sale
                            </p>

                            <div class="flex items-start gap-2 text-xs pt-1">
                                <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                                      style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">~~~~CUSTOM:&lt;label&gt;~~~~</code>
                            </div>
                            <p class="text-[0.6875rem] pl-1" style="color: var(--text-secondary);">
                                Custom-named block &mdash; e.g. <code class="font-mono">~~~~CUSTOM:Outstanding Repairs~~~~</code>
                            </p>

                            {{-- AT-262 — name your own fields in plain language. --}}
                            <div class="flex items-start gap-2 text-xs pt-2">
                                <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                                      style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">~~~~Seller - Full name~~~~</code>
                            </div>
                            <p class="text-[0.6875rem] pl-1" style="color: var(--text-secondary);">
                                Name a field however you like &mdash; e.g. <code class="font-mono">~~~~Property - Erf / Scheme~~~~</code> or <code class="font-mono">~~~~Asking price (Rand)~~~~</code>.
                                A name may use {{ \App\Services\Docuperfect\CdsParserService::INSERTABLE_NAME_HUMAN }} (anything except the <span class="font-mono">~</span> itself).
                            </p>
                        </div>
                    </div>
                </div>
                {{-- AT-262 — the Word-native conventions the standard importer ALSO reads.
                     They were accepted by the parser but never taught, so authors only
                     ever heard about half of what works. The screen now teaches
                     everything it reads. --}}
                <div class="mt-3 pt-3" style="border-top: 1px solid var(--border);">
                    <p class="text-[0.6875rem] font-semibold uppercase tracking-wider mb-2"
                       style="color: var(--text-secondary);">
                        Also recognised (standard import)
                    </p>
                    <div class="flex items-center gap-2 text-xs mb-1">
                        <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                              style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">________</code>
                        <span style="color: var(--text-secondary);">An underscore run &mdash; the usual Word fill-in line</span>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <code class="px-2 py-0.5 rounded font-mono font-bold whitespace-nowrap"
                              style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">[Full Name]</code>
                        <span style="color: var(--text-secondary);">A square-bracketed label</span>
                    </div>
                </div>

                <p class="text-xs mt-3" style="color: var(--text-muted);">
                    The system auto-detects fields from surrounding text. You can also tag fields manually after import.
                </p>
                <p class="text-xs mt-2" style="color: var(--text-muted);">
                    <strong>Tip:</strong> Insertable blocks let recipients add conditions during signing.
                    The first three markers stay locked to their template positions;
                    <span class="font-mono">~~~~</span> markers become live insertable areas.
                </p>
            </div>
        </form>
    </div>
</div>

@endsection
