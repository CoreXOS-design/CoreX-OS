<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Signing Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">

<div class="max-w-2xl mx-auto py-10 px-4" x-data="wetInkPortal()">

    {{-- Agency branding --}}
    <div class="text-center mb-8">
        @if(!empty($branding['logo']))
            <img src="{{ $branding['logo'] }}" alt="{{ $branding['name'] }}" class="h-16 mx-auto mb-3">
        @else
            <div class="w-16 h-16 rounded-full mx-auto mb-3 flex items-center justify-center text-2xl font-bold text-white"
                 style="background:{{ $branding['color'] ?? '#0b2a4a' }};">
                {{ strtoupper(substr($branding['name'] ?? 'A', 0, 1)) }}
            </div>
        @endif
        <h1 class="text-xl font-bold text-gray-900">{{ $branding['name'] ?? 'Agency' }}</h1>
    </div>

    {{-- Main card --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">

        {{-- Header --}}
        <div class="px-6 py-5 border-b border-gray-100" style="background:{{ $branding['color'] ?? '#0b2a4a' }};">
            <h2 class="text-lg font-semibold text-white">Document Signing Portal</h2>
            <p class="text-sm text-white/70 mt-1">Wet Ink Signing Required</p>
        </div>

        <div class="p-6 space-y-6">

            {{-- Document info --}}
            <div class="rounded-lg bg-gray-50 border border-gray-200 p-4">
                <div class="flex items-start gap-3">
                    <svg class="w-5 h-5 text-gray-400 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                    <div>
                        <p class="text-sm font-semibold text-gray-800">{{ $document->name }}</p>
                        <p class="text-xs text-gray-500 mt-0.5">Sent by {{ $request->sender->name ?? 'Agent' }}</p>
                    </div>
                </div>
            </div>

            {{-- Signer info --}}
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white"
                     style="background:{{ $branding['color'] ?? '#0b2a4a' }};">
                    {{ strtoupper(substr($request->signer_name ?? 'S', 0, 1)) }}
                </div>
                <div>
                    <p class="text-sm font-semibold text-gray-800">{{ $request->signer_name }}</p>
                    <p class="text-xs text-gray-500">{{ ucfirst(str_replace('_', ' ', $request->party_role)) }}</p>
                </div>
            </div>

            @if($request->wet_ink_status === \App\Models\Docuperfect\SignatureRequest::WET_INK_REJECTED)
            <div class="rounded-lg bg-red-50 border border-red-200 p-4">
                <p class="text-sm font-semibold text-red-800">Your previous upload was returned for revision.</p>
                @if($request->wet_ink_rejection_note)
                    <p class="text-sm text-red-700 mt-1"><strong>Note:</strong> {{ $request->wet_ink_rejection_note }}</p>
                @endif
                <p class="text-xs text-red-600 mt-2">Please download the document again, sign, and re-upload.</p>
            </div>
            @endif

            {{-- Instructions --}}
            <div class="rounded-lg bg-blue-50 border border-blue-200 p-4">
                <h3 class="text-sm font-semibold text-blue-800 mb-2">How to complete your signing</h3>
                <ol class="text-sm text-blue-700 space-y-2 list-decimal ml-5">
                    <li>Download the document using the button below</li>
                    <li>Print the document</li>
                    <li>Sign in ink at all marked positions</li>
                    <li>Scan or photograph the signed pages</li>
                    <li>Upload the signed document using the upload area below</li>
                </ol>
            </div>

            {{-- Step 1: Download --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Step 1: Download Document</h3>
                <a href="{{ route('signatures.external.download', $token) }}"
                   @click="downloaded = true"
                   class="inline-flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-semibold text-white transition-colors"
                   style="background:{{ $branding['color'] ?? '#0b2a4a' }};">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                    Download Document for Signing
                </a>
                <div x-show="downloaded" class="mt-2 text-xs text-green-600 flex items-center gap-1">
                    <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    Downloaded
                </div>
            </div>

            {{-- Step 2: Upload --}}
            <div>
                <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Step 2: Upload Signed Document</h3>

                <form action="{{ route('signatures.external.upload', $token) }}"
                      method="POST"
                      enctype="multipart/form-data"
                      class="space-y-4">
                    @csrf

                    {{-- Drop zone --}}
                    <div class="border-2 border-dashed border-gray-300 rounded-xl p-8 text-center hover:border-blue-400 hover:bg-blue-50/50 transition-colors cursor-pointer"
                         @click="$refs.fileInput.click()"
                         @dragover.prevent="dragover = true"
                         @dragleave.prevent="dragover = false"
                         @drop.prevent="handleDrop($event)"
                         :class="dragover ? 'border-blue-400 bg-blue-50/50' : ''">
                        <input type="file" name="files[]" x-ref="fileInput" multiple
                               accept=".pdf,.jpg,.jpeg,.png"
                               @change="handleFiles($event)"
                               class="hidden">
                        <svg class="w-10 h-10 mx-auto text-gray-400 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                        </svg>
                        <p class="text-sm text-gray-600 font-medium">Drop files here or click to browse</p>
                        <p class="text-xs text-gray-400 mt-1">Accepted: PDF, JPG, PNG (max 20MB each)</p>
                    </div>

                    {{-- File list --}}
                    <template x-if="selectedFiles.length > 0">
                        <div class="space-y-2">
                            <template x-for="(file, idx) in selectedFiles" :key="idx">
                                <div class="flex items-center justify-between p-3 rounded-lg bg-gray-50 border border-gray-200">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <span class="text-sm text-gray-700" x-text="file.name"></span>
                                        <span class="text-xs text-gray-400" x-text="(file.size / 1024 / 1024).toFixed(1) + ' MB'"></span>
                                    </div>
                                    <button type="button" @click="removeFile(idx)" class="text-red-400 hover:text-red-600">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </template>

                    <button type="submit"
                            :disabled="selectedFiles.length === 0"
                            class="w-full py-3 rounded-lg text-sm font-semibold text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            style="background:{{ $branding['color'] ?? '#0b2a4a' }};">
                        Upload Signed Document
                    </button>
                </form>
            </div>

            {{-- Status --}}
            @if($request->wet_ink_status === \App\Models\Docuperfect\SignatureRequest::WET_INK_UPLOADED_PENDING_REVIEW)
            <div class="rounded-lg bg-amber-50 border border-amber-200 p-4 text-center">
                <svg class="w-8 h-8 mx-auto text-amber-500 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p class="text-sm font-semibold text-amber-800">Document Uploaded — Awaiting Review</p>
                <p class="text-xs text-amber-600 mt-1">Your agent will review the uploaded document. You'll be notified once approved.</p>
            </div>
            @endif
        </div>
    </div>

    {{-- Version history --}}
    @if(isset($versions) && $versions->isNotEmpty())
    <div class="mt-6 bg-white rounded-2xl shadow-sm border border-gray-200 p-6">
        <h3 class="text-sm font-semibold text-gray-700 uppercase tracking-wide mb-3">Upload History</h3>
        <div class="space-y-2">
            @foreach($versions as $version)
            <div class="flex items-center justify-between p-3 rounded-lg bg-gray-50 border border-gray-200">
                <div>
                    <p class="text-sm text-gray-700 font-medium">Version {{ $version->version_number }}</p>
                    <p class="text-xs text-gray-500">{{ $version->uploaded_at?->format('d M Y H:i') ?? 'Unknown' }}</p>
                </div>
                <div>
                    @if($version->agent_approved)
                        <span class="text-xs px-2 py-1 rounded-full bg-green-100 text-green-700 font-medium">Approved</span>
                    @else
                        <span class="text-xs px-2 py-1 rounded-full bg-amber-100 text-amber-700 font-medium">Pending Review</span>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Footer --}}
    <div class="mt-8 text-center text-xs text-gray-400">
        <p>This is a secure document portal. Your information is protected.</p>
        <p class="mt-1">{{ $branding['name'] ?? '' }}</p>
    </div>
</div>

<script>
function wetInkPortal() {
    return {
        downloaded: false,
        dragover: false,
        selectedFiles: [],
        handleFiles(event) {
            this.selectedFiles = [...this.selectedFiles, ...Array.from(event.target.files)];
        },
        handleDrop(event) {
            this.dragover = false;
            this.selectedFiles = [...this.selectedFiles, ...Array.from(event.dataTransfer.files)];
        },
        removeFile(index) {
            this.selectedFiles.splice(index, 1);
        },
    };
}
</script>
</body>
</html>
