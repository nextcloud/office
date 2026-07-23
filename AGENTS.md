# Agent instructions

Instructions for AI coding agents (Claude Code and others) working in this repo.
Human contributors: see `README.md`.

## Before starting work

Sync with `origin/main` before branching and again before opening a PR. Branch
from a stale base and CI's `npm-build` "Check build changes" job, DCO check, or
plain merge conflicts will fail for reasons unrelated to your change — and a
refactor may have moved the code you're about to edit somewhere else entirely.

```bash
git fetch origin
git checkout -b <branch> origin/main
```

If you've been working for a while, `git fetch origin && git log --oneline
HEAD..origin/main` before pushing — rebase if `main` has moved.

## Commit atomically

One concern per commit: a functional change, a cleanup, and a docs update are
three commits, not one. Do not bundle an unrelated fix into a feature commit
because it happened to be nearby. A reviewer (human or bot) diffing a single
concern can actually verify it; a "fix + refactor + docs" commit can't be
reviewed, only trusted.

This applies to AI-authored changes as much as human ones. Confident,
well-commented AI output still needs to be split and verified per concern —
a flawless-looking helper and a real bug can come from the same commit.

## Sign off every commit

`git commit -s`. DCO is enforced by CI; a missing `Signed-off-by:` trailer
fails the PR regardless of how correct the change is.

## Never hand-commit build artifacts

Do not stage or commit changes under `js/` or `css/` — see `README.md`
("Committing changes") for the full flow. Source only; the `nextcloud-command`
bot recompiles and commits assets via `/compile` on the PR. `.githooks/pre-commit`
blocks this locally as a backstop, but don't rely on the hook catching it —
run `npm run build` for local testing, then `git restore --staged js css`
(or don't stage them in the first place) before committing.

## Reuse CSS variables and existing structure

Prefer existing design tokens (`var(--color-...)`, `var(--border-radius)`,
`var(--default-clickable-area)`, `var(--default-grid-baseline)`, etc.) over
hardcoded pixel values or colours. Before sizing or styling a new element,
check how a comparable element elsewhere in `src/` does it, and how the
`@nextcloud/vue` component it sits next to sizes itself (its compiled CSS is
under `node_modules/@nextcloud/vue/dist/assets/*.css` — many tokens
themselves are defined server-side, not in this package, so a grep miss
there isn't proof no token exists). If you genuinely can't find a matching
token, use the real value — never invent a `var(--something)` that isn't
defined anywhere, it silently resolves to nothing.

## Extract components over duplicated template blocks

When the same markup — or near-identical markup differing only in
props/size — appears at multiple call sites *and* a future change (a bug
fix, a new prop, a behaviour tweak) would need to be applied at every one
of them to stay correct, extract it into its own component rather than
copy-pasting. Two call sites can already justify this for UI markup, where
divergence is easy to introduce by only fixing one copy. Don't extract for
a single call site, and don't extract just because two blocks currently
look similar if that similarity is coincidental rather than a shared
responsibility — that's premature abstraction, not reuse.

Extraction is a refactor. If you're pulling apart duplication that already
existed before your change, do that as its own commit, separate from the
functional change that prompted you to notice it (see "Commit atomically"
above). If the component is new and only exists because of the feature
you're building, it belongs in that feature's commit — you're not
separating pre-existing duplication, you're introducing the abstraction as
part of the change itself.

## Prefer existing NC/Vue libraries over hand-rolled markup

Check whether `@nextcloud/vue/components` (or another already-installed
dependency) already covers what you're building before writing raw
HTML/CSS for it. Hand-rolled UI drifts from the design system over time and
duplicates maintenance that the library already carries.

## Structure code so it's easily unit-testable

Prefer small, focused functions/components with explicit inputs (props,
arguments) over logic buried inline in a large `<script setup>` or template,
which is often reachable only through a full component mount with heavy
stubbing — see `src/utils/` and `src/components/` for the pattern this repo
already uses. But don't extract solely to make something testable: if the
only caller of the new function or component would be its own test, it
didn't need extracting. Extract when the logic has a real responsibility of
its own (validation, formatting, a distinct UI piece) that composes into
the larger component either way — testability is a side effect of that,
not the reason for it.
