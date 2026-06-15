{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')

<div class="w-full space-y-6">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Knowledge Base</h1>
                <p class="text-sm text-white/60">Ellie's training documents &amp; agent resources</p>
            </div>
        </div>
    </div>

    {{-- Upload Section --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h3 class="text-base font-semibold mb-4" style="color: var(--text-primary);">Upload Document</h3>
        <form action="{{ route('admin.knowledge.upload') }}" method="POST" enctype="multipart/form-data">
            @csrf
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                    <label class="ds-label">Title <span class="text-red-500">*</span></label>
                    <input type="text" name="title" required
                           class="w-full rounded-md text-sm px-3 py-2 mt-1 transition-all duration-300 focus:outline-none"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="Document title" value="{{ old('title') }}">
                    @error('title') <div class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="ds-label">Category <span class="text-red-500">*</span></label>
                    <select name="category_id" required
                            class="w-full rounded-md text-sm px-3 py-2 mt-1 transition-all duration-300 focus:outline-none"
                            style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="">Select category...</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                    @error('category_id') <div class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="ds-label">File <span class="text-red-500">*</span></label>
                    <input type="file" name="file" required accept=".pdf,.docx,.doc,.txt,.md"
                           class="w-full text-sm mt-1 rounded-md px-3 py-1.5 transition-all duration-300"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-secondary);">
                    @error('file') <div class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label class="ds-label">Description</label>
                    <textarea name="description" rows="2"
                              class="w-full rounded-md text-sm px-3 py-2 mt-1 transition-all duration-300 focus:outline-none"
                              style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                              placeholder="Optional description">{{ old('description') }}</textarea>
                </div>
                <div>
                    <label class="ds-label">Version</label>
                    <input type="text" name="version"
                           class="w-full rounded-md text-sm px-3 py-2 mt-1 transition-all duration-300 focus:outline-none"
                           style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="e.g. v2.1" value="{{ old('version') }}">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="corex-btn-primary px-4 py-2 text-sm">Upload Document</button>
                </div>
            </div>
            <div class="text-xs mt-3" style="color: var(--text-muted);">Accepted: PDF, DOCX, DOC, TXT, MD &mdash; Max 20MB</div>
        </form>
    </div>

    {{-- Stats Row --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="ds-status-card">
            <div class="ds-label">Total Documents</div>
            <div class="ds-value text-2xl">{{ number_format($stats['total_documents']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">
                <span style="color: var(--ds-green);">{{ number_format($stats['by_status']['ready']) }} ready</span>
                @if($stats['by_status']['processing'] > 0)
                    &middot; <span style="color: var(--ds-amber);">{{ number_format($stats['by_status']['processing']) }} processing</span>
                @endif
                @if($stats['by_status']['error'] > 0)
                    &middot; <span style="color: var(--ds-crimson);">{{ number_format($stats['by_status']['error']) }} error</span>
                @endif
            </div>
        </div>
        <div class="ds-status-card">
            <div class="ds-label">Total Chunks</div>
            <div class="ds-value text-2xl">{{ number_format($stats['total_chunks']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">Searchable text segments</div>
        </div>
        <div class="ds-status-card">
            <div class="ds-label">Ellie-Enabled</div>
            <div class="ds-value text-2xl">{{ number_format($stats['ellie_enabled']) }}</div>
            <div class="text-xs mt-1" style="color: var(--text-muted);">Documents Ellie can search</div>
        </div>
    </div>

    {{-- Categories Section --}}
    <div x-data="categoryManager()">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-semibold" style="color: var(--text-primary);">Categories</h3>
            <button type="button" @click="openCreate()" class="corex-btn-primary text-xs px-3 py-1.5 inline-flex items-center gap-1.5">
                <i class="fas fa-plus text-[10px]"></i> New Category
            </button>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($categories as $idx => $cat)
                <div class="rounded-md p-4 transition-all duration-300 relative"
                     style="background: var(--surface); border: 1px solid var(--border);"
                     onmouseover="this.style.borderColor='var(--brand-icon, #0ea5e9)'" onmouseout="this.style.borderColor='var(--border)'">
                    <div class="flex items-start justify-between gap-2">
                        <a href="{{ route('admin.knowledge.category', $cat->id) }}" class="flex-1 block" style="text-decoration:none;color:inherit;">
                            <div class="flex items-center gap-3 mb-2">
                                @if($cat->icon)
                                    <i class="fas {{ $cat->icon }} text-lg" style="color: var(--brand-icon, #0ea5e9);"></i>
                                @endif
                                <div class="font-semibold text-sm" style="color: var(--text-primary);">{{ $cat->name }}</div>
                            </div>
                            @if($cat->description)
                                <div class="text-xs mb-1" style="color: var(--text-muted);">{{ Str::limit($cat->description, 60) }}</div>
                            @endif
                            <div class="text-xs" style="color: var(--text-secondary);">{{ $cat->documents_count }} {{ Str::plural('document', $cat->documents_count) }}</div>
                            <div class="text-xs mt-1.5 font-medium" style="color: var(--brand-icon, #0ea5e9);">View &rarr;</div>
                        </a>
                        <div class="flex items-center gap-1 shrink-0">
                            {{-- Reorder arrows --}}
                            @if($idx > 0)
                                <button type="button" @click="moveUp({{ $cat->id }}, {{ $categories[$idx - 1]->id }})" class="p-1 transition-all duration-300 hover:opacity-80" style="color: var(--text-muted);" title="Move up">
                                    <i class="fas fa-arrow-up text-xs"></i>
                                </button>
                            @else
                                <span class="p-1" style="color: var(--border);"><i class="fas fa-arrow-up text-xs"></i></span>
                            @endif
                            @if($idx < count($categories) - 1)
                                <button type="button" @click="moveDown({{ $cat->id }}, {{ $categories[$idx + 1]->id }})" class="p-1 transition-all duration-300 hover:opacity-80" style="color: var(--text-muted);" title="Move down">
                                    <i class="fas fa-arrow-down text-xs"></i>
                                </button>
                            @else
                                <span class="p-1" style="color: var(--border);"><i class="fas fa-arrow-down text-xs"></i></span>
                            @endif
                            {{-- Edit --}}
                            <button type="button" @click="openEdit({{ $cat->id }}, {{ Js::from($cat->name) }}, {{ Js::from($cat->description) }}, {{ Js::from($cat->icon) }})" class="p-1 transition-all duration-300" style="color: var(--text-muted);" onmouseover="this.style.color='var(--brand-icon, #0ea5e9)'" onmouseout="this.style.color='var(--text-muted)'" title="Edit category">
                                <i class="fas fa-pencil-alt text-xs"></i>
                            </button>
                            {{-- Delete --}}
                            @if($cat->documents_count === 0)
                                <button type="button" @click="openDelete({{ $cat->id }}, {{ Js::from($cat->name) }})" class="p-1 transition-all duration-300" style="color: var(--text-muted);" onmouseover="this.style.color='var(--ds-crimson)'" onmouseout="this.style.color='var(--text-muted)'" title="Delete category">
                                    <i class="fas fa-trash text-xs"></i>
                                </button>
                            @else
                                <span class="p-1 cursor-not-allowed" style="color: var(--border);" title="Cannot delete — has {{ $cat->documents_count }} document(s)">
                                    <i class="fas fa-trash text-xs"></i>
                                </span>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Create / Edit Modal --}}
        <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @keydown.escape.window="showModal = false">
            <div class="rounded-md shadow-xl w-full max-w-md mx-4 p-6" style="background: var(--surface); border: 1px solid var(--border);" @click.outside="showModal = false">
                <h4 class="text-sm font-bold mb-4" style="color: var(--text-primary);" x-text="editId ? 'Edit Category' : 'New Category'"></h4>
                <form :action="editId ? '{{ url('admin/knowledge/categories') }}/' + editId : '{{ route('admin.knowledge.storeCategory') }}'" method="POST">
                    @csrf
                    <template x-if="editId">
                        <input type="hidden" name="_method" value="PUT">
                    </template>
                    <div class="space-y-3">
                        <div>
                            <label class="ds-label">Name <span class="text-red-500">*</span></label>
                            <input type="text" name="name" x-model="form.name" required maxlength="255"
                                   class="w-full rounded-md text-sm px-3 py-2 mt-1 transition-all duration-300 focus:outline-none"
                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                                   placeholder="Category name">
                        </div>
                        <div>
                            <label class="ds-label">Description</label>
                            <textarea name="description" x-model="form.description" rows="2" maxlength="2000"
                                      class="w-full rounded-md text-sm px-3 py-2 mt-1 transition-all duration-300 focus:outline-none"
                                      style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                                      placeholder="Optional description"></textarea>
                        </div>
                        <div>
                            <label class="ds-label">Icon class</label>
                            <div class="flex items-center gap-2">
                                <input type="text" name="icon" x-model="form.icon" maxlength="100"
                                       class="w-full rounded-md text-sm px-3 py-2 mt-1 transition-all duration-300 focus:outline-none"
                                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                                       placeholder="e.g. fa-building">
                                <span class="shrink-0 w-8 h-8 flex items-center justify-center rounded-md" style="background: var(--surface-2);">
                                    <i class="fas" :class="form.icon || 'fa-folder'" style="color: var(--brand-icon, #0ea5e9);"></i>
                                </span>
                            </div>
                            <div class="text-xs mt-1" style="color: var(--text-muted);">FontAwesome class, e.g. fa-building, fa-book, fa-gavel</div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 mt-5">
                        <button type="button" @click="showModal = false" class="corex-btn-outline text-xs px-3 py-1.5">Cancel</button>
                        <button type="submit" class="corex-btn-primary text-xs px-4 py-1.5" x-text="editId ? 'Save Changes' : 'Create Category'"></button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Delete Confirmation Modal --}}
        <div x-show="showDeleteModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @keydown.escape.window="showDeleteModal = false">
            <div class="rounded-md shadow-xl w-full max-w-sm mx-4 p-6" style="background: var(--surface); border: 1px solid var(--border);" @click.outside="showDeleteModal = false">
                <h4 class="text-sm font-bold mb-2" style="color: var(--ds-crimson);">Delete Category</h4>
                <p class="text-sm mb-4" style="color: var(--text-secondary);">Are you sure you want to delete <strong x-text="deleteName" style="color: var(--text-primary);"></strong>? This cannot be undone.</p>
                <form :action="'{{ url('admin/knowledge/categories') }}/' + deleteId" method="POST">
                    @csrf
                    @method('DELETE')
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="showDeleteModal = false" class="corex-btn-outline text-xs px-3 py-1.5">Cancel</button>
                        <button type="submit" class="text-xs px-4 py-1.5 rounded-md font-medium text-white transition-all duration-300 hover:opacity-90" style="background: var(--ds-crimson);">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    function categoryManager() {
        return {
            showModal: false,
            showDeleteModal: false,
            editId: null,
            deleteId: null,
            deleteName: '',
            form: { name: '', description: '', icon: '' },

            openCreate() {
                this.editId = null;
                this.form = { name: '', description: '', icon: '' };
                this.showModal = true;
            },

            openEdit(id, name, description, icon) {
                this.editId = id;
                this.form = { name: name || '', description: description || '', icon: icon || '' };
                this.showModal = true;
            },

            openDelete(id, name) {
                this.deleteId = id;
                this.deleteName = name;
                this.showDeleteModal = true;
            },

            moveUp(currentId, aboveId) {
                this.swapOrder(currentId, aboveId);
            },

            moveDown(currentId, belowId) {
                this.swapOrder(currentId, belowId);
            },

            swapOrder(idA, idB) {
                fetch('{{ route('admin.knowledge.reorderCategories') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ swap: [idA, idB] }),
                }).then(() => window.location.reload());
            }
        };
    }
    </script>

    {{-- Recent Documents Section --}}
    <div>
        <h3 class="text-base font-semibold mb-4" style="color: var(--text-primary);">Recent Documents</h3>
        @if($recentDocuments->isEmpty())
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <i class="fas fa-file-alt"></i>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No documents yet</h3>
                <p class="text-sm" style="color: var(--text-muted);">Use the upload form above to add your first document.</p>
            </div>
        @else
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Title</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Category</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Status</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Chunks</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Ellie</th>
                            <th class="text-center px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Active</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Uploaded</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentDocuments as $doc)
                            <tr class="transition-all duration-300">
                                <td class="px-4 py-3 text-sm font-medium" style="color: var(--text-primary);">{{ Str::limit($doc->title, 40) }}</td>
                                <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $doc->category->name ?? '-' }}</td>
                                <td class="px-4 py-3 text-center">{!! $doc->status_badge !!}</td>
                                <td class="px-4 py-3 text-center text-sm" style="color: var(--text-secondary);">{{ $doc->chunk_count }}</td>
                                <td class="px-4 py-3 text-center">
                                    <form action="{{ route('admin.knowledge.toggleEllie', $doc->id) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit"
                                                class="text-xs px-2 py-0.5 rounded-md font-medium transition-all duration-300"
                                                style="{{ $doc->is_ellie_enabled
                                                    ? 'background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 15%, transparent); color: var(--brand-icon, #0ea5e9);'
                                                    : 'background: var(--surface-2); color: var(--text-muted);' }}"
                                                title="{{ $doc->is_ellie_enabled ? 'Disable Ellie' : 'Enable Ellie' }}">
                                            {{ $doc->is_ellie_enabled ? 'ON' : 'OFF' }}
                                        </button>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <form action="{{ route('admin.knowledge.toggleActive', $doc->id) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit"
                                                class="text-xs px-2 py-0.5 rounded-md font-medium transition-all duration-300"
                                                style="{{ $doc->is_active
                                                    ? 'background: color-mix(in srgb, var(--ds-green) 15%, transparent); color: var(--ds-green);'
                                                    : 'background: var(--surface-2); color: var(--text-muted);' }}"
                                                title="{{ $doc->is_active ? 'Deactivate' : 'Activate' }}">
                                            {{ $doc->is_active ? 'ON' : 'OFF' }}
                                        </button>
                                    </form>
                                </td>
                                <td class="px-4 py-3 text-xs" style="color: var(--text-muted);">{{ $doc->created_at->format('d M Y') }}</td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('admin.knowledge.preview', $doc->id) }}" class="text-xs font-medium transition-all duration-300 hover:underline" style="color: var(--brand-icon, #0ea5e9);">Preview</a>
                                        <form action="{{ route('admin.knowledge.reprocess', $doc->id) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-medium transition-all duration-300 hover:underline" style="color: var(--ds-amber);">Reprocess</button>
                                        </form>
                                        <form action="{{ route('admin.knowledge.destroy', $doc->id) }}" method="POST" class="inline" x-data x-on:submit.prevent="if(confirm('Delete this document and all its chunks?')) $el.submit()">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-xs font-medium transition-all duration-300 hover:underline" style="color: var(--ds-crimson);">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
            </div>
        @endif
    </div>

</div>

@endsection
