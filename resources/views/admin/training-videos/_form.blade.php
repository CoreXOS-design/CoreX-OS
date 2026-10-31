{{--
    Shared training video fields — included by create and edit.
    Spec: .ai/specs/training-videos.md §4.1, §8.6

    $video is null on create. $groups = the groups this video may be put in.

    Two kinds (spec §8): a YouTube link, or an uploaded file CoreX shrinks in the background.
    A new video chooses with the switch; an existing one keeps its kind. The enclosing <form>
    must carry x-data="tvForm({...})" (defined in _form-scripts) — it owns `source` and lets the
    Save button wait for an upload.
--}}
@php
    $v = $video ?? null;
    $selectedGroup = (string) old('training_video_group_id', $v->training_video_group_id ?? '');
    $isEdit = $v !== null;
    $isUploadKind = $isEdit && $v->isUpload();
@endphp

@include('admin.training-videos._form-scripts')

<div class="rounded-md p-5 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">

    <div>
        <label for="title" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
            Title <span class="text-red-500">*</span>
        </label>
        <input id="title" name="title" type="text" required maxlength="200"
               value="{{ old('title', $v->title ?? '') }}"
               placeholder="e.g. Loading your first listing"
               class="w-full rounded-md px-3 py-2 text-sm"
               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
        <x-input-error :messages="$errors->get('title')" class="mt-1" />
    </div>

    @unless ($isEdit)
        <div>
            <span class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Where is the video?</span>
            <div class="flex flex-wrap gap-5 text-sm" style="color: var(--text-primary);">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="source" value="youtube" x-model="source"> A YouTube link
                </label>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="source" value="upload" x-model="source"> Upload a video file
                </label>
            </div>
        </div>
    @endunless

    @unless ($isUploadKind)
        <div x-show="source === 'youtube'">
            <label for="youtube_url" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                YouTube link <span class="text-red-500">*</span>
            </label>
            <input id="youtube_url" name="youtube_url" type="text" maxlength="500" :required="source === 'youtube'"
                   value="{{ old('youtube_url', $v->youtube_url ?? '') }}"
                   placeholder="https://youtu.be/…"
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            <p class="mt-1 text-xs" style="color: var(--text-muted);">
                Use the Share button on YouTube and paste the link. Unlisted videos work too — they stay out of YouTube search but play here.
            </p>
            <x-input-error :messages="$errors->get('youtube_url')" class="mt-1" />
        </div>
    @endunless

    {{-- Upload a file: a new video (source = upload) or "Replace file" on an uploaded one. --}}
    <div x-show="source === 'upload'" @unless ($isUploadKind) x-cloak @endunless>
        @include('admin.training-videos._uploader', ['video' => $v])
        <x-input-error :messages="$errors->get('upload_id')" class="mt-1" />
    </div>

    <div>
        <label for="training_video_group_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
            Group
        </label>
        <select id="training_video_group_id" name="training_video_group_id"
                class="w-full rounded-md px-3 py-2 text-sm"
                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            <option value="">No group</option>
            @foreach ($groups as $g)
                <option value="{{ $g->id }}" @selected($selectedGroup === (string) $g->id)>{{ $g->name }}{{ $g->trashed() ? ' (archived)' : '' }}</option>
            @endforeach
        </select>
        <p class="mt-1 text-xs" style="color: var(--text-muted);">
            Each group is a section on the public page. Videos without a group show last, under "More videos".
            <a href="{{ route('admin.training-videos.groups.index') }}" class="hover:underline" style="color: var(--corex-accent);">Manage groups</a>
        </p>
        <x-input-error :messages="$errors->get('training_video_group_id')" class="mt-1" />
    </div>

    <div>
        <label for="description" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
            Description
        </label>
        <textarea id="description" name="description" rows="5" maxlength="5000"
                  class="w-full rounded-md px-3 py-2 text-sm"
                  style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                  placeholder="What this video shows and who it is for. Shown under the video on the public page.">{{ old('description', $v->description ?? '') }}</textarea>
        <x-input-error :messages="$errors->get('description')" class="mt-1" />
    </div>

</div>
