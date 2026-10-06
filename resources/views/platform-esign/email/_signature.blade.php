{{-- Platform company email signature + footer line (App\Models\Platform\PlatformCompany). Included by every Platform E-Sign
     email so no sender details are hard-coded here. Spec: .ai/specs/platform-company-profile.md §7. --}}
@php($company = \App\Models\Platform\PlatformCompany::current())
<tr><td style="padding:24px 4px 0;">{!! $company->emailSignatureHtml() !!}</td></tr>
<tr><td align="center" style="padding-top:24px; font-size:0.75rem; color:#9ca3af;">{{ $company->trading_name }}@if($company->websiteList()) &middot; {{ $company->websiteList()[0] }}@endif</td></tr>
