<?php

/**
 * The edit list for one rechain, kept as a script so every owner round rebuilds
 * the draft from the production copy instead of patching a patched file:
 *
 *   php chain-edits.php before.html draft.html
 *
 * Each pair is one joint. The comment above it names the link the new copy
 * gives the reader ("so" or "but") and where the chain now starts or ends.
 * Every old string must match exactly once in before.html, or the script stops
 * and writes nothing. Multi-line anchors are byte-exact, whitespace included;
 * copy them from before.html, never retype them.
 */
[$beforeFile, $draftFile] = [$argv[1] ?? null, $argv[2] ?? null];

if (! $beforeFile || ! $draftFile || ! is_file($beforeFile)) {
    fwrite(STDERR, "usage: php chain-edits.php before.html draft.html\n");
    exit(2);
}

$html = (string) file_get_contents($beforeFile);
$pairs = [];

// E1: the opening names the event; the second paragraph is its first "so".
$pairs[] = [<<<'OLD'
<p>
  The opening paragraph as it reads today, copied byte for byte from before.html.
</p>
OLD, <<<'NEW'
<p>
  The opening paragraph that names the event.
</p>
NEW];

// E2: the block before the table says why it is here.
$pairs[] = [
    '<p>Old one-line lead-in.</p>',
    '<p>New lead-in that gives the reader the "so" into the table:</p>',
];

foreach ($pairs as $i => [$old, $new]) {
    $n = substr_count($html, $old);
    if ($n !== 1) {
        fwrite(STDERR, 'E'.($i + 1).": expected 1 match, got {$n}\n");
        exit(1);
    }
    $html = str_replace($old, $new, $html);
}

file_put_contents($draftFile, $html);
echo 'applied ', count($pairs), " edits -> {$draftFile}\n";
