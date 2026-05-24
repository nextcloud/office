# Office

A Nextcloud app that integrates Euro-Office as a WOPI host, providing a full-page
editor and a document hub. Nextcloud acts as the WOPI host (file storage, token
authority, lock manager); Euro-Office acts as the WOPI client (rendering, editing).

The overview UI is developed in `feat/euro-office-overview`. This branch adds the
WOPI backend on top of it. For combined local testing see `feat/overview-and-wopi`.

---

## Features

- **Overview page** — browse, filter, search, and create office documents (see overview branch)
- **Full-page editor** at `/apps/office/open?fileId=N`
- **WOPI host implementation** — CheckFileInfo, GetFile, PutFile, Lock/Unlock/RefreshLock
- **Files app integration** — DEFAULT file action for all MIME types advertised by the editor
- **Public share support** — guest tokens for link-share access (Phase 3)
- **Conflict-free close** — editor close returns the user to the overview via `history.back()`

---

## Local development

### Requirements

- [nextcloud-docker-dev](https://github.com/juliushaertl/nextcloud-docker-dev)
- NC ≥ 31
- Node 24 / npm 11
- Euro-Office server reachable from the NC container

### 1. Mount the app into the container

Add to `nextcloud-docker-dev/docker-compose.override.yml`:

```yaml
services:
  nextcloud:
    volumes:
      - /path/to/office:/var/www/html/apps-extra/office
```

Restart the container after saving.

### 2. Enable the app and disable eurooffice

```bash
docker exec -u www-data nextcloud-docker-dev-nextcloud-1 \
  php occ app:enable office

# Disable eurooffice so it does not compete for the DEFAULT file action
docker exec -u www-data nextcloud-docker-dev-nextcloud-1 \
  php occ app:disable eurooffice
```

### 3. Build the frontend

```bash
npm ci
npm run build      # one-off build
npm run watch      # rebuild on file changes
```

Always build with `npm ci` (not `npm install`) so your local bundle matches
the committed `package-lock.json`. `npm install` can pull newer transitive
deps and make a trivial change churn unrelated `@nextcloud/vue` CSS.

### 4. Running tests

```bash
nvm use             # pins Node 24 — Node 25's native localStorage global
                     # silently breaks jsdom under Vitest
npm run test:unit         # one-off run
npm run test:unit:watch   # watch mode
```

Vitest + `@vue/test-utils` + jsdom, configured in `vitest.config.ts`
(standalone from `vite.config.ts` — no shared build config to keep in
sync). `vitest.setup.ts` provides shared mocks for the Nextcloud globals
every component needs outside a running NC page
(`@nextcloud/l10n`/`auth`/`initial-state`/`router`) plus a `ResizeObserver`
stub. `src/test-utils/fixtures.ts` has `makeNode()`/`makeCreator()` factories
for building test data. CI runs the suite on PRs via `test-unit.yml`.

### 5. Committing changes

Commit **source only** (`src/`, `lib/`, …) — do **not** commit the built
`js/`+`css/` bundle. `npm run build`/`watch` regenerates those locally for
testing; leave them uncommitted (`git add src/…`, then `git restore js css`
when you're done). A pre-commit hook (`.githooks/pre-commit`, wired via the
`prepare` npm script — run `npm install`/`npm ci` at least once to activate
it) blocks staged `js/`/`css/` changes locally as a safety net.

CI (`npm-build`) will fail your PR with *"Please recompile and commit the
assets"* — that's expected for a source-only PR. A maintainer then comments
`/compile` on the PR and the `nextcloud-command` bot builds and pushes the
recompiled assets as a `chore(assets): Recompile assets` commit.

---

## How it works

### WOPI flow

```
Browser                  NC (WOPI host)                  Euro-Office (WOPI client)
   |                          |                                   |
   |  GET /apps/office/open   |                                   |
   |------------------------->|                                   |
   |                          | mint WOPI token (TokenManager)    |
   |                          | build editor URL with wopisrc     |
   |   editor iframe / page   |                                   |
   |<-------------------------|                                   |
   |                          |  GET /wopi/files/{id}?token=...  |
   |                          |<----------------------------------|
   |                          |  CheckFileInfo response           |
   |                          |---------------------------------->|
   |                          |  GET /wopi/files/{id}/contents   |
   |                          |<----------------------------------|
   |                          |  file bytes                       |
   |                          |---------------------------------->|
   |   ← editing session →    |                                   |
   |                          |  POST /wopi/files/{id}/contents  |
   |                          |<----------------------------------|
   |                          |  204 No Content                   |
   |                          |---------------------------------->|
```

### Key classes

| Class | Responsibility |
|---|---|
| `EditorController` | Renders editor page; mints WOPI token; builds editor URL from discovery XML |
| `WopiController` | WOPI protocol endpoint — handles all `/wopi/files/` requests |
| `TokenManager` | Creates and validates WOPI tokens; manages token TTL and guest vs user access |
| `DiscoveryService` | Fetches and caches the editor's discovery XML; resolves MIME → action URL |
| `ShareController` | Issues guest tokens for public share links |
| `WopiMapper` / `WopiLockMapper` | Persistence for WOPI tokens and file locks |
| `CleanupJob` | Background job — expires stale locks and tokens |

---

## Editor integration

The overview opens files via NC's file shortlink (`/f/{fileid}`), which
redirects to the Files app and triggers the default file action for the
installed office editor.

To inject a custom editor URL, a backend component can call
`provideInitialState('office', 'editor-url', $url)` before the page renders.
The frontend reads this via `loadState('office', 'editor-url', null)` and, when
present, navigates directly to that URL instead of `/f/{fileid}`.

---

## Public share support (Phase 3)

Share link visitors (`/s/{token}`) receive a guest WOPI token via `ShareController`.
File access, locking, and `CheckFileInfo` flags (`HideExportOption`, `DisablePrint`,
`UserCanWrite`, etc.) are all derived from the share's permissions and `hide_download`
flag at token-issue time.

**Known gaps** — see `PHASE3_DECISIONS.md` for full context:

- **KG1** — Password-protected shares: no redirect to `/s/{token}` password page yet.
  Users must authenticate at `/s/{token}` before navigating to the editor.
- **KG2** — Authenticated users through share links receive guest tokens.
  Full user-token path deferred to Phase 4.
- **KG3** — Federated/remote shares not tested.

---

## Frontend architecture

```
/apps/office
└── PageController::index()       Renders the SPA shell
    └── App.vue
        └── OfficeOverview.vue    Rendering states + wiring (the "launchpad")
            ├── TemplateSection.vue   Template picker, scrollable card list
            ├── FileCard.vue          Grid-view file card
            ├── officeFiles.ts        WebDAV file listing via @nextcloud/files
            ├── templates.ts          Template discovery and file creation
            ├── config.ts             User preference persistence (grid/list view)
            └── utils/
                ├── fileFilters.ts     Mine/shared/all + search + sort (pure)
                ├── fileCategories.ts  Mime → category mapping (pure)
                └── validateFilename.ts
```

---

## Backend architecture notes

The WOPI token row (`oc_office_wopi`) is the authority for per-session flags
(`canwrite`, `hideDownload`, `ownerUid`). Flags are stamped at token-generation
time and not re-read on subsequent WOPI requests — this avoids a per-request
`IShareManager` lookup on every CheckFileInfo heartbeat, matching the richdocuments
pattern. Trade-off: share revocation mid-session is not enforced within the token TTL
(10 h).

Design decisions for Phase 3 are recorded in `PHASE3_DECISIONS.md`.
