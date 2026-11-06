<?php

/**
 * The mechanical half of the causal-chain checks. Called by
 * scripts/rechain-check.sh with the before and draft HTML of one post.
 *
 *   php scripts/lib/rechain-check.php BEFORE.html DRAFT.html [checks|first-words|british]
 *
 * "checks" prints the invariant counts a rechain must not change, the label
 * test from .claude/docs/causal-chain.md, a quick scan for the hard-banned
 * words BannedPhrasesTest will fail on, and the British spellings the house
 * style bans. The last line is the verdict (exit 1 on stop). "first-words"
 * prints the first clause of every paragraph under each H2, which is the
 * first-words test laid out for reading. "british" scans DRAFT alone (the
 * wrapper passes the hero alt text this way). The swap test stays a reading.
 */
[$before, $draft, $mode] = [$argv[1] ?? null, $argv[2] ?? null, $argv[3] ?? 'checks'];

if (! $before || ! $draft || ! is_file($before) || ! is_file($draft)) {
    fwrite(STDERR, "usage: rechain-check.php BEFORE.html DRAFT.html [checks|first-words|british]\n");
    exit(2);
}

$british = '~\b\w*(?:centre|colour|favour|behaviour|neighbour|honour|labour|organis|analys(?:e|ed|ing)\b|licence|programme|grey|fibre|metre|litre|defence|offence|catalogue|optimis|recognis|realis(?!t)|minimis|maximis|prioritis|summaris|standardis|utilis|customis|labell|modell|travell|cancell|judgement|towards|whilst|amongst|cheque|tyre|kerb|aluminium|manoeuvre)\w*~i';

$b = (string) file_get_contents($before);
$d = (string) file_get_contents($draft);

function text(string $html): string
{
    $html = preg_replace('~<(script|style)\b.*?</\1>~is', '', $html) ?? $html;
    $html = preg_replace('~</(p|li|h[1-6]|td|th|dd|dt|summary|figcaption)>~i', "\n", $html) ?? $html;

    return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
}

function wordCount(string $html): int
{
    $html = preg_replace('~<details.*?</details>~s', '', $html) ?? $html;

    return str_word_count(text($html));
}

/** @return list<string> lines of "context" for every match of $re in $text */
function hits(string $text, string $re): array
{
    preg_match_all($re, $text, $m, PREG_OFFSET_CAPTURE);
    $out = [];
    foreach ($m[0] as [$match, $offset]) {
        $start = max(0, $offset - 45);
        $snippet = substr($text, $start, strlen($match) + 90);
        $snippet = trim(preg_replace('~\s+~', ' ', $snippet) ?? $snippet);
        $out[] = sprintf('"%s"  ...%s...', $match, $snippet);
    }

    return $out;
}

if ($mode === 'british') {
    $britishHits = hits(text($d), $british);
    echo $britishHits === [] ? "none\n" : '  '.implode("\n  ", $britishHits)."\n";
    exit($britishHits === [] ? 0 : 1);
}

if ($mode === 'first-words') {
    $sections = preg_split('~(?=<h2\b)~i', $d) ?: [$d];
    foreach ($sections as $i => $section) {
        $heading = 'Opening (before the first H2)';
        if (preg_match('~<h2\b[^>]*>(.*?)</h2>~is', $section, $h)) {
            $heading = trim(text($h[1]));
        }
        echo "\n## {$heading}\n";
        $blocks = [];
        preg_match_all('~<(p|li)\b[^>]*>(.*?)</\1>~is', $section, $m, PREG_SET_ORDER);
        foreach ($m as $block) {
            $t = trim(preg_replace('~\s+~', ' ', text($block[2])) ?? '');
            if ($t === '') {
                continue;
            }
            $isList = strtolower($block[1]) === 'li';
            if ($isList && $i !== 0 && stripos($heading, 'TL;DR') === false) {
                continue; // list items are parallel inside; only the TL;DR and opening lists are chained
            }
            preg_match('~^(.{12,}?)(?:[,.;:]|\s(?:so|but|because|which|and)\s)~u', $t.' ', $c);
            $clause = isset($c[1]) ? $c[1] : mb_substr($t, 0, 90);
            $blocks[] = ($isList ? '  - ' : '  ').mb_substr(trim($clause), 0, 110);
        }
        echo implode("\n", $blocks), "\n";
    }
    echo "\nRead each section top to bottom: it should read as a short argument, not a table of contents.\n";
    exit(0);
}

$stops = [];
$warns = [];

echo "== invariants (before -> draft) ==\n";
$invariants = [
    'secai-term spans' => ['~<span class="secai-term"~', 'stop'],
    'links' => ['~<a\s~i', 'stop'],
    'images' => ['~<img\s~i', 'stop'],
    'blockquotes' => ['~<blockquote\b~i', 'warn'],
    'h2 headings' => ['~<h2\b~i', 'warn'],
    'tables' => ['~<table\b~i', 'warn'],
    'details' => ['~<details\b~i', 'warn'],
    'paragraphs' => ['~<p[\s>]~i', 'info'],
];
foreach ($invariants as $label => [$re, $tier]) {
    $nb = preg_match_all($re, $b);
    $nd = preg_match_all($re, $d);
    $state = $nb === $nd ? 'same' : 'CHANGED';
    printf("%-18s %4d -> %4d  %s\n", $label, $nb, $nd, $state);
    if ($nb !== $nd && $tier === 'stop') {
        $stops[] = "{$label} changed ({$nb} -> {$nd}); a rechain moves joints, not facts";
    } elseif ($nb !== $nd && $tier === 'warn') {
        $warns[] = "{$label} changed ({$nb} -> {$nd}); say why in the migration summary";
    }
}
printf("%-18s %4d -> %4d  (details stripped)\n", 'words', wordCount($b), wordCount($d));

$plain = text($d);

echo "\n== label test ==\n";
$labels = [
    'Therefore' => '~\bTherefore\b~',
    'This happened' => '~\bThis happened\b~i',
    'So: / But: / And then' => '~(?:^|[\s>])(?:So|But|Then):|\bAnd then\b~im',
];
$labelHits = 0;
foreach ($labels as $name => $re) {
    $found = hits($plain, $re);
    if ($found === []) {
        continue;
    }
    $labelHits++;
    echo "{$name} x".count($found)."\n  ".implode("\n  ", $found)."\n";
    if ($name === 'Therefore') {
        if (count($found) > 2) {
            $stops[] = 'more than two literal "Therefore"';
        } else {
            $warns[] = count($found).' literal "Therefore" (one or two survive, no more)';
        }
    } else {
        $stops[] = "label \"{$name}\" showing";
    }
}
if ($labelHits === 0) {
    echo "none\n";
}

echo "\n== paragraph openers (vary them; a repeated connective is a tic) ==\n";
preg_match_all('~<p\b[^>]*>(.*?)</p>~is', $d, $ps);
$openers = ['So ' => 0, 'But ' => 0, 'That leaves' => 0, 'That is why' => 0, 'Which is why' => 0, 'Because ' => 0, 'Once ' => 0, 'Put ' => 0];
foreach ($ps[1] as $p) {
    $t = ltrim(text($p));
    foreach ($openers as $word => $_) {
        if (str_starts_with($t, $word)) {
            $openers[$word]++;
        }
    }
}
foreach ($openers as $word => $n) {
    if ($n > 0) {
        printf("%-13s %d\n", trim($word), $n);
        if ($n >= 4) {
            $warns[] = "{$n} paragraphs open with \"".trim($word).'"';
        }
    }
}

echo "\n== hard-banned words (quick scan; BannedPhrasesTest is the authority) ==\n";
$scannable = preg_replace('~<(blockquote|code|pre|cite)\b.*?</\1>~is', '', $d) ?? $d;
$scannable = text($scannable);
$banned = [
    '~\b(?:honest|honestly|honesty|quietly|genuinely|landscape)\b~i',
    '~\bdeep[- ]dive\b~i',
    '~\b(?:the good news|the math|bottom line|under the hood)\b~i',
    '~\b(?:lands|landed)\b~i',
    '~\bunlock(?:s|ing)?\b(?!\s+(?:(?:the|your|a)\s+)?(?:domain|door|account|phone|device|screen|file|laptop)s?\b)~i',
    '~\bleverag(?:es|ed|ing)\b~i',
    '~\belevat(?:e|es|ed|ing)\b(?!\s+(?:privileges?|permissions?|access|rights))~i',
    '~—~u',
];
$bannedHits = [];
foreach ($banned as $re) {
    $bannedHits = [...$bannedHits, ...hits($scannable, $re)];
}
if ($bannedHits === []) {
    echo "none\n";
} else {
    echo '  '.implode("\n  ", $bannedHits)."\n";
    $stops[] = count($bannedHits).' hard-banned word(s) or em dash(es)';
}

echo "\n== British spellings (house style is US English, alt text included) ==\n";
$noUrls = preg_replace('~\b(?:href|src|srcset|data-term)="[^"]*"~i', '', $d) ?? $d;
$altText = preg_match_all('~\b(?:alt|title)="([^"]*)"~i', $d, $altMatches) ? implode(' ', $altMatches[1]) : '';
$britishHits = hits(text($noUrls).' '.$altText, $british);
if ($britishHits === []) {
    echo "none\n";
} else {
    echo '  '.implode("\n  ", $britishHits)."\n";
    $stops[] = count($britishHits).' British spelling(s); a proper noun (BBC "Today programme" is still "program" here) needs the owner\'s word to stay';
}

echo "\n";
foreach ($warns as $w) {
    echo "warn: {$w}\n";
}
if ($stops === []) {
    echo "verdict: ready\n";
    exit(0);
}
echo 'verdict: stop: '.implode('; ', $stops)."\n";
exit(1);
