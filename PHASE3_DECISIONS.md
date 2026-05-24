# Phase 3 — Design Decisions

**Date:** 2026-05-23  
**Scope:** WOPI share-link / guest token support

---

## D1 — User lock demotes guest write access

**Decision:** Yes — if a `TYPE_USER` lock exists on a file whose owner ≠ `editorUid`, the guest's `UserCanWrite` is forced to `false` in CheckFileInfo.

**Rationale:** `editorUid` is `null` for all guests, so they always fail the owner check. This is the safest default and matches both richdocuments and eurooffice-nextcloud behaviour. Two parties (a user and a guest) writing to the same file simultaneously would risk data loss.

---

## D2 — Share-level flags stored on the token row

**Decision:** `hide_download` is stamped onto the `oc_office_wopi` row at token-generation time (migration `1002`). It is never re-read from the share on subsequent requests.

**Rationale:** The `oc_office_wopi` token row is the right boundary — it is already the authority for `canwrite`, `owner_uid`, etc. Storing flags here avoids a per-request `IShareManager` lookup on every CheckFileInfo heartbeat. This is the richdocuments pattern.

**Trade-off accepted:** Share revocation mid-session is not enforced. A deleted/revoked share leaves its issued tokens valid until they expire (10 h TTL). Acceptable for Phase 3; can be revisited if needed.

---

## D3 — Share revocation window accepted

**Decision:** No per-request share re-validation. See D2.

**Rationale:** Adds latency and couples the WOPI token lifetime to the share object. The 10 h token TTL bounds the exposure window. eurooffice-nextcloud (which re-validates per request) uses a different architecture with no WOPI token table — not applicable here.

---

## D4 — Guests may issue WOPI locks (same as authenticated users)

**Decision:** No special guest check in `executeOperation`. Guests with `canWrite = true` may `LOCK`, `UNLOCK`, and `REFRESH_LOCK`.

**Rationale:** richdocuments allows guests to lock. Blocking guests from locking would cause EO to skip `PutFile` (EO locks before writing), breaking guest editing entirely. The 30-minute lock TTL and `CleanupJob` are the mitigations against lock-and-abandon abuse.

---

## D5 — Share scope for Phase 3

**Decision:** All three types handled in one shot:
- **File shares** — share node is a `File`; returned directly.
- **Folder shares** — share node is a `Folder`; resolved by `fileId` or relative `path` parameter. NC's `Folder::get()` / `getFirstNodeById()` throw `NotFoundException` on traversal — no extra guard needed.
- **Password-protected shares** — checked via `ISession::get('public_link_authenticated')`. Supports both legacy string format and current array-of-IDs format (matches richdocuments).

**Deferred:** Federated/remote shares. Internal user-through-share tokens (authenticated user viewing a share link gets a guest token for Phase 3; full user-token path deferred to Phase 4).

---

## Architecture notes

- `UserCanNotWriteRelative` = `$wopi->isGuest() || $wopi->getHideDownload()` — guests can never write relative (Save As to owner storage), and hideDownload also blocks it.
- `HideExportOption`, `DisablePrint`, `DisableExport` are all derived from `hideDownload` at CheckFileInfo time.
- `generateFileToken` (authenticated users) does not accept a `hideDownload` param — always `false`. Share-level restrictions only apply to the guest path.
- `files_sharing` app is not declared as a formal dependency (matches richdocuments). `IShareManager::getShareByToken()` returns a clean 404 if the share is not found.

---

## Known gaps (deferred)

**KG1 — Password-protected share UI** (P4)  
`ShareController` returns `401 Password required` when the session lacks `public_link_authenticated`. There is currently no redirect to `/s/{shareToken}` or embedded password form. Users must visit `/s/{shareToken}` first, then navigate to the editor URL. A P4 redirect or embedded challenge is needed.

**KG2 — Authenticated-user-through-share** (P4)  
An authenticated NC user visiting a share link currently gets a guest token. richdocuments issues a user token (with the user's own uid) for authenticated visitors. Deferred to P4 — requires a `$userSession->isLoggedIn()` branch in `ShareController`.

**KG3 — Federated/remote share support** (deferred, not P4)  
`ShareController` accepts any share type returned by `IShareManager::getShareByToken()`. Federated shares (TYPE_REMOTE, TYPE_REMOTE_GROUP) are not tested. `getHideDownload()` is confirmed in `OCP\Share\IShare` and returns `false` for non-link shares by default, so no crash risk. Full federated support is out of scope.
