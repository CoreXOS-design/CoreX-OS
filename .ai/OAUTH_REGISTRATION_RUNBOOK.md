# Per-agency outgoing mail — provider registration runbook

**Status:** paperwork only. No CoreX code exists for this yet, and none is to be written from this document.
**Decision on record (2026-09-07, Johan):** free tiers only. Send-only permissions. No paid Google security assessment.
**Legal entity for both registrations:** RR Technologies (Pty) Ltd.

---

## What this decision means

We ask each provider only for permission to **send** mail as the agent. We do not ask to read
their mailbox. That keeps both registrations free and fast.

| | Send-only (what we are doing) | Full mailbox (what we are NOT doing) |
|---|---|---|
| Google review | days | 4 to 12 weeks |
| Outside auditor | none | required |
| Yearly cost | none | roughly R10k to R18k, every year |
| Yearly re-audit | none | required |

**The consequence, stated plainly:** CoreX will be able to send mail from the agent's own
address. CoreX will not be able to see replies, file inbound mail against a deal, or show a
conversation thread. If we ever want that, we come back and pay for the assessment. Nothing
done here is wasted if we change our minds later.

---

## Sequencing — read this before booking time

**Microsoft can be finished today.** Nothing needs to be built first.

**Google can be started today but not finished.** Google requires a video showing the
permission actually being used inside CoreX. That video cannot be filmed until Phase B is
built. So: create the project, verify the domain and fill in the consent screen now, then
submit for review once the sign-in flow works. Everything before the video is the slow,
boring part and it is the part we can clear now.

---

## 1. Microsoft Entra (Azure)

Sign in as RR Technologies at https://entra.microsoft.com

1. **App registrations → New registration**
   - Name: `CoreX OS`
   - Supported account types: **Accounts in any organisational directory (multitenant)**.
     This is what lets other agencies use it, not just us.
   - Register it with a work account. An app registered with a personal Microsoft account
     can never be publisher verified.

2. **Redirect URIs** — platform type **Web**, add all three:
   ```
   https://corexos.co.za/oauth/mail/microsoft/callback
   https://demo1.corexos.co.za/oauth/mail/microsoft/callback
   http://localhost:8000/oauth/mail/microsoft/callback
   ```

3. **API permissions** — Delegated only. Do NOT add application permissions.
   ```
   https://outlook.office.com/SMTP.Send
   offline_access
   openid
   email
   profile
   ```
   `IMAP.AccessAsUser.All` is deliberately NOT requested. It is mailbox reading.

4. **Certificates & secrets → New client secret.** 24 month expiry. Copy the value once.
   See "Where the secrets live" below. Diarise the expiry date; mail stops working the day
   it lapses.

5. **Publisher verification.**
   - Needs a Microsoft AI Cloud Partner Program account for RR Technologies, free to create
     at https://partner.microsoft.com
   - Use the **global** Partner ID. Region-specific IDs are rejected.
   - `corexos.co.za` must be DNS-verified on the tenant and must match the partner account's
     primary contact domain.
   - Without this, every agency signing in sees an "unverified" warning.

**Send back:** Application (client) ID, Directory (tenant) ID. Not the secret.

---

## 2. Google Cloud

Sign in as RR Technologies at https://console.cloud.google.com

1. **New project**, name `CoreX OS`.

2. **Enable the Gmail API** under APIs & Services.

3. **Verify domain ownership** of `corexos.co.za` in Google Search Console, using the same
   account that owns the project. Google will not accept the consent screen otherwise.

4. **OAuth consent screen**
   - User type: **External**
   - App name: `CoreX OS`, publisher RR Technologies (Pty) Ltd
   - Homepage: `https://corexos.co.za`
   - Privacy policy: `https://corexos.co.za/privacy`
   - Terms: `https://corexos.co.za/terms`
   - Authorised domain: `corexos.co.za`

5. **Scopes** — add only:
   ```
   https://www.googleapis.com/auth/gmail.send
   openid
   email
   profile
   ```
   `https://mail.google.com/` is deliberately NOT requested. That is the restricted one that
   costs money.

6. **Credentials → OAuth client ID → Web application.** Same three redirect URIs:
   ```
   https://corexos.co.za/oauth/mail/google/callback
   https://demo1.corexos.co.za/oauth/mail/google/callback
   http://localhost:8000/oauth/mail/google/callback
   ```

7. **Submit for verification** — only once Phase B is built and the video can be filmed.
   The video must show a real agent connecting their account and a mail going out.

**Send back:** the Client ID. Not the secret.

---

## 3. Blockers on our side

Our privacy policy and terms pages are live at `/privacy` and `/terms`, but neither mentions
mailbox access at all. Google checks that the policy wording matches the permission being
requested and rejects on this routinely. Both pages need a section covering what CoreX does
with a connected mail account before the Google submission goes in. This is a build task and
has not been done.

---

## 4. Where the secrets live

Neither client secret is to travel through WhatsApp, email, a document, or a chat message.

When each secret is created, it is pasted directly into the server environment file on the
live host and nowhere else. It is never read back out and never held by a person. If one is
ever pasted into a message, treat it as burnt and issue a new one from the console.

---

## 5. Not in scope of this document

Phase A is password-based SMTP for cPanel and Afrihost-type hosting and needs none of the
above. Worth noting for later: Microsoft begins removing password-based SMTP from Exchange
Online on 30 April 2026, and new tenants created after December 2026 will not have it at all.
Agencies on Microsoft 365 will therefore never be servable by Phase A. That is the whole
reason Phase B exists.

---

## Registered IDs (recorded as they come back)

**Google — OAuth client created 2026-09-07.** Client type Web application, name `CoreX OS Web`.
No JavaScript origins (server-side flow only).

```
Client ID: 413795824945-rkk1j8tcnsnnqq0lfpd7ruo3031mjjr2.apps.googleusercontent.com
```

Client secret: held in the Google console only. Never messaged, never committed. Goes straight
into the live server environment file when Phase B is built.

Domain verified 2026-09-07 by HTML file method. The file lives at
`public/google357b492880cbc369.html` and is served from the live site. It must never be
deleted — Google re-checks it periodically and drops the verification if it 404s. NOT yet
committed to git as at 2026-09-07; a clean deployment would lose it.

Still outstanding on Google: confirm Gmail API is enabled, branding fields on the consent
screen, and the verification submission itself (blocked on the demo video until Phase B is
built).

**Microsoft — not yet registered.**
