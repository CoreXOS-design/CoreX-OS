# AT-508 addendum: phone notifications (hand-off for the mobile Claude)

You are changing the CoreX mobile app (Flutter). The server side is finished and on the Staging branch; do not guess fields, everything below is real server behaviour. Ask if you need something that is not here. Do not invent an endpoint.

The owner can now send a test push to any phone from the web app (System Developer, Push Test Lab). Every push the server sends is documented here so each one can be tested against the app.

## 1. Make sure the phone is registered (without this nothing arrives)

- Agents and staff: `POST /api/v1/device-tokens` `{platform: "android"|"ios", token, app_version}` right after sign-in, on every app start, and whenever FCM rotates the token. `DELETE /api/v1/device-tokens/{token}` on sign-out.
- Tenants and owners (client token): `POST /api/v1/client/device-tokens` and `DELETE /api/v1/client/device-tokens/{token}` — same body.
- Re-register after every agency switch / re-login. Ask for the notification permission at a sensible moment (after sign-in) and show a plain explanation if the person declined.
- Android: create a real HIGH-importance notification channel with the id `corex_alerts` at app start (the server posts every push to it; without it Android files the push silently).

## 2. What every push looks like

`notification.title` and `notification.body` (show as-is) and a `data` map of strings:

**Agent / staff pushes**
| key | meaning |
|---|---|
| `event_key` | the alert type, e.g. `rental_fault_report.created` |
| `target` | which screen to open: `fault`, `work_order`, `job_card`, `lease_notice`, `lease`, `inspection`, `application`. **Absent = no screen: open the notifications list.** |
| `target_id` | the record's number (string) |
| `severity` | `info`, `warning` (an emergency or something needing attention: show it more prominently), `overdue` |
| `notification_id`, `pillar`, `subject_type`, `subject_id`, `action_url` | as before; use `target` / `target_id` for opening, not `subject_type` |
| `test` | `"1"` when it is a test from the Push Test Lab: handle exactly like a real one (open the screen), but never count it as a real alert in any badge |

**Tenant / owner pushes:** `type: "rental"`, `category` (below), `mandatory` (`"1"`/`"0"`), `audience` (`tenant`|`owner`), `subject_type` (`fault`, `work_order`, `lease_notice`, **`inspection`**), `subject_id`, and `test` as above.

Tap behaviour: open the matching screen for `target`/`target_id` (agent) or `subject_type`/`subject_id` (tenant/owner), when the app is in the background, closed, or in the foreground (foreground: show an in-app banner, tap opens). If the person is not signed in, sign in first then open it. If the person's Rentals flag is off (`/client/me` `rentals`, `/mobile/visibility` `rentals_enabled`) or the record answers 404/403, go to the home screen with no error toast.

## 3. Agent alerts that now reach the phone

Rentals alerts (all agents the lease/property would alert, each agent can switch any one off in their own notification settings): fault reported (incl. emergency, severity `warning`), tenant added to / withdrew a fault, owner decided / stopped / resumed / queried a repair, fault resolved, owner asked to change who looks at faults first, quotes received, owner decided on a quote, owner set an appointment or reported progress / started / finished, tenant started / says not fixed / confirmed / disputes the work, extra work raised, work order overdue / completed, contractor accepted / declined / asked for extra money / sent an invoice / finished / job could not be sent, crew completed / sent parts and prices, notice received (needs review), rental application returned / handed over, lease agreement signed / declined / expired, lease expiry notice due, breach remedy date reached, inspection coming due / waiting for signatures / report copies not delivered. Each opens `target` as above (faults → `fault`, work orders → `work_order`, notices → `lease_notice`, inspections → `inspection`, leases → `lease`, applications → `application`).

Use the existing `GET/PUT /api/v1/notification-preferences` for the per-alert phone switch: each item carries `channel_push` (on/off for this person's phone) and you save it with `preferences:[{key, channel_push}]`; the person's master phone switch is `master.push`. These rentals alerts now actually reach the phone when `channel_push` is true (before, the switch existed but the server never pushed them).

## 4. Tenant / owner notifications

Category: mandatory (cannot be switched off, never listed in preferences) — `decision_needed` (owner), `work_check_requested` (tenant), `work_finished` (owner), `appointment` (both), `notice_outcome` (both). Switchable (listed in `GET /client/rentals/push-preferences` for tenants and `/client/rentals/landlord/push-preferences` for owners): `fault_status` (tenant), `work_order_update` (owner), and **new `inspection_ready` (both): "An inspection report is ready"** — sent when a move-in / move-out / other inspection report becomes readable; tap opens the read-only inspection (tenant `GET /client/rentals/inspections/{id}`, owner `GET /client/rentals/landlord/inspections/{id}`) from `subject_id`. Render the new switch from the list the server returns, do not hard-code it.

## 5. How to check it works (with the owner's Push Test Lab)

1. Sign in on a phone as an agent; confirm a row appears for the phone in the lab (platform, app version, last seen).
2. Lab → Send a plain test message → arrives with the title starting "[TEST]", with sound / heads-up banner, while the app is open, in the background and closed.
3. Lab → pick an agent alert, put a real fault's number in "Open this record when tapped" → tap the notification → the app opens that fault.
4. Repeat for a work order, a notice, an inspection, a lease, an application.
5. Sign in as a tenant and as an owner, repeat with their categories; `inspection_ready` opens the inspection view.
6. Switch an agent alert's Phone option off in the app; the lab shows it "off for real" for that person and a real alert no longer buzzes the phone.

## 6. New leads and appointment reminders

**New lead:** push `title` "New <portal> lead", `data.type = "portal_lead"`, `portal_lead_id`, `deep_link = /portal-leads/{id}`, and the shared `target: "portal_lead"`, `target_id`. Open the lead. It is alert type `lead.portal_received` (switchable per person in notification preferences like any other).

**Appointment reminder ("Upcoming: <title>"):** `data.type = "event_due_reminder"`, `event_id`, `event_date`, `deep_link = /calendar/events/{id}`, and `target: "calendar_event"`, `target_id`. Open the event. Sent one hour before by default, to everyone on the event, once per occurrence.

**Let the agent choose how long before (new):**
- **Their own default:** the existing notification-preferences item `agent.event_due` ("Calendar event reminder", minutes, 5 to 10080, default 60). Until now the server ignored it; it now decides how long before every appointment that person is warned (unless the appointment has its own time). Make sure this item is reachable in the app's notification settings.
- **Per appointment:** `GET /api/v1/command-center/calendar/options` returns `reminder: {lead_options:[{minutes,label}], my_default_minutes, channels:[{key,label}], default_channels}`. On the create and edit event screens add a "Remind me" picker built from `lead_options` (plus a "My default (<my_default_minutes> min)" choice) and a "Notification / Email" choice from `channels`, and an on/off switch. Send `reminder_minutes_before` (an offered number, or `null` for "my default"), `reminder_channels` (`["popup"]`, `["popup","email"]`, `["email"]`), and `send_reminder` (edit only). A number the agency does not offer answers 422. Read the current choice from the event: `reminder_minutes_before` / `reminder_channels` (null = none set, follows my default). Do not hard-code the options.
- An edit that does not mention reminders leaves them unchanged, so send the reminder fields only when the agent touched them.
