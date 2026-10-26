# Office

A Nextcloud app that integrates Euro-Office as a WOPI host, providing a full-page
editor and a document hub. Nextcloud acts as the WOPI host (file storage, token
authority, lock manager); Euro-Office acts as the WOPI client (rendering, editing).

---

## Features

- **Full-page editor** at `/apps/office/open?fileId=N`
- **Document overview** — browse, filter, search, and create office documents
- **WOPI host implementation** — see [WOPI spec compliance](#wopi-spec-compliance) below
- **Files app integration** — DEFAULT file action for all MIME types advertised by the editor
- **Public share support** — guest tokens for link-share access
- **Range reads** — partial file delivery via HTTP Range for large documents
- **Conflict-free close** — editor close returns the user to the overview via `history.back()`

---

## Local development

### Requirements

- [nextcloud-docker-dev](https://github.com/juliushaertl/nextcloud-docker-dev)
- NC ≥ 31
- Node ≥ 24 / npm ≥ 11
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

### 2. Install PHP dependencies and enable the app

The app bundles phpseclib for WOPI proof verification; `vendor/` is not in git:

```bash
composer install --no-dev   # releases ship this vendor/ directory
```


```bash
docker compose exec --user www-data nextcloud php occ app:enable office
```

If the Euro-Office connector app is also installed, disable it to prevent it from
competing for the DEFAULT file action:

```bash
docker compose exec --user www-data nextcloud php occ app:disable eurooffice
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
| `WopiAccessResolver` | Resolves a token to its file and current rights; share-link tokens through the live share |
| `WopiProofMiddleware` / `WopiProofVerifier` | Require the editor's proof signature when discovery publishes keys |
| `WopiAllowlistMiddleware` | Optional `wopi_allowlist` of editor addresses for the WOPI endpoints |
| `TokenManager` | Creates and validates WOPI tokens; manages token TTL and guest vs user access |
| `DiscoveryService` | Fetches and caches the editor's discovery XML; resolves MIME → action URL |
| `ShareController` | Issues guest tokens for public share links |
| `WopiMapper` / `WopiLockMapper` | Persistence for WOPI tokens and file locks |
| `CleanupJob` | Background job — expires stale locks and tokens |

---

## Who may call the WOPI endpoints

The WOPI routes are public: the access token authorises the request. Because the token
is in the page of whoever opened the document, the app also checks the caller:

- **Proof keys.** When the editor's discovery publishes a `<proof-key>`, every WOPI
  request must carry a valid `X-WOPI-Proof` / `X-WOPI-ProofOld` signature and an
  `X-WOPI-TimeStamp` no older than 20 minutes, or it gets 403. Collabora always signs;
  Euro-Office signs when its WOPI keys are configured (its images generate them with
  `WOPI_ENABLED=true`, the same switch that turns WOPI on). The integration tests still
  call the WOPI endpoints unsigned, so against an editor with proof keys they get 403
  until the test harness signs its own requests.
- **Allowlist.** `occ config:app:set office wopi_allowlist --value="10.0.0.0/24 192.0.2.7"`
  restricts the WOPI endpoints to those addresses (IPs or CIDR ranges); empty leaves
  them open.

With neither, the admin page shows a warning. The keys are remembered in app config and
rechecked hourly; a proof that doesn't verify triggers one reread (at most every 5
minutes) to pick up regenerated keys. Without `vendor/` (see setup) WOPI requests are
refused and the log says why.

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

## WOPI spec compliance

### Operations

| Operation | `X-WOPI-Override` | Status | Notes |
|---|---|---|---|
| CheckFileInfo | — | ✅ | `GET /wopi/files/{id}` |
| GetFile | — | ✅ | `GET /wopi/files/{id}/contents`; HTTP Range supported |
| PutFile | — | ✅ | `POST /wopi/files/{id}/contents`; lock-enforced, optimistic version check, quota check |
| Lock | `LOCK` | ✅ | |
| Unlock | `UNLOCK` | ✅ | |
| RefreshLock | `REFRESH_LOCK` | ✅ | |
| GetLock | `GET_LOCK` | ✅ | |
| UnlockAndRelock | `LOCK` + `X-WOPI-OldLock` | ✅ | |
| RenameFile | `RENAME_FILE` | ✅ | Authenticated users only; conflict returns 400 + `X-WOPI-InvalidFileNameError` |
| PutRelativeFile | `PUT_RELATIVE_FILE` | ⏳ Phase 6 | `UserCanNotWriteRelative: true` suppresses Save As in the editor UI |
| DeleteFile | `DELETE` | — | Deletion is handled by NC outside WOPI |

### CheckFileInfo capability flags

| Flag | Value | Notes |
|---|---|---|
| `SupportsUpdate` | `true` | PutFile is implemented |
| `SupportsLocks` | `true` | Locks are stored by the app (`office_wopi_locks`), independent of any NC lock provider |
| `SupportsGetLock` | `true` | GetLock is implemented |
| `SupportsExtendedLockLength` | `true` | `lock_id` column is `VARCHAR(1024)` |
| `SupportsRename` | per session | `true` for user tokens; `false` for share-link tokens, including logged-in users whose only access is the link |
| `UserCanRename` | per session | `true` for user tokens; `false` for share-link tokens, including logged-in users whose only access is the link |
| `UserCanNotWriteRelative` | `true` | PutRelativeFile deferred to Phase 6 |
| `UserCanWrite` | per request | the token's write right, narrowed to the share's current permissions for share-link sessions |
| `IsAnonymousUser` | per session | `true` when no Nextcloud user is behind the session (anonymous share-link visitors) |
| `HasContentRange` | `true` | partial file reads via HTTP Range are supported |
| `HideExportOption` | per request | the token's flag or the share's current `hide_download` |
| `DisablePrint` | per request | the token's flag or the share's current `hide_download` |
| `DisableExport` | per request | the token's flag or the share's current `hide_download` |

---

## Public share support

Share link visitors (`/s/{token}`) receive a share-link WOPI token via `ShareController`,
bound to the share. File access, locking, and `CheckFileInfo` flags (`HideExportOption`,
`DisablePrint`, `UserCanWrite`, etc.) follow the share's current permissions and
`hide_download` flag on every request.

**Known gaps:**

- **KG1** — Password-protected shares: users must authenticate at `/s/{token}` before
  navigating to the editor.
- **KG2** — Authenticated users arriving through share links get a user token when the
  file is in their own folder, and otherwise a share-link token that names them (after the
  link password, if the share has one). Versions and activity don't record them as the
  author yet: WOPI requests set no user scope.
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

The WOPI token row (`oc_office_wopi`) stamps `canwrite` and `hideDownload` when the
token is issued. `WopiAccessResolver` resolves each request's file and effective
rights: user tokens through the editor's own folder; share-link tokens (with a
`share_token`) through the live share, as richdocuments does, so deleting, expiring
or narrowing a share takes effect on the next WOPI request rather than when the
token expires (10 h).
