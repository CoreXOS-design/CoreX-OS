# Home Finders Coastal hardcodes - audit of 14 Oct 2026 (cc2)

Grep: `hfc-coastal | hfcoastal | home finders` over app, resources, config, routes, database/seeders, scripts, plus agency id 1 hardcodes. 136 hits outside the generated `web-templates/cds` and `web-templates/imported` folders; most are comments.

## Fixed (reached a client-facing document, mail, portal page or PDF)
| Where | What it did | Now |
|---|---|---|
| `resources/views/docuperfect/web-templates/components/company-header.blade.php:33` | letterhead fell back to the row with slug `hfc-coastal` whenever nobody was signed in (public signing page, portal, queue, PDF) | `App\Support\Docuperfect\DocumentAgencyContext`: document's agency, else signed-in user's branch/agency, else no letterhead |
| `resources/views/components/corex-document.blade.php:26` | same fallback | same resolver |
| `app/Services/Docuperfect/LetterheadRefresher.php:17` (callers `SigningController` show, `SignatureController` setup/sign/review, `CanonicalDocumentRenderer`) | re-rendered every stored document's letterhead as "whoever has no user" = HFC | takes the document's agency/branch; controllers pin it for the request |
| `rental-applications/pdf.blade.php` | a branch-less application fell to HFC's letterhead | passes the application's agency |
| `lease-agreement-popi-v8` (title, 2.3, 2.5, 21 and the sale-of-property clause), `commercial-lease-agreement-v5` (title, 2.3, 2.5 and the banking, cash-deposit and early-termination clauses), `letting-mandate-v5`, `letting-mandatory-disclosure-v7`, `letting-marketing-permission-v7` | "Home Finders Coastal" as printed text | `$docAgency['name']` (View composer on `docuperfect.web-templates.*`) |
| `marketing-permission-v6` | hand-built HFC letterhead (name, address, reg/VAT/FFC/FIC numbers, e-mail, two personal cell numbers), HFC privacy link | shared company-header; agency privacy URL |
| `rental-application-v8` | HFC name, `letting@hfcoastal.co.za` (x4), `039 315 0857`, `hfcoastal.co.za/for-rent` | agency name, e-mail, phone, website |
| `resources/views/tv/company.blade.php:50` | TV fallback message "HOME FINDERS COASTAL: Only N deals..." | agency name |

## Reported, not changed (not client-facing, or needs a business decision)
- `app/Services/SellerOutreach/SellerOutreachTemplateDefaultsService.php:37` - `SOURCE_AGENCY_ID = 1`: every new agency's first WhatsApp outreach template is a COPY of HFC's, wording included. Needs a neutral default text (Johan).
- `app/Models/Property.php:2790-2797` `isOnHfcWebsite()`: `agency_id === 1` placeholder for "published on hfcoastal.co.za".
- `config/integrations.php:37` `public_website_url` defaults to `https://www.hfcoastal.co.za`; `Property.php:2493` builds "View listing" links from it for every agency. Should read the agency's `website_url`.
- `app/Http/Controllers/BuyerPortalController.php:154` `?? 1` agency fallback when a buyer link has no agency (unreachable today: the link's agency is NOT NULL).
- Business terms hardcoded inside shipped templates: `marketing-permission-v6` fee "7.5%" and "7 days" notice; `rental-application-v8` "1 business day" processing. These should be agency settings (and then reach the setup wizard, rule 10a).
- `app/Support/Docuperfect/Cds/Reference/ReferencePackCds.php:27-30` - the reference pack's sample letterhead is HFC's (published by `esign:publish-reference-pack`).
- `resources/views/presentations/a07ec3fa04d8915aa39838aa429ec162.php` - hash-named presentation view with HFC title/logo (no caller found).
- `web-templates/cds/template-*.blade.php` (49 files incl. `imported/`) - generated copies of HFC's own imported documents; their wording is HFC's template content.
- Admin placeholders / demo: `admin/dev-settings/index.blade.php:390,434`, `admin/demo-connection/edit.blade.php:90`, seeders (`DemoDataSeeder`, `BranchSettingsSeeder`, `HfcRmcpMasterSeeder`, ...), `app/Console/Commands/P24ImportVettingAgents.php`, `scripts/p24-create-vetting-agents.php`.
- Everything else is a comment or docblock.

## Already correct (checked)
Portal header (`Agency::publicBrandingFor`), signing page chrome (`SigningController::getAgencyBranding`), `OtpMail`, the rental application mails, `BaseSignatureMail::companyDomainForAgent`.
