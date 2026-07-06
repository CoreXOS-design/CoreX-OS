@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6" x-data="rmcpEditor()">
    <x-page-header title="Edit RMCP v{{ $version->version_number }} Draft" :back-route="route('compliance.rmcp.show', $version)" back-label="View" :flush="true">
        <x-slot:actions>
            <span class="text-xs" style="color:#64748b;" x-show="saving">Saving...</span>
            <span class="text-xs" style="color:var(--brand-icon);" x-show="saved" x-cloak>Saved</span>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        {{-- Unsaved changes warning --}}
        <template x-if="hasUnsaved">
            <div class="mb-4 px-4 py-2 text-sm font-semibold" style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid rgba(234,179,8,0.3); border-radius:6px; color:#ca8a04;">
                You have unsaved changes.
            </div>
        </template>

        <div class="flex gap-6">
            {{-- Left: TOC --}}
            <div class="hidden lg:block flex-shrink-0" style="width:200px;">
                <div class="sticky top-16">
                    <h3 class="text-xs font-semibold uppercase mb-2" style="color:#64748b; letter-spacing:0.05em;">Sections</h3>
                    <nav class="space-y-0.5" style="max-height:calc(100vh - 120px); overflow-y:auto;">
                        @foreach($version->sections as $section)
                        <a href="#edit-section-{{ $section->id }}" class="block text-xs py-1 px-2 hover:bg-slate-50 transition" style="color:#64748b; border-radius:6px;">
                            {{ $section->section_number }}
                        </a>
                        @endforeach
                    </nav>
                </div>
            </div>

            {{-- Main: Section editors --}}
            <div class="flex-1 min-w-0 space-y-4">
                @foreach($version->sections as $section)
                <div id="edit-section-{{ $section->id }}" class="bg-white border p-4" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-semibold uppercase" style="color:#94a3b8;">{{ $section->section_type }} {{ $section->section_number }}</span>
                        <button type="button"
                                @click="saveSection({{ $section->id }}, $refs['title_{{ $section->id }}'].value, $refs['body_{{ $section->id }}'].value)"
                                class="text-xs font-semibold px-3 py-1.5 transition"
                                style="background:var(--brand-icon); color:var(--text-primary); border-radius:6px;">
                            Save Section
                        </button>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color:#64748b;">Title</label>
                        <input type="text" x-ref="title_{{ $section->id }}"
                               value="{{ $section->title }}"
                               @input="markUnsaved()"
                               class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color:#64748b;">Body (HTML)</label>
                        <textarea x-ref="body_{{ $section->id }}"
                                  @input="markUnsaved()"
                                  rows="10"
                                  class="w-full px-3 py-2 text-sm border font-mono" style="border-color:var(--border, #e5e7eb); border-radius:6px; line-height:1.6;">{{ $section->body_html }}</textarea>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Right: Variables reference --}}
            <div class="hidden xl:block flex-shrink-0" style="width:240px;">
                <div class="sticky top-16">
                    <h3 class="text-xs font-semibold uppercase mb-2" style="color:#64748b; letter-spacing:0.05em;">Available Variables</h3>
                    <div class="space-y-1" style="max-height:calc(100vh - 120px); overflow-y:auto;">
                        @foreach($variableKeys as $key)
                        {{-- Split mustache delimiters ('{'.'{' / '}'.'}') so no literal }} sits inside these echos — a literal }} closes Blade's {{ }} echo early and emits invalid PHP (AT-182 compile class). --}}
                        <div class="text-xs p-1.5 bg-slate-50" style="border-radius:6px; cursor:pointer;" @click="navigator.clipboard.writeText('{{ '{' . '{' . $key . '}' . '}' }}')">
                            <code class="font-mono" style="color:#0d9488;">{{ '{' . '{' . $key . '}' . '}' }}</code>
                            <div class="mt-0.5 truncate" style="color:#94a3b8;">{{ $variables[$key] ?? '(empty)' }}</div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function rmcpEditor() {
    return {
        saving: false,
        saved: false,
        hasUnsaved: false,

        markUnsaved() {
            this.hasUnsaved = true;
            this.saved = false;
        },

        async saveSection(sectionId, title, bodyHtml) {
            this.saving = true;
            this.saved = false;

            try {
                const res = await fetch('{{ route("compliance.rmcp.update", $version) }}', {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ section_id: sectionId, title: title, body_html: bodyHtml }),
                });

                const data = await res.json();
                if (data.success) {
                    this.saved = true;
                    this.hasUnsaved = false;
                    setTimeout(() => { this.saved = false; }, 3000);
                }
            } catch (e) {
                console.error('Save failed:', e);
            } finally {
                this.saving = false;
            }
        },

        init() {
            window.addEventListener('beforeunload', (e) => {
                if (this.hasUnsaved) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        }
    };
}
</script>
@endsection
