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

### 2. Enable the app

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
| `SupportsLocks` | dynamic | `true` when an NC lock provider is available |
| `SupportsGetLock` | `true` | GetLock is implemented |
| `SupportsExtendedLockLength` | `true` | `lock_id` column is `VARCHAR(1024)` |
| `SupportsRename` | per session | `true` for authenticated users; `false` for guests |
| `UserCanRename` | per session | `true` for authenticated users; `false` for guests |
| `UserCanNotWriteRelative` | `true` | PutRelativeFile deferred to Phase 6 |
| `UserCanWrite` | per token | stamped at token-issue time from file/share permissions |
| `IsAnonymousUser` | per session | `true` for guest (share-link) sessions |
| `HasContentRange` | `true` | partial file reads via HTTP Range are supported |
| `HideExportOption` | per token | derived from share `hide_download` flag |
| `DisablePrint` | per token | derived from share `hide_download` flag |
| `DisableExport` | per token | derived from share `hide_download` flag |

---

## Public share support

Share link visitors (`/s/{token}`) receive a guest WOPI token via `ShareController`.
File access, locking, and `CheckFileInfo` flags (`HideExportOption`, `DisablePrint`,
`UserCanWrite`, etc.) are all derived from the share's permissions and `hide_download`
flag at token-issue time.

**Known gaps:**

- **KG1** — Password-protected shares: users must authenticate at `/s/{token}` before
  navigating to the editor.
- **KG2** — Authenticated users arriving through share links receive guest tokens.
- **KG3** — Federated/remote shares not tested.

---

## Architecture notes

The WOPI token row (`oc_office_wopi`) is the authority for per-session flags
(`canwrite`, `hideDownload`, `ownerUid`). Flags are stamped at token-generation
time and not re-read on subsequent WOPI requests — this avoids a per-request
`IShareManager` lookup on every CheckFileInfo heartbeat, matching the richdocuments
pattern. Trade-off: share revocation mid-session is not enforced within the token TTL
(10 h).
