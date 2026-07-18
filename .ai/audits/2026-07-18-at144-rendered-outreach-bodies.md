# AT-144 — Rendered buyer-demand outreach bodies (for Johan's eyeball)

> Environment: **QA1** (`corex_qa1`, real data, rolled-back transaction). Branch `AT-144-buyer-demand-template`, tag `AT-144-READY-FOR-QA1`.
> Templates: **id17 (WhatsApp)** / **id18 (Email)** — "Active Buyer Match — Your Property". Enabled on the QA1 bench.
> The `{opt_out_link}` below is shown with an *example* one-tap link (`https://corex.hfcoastal.co.za/o/optout/Abc123XyZ`); at real send time the system substitutes the recipient's unique link.

Agent (sender): **Johan Reichel** · Seller (recipient): first name **"Aadil"** · Property: **#6096, Uvongo Beach** (10 matching buyers).

---

## 1. WhatsApp — seller WITH matching buyers  ✅ sends

```
Hi Aadil, I'm Johan Reichel from Home Finders Coastal — a registered estate agency on the KZN Coast.
We have 10 buyer(s) looking for properties like yours in Uvongo Beach right now — list it with us and we can put it straight in front of our buyers. I'd be glad to tell you what they're looking for and what your property could achieve in today's market.
May I share the details with you by WhatsApp, SMS or email?
- Reply OPT IN and I'll send what these buyers are looking for
- Reply OPT OUT and I won't contact you again
Manage your preferences or opt out anytime: https://corex.hfcoastal.co.za/o/optout/Abc123XyZ.
Home Finders Coastal · FFC 202615038880000 · 039 315 0857.
```

**Sendable:** YES

## 2. Email — seller WITH matching buyers  ✅ sends

**Subject:** Buyers looking for a home like yours in Uvongo Beach

```
Hi Aadil, I'm Johan Reichel from Home Finders Coastal — a registered estate agency on the KZN Coast.
We have 10 buyer(s) looking for properties like yours in Uvongo Beach right now — list it with us and we can put it straight in front of our buyers. I'd be glad to tell you what they're looking for and what your property could achieve in today's market.
May I share the details with you by WhatsApp, SMS or email?
- Reply OPT IN and I'll send what these buyers are looking for
- Reply OPT OUT and I won't contact you again
Manage your preferences or opt out anytime: https://corex.hfcoastal.co.za/o/optout/Abc123XyZ.
Home Finders Coastal · FFC 202615038880000 · 039 315 0857.
```

**Sendable:** YES

## 3. Zero matching buyers — the buyer claim COLLAPSES and the send is BLOCKED  🚫

Property #6098, Durban North (0 matching buyers). The `{?matching_buyer_count}` sentence is removed entirely — no "0 buyer(s)" ever shows — and the `no_buyers` gate blocks the send.

```
Hi Aadil, I'm Johan Reichel from Home Finders Coastal — a registered estate agency on the KZN Coast.
I'd be glad to tell you what they're looking for and what your property could achieve in today's market.
May I share the details with you by WhatsApp, SMS or email?
- Reply OPT IN and I'll send what these buyers are looking for
- Reply OPT OUT and I won't contact you again
Manage your preferences or opt out anytime: https://corex.hfcoastal.co.za/o/optout/Abc123XyZ.
Home Finders Coastal · FFC 202615038880000 · 039 315 0857.
```

**Sendable:** NO · **Gate:** ["no_buyers"]

## 4. Seller has opted out — send BLOCKED  🚫

Even with matching buyers, an opted-out recipient is never messaged.

**Sendable:** NO · **Opt-out blocks:** YES

---

### Footer binding (the correction)

The tail line renders **{agency_name} · FFC {agency_ffc} · {agency_contact}**:

- **FFC** = the **agency** Fidelity Fund Certificate (`agencies.ffc_no`) = **202615038880000**
- **Public contact** = the Company Settings **"Public Contact (seller outreach)"** field (`agencies.public_contact`) = **039 315 0857**
- It does **NOT** use the agency landline (071 351 0291). If the public-contact field is ever left blank, the tail shows nothing rather than any other number.
