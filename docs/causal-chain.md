# Causal chain: every block follows from the one before it

A draft can pass every other rule in this folder and still read like a list. Each paragraph is true, sourced and in the house voice, and the reader could shuffle them without noticing. That is "and then" writing: this happened, and then this, and also this. The owner set the replacement rule on 2026-11-06 while restructuring the Google vs. Cloudflare post: **replace every "and then" with "so" or "but".** A block either follows from the one before it or reverses it. Nothing else earns a place in the sequence.

The sibling docs cover the sentence ([`voice.md`](voice.md)), the block's job ([`prose-variety.md`](prose-variety.md)), the worn words ([`banned-phrases.md`](banned-phrases.md)), the worn shapes ([`shape-budget.md`](shape-budget.md)) and the substance ([`information-gain.md`](information-gain.md)). This one covers the joints between blocks.

**Scope.** User-facing copy: posts, pages, package and industry copy, emails, deck lesson blocks, teaser cards, meta descriptions. Code comments and instruction text stay as they are.

## The rule

Between any two consecutive prose blocks, and between the sentences inside a block, the reader must be able to supply one of two links without help:

| Link | What the next block does | Natural wordings |
| ---- | ------------------------ | ---------------- |
| **so** | states a consequence of the block before it | so, which is why, that leaves, which means, and the result was |
| **but** | reverses, limits or contradicts the block before it | but, except, and yet, although, which did not survive the check |

If the only link that fits is "and then", "also", "another thing" or "in addition", the block is in the wrong place or is a list item in disguise. Move it into the table or list it belongs to, move it to where its cause is, or cut it.

**The words are free. The link is not.** The rule does not ask for the literal words "therefore" and "but", and it does not ask for labels. "This happened:", "Therefore:", "But:" at the start of a bullet is the rule showing through the paint. Write the sentences so the reader supplies the link; vary the wording so no connective becomes a tic; and when the order alone makes the consequence obvious, leave the connective out. A literal "Therefore" survives at most once or twice in a piece.

## What a link is made of

A link holds when the reader can see the mechanism, and the mechanism is usually one level below the claim: the line item, the log row, the command, the billing unit. "Alphabet fell $510 billion" and "capital spending doubled" sit side by side as two facts. Add the layer underneath (free cash flow went negative, so the search ads are what pays for the chips) and the second fact becomes the cause of the first. When a "so" feels forced, the missing piece is almost always the data or the tool that connects the two blocks, not a better connective. Go down a level and the link writes itself.

## Where the chain starts and ends

- **The first paragraph names the first cause.** What happened, in literal terms, before any consequence is drawn. The opening is not a summary of the conclusions; it is the event the rest follows from.
- **Each H2 restarts a chain** from the cause its heading asks about, and the chains join: the last block of one section is the cause of the first block of the next, or the question it leaves open.
- **The "but" is where the finding lives.** "We expected X, but Y" is the surprise that `information-gain.md` asks for, stated as a reversal. One or two reversals per section carry a piece; a reversal in every paragraph is the turn-close groove `shape-budget.md` budgets, so the two rules meet here: put the "but" at the start of a block or inside a sentence, not as a short closing drum hit.
- **The owner line is the last "so".** The plain consequence sentence that closes a gain section is the chain's end for the business owner: this is what we measured, so this is what changes for you. What that change has to be (a problem gone, a step skipped or a feeling had) is in [`reader-payoff.md`](reader-payoff.md).

## By block

| Block | How the rule applies |
| ----- | -------------------- |
| Body paragraphs | Full rule. Every paragraph is a "so" or a "but" on the one before it. |
| TL;DR | Chained. The bullets are the piece's spine in order: cause, consequence, reversal, consequence, what to do. A reader should be able to read them as one run. |
| Key Takeaways | Parallel, exempt inside the list. They are actions, not a story. The sentence introducing them follows from the body. |
| Tables and lists | Parallel inside. The block before a table says why it is here; the block after it draws the consequence from it. A table nobody draws a conclusion from is "and then" in a grid. |
| FAQ answers | A short chain of their own: the plain answer first, then the reason it is so. Never a second unrelated fact. |
| Captions | Exempt from the chain; a caption's job is to say what is in the picture. |
| Meta description | Two sentences, one link: the second follows from or reverses the first. |
| Emails | Subject names what happened, the first line says what that means for the reader, the ask follows from that. |
| Teaser card lines | The hook names the cause, the payoff line is its consequence or reversal. |
| Deck lesson blocks | A slide is a block; the lesson follows from the scene above it. |

## Worked example

From the 2026-11-06 restructure of `/blog/google-vs-cloudflare-ai-adoption-risk-appetite`. The facts did not change; the joints did.

Before, the TL;DR opened with two facts beside each other:

> Alphabet grew revenue 25%, yet its market value still fell about $510 billion in a single day. Investors feared AI Mode would shrink the search ad inventory that funds a capital budget which more than doubled in a year.

After, the same facts as a chain the reader can follow one link at a time:

> Google's AI Mode answers the question before the reader reaches the ads, in the same year Alphabet's capital spending more than doubled to $51.4 billion.
> Investors feared the product would shrink the search ad inventory that pays for that build-out, so Alphabet lost about $510 billion of value in a single day despite 25% revenue growth.
> Cloudflare sells AI per request, on models other labs trained, so its AI revenue grows with every call and no older product loses a sale.
> That leaves the two companies with opposite downside, and only one of them can afford to cut into its own best business on purpose.

The first draft of that rewrite carried labels ("This happened:", "Therefore:", "But:") and a body "Therefore" at a paragraph start. The owner's correction: the chain is the rule, the vocabulary is not. The labels came out and the chain stayed.

In the body, the docs section had read as three findings in a row. Rewritten, it opens on the assumption ("Cloudflare's reputation for AI adoption rests on documentation an agent can read in one fetch. We assumed that was the edge, so we checked it with curl"), the bullets are the evidence, and the paragraph after them is the reversal and its consequence ("the first half held, but two claims did not survive the check, so the gap comes from price and setup more than from documentation").

## Three tests before saving

1. **The swap test.** Take any two adjacent paragraphs and imagine them in the other order. If nothing breaks, there is no link between them. Find the cause one of them is missing, or move one.
2. **The first-words test.** Read only the first clause of every paragraph in a section, top to bottom. It should read as a short argument on its own. If it reads as a table of contents, the section is a list.
3. **The label test.** Search the draft for "Therefore", "This happened", "But:" and "And then". A label or a second literal "Therefore" means the rule is showing; rewrite the joint so the reader supplies the link.

The swap test is a reading, not a regex, so no test in `tests/Unit` enforces it. Run it by hand on every H2 section and on the TL;DR, and say in the report which section needed the most reordering. The other two tests are mechanical: `php scripts/rechain-check.php before.html draft.html first-words` lays out the first clause of every paragraph per H2, and `php scripts/rechain-check.php before.html draft.html` runs the label test alongside the counts a joints-only rewrite must keep. Applying the rule to a published post is `/rechain` (this skill), with a project adapter supplying the fetch, load and ship steps.
