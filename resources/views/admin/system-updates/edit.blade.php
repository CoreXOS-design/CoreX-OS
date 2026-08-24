{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
     Spec: .ai/specs/system-updates.md §7.2, §7.4 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Edit Update</h1>
                <p class="text-xs" style="color: var(--text-muted);">
                    @if($update->trashed())
                        Archived — restore it from the list to show it again.
                    @elseif($update->isPublished())
                        Published {{ $update->published_at->format('d M Y \a\t H:i') }}.
                    @else
                        Draft — nobody can see this yet.
                    @endif
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('admin.system-updates.preview', $update->id) }}" class="corex-btn-outline text-xs">Preview</a>
                <a href="{{ route('admin.system-updates.show', $update->id) }}" class="corex-btn-outline text-xs">Who's seen it</a>
                <a href="{{ route('admin.system-updates.index') }}" class="corex-btn-outline text-xs">Back</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm"
             style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
            {{ session('success') }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.system-updates.update', $update->id) }}" enctype="multipart/form-data" class="space-y-5">
        @csrf
        @method('PUT')

        @include('admin.system-updates._form', ['isEdit' => true])

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="corex-btn-primary">Save changes</button>
            <span class="text-xs" style="color:var(--text-secondary);">
                Saving does not re-show this to people who already closed it.
            </span>
        </div>
    </form>

    {{-- Publish state + re-notify. Separate forms so a stray Enter in the editor
         can never publish or re-notify by accident. --}}
    <div class="rounded-md p-5 space-y-4"
         style="background:var(--surface); border:1px solid var(--border);">
        <div class="text-sm font-semibold" style="color:var(--text-primary);">Publishing</div>

        <div class="flex flex-wrap items-center gap-3">
            @if($update->isPublished())
                <form method="POST" action="{{ route('admin.system-updates.unpublish', $update->id) }}"
                      onsubmit="return confirm('Unpublish this update? It becomes a draft and stops showing to everyone.');">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-sm">Unpublish</button>
                </form>

                <form method="POST" action="{{ route('admin.system-updates.renotify', $update->id) }}"
                      onsubmit="return confirm('Show this update again to every CoreX user — including the people who already closed it?');">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-sm">Re-notify everyone</button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.system-updates.publish', $update->id) }}">
                    @csrf
                    <button type="submit" class="corex-btn-primary text-sm">Publish now</button>
                </form>
            @endif

            @unless($update->trashed())
            <form method="POST" action="{{ route('admin.system-updates.destroy', $update->id) }}" class="ml-auto"
                  onsubmit="return confirm('Archive this update? It stops showing to users immediately and can be restored at any time.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="corex-btn-danger text-sm">Archive</button>
            </form>
            @endunless
        </div>

        <p class="text-xs" style="color:var(--text-secondary);">
            “Re-notify everyone” shows the update again to people who already closed it. Nothing is deleted —
            the record of who saw the original is kept.
        </p>
    </div>
</div>
@endsection
