@extends('layouts.corex')

@section('corex-content')
<div class="space-y-6" x-data="ccSettings()">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold" style="color:var(--text-primary);">Command Center Settings</h1>
        <a href="{{ route('corex.dashboard') }}" class="text-xs px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Back to Dashboard</a>
    </div>

    {{-- ═══════ AUTOMATION RULES ═══════ --}}
    <div class="corex-panel">
        <div class="corex-panel-header">
            <h3 class="corex-panel-title">Automation Rules</h3>
            <span class="text-xs" style="color:var(--text-muted);">{{ $automationRules->where('is_active', true)->count() }} active / {{ $automationRules->count() }} total</span>
        </div>
        <div class="corex-panel-body">
            @if($automationRules->isEmpty())
                <div class="py-6 text-center">
                    <p class="text-sm" style="color:var(--text-muted);">No automation rules configured. Run the seeder to load defaults.</p>
                    <p class="text-xs mt-1" style="color:var(--text-muted);">
                        <code style="background:var(--surface-2); padding:2px 6px; border-radius:4px;">php artisan db:seed --class=CommandCenterAutomationSeeder</code>
                    </p>
                </div>
            @else
                <div class="divide-y" style="border-color:var(--border-default);">
                    @foreach($automationRules as $rule)
                        <div class="flex items-center gap-4 py-3">
                            <form method="POST" action="{{ route('command-center.settings.toggle-rule', $rule) }}">
                                @csrf @method('PATCH')
                                <button type="submit"
                                        class="w-10 h-5 rounded-full relative transition-colors duration-200"
                                        style="background:{{ $rule->is_active ? 'var(--brand-button)' : 'var(--surface-2)' }};">
                                    <span class="absolute top-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform duration-200"
                                          style="{{ $rule->is_active ? 'transform:translateX(1.25rem);' : 'transform:translateX(0.125rem);' }}"></span>
                                </button>
                            </form>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium" style="color:var(--text-primary);">
                                    {{ $rule->name }}
                                    @if($rule->is_system)
                                        <span class="text-[10px] px-1.5 py-0.5 rounded ml-1" style="background:var(--surface-2); color:var(--text-muted);">System</span>
                                    @endif
                                </p>
                                @if($rule->description)
                                    <p class="text-xs mt-0.5 truncate" style="color:var(--text-muted);">{{ $rule->description }}</p>
                                @endif
                            </div>
                            <div class="flex items-center gap-2 text-xs" style="color:var(--text-muted);">
                                <span class="px-2 py-0.5 rounded" style="background:var(--surface-2);">{{ $rule->trigger_model }}</span>
                                <span class="px-2 py-0.5 rounded" style="background:var(--surface-2);">{{ $rule->trigger_event }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- ═══════ DOCUMENT EXPECTATIONS ═══════ --}}
    <div class="corex-panel">
        <div class="corex-panel-header">
            <h3 class="corex-panel-title">Document Expectations</h3>
            <button @click="showAddExpectation = true" class="text-xs font-medium px-2 py-1 rounded-md" style="background:var(--brand-button); color:#fff;">+ Add</button>
        </div>
        <div class="corex-panel-body">
            <p class="text-xs mb-3" style="color:var(--text-muted);">Define which documents are expected when a property of each type is listed. Tasks are auto-created for the listing agent.</p>

            @if($docExpectations->isEmpty())
                <div class="py-4 text-center text-sm" style="color:var(--text-muted);">
                    No document expectations configured. Default tasks will be created for new listings.
                </div>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b" style="border-color:var(--border-default);">
                            <th class="text-left py-2 px-2 text-xs font-medium" style="color:var(--text-muted);">Property Type</th>
                            <th class="text-left py-2 px-2 text-xs font-medium" style="color:var(--text-muted);">Document</th>
                            <th class="text-left py-2 px-2 text-xs font-medium" style="color:var(--text-muted);">Due</th>
                            <th class="text-left py-2 px-2 text-xs font-medium" style="color:var(--text-muted);">Required</th>
                            <th class="py-2 px-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($docExpectations as $exp)
                            <tr class="border-b" style="border-color:var(--border-default);">
                                <td class="py-2 px-2" style="color:var(--text-primary);">{{ ucfirst($exp->property_type) }}</td>
                                <td class="py-2 px-2" style="color:var(--text-primary);">{{ $exp->label }}</td>
                                <td class="py-2 px-2 text-xs" style="color:var(--text-muted);">{{ $exp->due_offset_hours }}h</td>
                                <td class="py-2 px-2">
                                    @if($exp->required)
                                        <span class="text-xs px-1.5 py-0.5 rounded" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson);">Required</span>
                                    @else
                                        <span class="text-xs" style="color:var(--text-muted);">Optional</span>
                                    @endif
                                </td>
                                <td class="py-2 px-2">
                                    <form method="POST" action="{{ route('command-center.settings.destroy-expectation', $exp) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-xs px-2 py-1 rounded hover:bg-red-500/10" style="color:var(--ds-crimson);" onclick="return confirm('Remove this expectation?')">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    {{-- ═══════ EVENT CLASSES ═══════ --}}
    <div class="corex-panel">
        <div class="corex-panel-header">
            <h3 class="corex-panel-title">Event Classes</h3>
        </div>
        <div class="corex-panel-body">
            <div class="flex items-center justify-between">
                <p class="text-sm" style="color:var(--text-muted);">
                    Configure thresholds, visibility, and notifications for the 38 calendar event classes.
                </p>
                <a href="{{ route('command-center.settings.event-classes') }}"
                   class="px-4 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--brand-button);">
                    Configure
                </a>
            </div>
        </div>
    </div>

    {{-- ═══════ ADD EXPECTATION MODAL ═══════ --}}
    <div x-show="showAddExpectation" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(0,0,0,0.5);" @keydown.escape.window="showAddExpectation = false">
        <div class="w-full max-w-md rounded-lg shadow-xl" style="background:var(--surface);" @click.outside="showAddExpectation = false">
            <form method="POST" action="{{ route('command-center.settings.store-expectation') }}">
                @csrf
                <div class="px-6 py-4 border-b" style="border-color:var(--border-default);">
                    <h3 class="text-lg font-semibold" style="color:var(--text-primary);">Add Document Expectation</h3>
                </div>
                <div class="px-6 py-4 space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Property Type</label>
                        <select name="property_type" required class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);">
                            <option value="sale">Sale</option>
                            <option value="rental">Rental</option>
                            <option value="commercial">Commercial</option>
                            <option value="vacant_land">Vacant Land</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Document Label</label>
                        <input type="text" name="label" required placeholder="e.g. Signed Mandate" class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);">
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary);">Due (hours)</label>
                            <input type="number" name="due_offset_hours" value="72" min="1" required class="w-full px-3 py-2 rounded-md text-sm border" style="background:var(--surface-2); border-color:var(--border-default); color:var(--text-primary);">
                        </div>
                        <div class="flex items-end pb-2">
                            <label class="flex items-center gap-2 text-sm" style="color:var(--text-secondary);">
                                <input type="checkbox" name="required" value="1" checked class="rounded">
                                Required
                            </label>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 border-t flex justify-end gap-2" style="border-color:var(--border-default);">
                    <button type="button" @click="showAddExpectation = false" class="px-4 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-secondary);">Cancel</button>
                    <button type="submit" class="px-4 py-2 rounded-md text-sm font-semibold text-white" style="background:var(--brand-button);">Add</button>
                </div>
            </form>
        </div>
    </div>


</div>

<script>
function ccSettings() {
    return {
        showAddExpectation: false,
    };
}
</script>
@endsection
