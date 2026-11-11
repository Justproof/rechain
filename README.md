# rechain

A Claude Code skill that rewrites the **joints** of a published article so every paragraph follows from, or reverses, the one before it. Same facts, same links, same images. New order and connective tissue.

The rule is one sentence: between any two consecutive blocks, the reader must be able to supply "so" or "but" without help. If the only link that fits is "and then", the block is in the wrong place or is a list item in disguise. The words are free (so, which is why, that leaves, except, and yet); labels like "Therefore:" are not.

**Owner line:** an article can be true, sourced and in the house voice and still read like a list. This finds the "and then" joints, fixes them without touching a fact, and proves the facts survived before anyone ships it.

## What's in here

| Path                             | What it does                                                                                                                                                |
| -------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `SKILL.md`                       | The skill: the three tests (swap, first-words, label), the seven fixes seen so far, the edit-script pattern, the check, the ready report, the hard rules    |
| `docs/causal-chain.md`           | The rule in full, with a worked example and how it applies to TL;DRs, tables, FAQs, captions, meta descriptions and emails                                  |
| `scripts/rechain-check.php`      | The mechanical checker: counts a joints-only rewrite must not change, the label test, a paragraph-opener tally, a hard-banned quick scan, British spellings |
| `scripts/rechain-edits.stub.php` | The edit list as a replayable script; stops unless every anchor matches the live copy exactly once                                                          |
| `adapters/laravel/`              | A working adapter for a Laravel site with a posts table: triage over ssh, draft load, a hash-guarded reversible migration generator, the ship steps         |
| `tests/`                         | Pest tests for the checker, with fixtures                                                                                                                   |

## Install

```sh
git clone git@github.com:Justproof/rechain.git ~/.claude/skills/rechain
```

That makes `/rechain` available in every project Claude Code opens. Update with `git -C ~/.claude/skills/rechain pull`.

To point your global instructions at the rule, add to `~/.claude/CLAUDE.md`:

```markdown
Every paragraph follows from the one before it or reverses it, never "and then". Rule and tests: `~/.claude/skills/rechain/docs/causal-chain.md`.
```

## Use it on two files

The skill needs nothing from your site to diagnose and check:

```sh
cp article.html before.html                 # the live copy; never changes during a run
cp before.html draft.html
php ~/.claude/skills/rechain/scripts/rechain-check.php before.html draft.html first-words   # the first-words test, per H2
cp ~/.claude/skills/rechain/scripts/rechain-edits.stub.php chain-edits.php
# ... one [$old, $new] pair per joint ...
php chain-edits.php before.html draft.html
php ~/.claude/skills/rechain/scripts/rechain-check.php before.html draft.html               # verdict: ready | stop
```

The checker's last line is the verdict and its exit code is 1 on stop, so it can gate a chained command.

## Use it on a site

Three steps touch a real site: fetch the live copy, load the draft for preview, ship. `adapters/laravel/README.md` walks through the Laravel version, which reads your table and column names from one `.claude/rechain.json` in your project. Any other stack supplies the same three steps its own way; the skill's contract is in `SKILL.md`.

## Tests

```sh
composer install
vendor/bin/pest
```

## See also

The rule works on any prose, so it works on a plan too. [compound-skills](https://github.com/Justproof/compound-skills) holds `prd-bake`, which questions you until every decision in a feature is written down, and `compound-engineering`, which records what each build taught so the next one starts ahead. A PRD from the first is a good thing to rechain before anyone builds from it.

## Where it came from

Built on pcdrama.com on 2026-11-06, where it rechained nine posts the day the rule was written. The lessons from those runs (anchors that constrain lead-ins, British spellings hiding in alt text, once-tier words an article already carries) are in `SKILL.md`.

MIT.
