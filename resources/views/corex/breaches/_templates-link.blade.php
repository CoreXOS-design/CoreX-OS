{{-- rental-notices.md §7b - one way from the breach screens to the agency's own notice templates (Settings -> Rental Notice Templates), for whoever may manage them. --}}
@permission('rental_notice_templates.manage_settings')
    <a href="{{ route('corex.rental-notice-templates.index') }}" class="corex-btn-outline text-xs" data-qa="breach-templates-link" title="Your agency's breach notice and notice to vacate templates">Templates</a>
@endpermission
