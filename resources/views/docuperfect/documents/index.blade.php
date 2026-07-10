@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto" data-tour="dp-documents-root">

    <x-list-header
        :title="!empty($packInstance) ? 'Document Pack' : 'My Documents'"
        :form-action="route('docuperfect.documents.index', !empty($packInstance) ? ['pack_instance' => $packInstance] : [])"
        :paginator="$documents"
        search-placeholder="Search documents..."
    >
        <x-slot:filters>
            @if(empty($packInstance))
            <select name="filter" onchange="this.form.submit()" class="list-header-filter" data-tour="dp-documents-filter">
                <option value="active" {{ ($filter ?? 'active') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="archived" {{ ($filter ?? 'active') === 'archived' ? 'selected' : '' }}>Archived</option>
            </select>
            @endif
        </x-slot:filters>
        <x-slot:actions>
            @if(!empty($packInstance))
            <a href="{{ route('docuperfect.documents.index') }}" class="corex-btn-outline text-sm" data-tour="dp-documents-show-all">Show All</a>
            @else
            <a href="{{ route('docuperfect.dashboard') }}" class="corex-btn-primary text-sm">+ Create New</a>
            @endif
        </x-slot:actions>
    </x-list-header>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm mt-4">
            {{ session('status') }}
        </div>
    @endif

    @if(session('error'))
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm mt-4">
            {{ session('error') }}
        </div>
    @endif

    @if($documents->isEmpty())
        <div class="ds-status-card p-6 text-center mt-4">
            <div class="text-sm text-slate-500">
                @if(request('search'))
                    No documents match your search.
                @else
                    No documents yet. <a href="{{ route('docuperfect.dashboard') }}" class="ds-link">Create one from a template</a>.
                @endif
            </div>
        </div>
    @else
        @if(!empty($packInstance))
        <div class="flex justify-end mt-4">
            <button type="button" id="dpCombinedPdfBtn"
                    class="corex-btn-primary text-sm px-4 py-2" style="background:#0b2a4a;"
                    onclick="downloadCombinedPdf()" data-tour="dp-documents-combined-pdf">
                <i class="fas fa-file-pdf mr-1"></i> Download as Single PDF
            </button>
        </div>
        @endif
        <div class="rounded-2xl border border-slate-200 bg-white overflow-x-auto mt-4" data-tour="dp-documents-table">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr>
                        <x-sort-header field="name" label="Name" />
                        <th class="text-left px-4 py-3">Template</th>
                        <x-sort-header field="updated_at" label="Last Edited" />
                        @if($user->hasPermission('documents.edit'))
                        <th class="text-left px-4 py-3">Agent</th>
                        @endif
                        @if($user->hasPermission('manage_system'))
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
                        @if($user->hasPermission('documents.edit'))
                        <td class="px-4 py-3 text-slate-600">{{ $doc->owner->name ?? '—' }}</td>
                        @endif
                        @if($user->hasPermission('manage_system'))
                        <td class="px-4 py-3 text-slate-600">{{ $doc->branch->name ?? '—' }}</td>
                        @endif
                        <td class="px-4 py-3 text-right space-x-2">
                            @if(($filter ?? 'active') === 'archived')
                                <form method="POST" action="{{ route('docuperfect.documents.restore', $doc->id) }}" class="inline">
                                    @csrf
                                    <button class="text-sm text-blue-600 hover:text-blue-800">Restore</button>
                                </form>
                                <form method="POST" action="{{ route('docuperfect.documents.destroy', $doc->id) }}" class="inline" onsubmit="return confirm('Permanently delete this document? This cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-sm text-slate-400 hover:text-red-600">Delete</button>
                                </form>
                            @else
                                <a href="{{ route('docuperfect.documents.edit', $doc->id) }}" class="ds-link text-sm">Edit</a>
                                @php
                                    $sigTemplate = $doc->signatureTemplate;
                                    $isInActiveWorkflow = $sigTemplate && in_array($sigTemplate->status, [
                                        'awaiting_tenant', 'awaiting_landlord', 'signing',
                                        'pending_agent_approval', 'completed', 'sent',
                                    ]);
                                @endphp
                                @if($isInActiveWorkflow)
                                    <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-100 text-emerald-700">Active — cannot archive</span>
                                @else
                                    <form method="POST" action="{{ route('docuperfect.documents.archive', $doc->id) }}" class="inline" onsubmit="return confirm('Archive this document? It will be moved to the Archived tab.');">
                                        @csrf
                                        <button class="text-sm text-slate-400 hover:text-amber-600">Archive</button>
                                    </form>
                                @endif
                                @if(!$isInActiveWorkflow)
                                <form method="POST" action="{{ route('docuperfect.documents.destroy', $doc->id) }}" class="inline" onsubmit="return confirm('Permanently delete this document? This cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-sm text-slate-400 hover:text-red-600">Delete</button>
                                </form>
                                @endif
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $documents->links() }}
        </div>
    @endif

    {{-- Attachments (KB documents linked to pack instance) --}}
    @if(!empty($packInstance) && isset($attachments) && $attachments->isNotEmpty())
    <div class="mt-6">
        <h3 class="ds-section-header">Attachments</h3>
        <div class="rounded-2xl border border-slate-200 bg-white overflow-x-auto">
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
    // AT-207: the page-raster routes are auth-gated. img.crossOrigin='anonymous' has
    // credentials mode "same-origin", so on a cross-origin URL (edit/list page used on
    // corex.hfcoastal.co.za while route()/APP_URL emits corexos.co.za) the session
    // cookie is dropped, the auth middleware 302s to /login, and the <img> fails to
    // decode HTML → "Failed to load image". Fix (origin-agnostic, no CORS, untainted for
    // jsPDF): rebase to the current origin, fetch credentialed, hand jsPDF a data URL.
    var fetchUrl = url;
    try {
        var u = new URL(url, window.location.href);
        fetchUrl = u.pathname + u.search; // same-origin relative → current host + cookie
    } catch (e) { /* non-absolute URL: fetch as-is */ }

    return fetch(fetchUrl, { credentials: 'include' }).then(function(resp) {
        if (!resp.ok) {
            throw new Error('page image HTTP ' + resp.status + ' (' + fetchUrl + ')');
        }
        if (resp.redirected) {
            var to = fetchUrl;
            try { to = new URL(resp.url).pathname; } catch (e2) {}
            throw new Error('not authenticated (redirected to ' + to + ') — please reload and sign in');
        }
        var ct = resp.headers.get('content-type') || '';
        if (ct.indexOf('image') === -1) {
            throw new Error('unexpected response "' + (ct || 'unknown') + '" for page image (' + fetchUrl + ')');
        }
        return resp.blob();
    }, function() {
        throw new Error('network error loading page image (' + fetchUrl + ')');
    }).then(function(blob) {
        return new Promise(function(resolve, reject) {
            var reader = new FileReader();
            reader.onload = function() {
                var img = new Image();
                img.onload = function() { resolve(img); };
                img.onerror = function() { reject(new Error('could not decode page image (' + fetchUrl + ')')); };
                img.src = reader.result; // data: URL — same-origin, never taints the jsPDF canvas
            };
            reader.onerror = function() { reject(new Error('could not read page image data (' + fetchUrl + ')')); };
            reader.readAsDataURL(blob);
        });
    });
}
</script>
@endif
@endsection
