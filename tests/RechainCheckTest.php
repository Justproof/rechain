<?php

use Symfony\Component\Process\Process;

/*
| scripts/rechain-check.php is the mechanical half of the causal-chain
| rule (docs/causal-chain.md): the counts a joints-only rewrite must
| keep, the label test, the banned-phrase scan and the British spellings.
| These fixtures prove each tier reports and that a clean rechain reads ready.
*/

/**
 * @return array{0: int, 1: string} exit code and stdout
 */
function rechainCheck(string $before, string $draft, string $mode = 'checks', array $flags = []): array
{
    $dir = sys_get_temp_dir().'/rechain-check-'.uniqid();
    mkdir($dir);
    file_put_contents("{$dir}/before.html", $before);
    file_put_contents("{$dir}/draft.html", $draft);

    $process = new Process(['php', dirname(__DIR__).'/scripts/rechain-check.php', "{$dir}/before.html", "{$dir}/draft.html", $mode, ...$flags]);
    $process->run();

    unlink("{$dir}/before.html");
    unlink("{$dir}/draft.html");
    rmdir($dir);

    return [(int) $process->getExitCode(), $process->getOutput()];
}

$before = <<<'HTML'
<h2>Opening</h2>
<p>A <span class="secai-term" data-term="x">thing</span> happened on Monday. Also the data centre was busy.</p>
<p>Therefore: the <a href="https://example.com">team</a> met.</p>
<img src="https://cdn.example.com/a.webp" alt="a quiet data centre aisle">
HTML;

it('reads ready when the joints change and the facts do not', function () use ($before) {
    $draft = str_replace(
        ['Also the data centre was busy.', 'Therefore: the', 'quiet data centre aisle'],
        ['The data center was busy, so nobody saw it.', 'That is why the', 'dim data center aisle'],
        $before,
    );

    [$code, $out] = rechainCheck($before, $draft);

    expect($code)->toBe(0)
        ->and($out)->toContain('verdict: ready')
        ->toContain('secai-term spans      1 ->    1  same');
});

it('stops on labels, banned words, British spellings and a dropped link', function () use ($before) {
    $draft = str_replace('<a href="https://example.com">team</a>', 'team', $before);
    $draft .= "\n<p>So: the auditor quietly checked.</p>";

    [$code, $out] = rechainCheck($before, $draft);

    expect($code)->toBe(1)
        ->and($out)->toContain('links                 1 ->    0  CHANGED')
        ->toContain('label "So: / But: / And then" showing')
        ->toContain('"quietly"')
        ->toContain('"centre"')
        ->toContain('British spelling')
        ->toContain('verdict: stop:');
});

it('lets one literal Therefore through as a warning', function () use ($before) {
    $draft = str_replace(
        ['Also the data centre was busy.', 'Therefore: the', 'data centre aisle'],
        ['The data center was busy, so nobody saw it.', 'Therefore the', 'data center aisle'],
        $before,
    );

    [$code, $out] = rechainCheck($before, $draft);

    expect($code)->toBe(0)
        ->and($out)->toContain('warn: 1 literal "Therefore"')
        ->toContain('verdict: ready');
});

it('prints the first clause of each paragraph under its heading', function () use ($before) {
    [$code, $out] = rechainCheck($before, $before, 'first-words');

    expect($code)->toBe(0)
        ->and($out)->toContain("## Opening\n  A thing happened on Monday")
        ->toContain('Therefore: the team met');
});

it('catches British spellings inside alt attributes, not only body text', function () {
    $html = '<p>A sneaker on a bench.</p><img src="https://cdn.example.com/s.webp" alt="A grey sneaker and two carbon-fibre cylinders">';

    [$code, $out] = rechainCheck($html, $html);

    expect($code)->toBe(1)
        ->and($out)->toContain('"grey"')
        ->toContain('"fibre"');
});

it('scans a plain string in british mode for the hero alt text', function () {
    [$dirty, $dirtyOut] = rechainCheck('x', 'A rack in a dark data centre aisle', 'british');
    [$clean, $cleanOut] = rechainCheck('x', 'A rack in a dark data center aisle', 'british');

    expect($dirty)->toBe(1)
        ->and($dirtyOut)->toContain('"centre"')
        ->and($clean)->toBe(0)
        ->and($cleanOut)->toContain('none');
});

it('reads the project ban list: since-stamped hard entries, once entries and the allow list', function () {
    $flags = ['--banned='.__DIR__.'/fixtures/banned-phrases.md'];
    $html = '<p>Something smells off on the landing screen.</p><p>It actually works. It actually ships.</p><p>See the Honest Signals Report and the landing page.</p>';

    [$code, $out] = rechainCheck($html, $html, 'checks', $flags);

    expect($code)->toBe(1)
        ->and($out)->toContain('== banned phrases ('.__DIR__.'/fixtures/banned-phrases.md) ==')
        ->toContain('hard  "smells"')
        ->toContain('hard  "landing"  ...Something smells off on the landing screen')
        ->toContain('once  2 hits, one allowed: actually')
        ->toContain('2 hard-banned phrase(s)')
        ->toContain('1 once-per-piece phrase(s) used more than once')
        ->not->toContain('hard  "Honest"')
        ->and(substr_count($out, 'hard  "landing"'))->toBe(1);
});

it('allows one hit of a once entry', function () {
    $html = '<p>It actually works.</p>';

    [$code, $out] = rechainCheck($html, $html, 'checks', ['--banned='.__DIR__.'/fixtures/banned-phrases.md']);

    expect($code)->toBe(0)
        ->and($out)->toContain('verdict: ready');
});

it('skips quotes and code when scanning for banned phrases', function () {
    $html = '<blockquote>To be honest, it smells.</blockquote><p><code>honest()</code> returns true.</p>';

    [$code] = rechainCheck($html, $html, 'checks', ['--banned='.__DIR__.'/fixtures/banned-phrases.md']);

    expect($code)->toBe(0);
});

it('falls back to the built-in hard list and says so', function () {
    $html = "<p>An honest look at the landing screen \u{2014} and nothing else.</p>";

    [$code, $out] = rechainCheck($html, $html);

    expect($code)->toBe(1)
        ->and($out)->toContain('built-in hard list; pass --banned=FILE')
        ->toContain('"honest"')
        ->toContain('"landing"')
        ->toContain("\"\u{2014}\"");
});

it('refuses a missing or empty ban list instead of falling back', function () {
    $html = '<p>Fine.</p>';
    $empty = tempnam(sys_get_temp_dir(), 'ban');
    file_put_contents($empty, "# no blocks here\n");

    [$missing] = rechainCheck($html, $html, 'checks', ['--banned=/nonexistent/banned.md']);
    [$blank] = rechainCheck($html, $html, 'checks', ['--banned='.$empty]);
    unlink($empty);

    expect($missing)->toBe(2)->and($blank)->toBe(2);
});
