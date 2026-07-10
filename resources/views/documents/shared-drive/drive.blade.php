{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex')

@section('corex-content')

@php
    $currentId = $folder?->id;
    $maxBytes = $maxKilobytes * 1024;
    $acceptAttr = '.' . implode(',.', $allowedExts);
    $canBulk = $can['download'] || $can['deleteFile'];
@endphp

<div class="w-full" x-data="sharedDrive()">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5 mb-6" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="flex items-center gap-3">
                @if($drive->is_restricted)
                    <svg class="w-7 h-7 text-white/90" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                    </svg>
                @else
                    <svg class="w-7 h-7 text-white/90" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
                    </svg>
                @endif
                <div>
                    <h1 class="text-xl font-bold text-white leading-tight flex items-center gap-2">
                        {{ $drive->name }}
                        @if($drive->is_restricted)
                            <span class="text-[10px] uppercase tracking-wide px-2 py-0.5 rounded-md" style="background: rgba(255,255,255,.18); color:#fff;">Restricted</span>
                        @endif
                    </h1>
                    <p class="text-sm text-white/60">{{ $drive->is_restricted ? 'Visible only to invited members.' : 'Shared with everyone in the agency.' }} Max 50&nbsp;MB per file.</p>
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @if($can['manageDrive'])
                    <button type="button" @click="showAccessModal = true" class="corex-btn-outline corex-btn-on-brand">
                        Manage Access
                    </button>
                @endif
                @if($can['createFolder'])
                    <button type="button" @click="showFolderModal = true" class="corex-btn-outline corex-btn-on-brand">
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
        <a href="{{ route('documents.shared-drive.index') }}" class="hover:underline">Shared Drive</a>
        <span style="opacity:.5;">/</span>
        <a href="{{ route('documents.shared-drive.drive', $drive->id) }}" class="hover:underline {{ $currentId ? '' : 'font-semibold' }}" style="color: {{ $currentId ? 'var(--text-secondary)' : 'var(--text-primary)' }};">{{ $drive->name }}</a>
        @foreach($breadcrumb as $crumb)
            <span style="opacity:.5;">/</span>
            @if($loop->last)
                <span class="font-semibold" style="color: var(--text-primary);">{{ $crumb->name }}</span>
            @else
                <a href="{{ route('documents.shared-drive.folder', [$drive->id, $crumb->id]) }}" class="hover:underline">{{ $crumb->name }}</a>
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
                              :style="item.status === 'error' ? 'color: var(--ds-crimson);' : (item.status === 'done' ? 'color: var(--ds-green, #059669);' : 'color: var(--text-secondary);')"
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
                        <a href="{{ route('documents.shared-drive.folder', [$drive->id, $f->id]) }}" class="flex items-center gap-3 flex-1 min-w-0">
                            <svg class="w-8 h-8 flex-shrink-0" style="color: var(--brand-icon);" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/>
                            </svg>
                            <div class="min-w-0">
                                <div class="text-sm font-medium truncate" style="color: var(--text-primary);" title="{{ $f->name }}">{{ $f->name }}</div>
                                <div class="text-xs" style="color: var(--text-secondary);">{{ number_format($f->children_count) }} folder(s) · {{ number_format($f->files_count) }} file(s)</div>
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
        <div class="flex items-center justify-between mb-2 gap-3 flex-wrap">
            <h2 class="text-xs font-semibold uppercase tracking-wide" style="color: var(--text-secondary);">Files</h2>
            @if($canBulk && $files->count())
                <div class="flex items-center gap-2" x-show="selectedIds.length" x-cloak>
                    <span class="text-xs" style="color: var(--text-secondary);"><span x-text="selectedIds.length"></span> selected</span>
                    @if($can['download'])
                        <button type="button" @click="submitBulk('download')" class="text-xs px-2.5 py-1 rounded-md" style="background: var(--brand-button, var(--brand-icon)); color:#fff;">Download</button>
                    @endif
                    @if($can['deleteFile'])
                        <button type="button" @click="submitBulk('delete')" class="text-xs px-2.5 py-1 rounded-md" style="background: var(--ds-crimson); color:#fff;">Delete</button>
                    @endif
                    <button type="button" @click="selectedIds = []" class="text-xs px-2 py-1 rounded-md" style="border: 1px solid var(--border); color: var(--text-secondary);">Clear</button>
                </div>
            @endif
        </div>
        @if($files->count())
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
              <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr>
                            @if($canBulk)
                                <th class="px-4 py-2.5 w-10">
                                    <input type="checkbox" :checked="allSelected" @change="toggleAll($event)" title="Select all" style="cursor:pointer;">
                                </th>
                            @endif
                            <th class="text-left px-4 py-2.5">Name</th>
                            <th class="text-left px-4 py-2.5 hidden md:table-cell">Uploaded by</th>
                            <th class="text-left px-4 py-2.5 hidden sm:table-cell">Size</th>
                            <th class="text-left px-4 py-2.5 hidden lg:table-cell">Date</th>
                            <th class="px-4 py-2.5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($files as $file)
                            <tr style="color: var(--text-primary);" :class="selectedIds.includes({{ $file->id }}) ? 'is-selected' : ''">
                                @if($canBulk)
                                    <td class="px-4 py-3 w-10">
                                        <input type="checkbox" :value="{{ $file->id }}" x-model.number="selectedIds" style="cursor:pointer;">
                                    </td>
                                @endif
                                <td class="px-4 py-3">
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
                                <td class="px-4 py-3 hidden md:table-cell" style="color: var(--text-secondary);">{{ $file->uploader?->name ?? '—' }}</td>
                                <td class="px-4 py-3 hidden sm:table-cell" style="color: var(--text-secondary);">{{ $file->human_size }}</td>
                                <td class="px-4 py-3 hidden lg:table-cell" style="color: var(--text-secondary);">{{ $file->created_at?->format('d M Y') }}</td>
                                <td class="px-4 py-3">
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
            </div>
        @else
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent); color: var(--brand-icon);">
                    <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">This folder is empty</h3>
                @if($can['upload'])
                    <p class="text-sm mb-4" style="color: var(--text-muted);">Drag files here, or use the Upload button above to add documents.</p>
                    <button type="button" @click="$refs.fileInput.click()" class="corex-btn-primary">Upload Files</button>
                @else
                    <p class="text-sm" style="color: var(--text-muted);">No files have been added here yet.</p>
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
                    <input type="hidden" name="drive_id" value="{{ $drive->id }}">
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

    {{-- Manage Access Modal --}}
    @if($can['manageDrive'])
        <div x-show="showAccessModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,.5);" @keydown.escape.window="showAccessModal = false">
            <div class="rounded-md w-full max-w-md p-5 max-h-[85vh] flex flex-col" style="background: var(--surface); border: 1px solid var(--border);" @click.outside="showAccessModal = false">
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">Manage Access — {{ $drive->name }}</h3>
                <p class="text-xs mb-3" style="color: var(--text-secondary);">Choose who can see this drive. You (the creator) and agency admins always retain access.</p>
                <form method="POST" action="{{ route('documents.shared-drive.drives.access', $drive->id) }}" class="flex flex-col flex-1 min-h-0">
                    @csrf @method('PUT')
                    <label class="flex items-center gap-2 mb-3 text-sm" style="color: var(--text-primary);">
                        <input type="checkbox" name="is_restricted" value="1" x-model="restricted">
                        Restrict this drive to selected members
                    </label>

                    <div x-show="restricted" x-cloak class="flex flex-col flex-1 min-h-0">
                        <input type="text" x-model="memberSearch" placeholder="Search members…"
                               class="w-full rounded-md px-3 py-2 text-sm mb-2"
                               style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                        <div class="overflow-auto rounded-md flex-1" style="border: 1px solid var(--border);">
                            @forelse($members as $m)
                                <label class="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer"
                                       style="border-bottom: 1px solid var(--border); color: var(--text-primary);"
                                       x-show="memberMatches(@js(mb_strtolower($m->name . ' ' . $m->email)))">
                                    <input type="checkbox" name="user_ids[]" value="{{ $m->id }}" @checked(in_array($m->id, $accessUserIds))>
                                    <span>{{ $m->name }}</span>
                                    <span class="text-xs ml-auto" style="color: var(--text-secondary);">{{ $m->email }}</span>
                                </label>
                            @empty
                                <p class="px-3 py-3 text-sm" style="color: var(--text-secondary);">No other members in this agency.</p>
                            @endforelse
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 mt-4">
                        <button type="button" @click="showAccessModal = false" class="corex-btn-outline">Cancel</button>
                        <button type="submit" class="corex-btn-primary">Save Access</button>
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
        showAccessModal: false,
        restricted: {{ $drive->is_restricted ? 'true' : 'false' }},
        memberSearch: '',
        dragging: false,
        uploading: false,
        queue: [],
        viewer: { open: false, url: '', isImage: false, name: '' },
        maxBytes: {{ $maxBytes }},
        uploadUrl: @js(route('documents.shared-drive.upload')),
        driveId: @js($drive->id),
        folderId: @js($currentId),
        csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',

        // Selection
        selectedIds: [],
        allFileIds: @js($files->pluck('id')->map(fn ($id) => (int) $id)->all()),
        bulkDownloadUrl: @js(route('documents.shared-drive.files.bulk-download')),
        bulkDeleteUrl: @js(route('documents.shared-drive.files.bulk-destroy')),

        memberMatches(haystack) {
            const q = (this.memberSearch || '').toLowerCase().trim();
            return !q || haystack.includes(q);
        },

        get allSelected() {
            return this.allFileIds.length > 0 && this.selectedIds.length === this.allFileIds.length;
        },
        toggleAll(e) {
            this.selectedIds = e.target.checked ? [...this.allFileIds] : [];
        },
        submitBulk(action) {
            if (!this.selectedIds.length) return;
            if (action === 'delete' &&
                !confirm('Delete ' + this.selectedIds.length + ' selected file(s)? They can be recovered by an admin.')) {
                return;
            }
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = action === 'delete' ? this.bulkDeleteUrl : this.bulkDownloadUrl;
            const add = (name, value) => {
                const i = document.createElement('input');
                i.type = 'hidden'; i.name = name; i.value = value;
                form.appendChild(i);
            };
            add('_token', this.csrf);
            if (action === 'delete') add('_method', 'DELETE');
            add('drive_id', this.driveId);
            if (this.folderId) add('folder_id', this.folderId);
            this.selectedIds.forEach(id => add('ids[]', id));
            document.body.appendChild(form);
            form.submit();
        },

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

            const toUpload = [];
            for (const file of files) {
                const idx = this.queue.length;
                if (file.size > this.maxBytes) {
                    this.queue.push({ name: file.name, status: 'error', message: 'Too large (max 50 MB)' });
                } else {
                    this.queue.push({ name: file.name, status: 'pending', message: 'Waiting…' });
                    toUpload.push({ file, idx });
                }
            }
            if (!toUpload.length) return;

            this.uploading = true;
            let anySuccess = false;
            for (const { file, idx } of toUpload) {
                this.queue[idx].status = 'uploading';
                this.queue[idx].message = 'Uploading…';
                try {
                    const ok = await this.uploadOne(file, idx);
                    anySuccess = anySuccess || ok;
                } catch (err) {
                    this.queue[idx].status = 'error';
                    this.queue[idx].message = 'Upload failed';
                }
            }
            this.uploading = false;

            if (anySuccess) {
                setTimeout(() => window.location.reload(), 900);
            }
        },

        async uploadOne(file, idx) {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('drive_id', this.driveId);
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
                this.queue[idx].status = 'done';
                this.queue[idx].message = 'Uploaded';
                return true;
            }
            this.queue[idx].status = 'error';
            this.queue[idx].message = data.message || ('Failed (' + res.status + ')');
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
