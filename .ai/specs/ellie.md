# Spec: Ellie

**Status:** Live (KB + web search) — pillar awareness and document review are Phase 2

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

### Ellie: Pillar Awareness
Ellie can query live data from the four pillars when answering questions:
- "What's the current rental for Unit 7 Margate Gardens?" → queries Property + Deal
- "Has John Smith completed his FICA?" → queries Contact + Compliance
- "Which listings are overdue for a price review?" → queries Listings

- Read-only — Ellie queries, never writes
- Requires full spec before build
