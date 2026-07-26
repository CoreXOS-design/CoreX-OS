{{-- Step 1: User (read-only — selected in create step) --}}
<div class="p-4 rounded-md" style="background:var(--surface); border:1px solid var(--border);">
    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-muted); letter-spacing:0.05em;">1. User Account</h4>
    <div class="flex items-center gap-3 mb-3">
        <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold text-white" style="background:var(--brand-default);">{{ strtoupper(substr($takeOn->user->name ?? '?', 0, 1)) }}</div>
        <div>
            <p class="text-sm font-semibold" style="color:var(--text-primary);">{{ $takeOn->user->name }}</p>
            <p class="text-xs" style="color:var(--text-muted);">{{ $takeOn->user->email }} | {{ $takeOn->user->designation ?? '-' }} | {{ $takeOn->user->branch->name ?? '-' }}</p>
        </div>
    </div>
    <p class="text-xs" style="color:var(--text-muted);">Take-on type: <strong>{{ ucfirst(str_replace('_', ' ', $takeOn->take_on_type)) }}</strong> | Date: {{ $takeOn->take_on_date->format('d M Y') }}</p>
    <div class="mt-4">
        <a href="{{ route('staff-take-on.wizard', [$takeOn, 'personal']) }}" class="corex-btn-primary">Continue to Personal Details</a>
    </div>
</div>
