# Office

A Nextcloud app that provides a dedicated hub for office documents. Users can browse,
filter, search, and create Documents, Spreadsheets, Presentations, and Diagrams from
a single page — without going through the Files app.

---

## Features

- **Overview page** at `/apps/office` — categorised file list with sidebar navigation
- **Filters** — All / Mine / Shared with me
- **Search** — within the active category, with an "Open in Files" escape hatch
- **View toggle** — Grid (thumbnail previews) or List, persisted per user
- **Template creator** — create new files from editor-provided templates
- **Editor integration** — opens files directly in the configured office editor

---

## Local development

### Requirements

- [nextcloud-docker-dev](https://github.com/juliushaertl/nextcloud-docker-dev)
- NC ≥ 33
- Node 24 / npm 11

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
docker exec -u www-data nextcloud-docker-dev-nextcloud-1 \
  php occ app:enable office
```

### 3. Build the frontend

```bash
npm ci
npm run build      # one-off build
npm run watch      # rebuild on file changes
```

---

## Editor integration

The overview opens files using a URL provided by the backend at page load
(`editor-url` via NC initial state). The behaviour depends on which editor
is configured.

### With the WOPI backend

When the WOPI backend is active, `PageController` injects the editor's open
route. Clicking a file navigates directly to the full-page editor, and closing
the editor (`history.back()`) returns the user to the overview.

See the `feat/wopi-phase-4` branch for the WOPI backend implementation.

### With eurooffice (transitional)

When no WOPI backend is present, the app falls back to NC's file shortlink
(`/f/{fileid}`), which redirects to the Files app and triggers eurooffice's
default file action. The editor opens in the Files app context — closing it
returns the user to the Files app, not the overview.

To prevent eurooffice from intercepting opens during testing:

```bash
docker exec -u www-data nextcloud-docker-dev-nextcloud-1 \
  php occ app:disable eurooffice
```

---

## Architecture

```
/apps/office
└── PageController::index()       Renders the SPA shell (no initial state injected)
    └── OfficeOverview.vue        Full Vue 3 SPA
        ├── officeFiles.ts        WebDAV file listing via @nextcloud/files
        ├── templates.ts          Template discovery and file creation
        └── config.ts             User preference persistence (grid/list view)
```

The frontend calls `loadState('office', 'editor-url', null)` on load. When the
state is absent (default), it falls back to the `/f/{fileid}` shortlink. A WOPI
backend branch injects a concrete editor route via `provideInitialState`.
