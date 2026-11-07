{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md - Sign up a single agent (owner-only). Spec: solo-agent-accounts.md section 4.1. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Sign up a single agent</h1>
                <p class="text-xs" style="color: var(--text-muted);">Their account is created now; their login opens when they sign the agreement ({{ \App\Support\Money\Zar::formatWhole($fee) }} a month).</p>
            </div>
            <a href="{{ route('admin.solo-agents.index') }}" class="corex-btn-outline text-xs">Back to Solo Agents</a>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.solo-agents.store') }}" class="rounded-md p-5 space-y-5" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        @include('admin.solo-agents._fields', ['signup' => null])
        <div class="flex items-center gap-2">
            <button class="corex-btn-primary text-sm">Sign up</button>
            <a href="{{ route('admin.solo-agents.index') }}" class="corex-btn-outline text-sm">Cancel</a>
        </div>
    </form>
</div>
@endsection
