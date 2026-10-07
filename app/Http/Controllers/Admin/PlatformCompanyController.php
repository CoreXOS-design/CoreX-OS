<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformCompany;
use App\Models\Platform\PlatformCompanyAudit;
use App\Models\Platform\PlatformCompanyLogo;
use App\Services\Platform\PlatformCompanyService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Platform Company Profile (RR Technologies / CoreX OS). Owner-only screens (route middleware AND re-checked here);
 * the logo stream is the one public action. Spec: .ai/specs/platform-company-profile.md.
 */
class PlatformCompanyController extends Controller
{
    public function __construct(private PlatformCompanyService $svc)
    {
    }

    private function owner(Request $request)
    {
        $u = $request->user();
        abort_unless($u && $u->isOwnerRole(), 403);

        return $u;
    }

    /** Public asset stream — the current logo (or the built-in CoreX OS asset). Never anything but the logo. */
    public function logo(Request $request)
    {
        // `l` = the exact logo version a pinned document was sent with (0 = built-in); absent = the current logo.
        $versioned = $request->query->has('l');
        $id = (int) $request->query('l');
        // Only the current logo, the built-in one, or a version a sent document is pinned to is public (A-F3).
        abort_unless(! $versioned || PlatformCompany::logoIsPublic($id), 404);
        $company = $versioned ? PlatformCompany::fromSnapshot(['logo_id' => $id ?: null]) : PlatformCompany::current();
        $f = $company->logoFile();

        return response($f['bytes'], 200, [
            'Content-Type'            => $f['mime'],
            // A version's bytes never change, so a mail-image proxy or browser can keep it for good (fewer hits on the throttle).
            'Cache-Control'           => $versioned ? 'public, max-age=31536000, immutable' : 'public, max-age=3600',
            'X-Content-Type-Options'  => 'nosniff',
            // An SVG opened directly can never run script or load anything.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox",
            'Content-Disposition'     => 'inline; filename="logo"',
        ]);
    }

    /** Owner-only: one specific logo version (thumbnails in the version list). */
    public function logoVersion(Request $request, PlatformCompanyLogo $logo)
    {
        $this->owner($request);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($logo->path), 404);

        return response(\Illuminate\Support\Facades\Storage::disk('local')->get($logo->path), 200, [
            'Content-Type'            => $logo->mime,
            'Cache-Control'           => 'private, max-age=3600',
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox",
        ]);
    }

    public function index(Request $request)
    {
        $this->owner($request);

        $action = (string) $request->query('action', '');
        $history = PlatformCompanyAudit::query()->with('user')
            ->when(in_array($action, ['updated', 'logo_uploaded', 'logo_restored'], true), fn ($q) => $q->where('action', $action))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString();

        $company = PlatformCompany::current();

        return view('admin.platform-company.index', [
            'company'           => $company,
            'logos'             => PlatformCompanyLogo::query()->with('uploader')->orderByDesc('id')->get(),
            'history'           => $history,
            'action'            => $action,
            'labels'            => PlatformCompanyService::LABELS,
            'previewWeb'        => $company->letterheadHtml('web'),
            'previewPdf'        => $company->letterheadHtml('pdf', false),
            'senderDomainWarning' => $this->senderDomainWarning((string) $company->send_from_address),
            'previewFooter'     => $company->letterheadFooterHtml('web'),
            'previewSignature'  => $company->emailSignatureHtml(),
            'standardSignature' => $company->defaultEmailSignatureHtml(),
        ]);
    }

    /**
     * Sender-address hint (A-F4): a sending address on a domain this install does not itself mail from is the usual cause of
     * agreement emails landing in spam or being refused. Advisory only — never blocks a save, never does a DNS lookup.
     */
    private function senderDomainWarning(string $address): ?string
    {
        $domain = mb_strtolower((string) Str::after($address, '@'));
        if ($domain === '' || ! str_contains($address, '@')) {
            return null;
        }
        $known = array_values(array_filter([
            mb_strtolower((string) Str::after((string) config('mail.from.address'), '@')),
            mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
        ]));
        foreach ($known as $k) {
            $k = preg_replace('/^www\./', '', $k);
            if ($domain === $k || str_ends_with($domain, '.' . $k) || str_ends_with($k, '.' . $domain)) {
                return null;
            }
        }

        return 'The sending address is on ' . $domain . ', which is not the domain this system sends mail from'
            . ($known ? ' (' . implode(' / ', array_unique($known)) . ')' : '')
            . '. Agreement emails from another domain can be refused by the mail server or land in spam unless that domain is set up to send for CoreX.';
    }

    public function update(Request $request)
    {
        $user = $this->owner($request);

        $data = $this->svc->normalise($request->all());
        $request->merge($data);

        $request->validate([
            'version'              => ['required', 'integer'],
            'legal_name'           => ['required', 'string', 'max:255'],
            'trading_name'         => ['required', 'string', 'max:255'],
            'registration_number'  => ['nullable', 'string', 'max:100'],
            'vat_registered'       => ['nullable', 'boolean'],
            'vat_number'           => ['nullable', 'required_if:vat_registered,true', 'regex:/^\d{10}$/'],
            'directors'            => ['nullable', 'array', 'max:10'],
            'directors.*.name'     => ['required', 'string', 'max:150'],
            'directors.*.title'    => ['nullable', 'string', 'max:100'],
            'physical_address'     => ['nullable', 'string', 'max:500'],
            'postal_address'       => ['nullable', 'string', 'max:500'],
            'email_general'        => ['required', 'email', 'max:255'],
            'email_support'        => ['nullable', 'email', 'max:255'],
            'email_accounts'       => ['nullable', 'email', 'max:255'],
            // A bare mailbox only: no display name, brackets, quotes, commas or spaces smuggled into the address. Syntax only — no DNS lookup, so saving works offline.
            'send_from_address'    => ['required', 'email:rfc', 'max:255', 'regex:/^[^\s<>",;]+@[^\s<>",;]+$/'],
            'send_from_name'       => ['required', 'string', 'max:150', 'not_regex:/[\x00-\x1F<>"]/'],
            'phones'               => ['nullable', 'array', 'max:8'],
            'phones.*.label'       => ['nullable', 'string', 'max:40'],
            'phones.*.number'      => ['required', 'string', 'regex:/^[0-9+()\-\/. ]{5,40}$/'],
            'websites'             => ['nullable', 'array', 'max:5'],
            'websites.*'           => ['string', 'max:255', 'regex:/^(https?:\/\/)?[A-Za-z0-9][A-Za-z0-9.\-]*\.[A-Za-z]{2,}(\/\S*)?$/'],
            'strap_line'           => ['nullable', 'string', 'max:160'],
            'letterhead_footer'    => ['nullable', 'string', 'max:500'],
            'email_signature_html' => ['nullable', 'string', 'max:20000'],
            'bank_details'                => ['nullable', 'array'],
            'bank_details.bank_name'      => ['nullable', 'string', 'max:100'],
            'bank_details.account_holder' => ['nullable', 'string', 'max:150'],
            'bank_details.account_number' => ['nullable', 'regex:/^\d{4,20}$/'],
            'bank_details.branch_code'    => ['nullable', 'regex:/^\d{4,8}$/'],
            'bank_details.account_type'   => ['nullable', 'in:cheque,savings,transmission'],
            'bank_details.reference_note' => ['nullable', 'string', 'max:150'],
        ], [
            'version.required'                  => 'This page is out of date — please reload it and try again.',
            'legal_name.required'               => 'Enter the company\'s legal name.',
            'trading_name.required'             => 'Enter the trading name / brand shown on documents.',
            'email_general.required'            => 'Enter the general / admin email address.',
            'email_general.email'               => 'The general / admin email address is not a valid email address.',
            'email_support.email'               => 'The support email address is not a valid email address.',
            'email_accounts.email'              => 'The accounts email address is not a valid email address.',
            'send_from_address.required'        => 'Enter the sending address the agreement emails are sent from.',
            'send_from_address.email'           => 'The sending address is not a valid email address.',
            'send_from_address.regex'           => 'The sending address must be just an email address (like admin@example.co.za) — no name, brackets or spaces.',
            'send_from_name.not_regex'          => 'The sender name cannot contain line breaks, quotes or < > characters.',
            'send_from_name.required'           => 'Enter the sender name the agreement emails are sent as.',
            'vat_number.required_if'            => 'Enter the VAT number, or untick "VAT registered".',
            'vat_number.regex'                  => 'A South African VAT number is 10 digits (spaces are fine).',
            'directors.*.name.required'         => 'Each director needs a name.',
            'phones.*.number.required'          => 'Each telephone row needs a number (or remove the row).',
            'phones.*.number.regex'             => 'A telephone number may only contain digits, spaces and + ( ) - / .',
            'websites.*.regex'                  => 'A website must look like www.example.co.za.',
            'bank_details.account_number.regex' => 'The bank account number must be digits only (4–20).',
            'bank_details.branch_code.regex'    => 'The branch code must be digits only.',
            'bank_details.account_type.in'      => 'Choose cheque, savings or transmission for the account type.',
        ]);

        $changes = $this->svc->save($data, (int) $request->input('version'), $user);

        return redirect()->route('admin.platform-company.index')
            ->with($changes ? 'success' : 'warning', $changes ? 'Company profile saved.' : 'Nothing was changed.');
    }

    public function logoStore(Request $request)
    {
        $user = $this->owner($request);
        $request->validate(['logo' => ['required', 'file']], [
            'logo.required' => 'Choose a logo file to upload.',
            'logo.file'     => 'The logo upload did not complete. Please try again.',
        ]);

        $this->svc->uploadLogo($request->file('logo'), $user);

        return redirect()->route('admin.platform-company.index')->with('success', 'Logo uploaded. The previous logo is kept and can be restored below.');
    }

    /** {logo} is a logo version id, or the word "built-in" to go back to the built-in CoreX OS logo. */
    public function logoRestore(Request $request, string $logo)
    {
        $user = $this->owner($request);
        abort_unless($logo === 'built-in' || ctype_digit($logo), 404);

        $changed = $this->svc->restoreLogo($logo === 'built-in' ? null : (int) $logo, $user);

        return redirect()->route('admin.platform-company.index')
            ->with($changed ? 'success' : 'warning', $changed ? 'Logo restored.' : 'That logo is already the current one — nothing changed.');
    }

    /** Live preview of UNSAVED form values — never validated (absorb: show what we can), never stored. */
    public function preview(Request $request)
    {
        $this->owner($request);
        $c = PlatformCompany::preview($this->svc->normalise($request->except(['version', 'bank_details'])));

        return response()->json([
            'web'       => $c->letterheadHtml('web'),
            // The PDF layout, but the logo by URL — never the whole image as base64 on every keystroke refresh (A-F5).
            'pdf'       => $c->letterheadHtml('pdf', false),
            'footer'    => $c->letterheadFooterHtml('web'),
            'signature' => $c->emailSignatureHtml(),
            'standard'  => $c->defaultEmailSignatureHtml(),
        ]);
    }
}
