@extends('layouts.corex')

@section('corex-content')

@php
    $currentId = $folder?->id;
    $maxBytes = $maxKilobytes * 1024;
    $acceptAttr = '.' . implode(',.', $allowedExts);
@endphp

<div x-data="sharedDrive()">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5 mb-6" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="flex items-center gap-3">
                <svg class="w-7 h-7 text-white/90" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
                </svg>
                <div>
                    <h1 class="text-xl font-bold text-white leading-tight">Shared Drive</h1>
                    <p class="text-sm text-white/60">The agency's shared filing cabinet. Max 50&nbsp;MB per file.</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if($can['createFolder'])
                    <button type="button" @click="showFolderModal = true" class="corex-btn-outline" style="color:#fff;border-color:rgba(255,255,255,.4);">
                        + New Folder
                    </button>
                @endif
                @if($can['upload'])
                    <button type="button" @click="$refs.fileInput.click()" class="corex-btn-primary" :disabled="uploading" :style="uploading ? 'opacity:.6;' : ''">
                        <span x-show="!uploading">Upload Files</span>
                        <span x-show="uploading" x-cloak>Uploading…</span>
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Breadcrumb --}}
    <nav class="flex items-center flex-wrap gap-1 text-sm mb-4" style="color: var(--text-secondary);">
        <a href="{{ route('documents.shared-drive.index') }}" class="hover:underline {{ $currentId ? '' : 'font-semibold' }}" style="color: {{ $currentId ? 'var(--text-secondary)' : 'var(--text-primary)' }};">Shared Drive</a>
        @foreach($breadcrumb as $crumb)
            <span style="opacity:.5;">/</span>
            @if($loop->last)
                <span class="font-semibold" style="color: var(--text-primary);">{{ $crumb->name }}</span>
            @else
                <a href="{{ route('documents.shared-drive.folder', $crumb->id) }}" class="hover:underline">{{ $crumb->name }}</a>
            @endif
        @endforeach
    </nav>

    {{-- Hidden multi-file input (triggered by button / drag-drop) --}}
    @if($can['upload'])
        <input type="file" multiple x-ref="fileInput" accept="{{ $acceptAttr }}" class="hidden"
               @change="onFilesPicked($event)">

        {{-- Upload progress / results panel --}}
        <div x-show="queue.length" x-cloak class="rounded-md mb-4 overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
            <div class="flex items-center justify-between px-4 py-2" style="border-bottom: 1px solid var(--border);">
                <span class="text-sm font-medium" style="color: var(--text-primary);" x-text="uploading ? 'Uploading…' : 'Upload complete'"></span>
                <button type="button" x-show="!uploading" @click="queue = []" class="text-xs" style="color: var(--text-secondary);">Dismiss</button>
            </div>
            <ul class="divide-y" style="border-color: var(--border);">
                <template x-for="(item, i) in queue" :key="i">
                    <li class="flex items-center justify-between px-4 py-2 text-sm gap-3">
                        <span class="truncate" style="color: var(--text-primary);" x-text="item.name"></span>
                        <span class="text-xs flex-shrink-0"
                              :style="item.status === 'error' ? 'color: var(--ds-crimson);' : (item.status === 'done' ? 'color: var(--ds-emerald, #10b981);' : 'color: var(--text-secondary);')"
                              x-text="item.message"></span>
                    </li>
                </template>
            </ul>
        </div>
    @endif

    {{-- Drop zone wrapper --}}
    <div @if($can['upload']) @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="onDrop($event)" @endif
         class="rounded-md transition-all"
         :class="dragging ? 'ring-2' : ''"
         :style="dragging ? 'outline: 2px dashed var(--brand-icon); outline-offset: 4px;' : ''">

        {{-- Folders --}}
        @if($folders->count())
            <h2 class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-secondary);">Folders</h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-6">
                @foreach($folders as $f)
                    <div class="group rounded-md p-3 flex items-center gap-3 transition-all hover:shadow-sm"
                         style="background: var(--surface); border: 1px solid var(--border);">
                        <a href="{{ route('documents.shared-drive.folder', $f->id) }}" class="flex items-center gap-3 flex-1 min-w-0">
                            <svg class="w-8 h-8 flex-shrink-0" style="color: var(--brand-icon);" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/>
                            </svg>
                            <div class="min-w-0">
                                <div class="text-sm font-medium truncate" style="color: var(--text-primary);" title="{{ $f->name }}">{{ $f->name }}</div>
                                <div class="text-xs" style="color: var(--text-secondary);">{{ $f->children_count }} folder(s) · {{ $f->files_count }} file(s)</div>
                            </div>
                        </a>
                        @if($can['deleteFolder'])
                            <form method="POST" action="{{ route('documents.shared-drive.folders.destroy', $f->id) }}"
                                  onsubmit="return confirm('Delete folder “{{ $f->name }}” and everything inside it? It can be recovered by an admin.');">
                                @csrf @method('DELETE')
                                <button type="submit" class="opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded" title="Delete folder" style="color: var(--ds-crimson);">
                                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                </button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Files --}}
        <h2 class="text-xs font-semibold uppercase tracking-wide mb-2" style="color: var(--text-secondary);">Files</h2>
        @if($files->count())
            <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border);">
                <table class="w-full text-sm">
                    <thead>
                        <tr style="background: var(--surface-2, var(--surface)); color: var(--text-secondary);">
                            <th class="text-left font-medium px-4 py-2">Name</th>
                            <th class="text-left font-medium px-4 py-2 hidden md:table-cell">Uploaded by</th>
                            <th class="text-left font-medium px-4 py-2 hidden sm:table-cell">Size</th>
                            <th class="text-left font-medium px-4 py-2 hidden lg:table-cell">Date</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($files as $file)
                            <tr style="border-top: 1px solid var(--border); color: var(--text-primary);">
                                <td class="px-4 py-2">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <span class="text-base">{!! $file->isImage() ? '🖼️' : ($file->extension === 'pdf' ? '📕' : '📄') !!}</span>
                                        @if($file->isViewableInline())
                                            <button type="button" class="truncate hover:underline text-left"
                                                    @click="openViewer('{{ route('documents.shared-drive.files.view', $file->id) }}', {{ $file->isImage() ? 'true' : 'false' }}, @js($file->original_name))"
                                                    title="{{ $file->original_name }}">{{ $file->original_name }}</button>
                                        @elseif($can['download'])
                                            <a class="truncate hover:underline" href="{{ route('documents.shared-drive.files.download', $file->id) }}" title="{{ $file->original_name }}">{{ $file->original_name }}</a>
                                        @else
                                            <span class="truncate" title="{{ $file->original_name }}">{{ $file->original_name }}</span>
                                        @endif
                                    </div>
                                </td>
                                <td class="px-4 py-2 hidden md:table-cell" style="color: var(--text-secondary);">{{ $file->uploader?->name ?? '—' }}</td>
                                <td class="px-4 py-2 hidden sm:table-cell" style="color: var(--text-secondary);">{{ $file->human_size }}</td>
                                <td class="px-4 py-2 hidden lg:table-cell" style="color: var(--text-secondary);">{{ $file->created_at?->format('d M Y') }}</td>
                                <td class="px-4 py-2">
                                    <div class="flex items-center justify-end gap-1">
                                        @if($can['download'])
                                            <a href="{{ route('documents.shared-drive.files.download', $file->id) }}" class="p-1.5 rounded hover:bg-black/5" title="Download" style="color: var(--brand-icon);">
                                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                            </a>
                                        @endif
                                        @if($can['deleteFile'])
                                            <form method="POST" action="{{ route('documents.shared-drive.files.destroy', $file->id) }}"
                                                  onsubmit="return confirm('Delete “{{ $file->original_name }}”? It can be recovered by an admin.');">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="p-1.5 rounded hover:bg-black/5" title="Delete" style="color: var(--ds-crimson);">
                                                    <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="rounded-md px-6 py-12 text-center" style="background: var(--surface); border: 1px dashed var(--border);">
                <p class="text-sm" style="color: var(--text-secondary);">This folder is empty.</p>
                @if($can['upload'])
                    <p class="text-xs mt-1" style="color: var(--text-secondary);">Drag files here, or use the Upload button above.</p>
                @endif
            </div>
        @endif
    </div>

    {{-- New Folder Modal --}}
    @if($can['createFolder'])
        <div x-show="showFolderModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,.5);" @keydown.escape.window="showFolderModal = false">
            <div class="rounded-md w-full max-w-sm p-5" style="background: var(--surface); border: 1px solid var(--border);" @click.outside="showFolderModal = false">
                <h3 class="text-base font-semibold mb-3" style="color: var(--text-primary);">New Folder</h3>
                <form method="POST" action="{{ route('documents.shared-drive.folders.store') }}">
                    @csrf
                    <input type="hidden" name="parent_id" value="{{ $currentId }}">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Folder name</label>
                    <input type="text" name="name" required autofocus maxlength="255" value="{{ old('name') }}"
                           class="w-full rounded-md px-3 py-2 text-sm focus:outline-none mb-4"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="e.g. Branch SOPs">
                    <div class="flex justify-end gap-2">
                        <button type="button" @click="showFolderModal = false" class="corex-btn-outline">Cancel</button>
                        <button type="submit" class="corex-btn-primary">Create</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- In-app Viewer Modal --}}
    <div x-show="viewer.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,.8);" @keydown.escape.window="closeViewer()">
        <div class="rounded-md w-full max-w-5xl h-[85vh] flex flex-col overflow-hidden" style="background: var(--surface);" @click.outside="closeViewer()">
            <div class="flex items-center justify-between px-4 py-2 flex-shrink-0" style="border-bottom: 1px solid var(--border);">
                <span class="text-sm font-medium truncate" style="color: var(--text-primary);" x-text="viewer.name"></span>
                <button type="button" @click="closeViewer()" class="p-1 rounded hover:bg-black/5" style="color: var(--text-secondary);">
                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                </button>
            </div>
            <div class="flex-1 overflow-auto flex items-center justify-center" style="background: #1a1a1a;">
                <template x-if="viewer.open && viewer.isImage">
                    <img :src="viewer.url" class="max-w-full max-h-full object-contain" :alt="viewer.name">
                </template>
                <template x-if="viewer.open && !viewer.isImage">
                    <iframe :src="viewer.url" class="w-full h-full" frameborder="0"></iframe>
                </template>
            </div>
        </div>
    </div>

</div>

<script>
function sharedDrive() {
    return {
        showFolderModal: false,
        dragging: false,
        uploading: false,
        queue: [],
        viewer: { open: false, url: '', isImage: false, name: '' },
        maxBytes: {{ $maxBytes }},
        uploadUrl: @js(route('documents.shared-drive.upload')),
        folderId: @js($currentId),
        csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',

        onFilesPicked(e) {
            this.handleFiles(e.target.files);
            e.target.value = ''; // allow re-selecting the same file
        },
        onDrop(e) {
            this.dragging = false;
            this.handleFiles(e.dataTransfer.files);
        },

        async handleFiles(fileList) {
            const files = Array.from(fileList || []);
            if (!files.length) return;

            // Build the visible queue: oversize files are rejected up-front,
            // valid files are uploaded one request each (partial success ok).
            const toUpload = [];
            for (const file of files) {
                if (file.size > this.maxBytes) {
                    this.queue.push({ name: file.name, status: 'error', message: 'Too large (max 50 MB)' });
                } else {
                    const entry = { name: file.name, status: 'pending', message: 'Waiting…' };
                    this.queue.push(entry);
                    toUpload.push({ file, entry });
                }
            }
            if (!toUpload.length) return;

            this.uploading = true;
            let anySuccess = false;
            for (const { file, entry } of toUpload) {
                entry.status = 'uploading';
                entry.message = 'Uploading…';
                try {
                    const ok = await this.uploadOne(file, entry);
                    anySuccess = anySuccess || ok;
                } catch (err) {
                    entry.status = 'error';
                    entry.message = 'Upload failed';
                }
            }
            this.uploading = false;

            // Refresh to show the newly stored files, but keep the panel
            // visible briefly so the user sees results.
            if (anySuccess) {
                setTimeout(() => window.location.reload(), 700);
            }
        },

        async uploadOne(file, entry) {
            const fd = new FormData();
            fd.append('file', file);
            if (this.folderId) fd.append('folder_id', this.folderId);

            const res = await fetch(this.uploadUrl, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' },
                body: fd,
                credentials: 'same-origin',
            });

            let data = {};
            try { data = await res.json(); } catch (_) {}

            if (res.ok && data.ok) {
                entry.status = 'done';
                entry.message = 'Uploaded';
                return true;
            }
            entry.status = 'error';
            entry.message = data.message || ('Failed (' + res.status + ')');
            return false;
        },

        openViewer(url, isImage, name) {
            this.viewer = { open: true, url, isImage, name };
        },
        closeViewer() {
            this.viewer = { open: false, url: '', isImage: false, name: '' };
        },
    };
}
</script>

@endsection
