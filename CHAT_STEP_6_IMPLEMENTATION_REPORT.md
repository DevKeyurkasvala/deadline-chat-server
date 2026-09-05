# Chat STEP 6 — P5 Collaboration

Status: **complete for review**. STEP 7 was not started.

PHP remains the source of truth. Node only relays after persistence. Existing P0–P4 behavior is unchanged.

Pre-implementation audit: `chat-server/CHAT_STEP_6_AUDIT.md`.

---

## Audit findings (used)

- Conversations were `task` + `direct` only.
- Task access = `canViewTask`; send = `canCommentTask`.
- Project access = `canViewProject` (membership or admin). Viewers can comment on tasks if they have the `comment` capability.
- No `canViewClient()`. Dashboard visibility is `user_service_types` ∩ `client_service_types`.
- Mentions existed only for task comments (`task_parse_mentions`).
- Watchers / automation / project-member adds did not sync chat.
- `chat_participants` is additive; leftover rows do not grant task/project/client access because ACL is re-checked.
- Socket rooms are conversation IDs + PHP-signed tokens. Clients cannot join by `project_id` / `client_id`.

---

## Schema / migration

Backup: `backups/chat_pre_step6.sql` (SHOW CREATE + counts).

Pre-migration: conversations 2, participants 4, messages 1.

Applied with `php install_chat_step6.php`:

- `chat_conversations.type` ENUM now includes `project`, `client`
- columns `project_id`, `client_id`, `service_type_id`
- unique `uq_chat_project`, `uq_chat_client_service`
- table `chat_message_mentions`

No drops, truncates, or ID changes. Existing DMs/task chats remain.

Client-wide rooms use `service_type_id = 0` because MySQL unique indexes allow multiple NULLs.

---

## Files changed

| File | Change |
| --- | --- |
| `chat-server/CHAT_STEP_6_AUDIT.md` | Audit |
| `chat-step6-collaboration.sql` / `install_chat_step6.php` | Additive schema |
| `create_chat.sql` | Fresh installs include P5 |
| `includes/chat_collab.php` | **New** mentions, TASK keys, project/client ACL |
| `includes/chat_lib.php` | Access, list, search, notify, unread, send mentions |
| `includes/chat_rich.php` | Send ACL + hydrate mentions/task refs |
| `includes/task_notifications.php` | Chat mention/message URLs |
| `includes/task_automation.php` | Assignee/watcher chat sync |
| `apiv1/chat/mention_users.php` | Conversation-scoped autocomplete |
| `apiv1/chat/open_project_chat.php` | Deterministic project room |
| `apiv1/chat/open_client_chat.php` | Deterministic client/service room |
| `apiv1/chat/search.php` | Projects/clients/tasks/users |
| `apiv1/chat/fetch_messages.php` | Open via project_id/client_id |
| `apiv1/chat/fetch_conversations.php` | Project/client filters |
| `apiv1/tasks/add_watcher.php` | Sync task chat |
| `apiv1/tasks/add_project_member.php` | Sync project chat if it exists |
| `chat.php`, `chat.js`, `chat.css`, `global_footer.php` | UI + deep links |
| `tasks.php`, `task-management.js` | Open project chat |
| `chat-server/p5_php_test.php`, `p5_security_test.js` | P5 tests |

---

## APIs added

| Endpoint | Method | CSRF |
| --- | --- | --- |
| `apiv1/chat/mention_users.php` | GET | no (session + conversation ACL) |
| `apiv1/chat/open_project_chat.php` | POST | yes |
| `apiv1/chat/open_client_chat.php` | POST | yes |

## APIs modified

- `search.php` — extra result types, still `q` ≥ 2 / max 100 / limit 30
- `fetch_messages.php` — `project_id` / `client_id` / `service_type_id`
- `fetch_conversations.php` — filters `project`, `client`
- `send_message.php` — server-side mention parse/store/notify

---

## Mentions

- Parsed in PHP with `task_parse_mentions` (`@username` / `@[user:id]`).
- Each ID must exist and `chat_can_access_conversation`.
- Stored in `chat_message_mentions` (unique pair). Frontend metadata is not trusted.
- Autocomplete: GET `mention_users.php` — only eligible conversation users; `id`/`fullname`/`username` only.
- Notification: `chat_mention` via existing `notifications` table. Sender skipped. ACL re-checked. Unread mentions coalesce per conversation. Not a second notification system.
- Realtime: included on `chat:message`. No new socket event.

---

## TASK keys

- Bounded regex (`A-Z…-digits`, max 10 keys).
- Batch lookup by `task_key`. Inaccessible keys stay plain text (no title/client leak).
- Accessible keys become `tasks.php?task_id=` links after `escapeHtml`.
- Search includes task key / title with `canViewTask`.

---

## Task ↔ chat sync

- Assignee add already synced.
- Watcher add and automation assign/watcher now call `chat_add_user_to_task_chat` (only if the task room exists).
- Project member add syncs an **existing** project room; it does not auto-create one.
- Participants are not physically removed. Access is ACL-derived. History is kept.
- Task chat still requires `canViewTask` **and** conversation access. `chat_participants` is not authoritative.

Task status changes are **not** posted to chat (would spam; no existing hook). Deferred.

---

## Project chat

- One room per project (`type=project`, unique `project_id`).
- Access: `canViewProject`.
- Send: not view-user + existing `comment` capability + `canViewProject`.
- Unauthorized open: **404**.
- UI: inbox filter + `chat.php?project_id=` + “Open project chat” on the tasks project strip.

---

## Client / service chat

ACL is the **existing dashboard service-type rule**, not a new membership model:

- Service room: admin **or** (user has that service **and** client has that service).
- Client-wide room (`service_type_id=0`): admin **or** any overlapping service.
- Logged-in-only access is forbidden.
- Unauthorized open: **404** (does not leak existence).
- Send: not view-user + same access.
- Participants derived from `user_service_types` / `client_service_types`. No arbitrary add.

---

## Search / deep links / unread

Search types: conversations, messages, projects, clients, tasks, users. Server-side ACL. Parameterized. Bounded.

Deep links: `conversation_id`, `message_id`, `task_id`, `project_id`, `client_id`, `service_type_id`. No tokens/secrets in URLs. Server re-checks access.

Unread badge skips conversations the user can no longer access.

Notifications for new project/client messages reuse `chat_message` and re-check recipient ACL. No reaction/typing/read/sync spam.

---

## Realtime / security

- Same architecture: PHP persist → `/internal/emit` → conversation rooms.
- No `project:` / `client:` socket rooms.
- Browser cannot emit mention/project/client events.
- CSRF on all new POSTs.
- XSS: body escaped, then known tokens linkified from server metadata.

---

## Tests

| Suite | Result |
| --- | --- |
| PHP syntax | PASS |
| P0–P5 PHP | PASS |
| P0–P5 Node | PASS |

Browser E2E: **NOT RUN — Apache 403**.

---

## Deployment

1. Backup chat tables.
2. `php install_chat_step6.php`
3. Deploy PHP/JS/CSS.
4. No new env vars. No Node allowlist change required for mentions (they ride on `chat:message`).
5. Restart Node only if it is already down or stale from STEP 5.

Rollback: installer is additive. Removing types/columns is not provided and would be a separate planned migration.

---

## Remaining risks / deferred

- Stale `chat_participants` rows after membership removal (access still denied).
- Deadline-only task viewers are mentionable only after they have opened the room or are known members.
- Client chat does not invent a client-team membership table.
- Physical file/participant cleanup deferred.
- No P6 audit/retention/Redis/groups.
- Apache E2E not run.

---

## STOP

STEP 6 is finished and ready for review.

Do not start STEP 7 until this report is approved.
