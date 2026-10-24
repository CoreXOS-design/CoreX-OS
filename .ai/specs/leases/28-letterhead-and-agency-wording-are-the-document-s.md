## Letterhead and agency wording are the document's own agency, 14 Oct 2026 (cc2) - tests `SecondAgencyDocumentsTest`, `SigningView/LetterheadSecondAgencyTest`

- **One rule** (`App\Support\Docuperfect\DocumentAgencyContext`): the agency (and branch) of the document being rendered, else the signed-in user's branch / agency, else NOTHING. The old "else the agency with slug hfc-coastal" is gone: it printed HFC's letterhead on every other agency's public signing page, portal and PDF.
- The public signing page, the staff sign/review pages and the baked-letterhead refresher pin the document's agency; web templates say "us" via `$docAgency` (name, e-mail, phone, website, privacy link) from a View composer.
- The audit list of every remaining hardcode (fixed and not) is `.ai/audits/2026-10-14-hfc-hardcodes.md`.

- **14 Oct 2026 (cc5 re-walk): the lease list tile + filter read the page's status.** A signed renewal waiting for its start date is its own tile/filter ("Signed - starts later", `status=signed_awaiting_start`), no longer counted as Draft; exports/print use `displayStatus()`. See `rentals-faults-work-orders.md` 22.20.
