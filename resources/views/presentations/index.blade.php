@extends('layouts.nexus')

@section('nexus-content')
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">Presentations</h1>
        <p class="text-sm text-gray-500 mt-1">Upload & Extraction Framework — Scaffold</p>
    </div>

    @if(session('success'))
        <div class="mb-4 px-4 py-3 bg-green-50 border border-green-200 text-green-800 rounded text-sm">
            {{ session('success') }}
        </div>
    @endif

    {{-- Upload Form (scaffold: targets presentation ID 1) --}}
    <div class="bg-white rounded-xl shadow p-6 mb-6">
        <h2 class="text-base font-semibold text-gray-700 mb-3">Upload Document (Scaffold — Presentation ID 1)</h2>
        <p class="text-xs text-gray-400 mb-4">
            Stores file → extracts text → detects fields. No AI. No PDF output yet.
        </p>

        <form method="POST"
              action="{{ route('presentations.upload', 1) }}"
              enctype="multipart/form-data">
            @csrf
            <div class="flex items-center gap-4">
                <input type="file"
                       name="document"
                       accept=".pdf,.doc,.docx,.txt"
                       class="text-sm text-gray-600 border border-gray-300 rounded px-3 py-2 w-full">
                <button type="submit"
                        class="shrink-0 px-4 py-2 bg-indigo-600 text-white text-sm rounded hover:bg-indigo-700">
                    Upload
                </button>
            </div>
            @error('document')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </form>
    </div>

    {{-- Uploads List --}}
    <div class="bg-white rounded-xl shadow p-6">
        <h2 class="text-base font-semibold text-gray-700 mb-3">Uploaded Documents</h2>

        @if($uploads->isEmpty())
            <p class="text-sm text-gray-400">No uploads yet.</p>
        @else
            <table class="w-full text-sm text-left text-gray-600">
                <thead>
                    <tr class="border-b">
                        <th class="pb-2 pr-4">File</th>
                        <th class="pb-2 pr-4">Type</th>
                        <th class="pb-2 pr-4">Extraction</th>
                        <th class="pb-2">Uploaded</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($uploads as $upload)
                        <tr class="border-b last:border-0">
                            <td class="py-2 pr-4">{{ $upload->original_filename }}</td>
                            <td class="py-2 pr-4 text-gray-400 text-xs">{{ $upload->type }}</td>
                            <td class="py-2 pr-4">
                                @if($upload->extraction_status === 'ok')
                                    <span class="text-green-600 font-medium">OK</span>
                                @elseif($upload->extraction_status === 'failed')
                                    <span class="text-red-500">Failed</span>
                                @else
                                    <span class="text-yellow-500">Pending</span>
                                @endif
                            </td>
                            <td class="py-2 text-gray-400 text-xs">{{ $upload->created_at->format('Y-m-d H:i') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
