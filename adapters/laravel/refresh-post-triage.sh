#!/usr/bin/env bash
#
# refresh-post-triage.sh — everything a rewrite or rechain reads before it
# touches a post, in one call: the local row and its meta, the widget anchors
# the rewrite must keep matching, the ledger row, and whether production still
# matches local. Saves the production row as the --before file for
# app:post-rewrite-migration. Table and column names come from
# .claude/rechain.json (see rechain.example.json).
#
# Usage:
#   scripts/refresh-post-triage.sh {slug | /blog/... URL} [--candidates FILE] [--out DIR] [--no-prod] [--article]
#
#   --candidates  A JSON file with a "candidates" list keyed by slug (optional
#                 Search Console export); prints this post's row from it.
#   --out         Where the production row is saved (default
#                 {storage_dir}/{slug}, git-ignored).
#   --no-prod     Skip the ssh read of production. No --before file is written.
#   --article     End with the article as plain text.
#
# Read-only apart from the --out directory. The last line is the verdict:
# ready, or stop and why.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CFG="${RECHAIN_CONFIG:-$ROOT/.claude/rechain.json}"

die() {
    echo "refresh-post-triage: $*" >&2
    exit 1
}

section() {
    printf '\n== %s ==\n' "$1"
}

command -v jq >/dev/null 2>&1 || die "jq not found"
[ -f "$CFG" ] || die "no config at $CFG; copy adapters/laravel/rechain.example.json there and edit it"

# cfg KEY prints a required value; opt KEY prints an optional one or nothing.
cfg() {
    local v
    v="$(jq -r --arg k "$1" 'if has($k) and .[$k] != null then .[$k] else empty end' "$CFG")"
    [ -n "$v" ] || die "config key \"$1\" is missing or null in $CFG"
    printf '%s' "$v"
}
opt() {
    jq -r --arg k "$1" 'if has($k) and .[$k] != null then .[$k] else empty end' "$CFG"
}
# Column names are interpolated into PHP, so they are restricted to identifiers.
ident() {
    [[ "$2" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || die "config key \"$1\" must be a bare column or table name (got: $2)"
    printf '%s' "$2"
}

PROD_HOST="${REFRESH_TRIAGE_HOST:-$(cfg ssh_host)}"
REMOTE_PATH="$(cfg remote_path)"
TABLE="$(ident table "$(cfg table)")"
SLUG_COL="$(ident slug_column "$(cfg slug_column)")"
CONTENT_COL="$(ident content_column "$(cfg content_column)")"
STATUS_COL="$(opt status_column)"; [ -z "$STATUS_COL" ] || STATUS_COL="$(ident status_column "$STATUS_COL")"
STORAGE_DIR="$(cfg storage_dir)"
WIDGETS="$(opt widgets_config)"
LEDGER="$(opt ledger_path)"
STOP_CLASS="$(opt stop_reason_class)"

# Optional display and meta columns, in print order.
META_COLS=()
for key in title_column meta_title_column meta_description_column meta_keyword_column image_column image_alt_column updated_at_column; do
    v="$(opt "$key")"
    [ -z "$v" ] || META_COLS+=("$(ident "$key" "$v")")
done
php_list() {
    local out="" c
    for c in "$@"; do out="${out}'${c}', "; done
    printf '%s' "${out%, }"
}
META_PHP="$(php_list "${META_COLS[@]+"${META_COLS[@]}"}")"

SLUG=""
CANDIDATES=""
OUT=""
PROD=1
ARTICLE=0

while [ $# -gt 0 ]; do
    case "$1" in
        --candidates) CANDIDATES="${2:-}"; shift 2 ;;
        --out)        OUT="${2:-}"; shift 2 ;;
        --no-prod)    PROD=0; shift ;;
        --article)    ARTICLE=1; shift ;;
        -h|--help)    sed -n '3,21p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2; exit 1 ;;
        -*)           die "unknown argument: $1" ;;
        *)            SLUG="$1"; shift ;;
    esac
done

SLUG="${SLUG%%\?*}"
SLUG="${SLUG%/}"
SLUG="${SLUG##*/}"
[[ "$SLUG" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]] || die "give a post slug or a URL ending in one (got: ${SLUG:-nothing})"

cd "$ROOT"
OUT="${OUT:-$STORAGE_DIR/$SLUG}"

STOP_PHP="null"
[ -z "$STOP_CLASS" ] || STOP_PHP="\\${STOP_CLASS}::stopReason('$SLUG', (string) \$p->$CONTENT_COL)"

# --- local row ----------------------------------------------------------------
section "local row"
LOCAL_JSON="$(php artisan tinker --execute "
\$rows = DB::table('$TABLE')->where('$SLUG_COL', '$SLUG')->orderBy('id')->get();
\$p = \$rows->first();
if (! \$p) { echo 'null'; return; }
\$out = ['rows' => \$rows->count()];
${STATUS_COL:+\$out['status'] = \$p->$STATUS_COL;}
\$out['words'] = str_word_count(strip_tags((string) \$p->$CONTENT_COL));
\$out['sha256'] = hash('sha256', (string) \$p->$CONTENT_COL);
foreach ([$META_PHP] as \$c) { \$out[\$c] = (string) \$p->\$c; }
\$out['stop'] = $STOP_PHP;
echo json_encode(\$out);
")"
[ "$LOCAL_JSON" != "null" ] || die "no local $TABLE row for $SLUG"
jq -r 'to_entries[] | select(.key != "sha256" and .key != "stop") | "\(.key): \(.value)"' <<<"$LOCAL_JSON"
[ "$(jq -r '.rows' <<<"$LOCAL_JSON")" -eq 1 ] || echo "WARNING: duplicate slug; the generated migration updates every row with this $SLUG_COL"

# --- widgets --------------------------------------------------------------------
section "widget anchors (a rewrite must keep one anchor per row matching)"
if [ -n "$WIDGETS" ]; then
    ANCHORS="$(php artisan tinker --execute "
\$rows = config('$WIDGETS')['$SLUG'] ?? null;
if (\$rows === null) { echo \"none\n\"; return; }
\$html = (string) DB::table('$TABLE')->where('$SLUG_COL', '$SLUG')->orderBy('id')->value('$CONTENT_COL');
foreach (\$rows['content'] ?? [] as \$row) {
    \$hit = collect(\$row['anchors'])->first(fn (\$anchor) => preg_match(\$anchor['pattern'], \$html));
    echo (\$hit ? 'ok    ' : 'MISS  ').\$row['name'].'  view='.\$row['view'].\"\n\";
    foreach (\$row['anchors'] as \$anchor) { echo '        '.(\$anchor === \$hit ? '> ' : '  ').\$anchor['position'].'  '.\$anchor['pattern'].\"\n\"; }
}
foreach (\$rows['page'] ?? [] as \$row) { echo 'page  '.\$row['slot'].'  view='.\$row['view'].\"\n\"; }
")"
else
    ANCHORS="none (no widgets_config in $CFG)"
fi
echo "$ANCHORS"

# --- ledger and search data ---------------------------------------------------------
section "ledger"
if [ -n "$LEDGER" ] && [ -f "$LEDGER" ] && jq -e --arg s "$SLUG" 'any(.[]; .slug == $s)' "$LEDGER" >/dev/null; then
    jq -r --arg s "$SLUG" '.[] | select(.slug == $s) | "refreshed \(.date) at \(.commit // "?"): \(.baseline.impressions) impressions, \(.baseline.clicks) clicks, position \(.baseline.position)"' "$LEDGER"
else
    echo "never refreshed"
fi

if [ -n "$CANDIDATES" ]; then
    section "search console ($CANDIDATES)"
    jq -r --arg s "$SLUG" '
        (.candidates[] | select(.slug == $s)) as $c
        | "\(.start) to \(.end): \($c.impressions) impressions, \($c.clicks) clicks, position \($c.position), score \($c.score)",
          ($c.top_queries[] | "  \(.impressions)  pos \(.position)  \(.query)")
    ' "$CANDIDATES" 2>/dev/null || echo "not in the candidates file"
fi

# --- production -------------------------------------------------------------------------
VERDICT="ready"
STOP_REASON="$(jq -r '.stop // empty' <<<"$LOCAL_JSON")"
if [ -n "$STOP_REASON" ]; then
    VERDICT="stop: nothing to refresh, $STOP_REASON"
elif grep -q '^MISS' <<<"$ANCHORS"; then
    VERDICT="stop: a widget anchor no longer matches the local copy (MISS above)"
fi
if [ "$PROD" -eq 1 ]; then
    section "production ($PROD_HOST)"
    mkdir -p "$OUT"
    # Column names as a double-quoted PHP list; the remote tinker call is single-quoted.
    SELECT_PHP="$(php_list "$CONTENT_COL" "${META_COLS[@]+"${META_COLS[@]}"}" | tr "'" '"')"
    # The slug is kebab-case by the check above, so it is safe inside the quotes.
    if ssh -o ConnectTimeout=10 "$PROD_HOST" "cd $REMOTE_PATH && php artisan tinker --execute '\$p = DB::table(\"$TABLE\")->where(\"$SLUG_COL\", \"$SLUG\")->orderBy(\"id\")->first([$SELECT_PHP]); echo base64_encode(json_encode(\$p));'" 2>/dev/null \
        | base64 --decode > "$OUT/prod-row.json" && [ "$(jq -r 'type' "$OUT/prod-row.json" 2>/dev/null)" = "object" ]; then
        PROD_SHA="$(php -r 'echo hash("sha256", (string) json_decode(file_get_contents($argv[1]), true)[$argv[2]]);' "$OUT/prod-row.json" "$CONTENT_COL")"
        echo "saved: $OUT/prod-row.json"
        echo "prod sha256: $PROD_SHA"
        if [ "$PROD_SHA" = "$(jq -r '.sha256' <<<"$LOCAL_JSON")" ]; then
            echo "content: identical to local"
        else
            UNSHIPPED="$( { git ls-files --others --exclude-standard -- database/migrations; git diff --name-only origin/main -- database/migrations 2>/dev/null; } | sort -u | while read -r file; do [ -f "$file" ] && grep -lF "$SLUG" "$file"; done || true)"
            if [ "$VERDICT" != "ready" ]; then
                echo "content: DIFFERS from local"
            elif [ -n "$UNSHIPPED" ]; then
                echo "content: DIFFERS from local; unshipped local migrations name this post:"
                sed 's/^/  /' <<<"$UNSHIPPED"
                VERDICT="stop: ship or drop the unshipped migrations first"
            else
                echo "content: DIFFERS from local and no unshipped migration explains it"
                VERDICT="stop: production drifted; sync the local row from production first"
            fi
        fi
    else
        rm -f "$OUT/prod-row.json"
        VERDICT="stop: could not read production over ssh $PROD_HOST"
    fi
fi

if [ "$ARTICLE" -eq 1 ]; then
    section "article"
    php artisan tinker --execute "echo html_entity_decode(strip_tags(str_replace(['</p>', '</h2>', '</h3>', '</li>', '</tr>'], \"\\n\", (string) DB::table('$TABLE')->where('$SLUG_COL', '$SLUG')->orderBy('id')->value('$CONTENT_COL'))));" | cat -s
fi

section "verdict"
echo "$VERDICT"
if [ "$VERDICT" = "ready" ] && [ "$PROD" -eq 1 ]; then
    echo "generator: php artisan app:post-rewrite-migration $SLUG --before=$OUT/prod-row.json --expect-hash=$PROD_SHA"
fi
