---
name: rechain
description: >
  Rewrite the joints of one published article so every paragraph follows
  from or reverses the one before it (the causal-chain rule in
  docs/causal-chain.md): same facts, same links, same images, new order and
  connective tissue. Diagnoses the "and then" joints with the swap,
  first-words and label tests, keeps the edits as a replayable PHP script,
  and checks the draft mechanically before anyone reads it. A project
  adapter (adapters/laravel/ ships one) supplies the three steps that touch
  a real site: fetch the live copy, load the draft for preview, ship. Use
  when asked to update, rechain or restructure a post "based on the writing
  rule", "the therefore rule", "the so/but rule", "the causal chain", or to
  fix a post that reads like a list. Not for posts that need new facts or a
  fact-check.
keywords:
  - rechain
  - causal chain
  - therefore rule
  - so/but rule
  - update post based on the writing rule
  - reads like a list
  - and then writing
---

# /rechain

A rechain changes where the paragraphs sit and how they join, not what they say. The reader should be able to put "so" or "but" between any two consecutive blocks without help. The facts, links, images and any project-specific anchors all survive; the change summary says which sections were reordered and why.

**Target:** one article, as HTML. One article per run. The owner reads the draft on their own site and says when it ships.

**Rule:** `~/.claude/skills/rechain/docs/causal-chain.md`. Read it first, every run.

## What is in this skill

| Path                             | What it does                                                                                                                                              |
| -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `docs/causal-chain.md`           | The rule: every block is a "so" or a "but" on the one before it; where the chain starts and ends; how it applies by block; the three tests                |
| `scripts/rechain-check.php`      | The mechanical half: invariant counts, label test, paragraph-opener tally, a hard-banned quick scan, British spellings; `first-words` and `british` modes |
| `scripts/rechain-edits.stub.php` | The edit list as a script: one `[$old, $new]` pair per joint, replayed against the live copy, stops unless every anchor matches exactly once              |
| `adapters/laravel/`              | A working adapter for a Laravel site with a posts table: triage over ssh, draft load, a hash-guarded migration generator, the ship steps                  |
| `tests/`                         | Pest tests for the checker; `composer install && vendor/bin/pest`                                                                                         |

## The adapter contract

Three steps touch a real site, and this skill does not know how your site stores articles. A project adapter supplies them:

1. **Fetch** the live copy of the article into `{dir}/before.html`, and record its sha256. `before.html` never changes during a run.
2. **Load** `{dir}/draft.html` into the local site so the owner can read it at a preview URL.
3. **Ship** the change in whatever reversible form the project uses, guarded on the sha256 from step 1, and purge only the article's URL.

`adapters/laravel/README.md` is one implementation. Without an adapter, the skill still works on two files: hand it `before.html`, it hands back `draft.html` and the check output.

## Workflow

### 1. Get the live copy

Run the adapter's fetch (Laravel: `scripts/refresh-post-triage.sh {slug} --article`, which also prints the article as text and a verdict). Then:

```bash
DIR={the adapter's working dir for this article}
cp before.html draft.html                                   # a first draft equal to the live copy, for the first-words test
cp ~/.claude/skills/rechain/scripts/rechain-edits.stub.php $DIR/chain-edits.php
```

### 2. Diagnose the joints

Read the article as a chain, one H2 at a time, plus the TL;DR and the FAQ. For each section run the three tests from the rule:

- **Swap test:** would two adjacent paragraphs survive in the other order? If yes, there is no link.
- **First-words test:** `php ~/.claude/skills/rechain/scripts/rechain-check.php before.html draft.html first-words`. Each section's first clauses should read as a short argument; a table of contents is a list.
- **Label test:** literal "Therefore", "This happened", "So:", "But:", "And then".

Write down each failing joint and the fix it needs. The fixes seen so far, in order of how often they were needed:

1. **Go one level down.** Two facts side by side become cause and effect when the layer underneath is named: the billing unit, the log row, the command, the column of the table. A forced "so" means a missing mechanism, not a missing connective.
2. **Move the block to its cause.** A quote, figure or paragraph that introduces a later section belongs just before that section. Moving an Andrew Ng "halfway there" quote to the end of a build-line section let the next H2 open with "Halfway, because...".
3. **Table lead-in and follow-up.** The paragraph before a table says why it is here; the paragraph after it draws the consequence. A table with neither is "and then" in a grid.
4. **The reversal opens a block.** "The other four never join the line, because none of them hands over a build" is a "but" that starts a paragraph. A short closing "But not for long." is a drum hit, and a drum hit at the end of every block is a tic.
5. **Owner line last.** The plain consequence for the business owner closes its section; it is the chain's last "so", not a mid-section aside.
6. **FAQ answers lead with the answer**, then the reason. Never a second unrelated fact.
7. **Accordion lead-ins.** A `<details>` block gets one sentence before it saying what the reader will find and why it is here.

**Where the chain starts.** The first paragraph names the event in literal terms: what happened, when, to whom. Not a question, not a thesis, not a summary of the conclusions. When the owner proposes an opener that is a question or a thesis ("Is each company training people, training AI, or training people to use AI?"), say that the rule puts the event first, and fold the idea into the paragraph where its cause sits. When the owner hands over a replacement sentence, keep whatever mechanism the next "so" depends on; "the ads sit among those links" had to survive the rewrite of the sentence it lived in, because the next paragraph's "so the fewer search ads" needs it.

Say in the report which section needed the most reordering.

### 3. Write the edits as a script

Every joint is one `[$old, $new]` pair in `chain-edits.php`, with a comment above it naming the link the new copy gives the reader (`// E7: the reversal, and why the figure is a ring`). Anchors are copied from `before.html` byte for byte, whitespace included; a retyped anchor is the usual cause of "expected 1 match, got 0". Multi-line anchors go in nowdoc blocks (`<<<'OLD'`); a body line that begins with the marker word closes the heredoc early, so never start a line inside one with `OLD` or `NEW`. Pairs apply in order, so a later anchor must match the text as earlier pairs left it.

```bash
php $DIR/chain-edits.php $DIR/before.html $DIR/draft.html
```

The script writes `draft.html` only when every pair matched exactly once. Because the draft is rebuilt from the live copy each time, an owner round is an edit to `chain-edits.php` and a rerun, never a patch on a patched file, and the before hash stays the live hash through every round.

A rechain may: reorder blocks within a section, move a block across sections when its cause is there, merge two paragraphs, split one, rewrite connective sentences, rewrite the paragraph before and after a table, change a meta description. It may not: add or drop a fact, a link, an image or a glossary span, or change text a project widget anchors on. Counts are the check for the first four; the adapter checks the anchors. Captions are exempt from the chain and stay as they are. A split-image block (a figure beside text in one grid) keeps exactly one text child beside its figure; a lead-in added inside one goes into the existing paragraph or a wrapping `<div>`, never a sibling `<p>`, because a second child drops into a new grid row and leaves the photo column empty.

### 4. Check before anyone reads it

```bash
php ~/.claude/skills/rechain/scripts/rechain-check.php $DIR/before.html $DIR/draft.html
```

- **Invariants:** spans, links and images must read `same` (stop). Headings, tables, details and blockquotes may change when a block moved; the change summary says why.
- **Label test:** any label stops; a single literal "Therefore" survives, a third does not.
- **Paragraph openers:** a tally of "So", "But", "That leaves" and friends. Four of one is a tic; vary the wording or let the order carry the link.
- **Hard-banned quick scan:** a default list of worn words ("honest", "quietly", "genuinely", "landscape" and friends). Your project's own gate is the authority; this scan saves a round trip when it catches one early.
- **British spellings:** US English, alt text included. The scan reads body text and `alt` and `title` attributes; the adapter passes the hero image alt separately in `british` mode. Three British forms reached production on one post before the scan read alt text. A proper noun stays British only on the owner's word.
- **Meta description:** two sentences, one link, 160 characters or fewer (the adapter prints the live one).
- **A literal verb can still trip a banned-word regex.** "unlocks a parked Tesla's doors" fails an "unlock" tic regex that only exempts "unlock the door"; reword to the plain verb ("opens") rather than arguing with the gate.
- **Anchors constrain where a lead-in can go.** A widget anchored on `</table>(?=\s*<h2...)` means nothing may sit between that table and the H2, so the table's follow-up becomes the next section's first paragraph.
- **Once-tier words the article already carries.** A project gate that allows a word once reads the whole after body, so an article that already uses "actually" twice fails the moment it is rechained, and the quick scan here only reports the hard tier. Before shipping, count your project's once-tier words in the draft and drop one occurrence in an edit pair.

Fix in `chain-edits.php`, rebuild, rerun until `verdict: ready`.

### 5. Load the draft and preview

Run the adapter's load step, then give the owner the preview URL and the list of joints with the before and after first clause of each. The owner's edits go into `chain-edits.php` (step 3), then steps 4 and 5 again. However many rounds, one change.

### 6. Meta description

Change it only when the old one is a list of two facts or no longer matches the new opening. Two sentences, the second a "so" or "but" on the first, 160 characters or fewer (`php -r 'echo strlen($argv[1]);' "..."`). Never touch the title or the slug.

### 7. Ready, then wait

Tell the owner: preview link, word count before and after, the number of joints rewritten, the section that moved most, meta changed or not, invariants `same`, gates passed. Then wait for "Commit + deploy". Never ship on anything else.

### 8. Ship and report

The adapter's ship step. Then:

```
Rechained: {slug}  ({before words} -> {after words} words)
Joints rewritten: {n}; most reordering in "{H2}"
Blocks moved: {list, e.g. Andrew Ng quote -> end of build-line section}
Meta description: unchanged | new (chars)
Invariants: spans {n}, links {n}, images {n}, anchors ok
Gates: {n} passed
```

## Hard rules

- One article per run. Facts, links, images, glossary spans and project anchors unchanged; the check script's `stop` tiers are the gate.
- The first paragraph names the event. Never open on a question or a thesis, whoever proposed it.
- The words are free, the labels are not: no "Therefore:", "This happened:", "But:"; a literal "Therefore" at most once or twice.
- US English, alt text included. No em dashes, no "honest", no "quietly", no stage-play framing.
- Rebuild the draft from `before.html` through `chain-edits.php`; never hand-patch `draft.html`.
- Ship only on "Commit + deploy"; purge only the article's URL.
