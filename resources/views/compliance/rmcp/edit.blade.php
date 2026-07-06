{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6" x-data="rmcpEditor()">
    <x-page-header title="Edit RMCP v{{ $version->version_number }} Draft" :back-route="route('compliance.rmcp.show', $version)" back-label="View" :flush="true">
        <x-slot:actions>
            <span class="text-xs" style="color: var(--text-muted);" x-show="saving">Saving...</span>
            <span class="text-xs" style="color: var(--brand-icon);" x-show="saved" x-cloak>Saved</span>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        {{-- Unsaved changes warning --}}
        <template x-if="hasUnsaved">
            <div class="mb-4 px-4 py-2 text-sm font-semibold rounded-md" style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 30%, transparent); color: var(--text-primary);">
                You have unsaved changes.
            </div>
        </template>

        <div class="flex gap-6">
            {{-- Left: TOC --}}
            <div class="hidden lg:block flex-shrink-0" style="width:200px;">
                <div class="sticky top-4">
                    <h3 class="text-xs font-semibold uppercase mb-2" style="color: var(--text-muted); letter-spacing:0.05em;">Sections</h3>
                    <nav class="space-y-0.5" style="max-height:calc(100vh - 120px); overflow-y:auto;">
                        @foreach($version->sections as $section)
                        <a href="#edit-section-{{ $section->id }}" class="block text-xs py-1 px-2 rounded-md transition" style="color: var(--text-secondary);"
                           onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                            {{ $section->section_number }}
                        </a>
                        @endforeach
                    </nav>
                </div>
            </div>

            {{-- Main: Section editors --}}
            <div class="flex-1 min-w-0 space-y-4">
                @foreach($version->sections as $section)
                <div id="edit-section-{{ $section->id }}" class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-semibold uppercase" style="color: var(--text-muted);">{{ $section->section_type }} {{ $section->section_number }}</span>
                        <button type="button"
                                @click="saveSection({{ $section->id }}, $refs['title_{{ $section->id }}'].value, $refs['body_{{ $section->id }}'].value)"
                                class="corex-btn-primary text-xs">
                            Save Section
                        </button>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Title</label>
                        <input type="text" x-ref="title_{{ $section->id }}"
                               value="{{ $section->title }}"
                               @input="markUnsaved()"
                               class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Body (HTML)</label>
                        <textarea x-ref="body_{{ $section->id }}"
                                  @input="markUnsaved()"
                                  rows="10"
                                  class="w-full rounded-md px-3 py-2 text-sm font-mono" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary); line-height:1.6;">{{ $section->body_html }}</textarea>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Right: Variables reference --}}
            <div class="hidden xl:block flex-shrink-0" style="width:240px;">
                <div class="sticky top-4">
                    <h3 class="text-xs font-semibold uppercase mb-2" style="color: var(--text-muted); letter-spacing:0.05em;">Available Variables</h3>
                    <div class="space-y-1" style="max-height:calc(100vh - 120px); overflow-y:auto;">
                        @foreach($variableKeys as $key)
                        <div class="text-xs p-1.5 rounded-md" style="background: var(--surface-2); cursor:pointer;" @click="navigator.clipboard.writeText('{{ '{{' . $key . '}}' }}')">
                            <code class="font-mono" style="color: var(--brand-icon);">{{ '{{' . $key . '}}' }}</code>
                            <div class="mt-0.5 truncate" style="color: var(--text-muted);">{{ $variables[$key] ?? '(empty)' }}</div>
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
