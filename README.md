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

The overview opens files via NC's file shortlink (`/f/{fileid}`), which
redirects to the Files app and triggers the default file action for the
installed office editor.

To inject a custom editor URL, a backend component can call
`provideInitialState('office', 'editor-url', $url)` before the page renders.
The frontend reads this via `loadState('office', 'editor-url', null)` and, when
present, navigates directly to that URL instead of `/f/{fileid}`.

---

## Architecture

```
/apps/office
└── PageController::index()       Renders the SPA shell
    └── OfficeOverview.vue        Full Vue 3 SPA
        ├── officeFiles.ts        WebDAV file listing via @nextcloud/files
        ├── templates.ts          Template discovery and file creation
        └── config.ts             User preference persistence (grid/list view)
```
