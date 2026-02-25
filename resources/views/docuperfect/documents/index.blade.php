@extends('layouts.nexus')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            @if(!empty($packInstance))
            <h2 class="text-xl font-bold text-white leading-tight">Document Pack</h2>
            <div class="text-sm text-white/60">Documents created from this pack. Fill named fields once — they populate across all documents.</div>
            @else
            <h2 class="text-xl font-bold text-white leading-tight">My Documents</h2>
            <div class="text-sm text-white/60">All your filled documents. Create new ones from the <a href="{{ route('docuperfect.dashboard') }}" class="text-white/80 underline">Dashboard</a>.</div>
            @endif
        </div>
        @if(!empty($packInstance))
        <a href="{{ route('docuperfect.documents.index') }}" class="text-sm text-white/70 hover:text-white">Show All</a>
        @endif
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if($documents->isEmpty())
        <div class="ds-status-card p-6 text-center">
            <div class="text-sm text-slate-500">No documents yet. <a href="{{ route('docuperfect.dashboard') }}" class="ds-link">Create one from a template</a>.</div>
        </div>
    @else
        @if(!empty($packInstance))
        <div class="flex justify-end">
            <button type="button" id="dpCombinedPdfBtn"
                    class="nexus-btn-primary text-sm px-4 py-2" style="background:#0b2a4a;"
                    onclick="downloadCombinedPdf()">
                <i class="fas fa-file-pdf mr-1"></i> Download as Single PDF
            </button>
        </div>
        @endif
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-3">Name</th>
                        <th class="text-left px-4 py-3">Template</th>
                        <th class="text-left px-4 py-3">Last Edited</th>
                        @if($user->isAdmin() || $user->isBranchManager())
                        <th class="text-left px-4 py-3">Agent</th>
                        @endif
                        @if($user->isAdmin())
                        <th class="text-left px-4 py-3">Branch</th>
                        @endif
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($documents as $doc)
                    <tr x-data="{ renaming: false }">
                        <td class="px-4 py-3 font-medium text-slate-900">
                            <div x-show="!renaming" class="flex items-center gap-1.5">
                                <span>{{ $doc->name }}</span>
                                <button @click="renaming = true" class="text-slate-300 hover:text-slate-500" title="Rename">
                                    <i class="fas fa-pencil-alt text-[10px]"></i>
                                </button>
                            </div>
                            <form x-show="renaming" x-cloak method="POST" action="{{ route('docuperfect.documents.rename', $doc->id) }}" class="flex items-center gap-1.5">
                                @csrf
                                <input type="text" name="name" value="{{ $doc->name }}"
                                       class="rounded border border-slate-300 text-sm px-2 py-0.5 w-full max-w-xs focus:ring-1 focus:ring-blue-400"
                                       required maxlength="255"
                                       x-ref="renameInput"
                                       x-init="$watch('renaming', v => { if(v) $nextTick(() => $refs.renameInput.select()) })">
                                <button type="submit" class="text-green-600 hover:text-green-800 text-xs font-medium">Save</button>
                                <button type="button" @click="renaming = false" class="text-slate-400 hover:text-slate-600 text-xs">Cancel</button>
                            </form>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $doc->template->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $doc->updated_at->format('d M Y H:i') }}</td>
                        @if($user->isAdmin() || $user->isBranchManager())
                        <td class="px-4 py-3 text-slate-600">{{ $doc->owner->name ?? '—' }}</td>
                        @endif
                        @if($user->isAdmin())
                        <td class="px-4 py-3 text-slate-600">{{ $doc->branch->name ?? '—' }}</td>
                        @endif
                        <td class="px-4 py-3 text-right space-x-2">
                            <a href="{{ route('docuperfect.documents.edit', $doc->id) }}" class="ds-link text-sm">Edit</a>
                            <form method="POST" action="{{ route('docuperfect.documents.archive', $doc->id) }}" class="inline" onsubmit="return confirm('Archive this document?');">
                                @csrf
                                <button class="text-sm text-slate-400 hover:text-amber-600">Archive</button>
                            </form>
                            <form method="POST" action="{{ route('docuperfect.documents.destroy', $doc->id) }}" class="inline" onsubmit="return confirm('Permanently delete this document?');">
                                @csrf
                                @method('DELETE')
                                <button class="text-sm text-slate-400 hover:text-red-600">Delete</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Attachments (KB documents linked to pack instance) --}}
    @if(!empty($packInstance) && isset($attachments) && $attachments->isNotEmpty())
    <div>
        <h3 class="ds-section-header">Attachments</h3>
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-3">Document</th>
                        <th class="text-left px-4 py-3">Category</th>
                        <th class="text-left px-4 py-3">Slot</th>
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($attachments as $att)
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900">
                            <i class="fas fa-paperclip text-blue-400 mr-1"></i>
                            {{ $att->knowledgeDocument->title ?? 'Unknown' }}
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $att->knowledgeDocument->category->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $att->slot_label }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('docuperfect.attachments.download', $att->id) }}" class="ds-link text-sm">
                                <i class="fas fa-download mr-1"></i>Download
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

</div>

@if(!empty($packInstance) && !$documents->isEmpty())
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
function downloadCombinedPdf() {
    var btn = document.getElementById('dpCombinedPdfBtn');
    if (btn) { btn.textContent = 'Generating\u2026'; btn.disabled = true; }

    var jsPDF = window.jspdf && window.jspdf.jsPDF;
    if (!jsPDF) {
        alert('PDF library not loaded. Please refresh and try again.');
        if (btn) { btn.innerHTML = '<i class="fas fa-file-pdf mr-1"></i> Download as Single PDF'; btn.disabled = false; }
        return;
    }

    fetch(@json(route('docuperfect.api.combinedPdfData', ['instanceId' => $packInstance])), {
        headers: { 'Accept': 'application/json' }
    })
    .then(function(r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function(data) {
        var docs = data.documents;
        if (!docs || docs.length === 0) {
            alert('No documents to combine.');
            return;
        }

        var pdf = new jsPDF({ orientation: 'p', unit: 'pt', format: 'letter' });
        var W = 612, H = 792;
        var isFirstPage = true;

        var chain = Promise.resolve();

        docs.forEach(function(doc) {
            var fields = doc.fields || [];
            doc.pageImages.forEach(function(url, pageIdx) {
                chain = chain.then(function() {
                    if (!isFirstPage) pdf.addPage();
                    isFirstPage = false;

                    return loadImg(url).then(function(img) {
                        pdf.addImage(img, 'PNG', 0, 0, W, H);

                        fields.filter(function(f) { return f.pageIndex === pageIdx; }).forEach(function(f) {
                            var x = (f.position.x / 100) * W;
                            var y = (f.position.y / 100) * H;
                            var w = (f.size.width / 100) * W;
                            var h = (f.size.height / 100) * H;
                            var st = f.style || {};

                            if (st.solidBackground) { pdf.setFillColor(255, 255, 255); pdf.rect(x, y, w, h, 'F'); }

                            var fam = (st.fontFamily || 'helvetica').toLowerCase();
                            pdf.setFont(fam, st.bold ? 'bold' : 'normal');
                            pdf.setFontSize(st.fontSize || 12);
                            pdf.setTextColor(0, 0, 0);

                            switch (f.type) {
                                case 'placeholder':
                                case 'date':
                                    if (f.value) {
                                        var fs = st.fontSize || 12;
                                        pdf.text(f.value, x + 2, y + fs, { maxWidth: w - 4 });
                                        if (st.underline) {
                                            var met = pdf.getTextDimensions(f.value, { maxWidth: w - 4 });
                                            pdf.setLineWidth(0.5);
                                            pdf.line(x + 2, y + fs + 1, x + 2 + met.w, y + fs + 1);
                                        }
                                    }
                                    break;
                                case 'signature':
                                case 'initial':
                                    pdf.setDrawColor(0, 0, 0); pdf.setLineWidth(1);
                                    pdf.line(x, y + h - 5, x + w, y + h - 5);
                                    pdf.setFontSize(8);
                                    pdf.text(f.type, x, y + h - 7);
                                    break;
                                case 'selection':
                                    if (f.selectedValue) pdf.text(f.selectedValue, x + 2, y + h / 2, { baseline: 'middle' });
                                    break;
                                case 'strikethrough':
                                    if (f.active) {
                                        pdf.setDrawColor(0, 0, 0); pdf.setLineWidth(1.5);
                                        if (f.strikethroughType === 'diagonal') {
                                            pdf.line(x, y + h, x + w, y);
                                        } else {
                                            pdf.line(x, y + h / 2, x + w, y + h / 2);
                                        }
                                    }
                                    break;
                                case 'condition':
                                    if (f.text) {
                                        var cfs = st.fontSize || 10;
                                        pdf.setFontSize(cfs);
                                        pdf.text(f.text, x + 2, y + cfs, { maxWidth: w - 4 });
                                    }
                                    break;
                            }
                        });
                    });
                });
            });
        });

        chain.then(function() {
            var firstName = docs[0].name || 'Document Pack';
            var prefix = firstName.split(' \u2014 ')[0];
            var filename = prefix ? 'Document Pack \u2014 ' + prefix + '.pdf' : 'Document Pack.pdf';
            pdf.save(filename);
        });

        return chain;
    })
    .catch(function(err) {
        alert('Combined PDF failed: ' + err.message);
    })
    .finally(function() {
        if (btn) { btn.innerHTML = '<i class="fas fa-file-pdf mr-1"></i> Download as Single PDF'; btn.disabled = false; }
    });
}

function loadImg(url) {
    return new Promise(function(resolve, reject) {
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function() { resolve(img); };
        img.onerror = function() { reject(new Error('Failed to load image: ' + url)); };
        img.src = url;
    });
}
</script>
@endif
@endsection
