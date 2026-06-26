@extends('layouts.corex')

@section('corex-content')

<div x-data="driveList()">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5 mb-6" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div class="flex items-center gap-3">
                <svg class="w-7 h-7 text-white/90" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z" />
                </svg>
                <div>
                    <h1 class="text-xl font-bold text-white leading-tight">Shared Drive</h1>
                    <p class="text-sm text-white/60">Open a drive to browse its folders and files. Restricted drives are visible only to invited members.</p>
                </div>
            </div>
            @if($can['createDrive'])
                <button type="button" @click="showDriveModal = true" class="corex-btn-primary">+ New Drive</button>
            @endif
        </div>
    </div>

    {{-- Drives grid --}}
    @if($drives->count())
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($drives as $d)
                @php $canManageThis = !$d->is_default && ($can['manage'] || (int) $d->created_by_user_id === (int) auth()->id()); @endphp
                <div class="group rounded-md p-4 flex items-start gap-3 transition-all hover:shadow-sm"
                     style="background: var(--surface); border: 1px solid var(--border);">
                    <a href="{{ route('documents.shared-drive.drive', $d->id) }}" class="flex items-start gap-3 flex-1 min-w-0">
                        <div class="rounded-md p-2 flex-shrink-0" style="background: color-mix(in srgb, var(--brand-icon) 12%, transparent);">
                            @if($d->is_restricted)
                                <svg class="w-7 h-7" style="color: var(--brand-icon);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z" />
                                </svg>
                            @else
                                <svg class="w-7 h-7" style="color: var(--brand-icon);" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/>
                                </svg>
                            @endif
                        </div>
                        <div class="min-w-0">
                            <div class="text-sm font-semibold truncate flex items-center gap-2" style="color: var(--text-primary);" title="{{ $d->name }}">
                                {{ $d->name }}
                                @if($d->is_default)
                                    <span class="text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded" style="background: var(--surface-2, var(--surface)); color: var(--text-secondary); border: 1px solid var(--border);">Default</span>
                                @endif
                            </div>
                            <div class="text-xs mt-0.5" style="color: var(--text-secondary);">
                                {{ $d->is_restricted ? 'Restricted' : 'Open to agency' }} · {{ $d->folders_count }} folder(s) · {{ $d->files_count }} file(s)
                            </div>
                        </div>
                    </a>
                    @if($canManageThis)
                        <form method="POST" action="{{ route('documents.shared-drive.drives.destroy', $d->id) }}"
                              onsubmit="return confirm('Delete drive “{{ $d->name }}” and everything inside it? It can be recovered by an admin.');">
                            @csrf @method('DELETE')
                            <button type="submit" class="opacity-0 group-hover:opacity-100 transition-opacity p-1 rounded" title="Delete drive" style="color: var(--ds-crimson);">
                                <svg class="w-4 h-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                            </button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <div class="rounded-md px-6 py-12 text-center" style="background: var(--surface); border: 1px dashed var(--border);">
            <p class="text-sm" style="color: var(--text-secondary);">No drives yet.</p>
        </div>
    @endif

    {{-- New Drive Modal --}}
    @if($can['createDrive'])
        <div x-show="showDriveModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,.5);" @keydown.escape.window="showDriveModal = false">
            <div class="rounded-md w-full max-w-md p-5 max-h-[85vh] flex flex-col" style="background: var(--surface); border: 1px solid var(--border);" @click.outside="showDriveModal = false">
                <h3 class="text-base font-semibold mb-3" style="color: var(--text-primary);">New Drive</h3>
                <form method="POST" action="{{ route('documents.shared-drive.drives.store') }}" class="flex flex-col flex-1 min-h-0">
                    @csrf
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Drive name</label>
                    <input type="text" name="name" required maxlength="255" value="{{ old('name') }}"
                           class="w-full rounded-md px-3 py-2 text-sm focus:outline-none mb-4"
                           style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
                           placeholder="e.g. Directors, Marketing, Branch SOPs">

                    @if($can['createRestricted'])
                        <label class="flex items-center gap-2 mb-3 text-sm" style="color: var(--text-primary);">
                            <input type="checkbox" name="is_restricted" value="1" x-model="restricted">
                            Lock this drive — only selected members can see it
                        </label>

                        <div x-show="restricted" x-cloak class="flex flex-col flex-1 min-h-0">
                            <p class="text-xs mb-2" style="color: var(--text-secondary);">You always keep access as the creator. Choose who else can see it:</p>
                            <input type="text" x-model="memberSearch" placeholder="Search members…"
                                   class="w-full rounded-md px-3 py-2 text-sm mb-2"
                                   style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                            <div class="overflow-auto rounded-md flex-1" style="border: 1px solid var(--border); min-height: 8rem;">
                                @forelse($members as $m)
                                    <label class="flex items-center gap-2 px-3 py-2 text-sm cursor-pointer"
                                           style="border-bottom: 1px solid var(--border); color: var(--text-primary);"
                                           x-show="memberMatches(@js(mb_strtolower($m->name . ' ' . $m->email)))">
                                        <input type="checkbox" name="user_ids[]" value="{{ $m->id }}">
                                        <span>{{ $m->name }}</span>
                                        <span class="text-xs ml-auto" style="color: var(--text-secondary);">{{ $m->email }}</span>
                                    </label>
                                @empty
                                    <p class="px-3 py-3 text-sm" style="color: var(--text-secondary);">No other members in this agency.</p>
                                @endforelse
                            </div>
                        </div>
                    @endif

                    <div class="flex justify-end gap-2 mt-4">
                        <button type="button" @click="showDriveModal = false" class="corex-btn-outline">Cancel</button>
                        <button type="submit" class="corex-btn-primary">Create Drive</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

</div>

<script>
function driveList() {
    return {
        showDriveModal: false,
        restricted: false,
        memberSearch: '',
        memberMatches(haystack) {
            const q = (this.memberSearch || '').toLowerCase().trim();
            return !q || haystack.includes(q);
        },
    };
}
</script>

@endsection
