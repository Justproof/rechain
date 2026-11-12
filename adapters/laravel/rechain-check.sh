#!/usr/bin/env bash
#
# rechain-check.sh — what /rechain reads before the migration is generated: the
# counts a joints-only rewrite must not change, the label test from the
# causal-chain rule, the banned-phrase scan and British spellings,
# and the local row's meta description and hero alt. With --first-words it
# prints the first clause of every paragraph per H2 instead, which is the
# first-words test laid out for reading.
#
# Usage:
#   scripts/rechain-check.sh {slug | /blog/... URL} [--first-words] [--no-meta]
#
# Reads {storage_dir}/{slug}/draft.html against before.html, writing before.html
# from prod-row.json (saved by refresh-post-triage.sh) when it is missing. The
# checker itself is the global skill's scripts/rechain-check.php; set
# RECHAIN_SKILL if the clone is not at ~/.claude/skills/rechain. When the
# config names banned_phrases, the checker scans the draft against that file
# (the same list the project's own guard reads); without it, a built-in list
# stands in. Read-only otherwise. The last line is the verdict; exit 1 on stop
# so it can gate a chained command.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CFG="${RECHAIN_CONFIG:-$ROOT/.claude/rechain.json}"
SKILL="${RECHAIN_SKILL:-$HOME/.claude/skills/rechain}"
CHECK="$SKILL/scripts/rechain-check.php"

die() {
    echo "rechain-check: $*" >&2
    exit 2
}

command -v jq >/dev/null 2>&1 || die "jq not found"
[ -f "$CFG" ] || die "no config at $CFG; copy adapters/laravel/rechain.example.json there and edit it"
[ -f "$CHECK" ] || die "no checker at $CHECK; run: git clone git@github.com:Justproof/rechain.git ~/.claude/skills/rechain"

cfg() {
    local v
    v="$(jq -r --arg k "$1" 'if has($k) and .[$k] != null then .[$k] else empty end' "$CFG")"
    [ -n "$v" ] || die "config key \"$1\" is missing or null in $CFG"
    printf '%s' "$v"
}
opt() {
    jq -r --arg k "$1" 'if has($k) and .[$k] != null then .[$k] else empty end' "$CFG"
}
ident() {
    [[ "$2" =~ ^[A-Za-z_][A-Za-z0-9_]*$ ]] || die "config key \"$1\" must be a bare column or table name (got: $2)"
    printf '%s' "$2"
}

TABLE="$(ident table "$(cfg table)")"
SLUG_COL="$(ident slug_column "$(cfg slug_column)")"
CONTENT_COL="$(ident content_column "$(cfg content_column)")"
STATUS_COL="$(opt status_column)"; [ -z "$STATUS_COL" ] || STATUS_COL="$(ident status_column "$STATUS_COL")"
PUBLISHED="$(opt published_value)"
STORAGE_DIR="$(cfg storage_dir)"
META_COL="$(opt meta_description_column)"; [ -z "$META_COL" ] || META_COL="$(ident meta_description_column "$META_COL")"
ALT_COL="$(opt image_alt_column)"; [ -z "$ALT_COL" ] || ALT_COL="$(ident image_alt_column "$ALT_COL")"
BANNED_FILE="$(opt banned_phrases)"
BANNED_ARGS=()
if [ -n "$BANNED_FILE" ]; then
    [[ "$BANNED_FILE" = /* ]] || BANNED_FILE="$ROOT/$BANNED_FILE"
    [ -f "$BANNED_FILE" ] || die "config key \"banned_phrases\" names $BANNED_FILE, which does not exist"
    BANNED_ARGS=("--banned=$BANNED_FILE")
fi

SLUG=""
MODE="checks"
META=1

while [ $# -gt 0 ]; do
    case "$1" in
        --first-words) MODE="first-words"; shift ;;
        --no-meta)     META=0; shift ;;
        -h|--help)     sed -n '3,21p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2; exit 2 ;;
        -*)            die "unknown argument: $1" ;;
        *)             SLUG="$1"; shift ;;
    esac
done

SLUG="${SLUG%%\?*}"
SLUG="${SLUG%/}"
SLUG="${SLUG##*/}"
[[ "$SLUG" =~ ^[a-z0-9]+(-[a-z0-9]+)*$ ]] || die "give a post slug or a URL ending in one (got: ${SLUG:-nothing})"

DIR="$ROOT/$STORAGE_DIR/$SLUG"
BEFORE="$DIR/before.html"
DRAFT="$DIR/draft.html"
ROW="$DIR/prod-row.json"

[ -f "$DRAFT" ] || die "no draft at $DRAFT; run the triage, then php chain-edits.php before.html draft.html"
if [ ! -f "$BEFORE" ]; then
    [ -f "$ROW" ] || die "no before.html and no prod-row.json in $DIR; run scripts/refresh-post-triage.sh $SLUG --article first"
    jq -r --arg c "$CONTENT_COL" '.[$c]' "$ROW" > "$BEFORE"
    echo "wrote $BEFORE from prod-row.json"
fi

cd "$ROOT"

if [ "$MODE" = "first-words" ]; then
    php "$CHECK" "$BEFORE" "$DRAFT" first-words
    exit 0
fi

STATUS=0
php "$CHECK" "$BEFORE" "$DRAFT" checks ${BANNED_ARGS[@]+"${BANNED_ARGS[@]}"} || STATUS=$?

# The published-row filter, as a PHP where() chain fragment (empty when the config has no status column).
WHERE="where('$SLUG_COL', '$SLUG')"
[ -z "$STATUS_COL" ] || WHERE="$WHERE->where('$STATUS_COL', '$PUBLISHED')"

if [ "$META" = 1 ] && [ -n "$META_COL" ]; then
    echo
    echo "== meta description (local row; two sentences, one link, 160 chars or fewer) =="
    php artisan tinker --execute "
        \$m = (string) DB::table('$TABLE')->$WHERE->orderBy('id')->value('$META_COL');
        \$sentences = count(array_filter(preg_split('/(?<=[.!?])\s+/', trim(\$m))));
        printf(\"%s\n%d chars, %d sentence(s)%s%s\n\", \$m, strlen(\$m), \$sentences, strlen(\$m) > 160 ? '  OVER 160' : '', \$sentences === 2 ? '' : '  not two sentences');
    " 2>/dev/null | sed '/^$/d'
fi

if [ "$META" = 1 ] && [ -n "$ALT_COL" ]; then
    echo
    echo "== hero image alt text (local row; US spelling) =="
    ALT="$DIR/hero-alt.txt"
    php artisan tinker --execute "echo DB::table('$TABLE')->$WHERE->orderBy('id')->value('$ALT_COL');" 2>/dev/null > "$ALT"
    cat "$ALT"; echo
    php "$CHECK" "$ALT" "$ALT" british || STATUS=1
fi

exit "$STATUS"
