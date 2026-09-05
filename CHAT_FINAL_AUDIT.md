# Chat Final System Audit (STEP 7–9 / P6–P8)

Read-only audit completed before P6/P7/P8 changes.

## Architecture (actual)

- PHP is the source of truth. Node (`chat-server/server.js`, port 3015) is a Socket.IO relay.
- Auth: `$_SESSION['id']`, role 1 user / 2 admin / 3 view-only.
- CSRF: `X-Chat-CSRF` via `chat_api_boot()`.
- Rooms: conversation IDs + PHP-signed `room_token`. No project/client socket rooms.
- Presence: in-memory `PresenceRegistry`. **No Redis.**
- Conversations: `task`, `direct`, `project`, `client`.
- Attachments: private `storage/chat/` + authorized download.

## Task audit / retention (reusable patterns)

| Existing | Usable for chat? |
| --- | --- |
| `task_activity_log` (`task_id` NOT NULL FK) | **No** for DMs/project/client. Task-chat-only would split audit. |
| `task_admin_audit` | Write-only admin config events. No viewer. |
| `cron/task_retention_worker.php` | **Yes as a template**: CLI, `GET_LOCK`, default OFF, batch 100. Archives tasks; does not delete chat. |
| `task_dashboard_csv()` | Pattern for admin CSV export. No chat export today. |

**Decision:** dedicated `chat_audit_log` + `cron/chat_retention_worker.php`. Do not overload `task_activity_log`.

## Chat gaps

- No chat audit table or admin viewer.
- No chat retention worker. Soft-deleted messages and files stay forever.
- Conversation list is unpaginated; `chat_hydrate_conversation()` does per-row unread COUNT (N+1).
- Search is bounded LIKE, no FULLTEXT (acceptable at current scale).
- Node `/internal/emit` and `/internal/presence` already have secret, IP allowlist, event/room bounds, 64kb JSON.
- PHP emit already times out (0.5s local / 3s remote) and does not fail the request if Node is down.
- Health: Node `GET /health` only. No PHP/DB/storage health for chat.
- UI: no `aria-*`; mention autocomplete is click-only; task drawer has no mention list; no search loading/error/retry.

## Cron / health / backups

- Cron: automation, email, digest, task retention. **No chat worker.**
- Backups: manual SQL under `backups/`. No automated backup script.
- Health: Node `/health`; `task_automation_health.php` is role-2 and not chat.

## Scaling

Single-instance Node is sufficient. Presence is process-local. Redis adapter is **not justified** until multiple Node processes are required.

## Implementation order

P6 audit + retention → P6 tests → P7 list/unread/indexes/health/config → P7 tests → P8 UX/a11y → P8 tests → final QA → STOP.
