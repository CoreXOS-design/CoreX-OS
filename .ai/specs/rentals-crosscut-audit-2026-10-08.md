# Rentals cross-cutting audit — 2026-10-08 (lane qa1-cc2)

Scope: the parts of rentals no single walk covers — Command Centre, navigation, permissions, settings/wizard, mail safety.
Other lanes' screens (lease notice terms, application-to-lease, inspections, faults / work orders / maintenance) were audited
read-only and are listed under "Open" where they need a change. Command Centre details: `rental-command-centre.md` §17.

## Decisions taken while Johan was away

Each is the recommended default, taken because the alternative left a hole. All are reversible in the Role Manager / settings.

1. **A notice is as visible as its lease.** `RentalNoticeController` now applies `Lease::visibleTo` (own / branch / agency) on the list, and blocks open-by-id of a notice, its PDF, and the create / send form for a lease the user cannot see. Before, anyone holding `rental_notices.create` could open or send on any lease in the agency.
2. **Mobile job-card writes need `rental_job_cards.create`**, the same key the web screen needs. A view-only user could edit access notes, tick tasks and upload photos through the API.
3. **DocuPerfect lease renew / terminate need `leases.renew` / `leases.cancel`**, not only `access_docuperfect`.
4. **Rental document types** (`rental/settings/document-types`) need `access_settings`. Open to any logged-in user before. (The table has no `agency_id` — one agency's edit changes every agency's; open item 6.)
5. **The Rentals menu opens for any rentals permission**, not only the applications ones: a user with leases / work orders / inspections access but no applications access never saw the menu.
6. **Fresh agencies get `rental_work_orders.manage_completion` and `.record_emergency_approval`** for branch manager and agent — existing agencies already received them by migration `2026_10_11_100800`, so a new agency (Cape Town, October) would have behaved differently from HFC.
7. **The Leases list "Expiring soon" tile uses the agency's own expiry notice window** (Settings → Leases), the same number the Command Centre uses. It was a hardcoded 60.
8. **Role Manager headings** for the rentals / lease / PPRA modules are plain words ("PPRA Employment Letters", not "Ppra Employment Letters"). Settings search keywords for Leases, Inspections, Inventory, Work Orders and Portal now include the settings added since 1 Oct.
9. **Custom-field example text** no longer uses an HFC term ("Lets Assist"); it says "Pets allowed".

## Open — needs Johan

- **Who gets what by default (second agency).** Only admin holds `rental_command_centre.view`, `rental_reports.view`, `rental_job_cards.*`, `rental_catalogue.*`, `rental_notices.create`, `rentals_take_on_import.*` and `client_app.create_login`; office admin holds no `leases.*` or `rental_inspections.*`. Cape Town's branch managers and agents will not see Command Centre, Reports, Job Cards, Notices. Recommended default: branch manager = Command Centre (branch), Reports, Job Cards, Notices, Catalogue; agent = Command Centre (own), Job Cards, Notices (own); office admin = leases.view / rental_inspections.view (agency). Not done: `corex:sync-permissions --merge-defaults` would also add these to HFC's existing roles on the next deploy.
- **Setup Wizard (CLAUDE.md 10a — Johan's call, not the lane's).** In neither the wizard nor spec `agency-onboarding-setup.md` §5.1: `lease_settings.active_rental_statuses`; `auto_readvertise_on_notice`, `auto_restore_status_on_lease_ended`, `auto_restore_status_on_lease_cancelled`, `default_pre_let_status` (read by code, **no Settings control either**, and `rental-renewals.md:345` wrongly says they are surfaced); inspection `inspection_feature_labels`, `room_type_item_defaults`, `room_type_walking_order`; inventory `disposition_presets`, `baseline_disposition_key`; notice templates, rental VAT types, catalogue units, crews, fault types. `auto_pair_photos_enabled` is in the wizard but has no Settings control. Recommended: wizard step for active statuses + the four status switches; the rest recorded in §5.1 as expert knobs.
- **Staging sends real mail by policy** (`OutboundMailGuard::SENDING_ENVIRONMENTS` includes staging). Today it is held by DevSetting `mail_intercept_forced=1` and the box's Mailpit transport; a database restored from live that carries `mail_intercept_forced=0` would let rentals mail (tenant / landlord / applicant / contractor invites and notices, agents' own mailboxes) reach real addresses. Recommended default: intercept on every non-production environment unless explicitly opted in, and apply `MAIL_NON_PRODUCTION_REDIRECT` inside the guard.
- Listed in the report file, not fixed here (other lanes' screens): planned-date actions lack a lease-scope guard (inspections); FICA link expiry `addDays(14)` and validity `subMonths(11)` are literals (applications); the fault / work-order lists need an open-only filter for the Command Centre links; `lease_reminder_days_before` (user settings) is dead; `rental_document_types` has no `agency_id`.
