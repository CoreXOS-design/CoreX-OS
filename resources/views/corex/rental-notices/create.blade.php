@extends('layouts.corex')

@section('content')
<div class="p-6 max-w-2xl space-y-4">
    <h1 class="text-lg font-semibold">Send Notice — {{ $lease->property?->buildDisplayAddress() }}</h1>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if($templates->isEmpty())
        <p class="text-sm" style="color: var(--text-muted);">No active notice templates yet. Add one under Rentals → Settings → Notice Templates.</p>
    @else
        <form method="POST" action="{{ route('corex.leases.notices.store', $lease) }}" class="space-y-4" x-data="{ templateId: '{{ old('rental_notice_template_id') }}' }">
            @csrf
            <div>
                <label class="prop-label">Template</label>
                <select name="rental_notice_template_id" x-model="templateId" required class="prop-input">
                    <option value="">Choose…</option>
                    @foreach($templates as $t)
                        <option value="{{ $t->id }}">{{ $t->name }} ({{ ucfirst(str_replace('_', ' ', $t->notice_type)) }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="prop-label">Breach description / arrears amount / vacate-by date (as applicable)</label>
                <p class="text-xs mb-1" style="color: var(--text-muted);">Fill in whichever figures the chosen template uses — enter the token name exactly as it appears in the template, one per line, as <code>token=value</code>.</p>
                <textarea name="figures_raw" rows="4" class="prop-input" style="font-family: monospace;" placeholder="arrears_amount=R 4,500&#10;vacate_by_date=2026-11-30&#10;breach_description=Rent unpaid for October and November"></textarea>
            </div>

            <div class="space-x-4">
                <label class="text-sm"><input type="checkbox" name="send_to_tenant" value="1" checked> Send to tenant</label>
                <label class="text-sm"><input type="checkbox" name="send_to_landlord" value="1"> Send to landlord</label>
            </div>

            <button type="submit" class="corex-btn-primary text-sm">Send notice</button>
        </form>
    @endif

    @feature('rental-leases')
    <a href="{{ route('corex.leases.show', $lease) }}" class="text-xs" style="color: var(--brand-icon, #0ea5e9);">&larr; Back to Lease Hub</a>
    @endfeature
</div>
@endsection
