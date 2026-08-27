// E-sign regression harness — the 7 assertions.
//
// Each assertion function takes the shape's ordered stage snapshots and its
// `expected` data (from shapes.js — the literal names/values the shape
// itself set up, not a guess) and returns:
//   { pass: bool, firstDivergentStage: string|null, detail: string }
//
// `stages` is an ordered array of { name, domicilium, clause, signatureSummary }.
// The LAST stage, if reached, additionally carries `signPageBlocks` and
// `signingOrderCount` from the Signing Setup screen.

// 0. THE MASTER ASSERTION (Johan's architecture, 2026-08-27): "it's one
// document that flows like a printed page." Fill & Review is the agent's
// last chance to change text — everything already on the page there must
// be CHARACTER-FOR-CHARACTER IDENTICAL on every later stage. Only new
// material (signatures, dates, places, the agent's block) may be added.
// This one diff catches the off-by-one Domicilium swap AND the tripled
// Domicilium at once, and will catch the next shape of the same bug too —
// it does not need to know what kind of corruption to look for.
// The live signing screen paginates the SAME continuous document into
// physical A4 pages (client-side), which inserts real, legitimate furniture
// — "Page 1 of 5" markers and the small role-chip labels (Agent / Seller /
// Buyer, standalone, marking where a signature anchor sits) — INSIDE the
// flow of text at each page break. That is an addition Johan's rule
// explicitly allows; it is not the document body changing. Strip it before
// comparing, so the diff is measuring the same thing pagination or not.
function stripPaginationFurniture(text) {
    return (text || '')
        .split('\n')
        .filter(line => {
            const t = line.trim();
            if (/^Page\s+\d+\s+of\s+\d+$/i.test(t)) return false;
            if (/^(Agent|Seller|Buyer|Landlord|Tenant)$/.test(t)) return false; // standalone chip, not "Role - Name"
            return true;
        })
        .join('\n');
}

function normaliseForDiff(text) {
    return stripPaginationFurniture(text || '').replace(/\s+/g, ' ').trim();
}

function firstDivergenceIndex(a, b) {
    const len = Math.min(a.length, b.length);
    for (let i = 0; i < len; i++) {
        if (a[i] !== b[i]) return i;
    }
    return a.length === b.length ? -1 : len;
}

function bodySnapshot(stage) {
    const clause = stage.clause && stage.clause.found ? stage.clause.text : '';
    const dom = stage.domicilium && stage.domicilium.found ? stage.domicilium.raw : '';
    return normaliseForDiff(clause + '\n' + dom);
}

// Johan's full chain (2026-08-27, replaces the Fill&Review-only framing):
// document selected -> property -> recipients -> details -> fill & review
// (hard stop) -> next/sign & send -> preview -> agent signing -> rec 1 ->
// rec 2 -> ... Each link diffs against the ONE BEFORE IT, not a fixed
// baseline — every step legitimately adds its own material (property adds
// property fields, recipients add the parties, a signature adds itself),
// but everything the PREVIOUS step already had must survive untouched.
// Reports the first broken link as "stageA -> stageB", the exact shape of
// answer a lane needs.
// Links where the step's OWN job is to fill in content that genuinely was
// not there before (so a strict prefix/equality diff would misfire on the
// step doing exactly what it's supposed to). Every OTHER link — from
// Recipients onward — must be a byte-identical match: nothing in the
// clause-opening + Domicilium scope has any legitimate reason to change
// once recipients are confirmed, and both of today's real bugs (the
// Sign & Send off-by-one, the tripled Domicilium on Agent Signing) live in
// exactly that "should never change" range.
const NON_STRICT_LINKS = new Set([
    'Template -> Property',   // no recipients yet — nothing comparable
    'Property -> Recipients', // recipients/Domicilium go from absent to populated — this step's OWN job
]);

function assertChainHolds(stages) {
    const links = [];
    let prevSnapshot = null;
    let prevName = null;
    let firstBreak = null;

    for (const stage of stages) {
        const current = bodySnapshot(stage);
        const linkLabel = prevName ? `${prevName} -> ${stage.name}` : stage.name;

        if (!current) {
            links.push({ link: linkLabel, result: 'could_not_check', detail: `${stage.name} was never reached / captured no comparable text` });
            prevName = stage.name;
            continue;
        }
        if (prevSnapshot === null) {
            links.push({ link: stage.name, result: 'baseline', detail: `${stage.name} captured as the chain's first link` });
        } else if (NON_STRICT_LINKS.has(linkLabel)) {
            links.push({ link: linkLabel, result: 'ok (not strict — this step fills in new content by design)', detail: `${stage.name} adds its own material; not diffed byte-for-byte against ${prevName}` });
        } else if (current === prevSnapshot) {
            links.push({ link: linkLabel, result: 'ok', detail: 'byte-identical to the previous step' });
        } else {
            const idx = firstDivergenceIndex(prevSnapshot, current);
            const ctx = 70;
            const before = prevSnapshot.slice(Math.max(0, idx - ctx), idx + ctx);
            const after = current.slice(Math.max(0, idx - ctx), idx + ctx);
            links.push({ link: linkLabel, result: 'BROKEN', detail: `at ${prevName}: "...${before}..." — at ${stage.name}: "...${after}..."` });
            if (!firstBreak) firstBreak = linkLabel;
        }
        prevSnapshot = current;
        prevName = stage.name;
    }

    if (firstBreak) {
        const broken = links.find(l => l.link === firstBreak);
        return { pass: false, firstDivergentStage: firstBreak, detail: broken.detail, chain: links };
    }
    const uncheckable = links.filter(l => l.result === 'could_not_check');
    if (uncheckable.length) {
        return { pass: null, firstDivergentStage: uncheckable[0].link, detail: `chain holds everywhere it could be checked; COULD NOT CHECK: ${uncheckable.map(l => l.link).join(', ')}`, chain: links };
    }
    return { pass: true, firstDivergentStage: null, detail: `every link in the chain holds, ${links.length} stages`, chain: links };
}

function findEntry(domicilium, namePart) {
    if (!domicilium || !domicilium.entries) return null;
    return domicilium.entries.find(e => e.name.includes(namePart)) || null;
}

// 1. The same party's address/tel/email are IDENTICAL at every stage.
function assertConsistentAcrossStages(stages, expected) {
    const baselines = {};
    for (const stage of stages) {
        for (const namePart of expected.domiciliumNames) {
            const entry = findEntry(stage.domicilium, namePart);
            if (!entry) continue; // not every stage necessarily renders Domicilium (e.g. Recipients before confirm)
            const key = namePart;
            if (!baselines[key]) {
                baselines[key] = { stage: stage.name, address: entry.address, tel: entry.tel, email: entry.email };
                continue;
            }
            const b = baselines[key];
            if (entry.address !== b.address || entry.tel !== b.tel || entry.email !== b.email) {
                return {
                    pass: false,
                    firstDivergentStage: stage.name,
                    detail: `"${namePart}" was (address="${b.address}", tel="${b.tel}", email="${b.email}") at ${b.stage}, became (address="${entry.address}", tel="${entry.tel}", email="${entry.email}") at ${stage.name}`,
                };
            }
        }
    }
    return { pass: true, firstDivergentStage: null, detail: 'consistent at every stage that rendered Domicilium' };
}

// 2. Nobody's values ever appear under another person's name.
function assertNoCrossedIdentity(stages, expected, fixtureTruth) {
    for (const stage of stages) {
        if (!stage.domicilium || !stage.domicilium.entries) continue;
        for (const entry of stage.domicilium.entries) {
            const truth = fixtureTruth[entry.name] || Object.values(fixtureTruth).find(t => entry.name.includes(t.namePart));
            // Check the entry's values don't match a DIFFERENT fixture's known truth.
            for (const [otherNamePart, otherTruth] of Object.entries(fixtureTruth)) {
                if (entry.name.includes(otherNamePart)) continue; // this IS them
                if (otherTruth.tel && entry.tel && entry.tel === otherTruth.tel) {
                    return { pass: false, firstDivergentStage: stage.name, detail: `"${entry.name}"'s tel (${entry.tel}) is actually "${otherNamePart}"'s real number` };
                }
                if (otherTruth.email && entry.email && entry.email === otherTruth.email) {
                    return { pass: false, firstDivergentStage: stage.name, detail: `"${entry.name}"'s email (${entry.email}) is actually "${otherNamePart}"'s real email` };
                }
            }
        }
    }
    return { pass: true, firstDivergentStage: null, detail: 'no cross-identity values found' };
}

// 3. Each party appears exactly ONCE in Domicilium — never duplicated.
function assertNoDuplicateEntries(stages) {
    for (const stage of stages) {
        if (!stage.domicilium || !stage.domicilium.entries) continue;
        const seen = {};
        for (const entry of stage.domicilium.entries) {
            seen[entry.name] = (seen[entry.name] || 0) + 1;
        }
        for (const [name, count] of Object.entries(seen)) {
            if (count > 1) {
                return { pass: false, firstDivergentStage: stage.name, detail: `"${name}" appears ${count} times in Domicilium` };
            }
        }
    }
    return { pass: true, firstDivergentStage: null, detail: 'no duplicate entries at any stage' };
}

// 4. A deceased party appears in the seller clause and NEVER in Domicilium.
function assertDeceasedHandling(stages, expected) {
    if (!expected.deceasedNames || expected.deceasedNames.length === 0) {
        return { pass: true, firstDivergentStage: null, detail: 'no deceased party in this shape' };
    }
    for (const stage of stages) {
        for (const namePart of expected.deceasedNames) {
            const inDomicilium = findEntry(stage.domicilium, namePart);
            if (inDomicilium) {
                return { pass: false, firstDivergentStage: stage.name, detail: `deceased party "${namePart}" appears in Domicilium at ${stage.name}` };
            }
        }
    }
    // Clause must mention the deceased once recipients actually exist —
    // "Template" and "Property" legitimately render a blank "I / We  the
    // undersigned" clause before any recipient has been added at all, so
    // checking the FIRST stage with any clause text would false-positive
    // on the shape's own not-yet-populated starting point.
    const clauseStage = stages.find(s => s.clause && s.clause.found && expected.domiciliumNames.length && s.domicilium && s.domicilium.entries.length > 0);
    if (clauseStage) {
        for (const namePart of expected.deceasedNames) {
            if (!clauseStage.clause.text.includes(namePart)) {
                return { pass: false, firstDivergentStage: clauseStage.name, detail: `deceased party "${namePart}" missing from the seller clause` };
            }
        }
    }
    return { pass: true, firstDivergentStage: null, detail: 'deceased party correctly in clause only, never Domicilium' };
}

// 5. Under proxy: every representative still appears in Domicilium; only
// one appears in the signing order.
function assertProxyExpansion(stages, expected) {
    if (expected.signingOrderCount === undefined) {
        return { pass: true, firstDivergentStage: null, detail: 'not a proxy/entity shape' };
    }
    const domStage = [...stages].reverse().find(s => s.domicilium && s.domicilium.entries && s.domicilium.entries.length > 0);
    if (domStage) {
        for (const namePart of expected.domiciliumNames) {
            if (!findEntry(domStage.domicilium, namePart)) {
                return { pass: false, firstDivergentStage: domStage.name, detail: `expected representative "${namePart}" missing from Domicilium` };
            }
        }
    }
    const signSetupStage = stages.find(s => s.name === 'Sign & Send');
    if (signSetupStage && signSetupStage.signingOrderCount !== undefined) {
        if (signSetupStage.signingOrderCount !== expected.signingOrderCount) {
            return {
                pass: false, firstDivergentStage: 'Sign & Send',
                detail: `signing order has ${signSetupStage.signingOrderCount} entries, expected ${expected.signingOrderCount}`,
            };
        }
    }
    return { pass: true, firstDivergentStage: null, detail: 'all representatives in Domicilium, signing order count correct' };
}

// 6. An empty field prints blank — never another party's value, never
// values joined with "and".
function assertBlankFieldsStayBlank(stages) {
    for (const stage of stages) {
        if (!stage.domicilium || !stage.domicilium.entries) continue;
        for (const entry of stage.domicilium.entries) {
            for (const field of ['address', 'tel', 'email']) {
                const v = entry[field] || '';
                if (/\band\b/i.test(v) && v.length > 3) {
                    return { pass: false, firstDivergentStage: stage.name, detail: `"${entry.name}"'s ${field} looks joined with "and": "${v}"` };
                }
            }
        }
    }
    return { pass: true, firstDivergentStage: null, detail: 'no joined-with-"and" values found' };
}

// 7. Signature blocks: correct count, none blank, nobody twice.
// (Cross-signer place/time contamination needs multiple real signers to
// fully exercise — this harness signs only as the agent, so that specific
// sub-check is marked partial, honestly, rather than claimed as covered.)
function assertSignatureBlocks(stages, expected) {
    const signStage = stages.find(s => s.name === 'Agent Signing Screen');
    if (!signStage) {
        return { pass: null, firstDivergentStage: null, detail: 'COULD NOT COMPLETE — agent signing screen was never reached' };
    }
    if (!signStage.signPageBlocks || signStage.signPageBlocks.length === 0) {
        return { pass: false, firstDivergentStage: 'Agent Signing Screen', detail: 'no signature blocks found on the signing screen' };
    }
    const blocks = signStage.signPageBlocks;
    if (blocks.length !== expected.signingOrderCount) {
        return { pass: false, firstDivergentStage: 'Agent Signing Screen', detail: `${blocks.length} signature blocks, expected ${expected.signingOrderCount}` };
    }
    const seenNames = {};
    for (const b of blocks) {
        if (!b.role) {
            return { pass: false, firstDivergentStage: 'Agent Signing Screen', detail: 'a signature block has no role at all' };
        }
        const key = `${b.role}|${b.name || ''}`;
        seenNames[key] = (seenNames[key] || 0) + 1;
        if (seenNames[key] > 1) {
            return { pass: false, firstDivergentStage: 'Agent Signing Screen', detail: `"${key}" appears as a signature block more than once` };
        }
    }
    return { pass: true, firstDivergentStage: null, detail: `${blocks.length} signature blocks, correct count, none blank, nobody twice (cross-signer place/time contamination only partially checked — single-signer harness)` };
}

function runAssertions(stages, expected, fixtureTruth) {
    return {
        '0_MASTER_chain_holds_link_by_link': assertChainHolds(stages),
        '1_consistent_across_stages': assertConsistentAcrossStages(stages, expected),
        '2_no_crossed_identity': assertNoCrossedIdentity(stages, expected, fixtureTruth),
        '3_no_duplicate_entries': assertNoDuplicateEntries(stages),
        '4_deceased_handling': assertDeceasedHandling(stages, expected),
        '5_proxy_expansion': assertProxyExpansion(stages, expected),
        '6_blank_fields_stay_blank': assertBlankFieldsStayBlank(stages),
        '7_signature_blocks': assertSignatureBlocks(stages, expected),
    };
}

module.exports = { runAssertions };
