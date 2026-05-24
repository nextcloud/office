# Office

A Nextcloud app that integrates Euro-Office as a WOPI host, providing a document hub
and full-page editor. Nextcloud acts as the WOPI host (file storage, token authority,
lock manager); Euro-Office acts as the WOPI client (rendering, editing).

> **Note:** This branch (`feat/overview-and-wopi`) is a combined test branch that
> merges `feat/euro-office-overview` and `feat/wopi-phase-4`. It is not a development
> target — work happens on those two branches independently.

---

## Features

- **Overview page** at `/apps/office` — browse, filter, search, and create Documents,
  Spreadsheets, Presentations, and Diagrams from one place
- **Filters** — All / Mine / Shared with me
- **Search** — within the active category, with an "Open in Files" escape hatch
- **View toggle** — Grid (thumbnail previews) or List, persisted per user
- **Template creator** — create new files from editor-provided templates
- **Full-page editor** at `/apps/office/open?fileId=N`
- **WOPI host implementation** — CheckFileInfo, GetFile, PutFile, Lock/Unlock/RefreshLock
- **Files app integration** — DEFAULT file action for all MIME types advertised by the editor
- **Public share support** — guest tokens for link-share access (Phase 3)
- **Clean navigation** — editor close (`history.back()`) returns the user to the overview

---

## Local development

### Requirements

- [nextcloud-docker-dev](https://github.com/juliushaertl/nextcloud-docker-dev)
- NC ≥ 33
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

---

## How it works

### User flow

1. User navigates to `/apps/office`
2. Overview lists all office files, grouped by type
3. User clicks a file → navigated to `/apps/office/open?fileId=N`
4. `EditorController` mints a WOPI token and builds the editor URL
5. Euro-Office loads the file via the WOPI protocol
6. User closes the editor → `history.back()` returns to the overview

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
| `PageController` | Renders overview page; injects editor URL into NC initial state |
| `EditorController` | Renders editor page; mints WOPI token; builds editor URL from discovery XML |
| `WopiController` | WOPI protocol endpoint — handles all `/wopi/files/` requests |
| `TokenManager` | Creates and validates WOPI tokens; manages token TTL and guest vs user access |
| `DiscoveryService` | Fetches and caches the editor's discovery XML; resolves MIME → action URL |
| `ShareController` | Issues guest tokens for public share links |
| `WopiMapper` / `WopiLockMapper` | Persistence for WOPI tokens and file locks |
| `CleanupJob` | Background job — expires stale locks and tokens |

---

## Public share support (Phase 3)

Share link visitors (`/s/{token}`) receive a guest WOPI token via `ShareController`.
File access, locking, and `CheckFileInfo` flags (`HideExportOption`, `DisablePrint`,
`UserCanWrite`, etc.) are derived from the share's permissions and `hide_download`
flag at token-issue time.

**Known gaps** — see `PHASE3_DECISIONS.md` for full context:

- **KG1** — Password-protected shares: no redirect to `/s/{token}` password page yet.
  Users must authenticate at `/s/{token}` before navigating to the editor.
- **KG2** — Authenticated users through share links receive guest tokens.
  Full user-token path deferred to Phase 4.
- **KG3** — Federated/remote shares not tested.
