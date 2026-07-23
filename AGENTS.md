# Agent instructions

For AI coding agents. Human contributors: see `README.md`.

## Branch from fresh `main`

```bash
git fetch origin && git checkout -b <branch> origin/main
```

A stale base fails CI's `npm-build` "Check build changes" job or DCO, and a
refactor may have moved the code you're about to edit. You'll re-check `main`
before opening the PR too — see below.

## One concern per commit, signed off

`git commit -s` — CI enforces DCO. A functional change, a cleanup, and a docs
update are three commits. Confident AI output still needs splitting: a
flawless-looking helper and a real bug can come from the same commit.

## Commit source only

The `nextcloud-command` bot compiles assets via `/compile` on the PR. Build
locally to test, then hand the tree back clean:

```bash
npm run build
git restore --staged js css
```

`.githooks/pre-commit` catches staged `js/` or `css/` as a second line of
defence — unstage them yourself and it never has to fire.

## Reuse before you write

Preference order: an existing `@nextcloud/vue` component, an existing design
token, an existing pattern in `src/`. Each keeps you on the design system that
the library already maintains.

Tokens: `var(--color-...)`, `var(--border-radius)`,
`var(--default-clickable-area)`, `var(--default-grid-baseline)`. Treat
`node_modules/@nextcloud/vue/dist/assets/*.css` as a partial list — many tokens
are defined server-side, so search there and assume more exist. Where no token
matches, use the literal value: an undefined `var()` doesn't fall back to
anything sensible, the declaration is just dropped.

## Separate concerns by default

Give each responsibility its own unit. If you can name what a block of markup
or logic *does* — validates a filename, formats a timestamp, renders a share
row — that name is the component or function it should become, at one call site
or five. Prefer small units with explicit inputs (props, arguments) over logic
inline in a large `<script setup>`; see `src/utils/` and `src/components/` for
the shape this repo already uses.

Two things follow:

- **Naming is the test — and the bar is a specific, honest name, not just any
  name.** Almost any block can be given *some* label; that's not the test.
  If the only name you can find is generic (`handleStuff`, `MiscWrapper.vue`)
  **or** merely plausible-sounding without actually describing one clear
  responsibility, the split is along the wrong seam — find the boundary that
  earns a real name, or leave it inline until one emerges.
- **Extraction is its own commit** when you're separating duplication or
  responsibilities that predate your change. A unit that exists only because of
  the feature you're building belongs in the feature commit.

## Before opening a PR

```bash
git fetch origin && git log --oneline HEAD..origin/main   # rebase if non-empty
npm run lint
npm run stylelint
npm run test:unit
npm run build   # then unstage js/ css/
```
