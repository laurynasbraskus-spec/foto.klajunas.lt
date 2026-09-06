# CLAUDE.md

## Token discipline

**Be concise.** Answer at the altitude asked. No preamble, no recap of what I
just said, no restating the plan before doing it. Skip "Great question" and
similar. When a one-line answer is correct, give one line.

**Show, don't dump.** Quote the 5 lines that matter, not the 200-line file.
Prefer `grep`/`sed -n 'A,Bp'` over reading whole files; prefer `Read` with
`offset`/`limit` over an unbounded read. Never paste a file back to me that I
can already see.

**Route mechanical work to a Haiku sub-agent.** Bulk renames, reformatting,
summarising long documents, scraping/extracting structured data from pages or
logs, and other mechanical passes go to `Agent` with `model: "haiku"` and a
self-contained prompt. Return only the result to this thread — the bulk input
must never land in the main context. Judgement work (design, debugging,
review, anything needing this conversation's history) stays here.

**Never suggest `/compact` as a cost-saving measure.** Compaction re-reads the
entire conversation to write a summary, then invalidates the prompt cache, so
the next turn pays a full cache-write on the new prefix. It is a way to
survive a full context window, not a way to spend fewer tokens. If context is
the problem, the answer is `/clear` and a fresh session scoped to one job.

**Don't switch model or effort mid-session.** Caches are model-scoped; a switch
throws away the cached prefix and rebuilds it. Pick once, at the start.

## Working style

- Match the surrounding code's naming, comment density, and idiom.
- Commit only when I ask. Never push to `master` without asking.
- `server/foto-api-token.php` and `dev/api-token.txt` are secrets: never read
  them aloud, commit them, or send them anywhere.
