import re, sys, os, shutil
W = "/mnt/HC_Volume_103099143/corex-worktrees/lean-standing-files-2026-10-24/.ai/specs/"
name = sys.argv[1]
src = W + name + ".md"
orig = open(src, encoding="utf-8").read()
lines = orig.split("\n")
idx = [i for i, l in enumerate(lines) if l.startswith("## ")]
pre = lines[: idx[0]]
d = W + name + "/"
os.makedirs(d, exist_ok=True)
rows, parts = [], []
for n, s in enumerate(idx):
    e = idx[n + 1] if n + 1 < len(idx) else len(lines)
    body = lines[s:e]
    h = body[0][3:].strip()
    m = re.match(r"^(\d+[a-z]?)\.\s", h)
    sec = m.group(1) if m else "-"
    slug = re.sub(r"[^a-z0-9]+", "-", re.sub(r"^\d+[a-z]?\.\s*", "", h).lower()).strip("-")[:48] or "section"
    fn = f"{n+1:02d}-{('s'+sec+'-') if sec!='-' else ''}{slug}.md"
    text = "\n".join(body)
    open(d + fn, "w", encoding="utf-8").write(text)
    parts.append(text)
    rows.append((n + 1, sec, h, fn, len(text.encode())))
# verbatim check: preface + sections rejoin to the original
assert "\n".join(pre + [""] * 0) + ("\n" if pre else "") + "\n".join(parts) == orig, "NOT VERBATIM"
idxlines = []
idxlines.append(f"# {name} — INDEX (split from a {len(orig.encode())//1024} KB single file, 2026-10-24)")
idxlines.append("")
idxlines.append(f"> **Read this index, then open ONLY the section file(s) the task names.** Do not read the whole folder. Every section below is the original text, verbatim, one file per `##` heading, in original order (order is NOT numeric: later amendments sit after earlier ones). Cross-references elsewhere that say `{name}.md §N` map to the **§** column. New amendments: add a new `NN-…md` file and one row here; update the section it amends in place.")
idxlines.append("")
idxlines.append("## Original preface (verbatim)")
idxlines.extend(pre)
idxlines.append("")
idxlines.append("## Sections")
idxlines.append("| # | § | Heading | File | KB |")
idxlines.append("|---|---|---|---|---|")
for n, sec, h, fn, sz in rows:
    idxlines.append(f"| {n} | {sec} | {h[:150].replace('|','/')} | `{name}/{fn}` | {max(1, round(sz/1024))} |")
shutil.copy(src, "/mnt/HC_Volume_103099143/corex-worktrees/lean-standing-files-2026-10-24/.ai/archive/pre-split-" + name + ".md")
open(src, "w", encoding="utf-8").write("\n".join(idxlines) + "\n")
print(len(rows), "sections; index", len("\n".join(idxlines).encode()), "bytes; largest", max(r[4] for r in rows)//1024, "KB")
