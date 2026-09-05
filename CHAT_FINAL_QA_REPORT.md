# Chat Final QA Report (STEPS 7–9 / P6–P8)

Generated after P6 → P6 tests → P7 → P7 tests → P8 → attempted browser QA → security/regression/integrity.

## 1. Architecture summary

PHP remains the source of truth. Node Socket.IO (port 3015) is a realtime relay only.

- Auth: `$_SESSION['id']`, role 1 / 2 / 3.
- CSRF: `X-Chat-CSRF` via `chat_api_boot()`.
- Rooms: `conversation:{id}` and `user:{id}` with PHP-signed tokens.
- Presence: in-memory `PresenceRegistry`. Single-instance only.
- Conversations: `task`, `direct`, `project`, `client` (service rooms use `service_type_id`; `0` = client-wide).
- Attachments: private `storage/chat/` + ACL download.

No React/Vue migration. No replacement of AdminLTE/jQuery. No new permission model.

## 2. Database changes

Additive only (`php install_chat_step7.php`):

- `chat_audit_log` (append-oriented).
- `idx_chat_msg_deleted_created` on `chat_messages(deleted_at, created_at)`.
- `task_settings` seeds: `chat_retention_enabled=0`, purge-day keys `0`.

P7 added no indexes. Existing `idx_chat_conv_msg`, `idx_chat_user`, `idx_chat_type_last` already match list/history/unread patterns.

Pre-step7 backup: `backups/chat_pre_step7.sql` (counts: conversations=3, participants=5, messages=1).

## 3. APIs added

- `GET apiv1/chat/audit.php` — admin, cursor pagination, filters.
- `POST apiv1/chat/audit_export.php` — admin CSV, CSRF.
- `POST apiv1/chat/retention_settings.php` — admin, CSRF.
- `GET apiv1/chat/health.php` — authenticated health, no secrets/paths.

## 4. APIs modified

- `GET apiv1/chat/fetch_conversations.php` — `limit`, `before_id`, `has_more`, `next_before_id`. Existing callers still receive `conversations` + `unread_count`.
- Send/edit/delete/forward/react/download/open paths now write high-value audit events.

## 5. Security changes

- Audit/export/retention require `task_is_admin()`.
- Audit metadata is bounded and excludes bodies, paths, tokens, secrets.
- Health requires a session.
- Internal emit/presence unchanged: POST, JSON, 64kb, secret, timing-safe compare, IP allowlist, event/room allowlist.
- Node still rejects client-authored persistence events.
- Upload blocklist unchanged (php/phtml/phar/js/html/svg/executables).
- Storage `.htaccess` still `Require all denied`.

## 6. Audit implementation

See `CHAT_STEP_7_IMPLEMENTATION_REPORT.md`. Admin UI: `chat_audit.php` (sidebar Settings → Chat Audit). Pagination mandatory.

## 7. Retention implementation

OFF by default. CLI worker only. Lock + batches. File deletes use DB `storage_path` + `chat_storage_root()`. Unlink failure skips that message. No web-request deletion.

## 8. Performance changes

Batched conversation hydration and notification lookup. Cursor list pagination. No FULLTEXT. No OFFSET for message history.

Actual measured CLI results (see STEP 8 report): list 250 ms / 3 rows on first run; later 888 ms on the same corpus. Do not treat these as SLAs.

## 9. Scaling changes

None implemented. Redis was evaluated and rejected. Node health now states `presence_mode=memory`, `multi_instance=false`. Restart Node after deploy to expose those fields on the live process.

## 10. UX changes

Loading / empty / error / retry on conversations, messages, search, task chat. Search highlighting remains escaped. Reply jump. Attachment size + progress.

## 11. Mobile changes

CSS for 320–414 and tablet/desktop. Mobile shows list or thread, not both. Back button. Actions remain visible (not hover-only).

## 12. Accessibility changes

Aria labels, live regions, focus-visible, visible control labels, mention listbox role, keyboard mention navigation.

## 13. Test results

| Suite | Result |
|---|---|
| P0 PHP | PASS |
| P1 PHP | PASS |
| P2 PHP | PASS |
| P3 PHP | PASS |
| P4 PHP | PASS |
| P5 PHP | PASS |
| P6 PHP | PASS |
| P7 PHP | PASS |
| P7 performance | PASS (measured) |
| P8 PHP | PASS |
| Final security PHP | PASS |
| Integrity | PASS (one documented NOTE) |
| PHP syntax (touched files) | PASS |
| P0 Node | PASS |
| P1 Node | PASS |
| P2 Node | PASS |
| P3 Node | PASS |
| P4 Node | PASS |
| P5 Node | PASS |
| P6 Node | PASS |
| P7 Node unit | PASS |
| P7 Node live | PASS |
| P8 Node | PASS |

DB-unavailable process kill: **NOT RUN** (shared production-like MariaDB).
Concurrent worker lock under load: **NOT RUN** beyond source + single-process OFF rerun.
Full upload MIME matrix in browser: **NOT RUN**.

## 14. Browser E2E status

**NOT RUN — Apache 403**

`curl -sI http://127.0.0.1/deadline/chat.php` returned HTTP 403. Desktop Chrome and 375/390 mobile simulation were not executed. No browser-tested claim is made.

## 15. Known limitations

- Single-instance Node. Presence is lost on Node restart until clients reconnect.
- Conversation list ACL is still evaluated per row after the SQL page (correctness over skipping authorization).
- Search remains bounded `LIKE`, not full-text.
- One existing message (`id=3`, conversation `2`) stores `reply_to_message_id=0` instead of NULL. Application treats 0 as no-reply. **Not repaired.**
- Live Node process during QA predated `presence_mode` in `/health`. Source and installer are updated; restart Node to expose the field.
- Automated backups remain manual SQL under `backups/`.
- Session cookie flags were not changed application-wide.

## 16. Deployment requirements

1. Backup chat tables.
2. `php install_chat_step7.php` (idempotent).
3. Deploy PHP, JS, CSS, cron worker, `.env.example` updates.
4. Confirm `storage/chat/.htaccess` denies web access and the directory is writable by PHP.
5. Restart Node so `/health` and emit allowlists match this code.
6. Do **not** enable `chat_retention_enabled` unless a retention policy is approved.
7. Schedule `php cron/chat_retention_worker.php` only after policy review.
8. Keep `/internal/emit` and `/internal/presence` off the public internet.
9. PHP and Node must share `CHAT_INTERNAL_SECRET`.

## 17. Rollback procedure

1. Restore previous PHP/JS/CSS/Node files.
2. Leave `chat_audit_log` in place (additive, unused if code is rolled back).
3. Do not drop audit rows unless a DBA explicitly decides to.
4. Disable any new cron entry for `chat_retention_worker.php`.
5. Restart Node on the previous `server.js`.
6. Conversation list clients that ignore unknown `has_more` continue to work; older PHP that ignores extra JSON keys is safe.

## 18. Remaining technical debt

- Automated chat backups.
- Multi-instance Node / Redis adapter if a second process is ever required.
- Application-wide Secure/HttpOnly/SameSite cookie hardening.
- Normalize `reply_to_message_id=0` to NULL after a reviewed data change (not done here).
- Browser E2E once Apache `/deadline/` is reachable.
- Load tests against a large conversation corpus (current DB has 3 conversations).

## Checklist

P6: chat audit, admin access, audit protection, retention config, CLI worker, attachment cleanup, P6 tests — done.

P7: DB/N+1 audit, existing indexes kept, search bounds, notification batch, Node/internal hardening, failure handling, health, config, performance tests — done.

P8: desktop/mobile/attachment/reaction/reply/mention/search/loading/error/a11y/session code — done. Browser QA — **NOT RUN**.

FINAL: security suite PASS, regression PASS, integrity PASS with one NOTE, deployment/rollback documented.

No further chat feature work was started after this report.
