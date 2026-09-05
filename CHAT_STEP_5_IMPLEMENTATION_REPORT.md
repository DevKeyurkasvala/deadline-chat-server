# Chat STEP 5 — P4 Rich Messaging

Status: **complete for review**. STEP 6 was not started.

PHP remains the source of truth. Node only relays events after persistence.

---

## Audit findings

- `chat_messages` already had `edited_at` and `deleted_at`. Those are reused.
- No reply, forward, attachment, or reaction tables existed.
- Task uploads (`uploads/tasks/` + `TASK_ALLOWED_MIME`) allow zip/office and live under a web path. **Not reused.** Chat uses a private `storage/chat/` tree and a stricter allowlist.
- `finfo` is available. `getimagesize()` is used for image validation.
- Role 3 (`task_require_not_view_user`) blocks send/edit/delete/forward. Reactions are **not** treated as message mutation, so view-only users may react.
- There is no existing chat moderator permission. Admins cannot edit/delete other users’ chat messages.
- No existing chat audit logger. Edit/delete/forward/upload logging is **deferred** (not a P6 system).
- PHP upload ini is not trusted; application limits are enforced.

---

## Files changed

| File | Change |
| --- | --- |
| `chat-step5-rich-messaging.sql` | Additive schema |
| `install_chat_step5.php` | Idempotent CLI installer |
| `create_chat.sql` | New installs include rich columns/tables |
| `includes/chat_rich.php` | **New** attachment/reaction/edit/delete/forward |
| `includes/chat_lib.php` | Hydration, send with files/replies, search metadata |
| `includes/chat_realtime.php` | New emit helpers |
| `includes/chat_settings.php` / `chat_config.php` | 15-minute edit window |
| `apiv1/chat/send_message.php` | Multipart attachments + reply |
| `apiv1/chat/react.php` | Toggle reaction |
| `apiv1/chat/edit_message.php` | Own-message edit |
| `apiv1/chat/delete_message.php` | Soft delete |
| `apiv1/chat/forward_message.php` | ACL-checked forward |
| `apiv1/chat/download_attachment.php` | Authorized stream |
| `storage/chat/.htaccess` | Deny all direct access |
| `chat.php`, `chat.js`, `chat.css`, `chat-realtime.js` | UI + realtime |
| `chat-server/security.js` | New emit events |
| `chat-server/p4_php_test.php`, `p4_security_test.js` | P4 tests |
| `backups/chat_pre_step5.sql` | Pre-migration snapshot |

---

## APIs added

| Endpoint | Method | CSRF |
| --- | --- | --- |
| `apiv1/chat/react.php` | POST | yes |
| `apiv1/chat/edit_message.php` | POST | yes |
| `apiv1/chat/delete_message.php` | POST | yes |
| `apiv1/chat/forward_message.php` | POST | yes |
| `apiv1/chat/download_attachment.php` | GET | no (session ACL) |

## APIs modified

`POST apiv1/chat/send_message.php` — optional `attachments[]`, `reply_to_message_id`; empty body allowed only with files.

`GET apiv1/chat/fetch_messages.php` — history includes deleted placeholders; batch-hydrates attachments, reactions, reply previews.

`GET apiv1/chat/search.php` — still excludes deleted bodies; adds reply/forwarded metadata.

---

## DB migration

Applied in this environment after `backups/chat_pre_step5.sql`.

Pre-migration counts: conversations 2, participants 4, messages 0.

Added:

- `chat_messages.reply_to_message_id`
- `chat_messages.forwarded_from_message_id`
- indexes `idx_chat_reply`, `idx_chat_forward`
- `chat_attachments`
- `chat_message_reactions`

No drops, truncates, or ID changes. Existing chat rows remain intact.

Installer is re-runnable: `php install_chat_step5.php`

---

## Attachment security

Allowlist only:

- Images: jpeg, png, gif, webp
- Documents: pdf, txt

Blocked: php*, phtml, phar, cgi, pl, py, sh, js, html, htm, svg, executables.

Validation: Fileinfo MIME + extension map + magic bytes (PDF `%PDF`, reject `<?` in txt) + `getimagesize()` for images.

Limits: 10 MB / file, 5 files, 25 MB total. 413/415/422 on violation.

Storage: `storage/chat/YYYY/MM/{random}.ext`. Original filename is never the disk name. Public DTO has no path. Download endpoint checks ACL, `realpath` prefix, and deleted parent message.

Task chat attachments still require `canCommentTask` to send. Download requires `canViewTask` / conversation access. Unauthorized download is 404.

Orphans: if attachment insert fails after write, the file is unlinked.

---

## Reactions

Allowlist: 👍 ❤️ 😂 😮 😢 😡 🎉

Toggle on unique `(message_id, user_id, reaction)`.

View-only may react. Cannot react to deleted/inaccessible messages.

PHP persists, then emits `chat:reaction` with counts + `mine`.

---

## Replies

`reply_to_message_id` must be in the same conversation. Cross-conversation IDs are rejected. When there is no reply, the column is stored as `NULL` (not `0`).

Payload includes a short preview. Deleted originals show “Original message deleted” without body.

---

## Edit / delete

- Own message only. No invented moderator role.
- Server window: 900 seconds from `created_at` (`CHAT_EDIT_WINDOW_SECONDS`).
- Empty edit rejected unless attachments exist.
- Soft delete sets `deleted_at` and clears `body` so search cannot leak it.
- Deleted messages cannot be edited or reacted to.
- UI: edited indicator / “Message deleted”.
- Attachments stay on disk; they are hidden after delete. Physical cleanup is deferred (not a P6 retention system).

---

## Forwarding

Creates a new message in the target conversation with `forwarded_from_message_id`.

Source and target must be accessible; sender must be allowed to send to the target.

Attachments are **copied** into new private files for the new message (not a public link to the source file).

Uses existing new-message notifications. Source conversation is not notified.

---

## Realtime events

Trusted events from PHP `/internal/emit` only:

- `chat:message` (send / forward)
- `chat:reaction`
- `chat:message_edited`
- `chat:message_deleted`

Clients cannot emit these. Node must be restarted to accept the new allowlist. Until restart, persistence still succeeds; emit may return HTTP 400.

Polling/fetch hydrates the same rich fields. DOM updates by message id (no duplicates).

---

## Notifications

Unchanged. New messages (including replies/forwards) notify as before. No notifications for reactions, edits, deletes, typing, or read receipts.

---

## Search / pagination

- Search uses current (edited) body.
- Deleted messages are excluded from search.
- Reply/forward metadata is included when present.
- History/around/older include deleted placeholders and batch-load related data (no N+1).

---

## Performance

One attachments query, one reactions query, one reply-preview query per page (≤100 ids).

---

## Tests

| Suite | Result |
| --- | --- |
| PHP syntax | PASS |
| P0–P3 PHP | PASS |
| P4 PHP | PASS |
| P0–P4 Node | PASS |

Browser E2E: **NOT RUN — Apache 403** (`/deadline/chat.php`). Site-wide 403 also means a direct `storage/chat` URL cannot be distinguished in this environment; `.htaccess` still denies that directory.

---

## Deployment

1. Backup chat tables.
2. `php install_chat_step5.php`
3. Deploy PHP/JS/CSS and `storage/chat/.htaccess`.
4. **Restart Node** (required for `chat:reaction` / edit / delete emits).
5. Confirm `storage/chat` is not a public alias.

---

## Remaining risks / deferred

- Node emit allowlist is process-local; old Node processes reject new events until restart.
- Attachment-only forward copies files; disk use grows.
- Deleted attachments are hidden, not purged.
- No chat audit log (no existing safe hook).
- Hard browser disconnect still depends on Socket.IO ping timeout for presence (STEP 4).
- Apache E2E not run.

Not implemented (later steps): mentions, groups, rooms, Redis, comments unification, retention, analytics.

---

## STOP

STEP 5 is finished and ready for review.

Do not start STEP 6 until this report is approved.
