{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6" x-data="policyEditor()">
    <x-page-header :title="'Edit ' . $version->policy->name . ' v' . $version->version_number . ' Draft'" :back-route="route('compliance.policy.show', $version)" back-label="View" :flush="true">
        <x-slot:actions>
            <span class="text-xs" style="color:var(--text-muted);" x-show="saving">Saving...</span>
            <span class="text-xs" style="color:var(--brand-icon);" x-show="saved" x-cloak>Saved</span>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        @if(session('success'))
        <div class="mb-4 rounded-md px-4 py-2 text-sm font-semibold" style="background:color-mix(in srgb, var(--ds-green) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color:var(--text-primary);">{{ session('success') }}</div>
        @endif

        <template x-if="hasUnsaved">
            <div class="mb-4 rounded-md px-4 py-2 text-sm font-semibold" style="background:color-mix(in srgb, var(--ds-amber) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent); color:var(--text-primary);">
                You have unsaved changes.
            </div>
        </template>

        <div class="flex gap-6">
            <div class="flex-1 min-w-0 space-y-4">
                @foreach($version->sections as $section)
                <div id="edit-section-{{ $section->id }}" class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
                    <div class="flex items-center justify-between mb-3">
                        <span class="text-xs font-semibold uppercase" style="color:var(--text-muted);">{{ $section->section_type }} {{ $section->section_number }}</span>
                        <div class="flex items-center gap-3">
                            <button type="button"
                                    @click="saveSection({{ $section->id }}, $refs['title_{{ $section->id }}'].value, $refs['body_{{ $section->id }}'].value)"
                                    class="corex-btn-primary text-xs">
                                Save Section
                            </button>
                            <form method="POST" action="{{ route('compliance.policy.section.delete', [$version, $section]) }}" onsubmit="return confirm('Remove this section?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-xs font-semibold" style="color:var(--ds-crimson);">Remove</button>
                            </form>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Title</label>
                        <input type="text" x-ref="title_{{ $section->id }}" value="{{ $section->title }}" @input="markUnsaved()"
                               class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Body (HTML)</label>
                        <textarea x-ref="body_{{ $section->id }}" @input="markUnsaved()" rows="10"
                                  class="w-full rounded-md px-3 py-2 text-sm font-mono" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary); line-height:1.6;">{{ $section->body_html }}</textarea>
                    </div>
                </div>
                @endforeach

                {{-- Add section --}}
                <div class="rounded-md p-4" style="background:var(--surface); border:1px solid var(--border);">
                    <h3 class="text-sm font-bold mb-3" style="color:var(--text-primary);">Add Section</h3>
                    <form method="POST" action="{{ route('compliance.policy.section.add', $version) }}" class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
                        @csrf
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Number</label>
                            <input type="text" name="section_number" required placeholder="e.g. 2"
                                   class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Title</label>
                            <input type="text" name="title" required placeholder="Section title"
                                   class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Type</label>
                            <select name="section_type" class="w-full rounded-md px-3 py-2 text-sm" style="background:var(--surface); border:1px solid var(--border); color:var(--text-primary);">
                                <option value="section">Section</option>
                                <option value="schedule">Schedule</option>
                                <option value="annexure">Annexure</option>
                                <option value="acknowledgement">Acknowledgement</option>
                            </select>
                        </div>
                        <div class="sm:col-span-4 flex items-center gap-3">
                            <label class="flex items-center gap-2 text-xs" style="color:var(--text-secondary);">
                                <input type="checkbox" name="requires_acknowledgement" value="1" checked style="accent-color:var(--brand-icon);">
                                Requires acknowledgement
                            </label>
                            <button type="submit" class="corex-btn-primary">Add Section</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="hidden xl:block flex-shrink-0" style="width:240px;">
                <div class="sticky top-16">
                    <h3 class="text-xs font-semibold uppercase mb-2" style="color:var(--text-secondary); letter-spacing:0.05em;">Available Variables</h3>
                    <div class="space-y-1" style="max-height:calc(100vh - 120px); overflow-y:auto;">
                        @foreach($variableKeys as $key)
                        <div class="text-xs p-1.5 rounded-md" style="background:var(--surface-2); cursor:pointer;" @click="navigator.clipboard.writeText('{{ '{' . '{' . $key . '}' . '}' }}')">
                            <code class="font-mono" style="color:var(--brand-icon);">{{ '{' . '{' . $key . '}' . '}' }}</code>
                            <div class="mt-0.5 truncate" style="color:var(--text-muted);">{{ $variables[$key] ?? '(empty)' }}</div>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function policyEditor() {
    return {
        saving: false,
        saved: false,
        hasUnsaved: false,

        markUnsaved() { this.hasUnsaved = true; this.saved = false; },

        async saveSection(sectionId, title, bodyHtml) {
            this.saving = true;
            this.saved = false;
            try {
                const res = await fetch('{{ route("compliance.policy.update", $version) }}', {
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
                if (this.hasUnsaved) { e.preventDefault(); e.returnValue = ''; }
            });
        }
    };
}
</script>
@endsection
