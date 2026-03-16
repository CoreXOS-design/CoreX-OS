@extends('layouts.corex')

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-8">
        <h1 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Import Document Template</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">
            Upload a Word document (.docx). CoreX will detect fillable fields and convert it to a web template automatically.
        </p>

        @if(session('success'))
            <div class="bg-teal-900/40 border border-teal-500 text-teal-200 rounded px-4 py-3 mb-4 text-sm">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">
                {{ session('error') }}
            </div>
        @endif

        {{-- Resume drafts section --}}
        @if(isset($drafts) && $drafts->count() > 0)
            <div class="mb-6" x-data="{ csrfToken: '{{ csrf_token() }}' }">
                <h2 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wider mb-3">Drafts in Progress</h2>
                <div class="space-y-2">
                    @foreach($drafts as $draft)
                        <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg px-4 py-3"
                             id="draft-row-{{ $draft->id }}">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate">{{ $draft->template_name }}</p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $draft->linked_count }} of {{ $draft->tag_count }} linked
                                    &middot;
                                    {{ $draft->updated_at->diffForHumans() }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2 ml-4">
                                <a href="{{ route('docuperfect.import.review', ['draft_id' => $draft->id]) }}"
                                   class="text-xs font-semibold text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 whitespace-nowrap">
                                    Resume &rarr;
                                </a>
                                <button type="button"
                                        class="text-xs text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 whitespace-nowrap"
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
                <hr class="border-gray-200 dark:border-gray-600 mt-6 mb-2">
            </div>
        @endif

        <div x-data="{
            fileName: '',
            dragging: false,
            submitting: false,
            progress: '',
            error: '',

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

            {{-- Template Name --}}
            <div class="mb-5">
                <label for="template_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Template Name</label>
                <input type="text" x-ref="templateName" id="template_name"
                       value="{{ old('template_name') }}"
                       placeholder="e.g. Residential Lease Agreement v2"
                       class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm"
                       :disabled="submitting"
                       required>
                @error('template_name')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- File Upload --}}
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Document File</label>
                <div class="relative border-2 border-dashed rounded-lg p-8 text-center transition-colors"
                     :class="dragging ? 'border-blue-400 bg-blue-50' : 'border-gray-300 dark:border-gray-600 hover:border-gray-400'"
                     @dragover.prevent="dragging = true"
                     @dragleave.prevent="dragging = false"
                     @drop.prevent="dragging = false; $refs.fileInput.files = $event.dataTransfer.files; fileName = $event.dataTransfer.files[0]?.name || ''">
                    <input type="file" accept=".docx" class="hidden" x-ref="fileInput"
                           @change="fileName = $event.target.files[0]?.name || ''">

                    <div x-show="!fileName" class="space-y-2">
                        <svg class="mx-auto h-10 w-10 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m6.75 12-3-3m0 0-3 3m3-3v6m-1.5-15H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                        </svg>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            <button type="button" @click="$refs.fileInput.click()" class="text-blue-600 font-medium hover:text-blue-500"
                                    :disabled="submitting">
                                Click to upload
                            </button>
                            or drag and drop
                        </p>
                        <p class="text-xs text-gray-400">.docx only, max 10MB</p>
                    </div>

                    <div x-show="fileName" x-cloak class="space-y-2">
                        <svg class="mx-auto h-10 w-10 text-green-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        </svg>
                        <p class="text-sm font-medium text-gray-700 dark:text-gray-300" x-text="fileName"></p>
                        <button type="button" @click="fileName = ''; $refs.fileInput.value = ''"
                                class="text-xs text-red-500 hover:text-red-700"
                                :disabled="submitting">Remove</button>
                    </div>
                </div>
                @error('docx_file')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            {{-- Progress / Error Messages --}}
            <div x-show="submitting && progress" x-cloak
                 class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg flex items-center gap-3">
                <svg class="animate-spin h-4 w-4 text-blue-600 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span class="text-sm text-blue-700" x-text="progress"></span>
            </div>

            <div x-show="error" x-cloak
                 class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm"
                 x-text="error"></div>

            {{-- Submit --}}
            <button type="button"
                    @click="submitForm()"
                    :disabled="submitting"
                    class="w-full flex items-center justify-center gap-2 bg-blue-600 text-white px-5 py-2.5 rounded-lg font-medium text-sm hover:bg-blue-700 disabled:opacity-50 transition-colors">
                <span x-text="submitting ? 'Processing...' : 'Parse Document'"></span>
            </button>
        </div>
    </div>
</div>
@endsection
