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

Commit messages are written for a public reviewer who has only the diff —
not for your own notes. If a sentence only makes sense with context outside
this repo, it doesn't belong in the message.

Commits substantially written by an AI agent carry a `Co-Authored-By:`
trailer naming the tool, alongside `Signed-off-by` — use `git commit -s`
so git supplies your identity instead of guessing it.

A new unit lands with its `.spec.ts` sibling in the same commit —
`src/utils/` and `src/components/` pair each unit with one; follow that.

Changing what an existing function returns or accepts isn't done until every
`.spec.ts` that mocks or asserts on it reflects the new shape — `grep` the
function name across `src/**/*.spec.ts` before considering the commit
finished. `npm run test:unit` (see the checklist below) will catch the ones
you miss, but only if you run it before opening the PR, not after.

Fixing a bug in existing logic — same signature, different behavior — still
needs a regression test pinning the corrected behavior, in the same commit
as the fix. Without one, nothing stops the bug from coming back next time
this code is touched, and the fix reads as untested even in a file that
already has specs.

A new interactive element or render branch in a file that already has specs
still needs a test — assert on the rendered effect, not the prop passed into
a mock. If two instances could receive the same input, test that case too.

A red test means one of three things: the code is wrong, the behavior it
pins changed on purpose, or the test itself is wrong — asserting on a mock
instead of real behavior, or flaky. Only the last two justify touching the
test, and both require the commit message to say which it is and why, not
just that the failure went away. Deciding a test is wrong is itself a
contestable call (see below) — if you're not certain, ask rather than
deleting the evidence.

Skipping (`.skip`, `.todo`), deleting a case, or dulling a matcher
(`toEqual` → `toBeTruthy`, dropping an assertion) to turn a failure green
without that justification makes the test stop proving anything; it's the
bug staying in, with the evidence removed.

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

Preference order, before writing new logic of any kind:

1. A feature the server already exposes. Grep `vendor/nextcloud/ocp/OCP/`
   for an existing interface — OCP is Nextcloud's public server API, and
   `composer require-dev` vendors the full stub tree, so it's local and
   searchable even though OCP isn't a runtime dependency of this app. For
   protocol-level features with no OCP interface — DAV `SEARCH`'s
   `orderby`/`limit` is one — there's nothing to grep; if you're not sure
   the server already supports what you need, ask rather than assume it
   doesn't.
2. An `@nextcloud/*` package for the behaviour, not just `@nextcloud/vue`
   for UI. Check `package.json` for what's already a dependency, then the
   wider `@nextcloud/*` npm scope (dialogs, upload, and more) for what
   isn't.
3. An existing `@nextcloud/vue` component or design token, for UI
   specifically.
4. An existing pattern in `src/`.

If the closest match is a partial fit — right shape, wrong semantics, or
costs more to bend into place than it saves — that's grounds to write it
instead, not force it. That's a contestable call (see below): flag it in
the PR description rather than silently picking either way.

Each rung down this ladder is more for us to build, test, and maintain
ourselves for something Nextcloud, or this repo, may already do correctly;
reuse also comes pre-tested, and — for UI — pre-styled, in ways a bespoke
equivalent won't.

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
  the feature you're building — including a component that finally gets split
  up because your addition is what tipped it over — belongs in the feature
  commit, not a dedicated cleanup PR to get to later.

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
- A new dependency pays its way in bundle size and upkeep: check what an
  import drags in, that it's actively maintained (recent releases, no pile
  of unanswered issues), and that its license is compatible — before adding
  it. An abandoned package is a security patch nobody ships.

## When the call is contestable, ask

Two defensible seams from the naming test, a cleanup that would grow the PR,
a security attribute you can justify but a reviewer might not accept, a
dependency whose bundle cost is arguable, an existing feature or component
that's only a partial fit for what you need — anywhere a reviewer could
reasonably want the other option, put the choice to the human driving the
session before committing to it; unattended, take the narrower option — the
one that's easier to reverse in review. Either way, flag the call in the PR
description.

## Before opening a PR

```bash
git fetch origin && git log --oneline HEAD..origin/main   # rebase if non-empty
```

Say in the description which reuse option ("Reuse before you write") you
used, or that none fit and why.

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
composer test:unit
composer openapi   # if controllers or routes changed
                   # commit openapi*.json — unlike js/css, this one is tracked
```
