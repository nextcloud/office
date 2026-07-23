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
