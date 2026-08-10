# Agent instructions

For AI coding agents. Human contributors: see `README.md`.

## Branch from fresh `main`

```bash
git fetch origin && git checkout -b <branch> origin/main
```

CI builds your branch merged with current `main`, so a stale base can fail the
`npm-build` "Check build changes" job even after a recompile, and a refactor
may have moved the code you're about to edit. You'll re-check `main` before
opening the PR too — see below.

## Commit discipline

One concern per commit: a functional change, a cleanup, and a docs update are
three commits. A reviewer can verify a single-concern diff; a mixed one can
only be trusted.

CI checks every commit: conventional-format headline (`fix:`, `feat:`,
`docs:`, ...) and DCO sign-off — `git commit -s`.

A new unit lands with its `.spec.ts` sibling in the same commit —
`src/utils/` and `src/components/` pair each unit with one; follow that.

Changing what an existing function returns or accepts isn't done until every
`.spec.ts` that mocks or asserts on it reflects the new shape — `grep` the
function name across `src/**/*.spec.ts` before considering the commit
finished. `npm run test:unit` (see the checklist below) will catch the ones
you miss, but only if you run it before opening the PR, not after.

Confident AI output still needs splitting: a flawless-looking helper and a real
bug can come from the same commit.

## Commit source only

Build locally to test, then hand the tree back clean:

```bash
npm run build
git restore --staged js css
```

`js/` and `css/` are build output; the `nextcloud-command` bot compiles and
commits them to your branch after a maintainer comments `/compile` on the PR —
say in the description that it's still needed. `.githooks/pre-commit` is a
backstop, not a plan.

## Reuse before you write

Preference order: an existing `@nextcloud/vue` component, an existing design
token, an existing pattern in `src/`.

Each keeps you on the design system the library already maintains; hand-rolled
equivalents drift from it and duplicate maintenance.

Tokens: `var(--color-...)`, `var(--border-radius)`,
`var(--default-clickable-area)`, `var(--default-grid-baseline)`. Treat
`node_modules/@nextcloud/vue/dist/assets/*.css` as a partial list — many tokens
are defined server-side, so search there and assume more exist. Where no token
matches, use the literal value.

An undefined `var()` doesn't fall back to an earlier declaration in the same
rule — the property resets to its inherited or initial value, so a hardcoded
line above the token is not a safety net.

## Separate concerns by default

Give each responsibility its own unit. If you can name what a block of markup or
logic *does* — validates a filename, formats a timestamp, renders a share row —
that name is the component or function it should become, at one call site or
five. Prefer small units with explicit inputs (props, arguments) over logic
inline in a large `<script setup>`; see `src/utils/` and `src/components/` for
the shape this repo already uses.

Two things follow:

- **Naming is the test.** The name has to be specific and honest: if the best
  you can find is `handleStuff` or `MiscWrapper.vue`, or the accurate name needs
  an "and" in it, the seam is wrong. Find the boundary that earns a real name,
  or leave it inline until one emerges.
- **Extraction is its own commit** when you're separating duplication or
  responsibilities that predate your change. A unit that exists only because of
  the feature you're building belongs in the feature commit.

## Never loosen a security check

Auth here is deliberate: `#[NoAdminRequired]` and `#[NoCSRFRequired]` on a
controller method are security decisions, not boilerplate — adding one to fix
a 403 needs a reason a reviewer can check. The same goes for widening a token
TTL, scope, or validation to make a test or client pass: that is the
authentication being loosened.

No tokens, secrets, or user file paths in logs or error messages. Render
user-controlled strings as text, never markup. Psalm findings get fixed, not
baselined.

## Accessibility is a gate, not polish

Enterprise deployments audit it; regressions block releases.

`@nextcloud/vue` components ship accessible semantics — one more reason
hand-rolled markup loses. On top of that: every interactive element gets an
accessible name (icon-only buttons need `aria-label`), state is never colour
alone, and anything clickable is keyboard-reachable with visible focus.

Nothing enforces this yet — `npm run lint` carries no accessibility rules —
so check the list above by hand before the PR.

## Bound work and memory

- No unbounded reads: cap or paginate anything that grows with a user's file
  count, in queries and in rendered rows.
- Release what you register: listeners, timers, and observers added in a
  component are removed in `onUnmounted` — see `TemplateSection.vue`.
- Stream file contents; never read a whole document into memory — office
  files have no upper size.
- A new dependency pays its way in bundle size: check what an import drags in
  before adding it.

## When the call is contestable, ask

Two defensible seams from the naming test, a cleanup that would grow the PR,
a security attribute you can justify but a reviewer might not accept, a
dependency whose bundle cost is arguable — anywhere a reviewer could
reasonably want the other option, put the choice to the human driving the
session before committing to it; unattended, take the narrower option — the
one that's easier to reverse in review. Either way, flag the call in the PR
description.

## Before opening a PR

```bash
git fetch origin && git log --oneline HEAD..origin/main   # rebase if non-empty
```

If the change touches `src/` (JS/Vue/CSS):

```bash
npm run lint
npm run stylelint
npm run test:unit
npm run build   # then unstage js/ css/
```

If the change touches PHP:

```bash
composer lint
composer cs:check
composer psalm
composer openapi   # if controllers or routes changed
                   # commit openapi*.json — unlike js/css, this one is tracked
```
