# Laravel adapter

The three steps `/rechain` cannot do on its own (fetch the live copy, load the draft, ship) for a Laravel site whose posts live in one table with a slug column and an HTML content column. Production is reached over an ssh alias; the change ships as one hash-guarded, reversible migration.

Everything site-specific is in one file, `.claude/rechain.json`. The scripts and the artisan command read it; nothing here names a table or a column.

## Install

Copy five files into your project, then the config:

| From                          | To                                                                         |
| ----------------------------- | -------------------------------------------------------------------------- |
| `refresh-post-triage.sh`      | `scripts/refresh-post-triage.sh`                                           |
| `rechain-check.sh`            | `scripts/rechain-check.sh`                                                 |
| `PostRewriteMigration.php`    | `app/Console/Commands/PostRewriteMigration.php`                            |
| `post-rewrite-migration.stub` | `scripts/lib/post-rewrite-migration.stub` (or wherever `stub_path` points) |
| `config/rechain.php`          | `config/rechain.php`                                                       |
| `rechain.example.json`        | `.claude/rechain.json`, then edit every value                              |

```sh
S=~/.claude/skills/rechain/adapters/laravel
mkdir -p scripts/lib app/Console/Commands config .claude
cp $S/refresh-post-triage.sh $S/rechain-check.sh scripts/
cp $S/PostRewriteMigration.php app/Console/Commands/
cp $S/post-rewrite-migration.stub scripts/lib/
cp $S/config/rechain.php config/
cp $S/rechain.example.json .claude/rechain.json
chmod +x scripts/refresh-post-triage.sh scripts/rechain-check.sh
php artisan list app | grep post-rewrite-migration     # proves the command is registered
```

Add `storage_dir` to `.gitignore` if it is not already under an ignored path. Needs `jq`, `php` on your PATH, and an ssh alias for production in `~/.ssh/config`.

## Config keys

| Key                                                                                                       | Required | What it is                                                                                             |
| --------------------------------------------------------------------------------------------------------- | -------- | ------------------------------------------------------------------------------------------------------ |
| `ssh_host`                                                                                                | yes      | ssh alias for production. An alias, never a host with credentials in it.                               |
| `remote_path`                                                                                             | yes      | Directory on that host holding `artisan`.                                                              |
| `site_url`, `local_url`                                                                                   | yes      | Scheme and host of production and of the local preview.                                                |
| `blog_path`                                                                                               | yes      | URL prefix in front of the slug, such as `/blog`.                                                      |
| `table`                                                                                                   | yes      | The posts table.                                                                                       |
| `slug_column`                                                                                             | yes      | The column the URL slug lives in.                                                                      |
| `content_column`                                                                                          | yes      | The HTML body.                                                                                         |
| `status_column`                                                                                           | no       | With `published_value`, filters to the live row. Null when every row is live.                          |
| `title_column`                                                                                            | no       | Printed by the triage; never changed.                                                                  |
| `meta_title_column`, `meta_description_column`, `meta_keyword_column`, `image_column`, `image_alt_column` | no       | Printed by the triage; the generator swaps the ones that changed and `down()` restores them.           |
| `updated_at_column`                                                                                       | no       | Set to `now()` on every swap. Null to leave timestamps alone.                                          |
| `storage_dir`                                                                                             | yes      | Git-ignored working directory; one subdirectory per slug.                                              |
| `stub_path`                                                                                               | no       | Where the migration stub lives; default `scripts/lib/post-rewrite-migration.stub`.                     |
| `migration_prefix`                                                                                        | no       | First word of the migration file name; default `rewrite`.                                              |
| `widgets_config`                                                                                          | no       | A config file keyed by slug whose rows carry regex anchors a rewrite must keep matching. Null to skip. |
| `ledger_path`                                                                                             | no       | JSON list of past rewrites by slug. Null to skip.                                                      |
| `banned_phrases`                                                                                          | no       | Your ban list (format in the skill README), relative to the project root. The checker scans every draft against it. Null to use the built-in quick scan.|
| `stop_reason_class`                                                                                       | no       | A class with `static stopReason(string $slug, string $content): ?string`. Null to skip.                |

Keys starting with `_` are comments and are ignored.

## The run

Steps 2, 3, 4 and 6 are the global skill's (`~/.claude/skills/rechain/SKILL.md`). These are the adapter's.

### 1. Fetch

```sh
scripts/refresh-post-triage.sh {slug} --article
```

Prints the local row, the widget anchors (if configured), the ledger row, saves the production row to `{storage_dir}/{slug}/prod-row.json`, compares its hash with local, prints the article as text, and ends with a verdict. Stop on anything but `ready`: a drifted production row means sync local from production first; an unshipped migration naming the post means ask the owner. Carry forward the prod sha256 and the generator line it prints, then:

```sh
DIR={storage_dir}/{slug}
jq -r '.{content_column}' $DIR/prod-row.json > $DIR/before.html
cp $DIR/before.html $DIR/draft.html
cp ~/.claude/skills/rechain/scripts/rechain-edits.stub.php $DIR/chain-edits.php
```

`scripts/rechain-check.sh {slug} --first-words` and `scripts/rechain-check.sh {slug}` wrap the global checker with your storage dir and add the live meta description and hero alt from the local row.

### 5. Load

```sh
php artisan tinker --execute '$slug="{slug}"; $html=file_get_contents(storage_path("app/refresh-post/$slug/draft.html")); $n=DB::table("{table}")->where("{slug_column}",$slug)->where("{status_column}","{published_value}")->update(["{content_column}"=>$html,"updated_at"=>now()]); echo "rows updated: $n\nsha256: ", hash("sha256", DB::table("{table}")->where("{slug_column}",$slug)->orderBy("id")->value("{content_column}")), "\n";'
php artisan cache:clear --no-interaction
scripts/refresh-post-triage.sh {slug} --no-prod      # every widget row still ok; "DIFFERS from local" is the draft, not drift
```

Two rows updated means a duplicate slug; the migration will update both, say so. Preview at `{local_url}{blog_path}/{slug}`.

### 6. Meta description

Set it on the local row with tinker; the generator records the old value and `down()` restores it. Never touch the title or the slug.

### 7. Generate, round-trip, gate

```sh
php artisan app:post-rewrite-migration {slug} --before=$DIR/prod-row.json --expect-hash={prod sha256} --name=causal_chain --summary="Rechained ... Sections reordered: ... Facts, links and images unchanged." --no-interaction
vendor/bin/pint database/migrations/{file}                          # the named file only
php artisan migrate --force --path=database/migrations/{file}       # no-op on the loaded row; records the file
php artisan migrate:rollback --force --path=database/migrations/{file}   # sha256(content) == before hash
php artisan migrate --force --path=database/migrations/{file}       # sha256(content) == after hash
```

The generator refuses when `--expect-hash` does not match the captured row, when local equals production, or when a body line starts with `HTML` (it would close the nowdoc). The migration's `swap()` checks the current hash before writing, so a drifted production row is left alone and `down()` is exact.

**Regenerating after an owner change.** Roll back first, then delete the file, then edit `chain-edits.php`, rebuild, reload and regenerate with the same `--expect-hash`. `migrate:rollback --path` only rolls back the latest batch: when another migration has run since, it does nothing and the deleted file's record stays in `migrations`. After every rollback, confirm the record is gone:

```sh
php artisan tinker --execute 'print_r(DB::table("migrations")->where("migration","like","%{slug_ish}%")->pluck("batch","migration")->all());'
```

### 9. Ship

1. Commit the migration by path. Never `git add -A`, never `git stash`.
2. If you keep a ledger, append a row with the commit SHA, the date and a Search Console baseline, and commit it on its own. If you push by cherry-picking onto `origin/main` (to leave other unpushed commits behind), the SHAs change: write the pushed SHAs into the ledger before the push.
3. Deploy however your project deploys. On the host: `php artisan migrate --force`, then check `migrate:status` shows the file and `sha256(content)` equals the after hash.
4. Purge only `{site_url}{blog_path}/{slug}` from every cache layer in front of the page. Nothing else on the page changed.
5. `curl -o FILE` the live page, then `rg -F` a marker pasted from `draft.html`. A retyped marker fails on your own typo.

## What pcdrama.com adds and this adapter does not ship

pcdrama runs four Pest gates on every new content migration: a banned-phrases list with hard and once-only tiers, a sentence-shape budget, a split-image layout check, and a test that no agent-instruction text leaked into copy. They encode one house style and read that project's docs, so they stay there. The part that travels is the ban-list format: set `banned_phrases` and the checker reads the same file your own gate does, so the two cannot disagree.
