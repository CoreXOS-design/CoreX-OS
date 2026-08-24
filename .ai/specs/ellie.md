# Spec: Ellie

**Status:** Live — **rebuilt as a tool-using assistant 2026-07-26. See `.ai/specs/ellie-v2.md`.**

> **Ellie v2 (live).** Ellie's reasoning loop moved out of the Python service and
> into Laravel, and she now calls tools on demand instead of having retrieval
> guessed for her up front. **Pillar Awareness below is BUILT** — `my_listings`,
> `my_deals`, `my_performance`, `find_contact`, `find_property`, all read-only and
> permission-scoped. See `.ai/specs/ellie-v2.md` for the toolkit, the agent loop
> and the retrieval repairs.
>
> **Correction:** the "Web Search" section below was never true. The Python
> service has no web-search path and never did. Ellie has no internet access.

> **Navigation Atlas (live).** Ellie answers "where do I go to…" questions with the
> real, permission-filtered page link. See `.ai/specs/ellie-navigation-atlas.md`.
>
> **Tour Knowledge (live).** Ellie answers "how do I do X" by reading the 88 guided
> tours (step-by-step, agent-facing, permission-gated). See `.ai/specs/ellie-tour-knowledge.md`.

---

## What Ellie Is

Ellie is CoreX's embedded domain AI assistant. She is not a general-purpose chatbot — she is a real estate operations specialist with deep knowledge of South African property law, agency processes, and the CoreX platform itself.

**Ellie is distinguished from general AI by:**
- Domain specificity — she knows SA property law, not generic legal advice
- Embedded business logic — she understands listings, agents, deals, compliance
- Vector-embedded knowledge base — trained on CoreX documentation and SA legislation
- Context awareness — she knows what module the user is in when they ask a question

---

## Core Principle: Ellie Advises, Humans Decide

Ellie never makes automated changes to documents, records, or data. She surfaces information, flags issues, and references legislation. The agent or principal acts on that information.

This is non-negotiable.

---

## What's Live

### Knowledge Base
- Vector embeddings via OpenAI
- Hybrid cosine + structural scoring (`KnowledgeSearchService.php`)
- 29+ documents embedded covering all CoreX modules
- SA legislation gathered: PPA, FICA, POPIA, CPA

### Web Search
- Routing fixed: KB questions no longer mis-routed to web search
- `needs_web()` logic corrected

### Knowledge Base Training Documents
10 KB training documents covering all CoreX modules created and embedded.

---

## Consolidation Notes

- `OPENAI_API_KEY` must be present in `/corex/.env` — missing key = zero embeddings
- Python AI service at `/opt/hf-ai/app.py` on port 3100 — not in git, restart manually

---

## Phase 2 Spec Items

### Ellie: Document Legal Review
User highlights a clause in a DocuPerfect document → asks Ellie → Ellie references the relevant SA legislation (PPA, FICA, POPIA, CPA) and advises on what the clause means and whether it complies.

- Never automated — user triggers, Ellie responds
- Feeds back into knowledge base (reviewed clauses become training data)
- Requires full spec before build

### ~~Ellie: Pillar Awareness~~ — BUILT 2026-07-26 (`.ai/specs/ellie-v2.md` §3.2)
Ellie can query live data from the four pillars when answering questions:
- "What's the current rental for Unit 7 Margate Gardens?" → queries Property + Deal
- "Has John Smith completed his FICA?" → queries Contact + Compliance
- "Which listings are overdue for a price review?" → queries Listings

- Read-only — Ellie queries, never writes
- Requires full spec before build
