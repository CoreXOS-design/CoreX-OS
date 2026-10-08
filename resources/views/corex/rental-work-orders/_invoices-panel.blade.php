{{--
    .ai/specs/rental-work-orders.md §17.31 — the supplier's invoice documents on this work order: file one or more (PDF / photo), see the
    list, view, change the facts, replace the file, tick "share with owner", archive and restore. Office only (rental_work_orders.manage_invoices);
    the tenant never sees invoices or amounts, the owner sees an invoice only while "Share with owner" is ticked. The amount is evidence for the
    cost / who-pays record: it pre-fills the Complete form's cost box and is shown beside the recorded cost, but never writes it.
--}}
@permission('rental_work_orders.manage_invoices')
@php
    $invoiceService = app(\App\Services\Rentals\RentalWorkOrderInvoiceService::class);
    $liveInvoices = $workOrder->invoices()->with(['supplier', 'uploadedBy'])->get();
    $archivedInvoices = $workOrder->invoices()->onlyTrashed()->with('supplier')->get();
    $invoiceTotal = $invoiceService->total($workOrder);
    $invoiceLimits = $invoiceService->limitsText($workOrder->agency_id);
    $invoiceAccept = '.' . implode(',.', \App\Models\RentalWorkOrderSetting::invoiceAllowedExtensionsFor($workOrder->agency_id));
    $invoiceSuppliers = \App\Models\DealV2\AgencyServiceProvider::query()->where('agency_id', $workOrder->agency_id)->orderBy('name')->get(['id', 'name']);
@endphp
<div id="invoices" class="space-y-3 p-5" style="background: var(--surface); border: 1px solid var(--border); border-radius: 6px;" data-invoices-panel>
    <h2 class="text-sm font-bold" style="color: var(--text-primary);">Supplier invoices</h2>

    @if($errors->has('invoice') || $errors->has('document'))
        <p class="text-xs" style="color:#b3261e;" data-invoice-error>{{ $errors->first('invoice') ?: $errors->first('document') }}</p>
    @endif

    @if($liveInvoices->isEmpty())
        <p class="text-xs" style="color: var(--text-muted);" data-invoices-empty>No supplier invoice filed yet.</p>
    @else
        <ul class="space-y-2 text-sm">
            @foreach($liveInvoices as $invoice)
                <li data-invoice-row="{{ $invoice->id }}">
                    <div class="flex flex-wrap items-center gap-2">
                        <span>
                            <strong>{{ $invoice->invoice_number }}</strong>
                            — R{{ number_format((float) $invoice->amount, 2) }}
                            <span style="color: var(--text-muted);">({{ $invoice->invoice_date?->format('Y-m-d') }}{{ $invoice->supplierLabel() ? ' · ' . $invoice->supplierLabel() : '' }})</span>
                            @if($invoice->share_with_owner)
                                <span class="text-xs px-2 py-0.5 rounded" style="background:#e6f4ea; color:#137333;">Shared with owner</span>
                            @endif
                            <a href="{{ route('corex.rental-work-orders.invoices.download', [$workOrder, $invoice]) }}" target="_blank" rel="noopener" class="underline text-xs">View file</a>
                        </span>
                        <form method="POST" action="{{ route('corex.rental-work-orders.invoices.share', [$workOrder, $invoice]) }}">
                            @csrf
                            <input type="hidden" name="share_with_owner" value="{{ $invoice->share_with_owner ? 0 : 1 }}">
                            <button type="submit" class="corex-btn-outline text-xs">{{ $invoice->share_with_owner ? 'Stop sharing with owner' : 'Share with owner' }}</button>
                        </form>
                        <button type="button" onclick="document.getElementById('edit-invoice-form-{{ $invoice->id }}').classList.toggle('hidden')" class="corex-btn-outline text-xs">Edit / replace file</button>
                        <form method="POST" action="{{ route('corex.rental-work-orders.invoices.destroy', [$workOrder, $invoice]) }}" onsubmit="return confirm('Archive this invoice?');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="corex-btn-outline text-xs">Archive</button>
                        </form>
                    </div>
                    <form id="edit-invoice-form-{{ $invoice->id }}" method="POST" action="{{ route('corex.rental-work-orders.invoices.update', [$workOrder, $invoice]) }}" enctype="multipart/form-data" class="hidden space-y-2 pt-2">
                        @csrf
                        @method('PUT')
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
                            <div>
                                <label class="text-xs">Invoice number</label>
                                <input type="text" name="invoice_number" required maxlength="100" value="{{ $invoice->invoice_number }}" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                            </div>
                            <div>
                                <label class="text-xs">Invoice date</label>
                                <input type="date" name="invoice_date" required value="{{ $invoice->invoice_date?->format('Y-m-d') }}" max="{{ now()->format('Y-m-d') }}" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                            </div>
                            <div>
                                <label class="text-xs">Amount (R)</label>
                                <input type="number" name="amount" required min="0" step="0.01" value="{{ $invoice->amount }}" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                            </div>
                        </div>
                        <div>
                            <label class="text-xs">Replace the file (optional — {{ $invoiceLimits }}; the old file is kept on record)</label>
                            <input type="file" name="document" accept="{{ $invoiceAccept }}" class="w-full text-xs mt-1">
                        </div>
                        <label class="flex items-center gap-2 text-xs">
                            <input type="hidden" name="share_with_owner" value="0">
                            <input type="checkbox" name="share_with_owner" value="1" @checked($invoice->share_with_owner)>
                            Share with owner
                        </label>
                        <button type="submit" class="corex-btn-outline text-xs">Save invoice</button>
                    </form>
                </li>
            @endforeach
        </ul>
        <p class="text-xs" style="color: var(--text-muted);" data-invoices-total>
            Invoices filed: R{{ number_format($invoiceTotal, 2) }}
            @if($workOrder->cost_amount !== null)
                · Recorded cost: R{{ number_format((float) $workOrder->cost_amount, 2) }}
                @if(abs($invoiceTotal - (float) $workOrder->cost_amount) > 0.004)
                    <strong style="color:#b3261e;" data-invoice-mismatch>— differs from the invoices by R{{ number_format(abs($invoiceTotal - (float) $workOrder->cost_amount), 2) }}; the recorded cost is unchanged.</strong>
                @endif
            @elseif($workOrder->status !== \App\Models\RentalWorkOrder::STATUS_COMPLETED)
                · This total is offered as the cost when you mark the work order complete.
            @endif
        </p>
    @endif

    <form method="POST" action="{{ route('corex.rental-work-orders.invoices.store', $workOrder) }}" enctype="multipart/form-data" class="space-y-2 pt-2" data-invoice-upload-form>
        @csrf
        <div class="grid grid-cols-1 md:grid-cols-3 gap-2">
            <div>
                <label class="text-xs">Invoice number</label>
                <input type="text" name="invoice_number" required maxlength="100" value="{{ old('invoice_number') }}" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs">Invoice date</label>
                <input type="date" name="invoice_date" required max="{{ now()->format('Y-m-d') }}" value="{{ old('invoice_date') }}" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs">Amount (R)</label>
                <input type="number" name="amount" required min="0" step="0.01" value="{{ old('amount') }}" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
            </div>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
            <div>
                <label class="text-xs">Supplier</label>
                <select name="agency_service_provider_id" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
                    <option value="">Other / not in the list</option>
                    @foreach($invoiceSuppliers as $provider)
                        <option value="{{ $provider->id }}" @selected((int) old('agency_service_provider_id', $workOrder->agency_service_provider_id) === $provider->id)>{{ $provider->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="text-xs">Supplier name (if not in the list)</label>
                <input type="text" name="supplier_name" maxlength="191" value="{{ old('supplier_name') }}" class="w-full rounded-md px-3 py-2 text-xs mt-1" style="border: 1px solid var(--border);">
            </div>
        </div>
        <div>
            <label class="text-xs">Invoice file ({{ $invoiceLimits }})</label>
            <input type="file" name="document" required accept="{{ $invoiceAccept }}" class="w-full text-xs mt-1">
        </div>
        <label class="flex items-center gap-2 text-xs">
            <input type="hidden" name="share_with_owner" value="0">
            <input type="checkbox" name="share_with_owner" value="1" @checked(old('share_with_owner'))>
            Share with owner (the owner then sees this invoice on their work order; the tenant never does)
        </label>
        <button type="submit" class="corex-btn-outline text-xs">File invoice</button>
    </form>

    @if($archivedInvoices->isNotEmpty())
        <div>
            <button type="button" onclick="document.getElementById('archived-invoices').classList.toggle('hidden')" class="corex-btn-outline text-xs">{{ $archivedInvoices->count() }} archived invoice(s)</button>
            <ul id="archived-invoices" class="hidden space-y-1 text-sm pt-1">
                @foreach($archivedInvoices as $archived)
                    <li class="flex flex-wrap items-center gap-2" data-archived-invoice="{{ $archived->id }}">
                        <span style="color: var(--text-muted);">{{ $archived->invoice_number }} — R{{ number_format((float) $archived->amount, 2) }} ({{ $archived->invoice_date?->format('Y-m-d') }})</span>
                        <a href="{{ route('corex.rental-work-orders.invoices.download', [$workOrder, $archived->id]) }}" target="_blank" rel="noopener" class="underline text-xs">View file</a>
                        <form method="POST" action="{{ route('corex.rental-work-orders.invoices.restore', [$workOrder, $archived->id]) }}">
                            @csrf
                            <button type="submit" class="corex-btn-outline text-xs">Restore</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
@endpermission
