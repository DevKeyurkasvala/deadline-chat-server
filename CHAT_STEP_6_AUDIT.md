# Chat STEP 6 / P5 — Pre-implementation Audit

Read-only audit completed before any STEP 6 code or schema changes.

PHP remains the source of truth. Node is a Socket.IO relay. Existing P0–P4 security, CSRF, rate limits, presence, read receipts, attachments, reactions, replies, edit/delete, and forwarding stay intact.

---

## Current architecture

- Conversations: `chat_conversations.type` is `ENUM('task','direct')` only.
- Identity: task rooms use unique `task_id`; DMs use unique `pair_key` (`min:max`).
- Access: `chat_can_access_conversation()` — task → `canViewTask()`; direct → `chat_participants` row.
- Send: `chat_assert_can_send()` — role 3 blocked; task rooms also require `canCommentTask()`.
- Participants are additive only. There is no participant removal. Leftover rows do **not** grant task access because task ACL is re-checked.
- Task chat is created by `chat_open_task_conversation()` (duplicate-safe via unique `task_id`).
- Assignment already calls `chat_add_user_to_task_chat()`. Watchers and project-member adds do not.
- Search is ACL-filtered and parameterized (`q` ≥ 2, max 100).
- Deep links: `chat.php?conversation_id=&task_id=&message_id=`.
- Notifications: `notifications` + `chat_upsert_message_notification()` (`type=chat_message`). Sender excluded. Recipients re-checked with `canViewTask` / conversation ACL.
- Mentions exist only for **task comments** (`task_parse_mentions()` + `task_comment_mentions`). Not wired to chat.
- Realtime rooms are conversation IDs plus PHP-signed room tokens. Clients cannot join by raw `project_id` / `client_id`.

---

## Reusable helpers (must reuse)

| Helper | File |
| --- | --- |
| `canViewTask`, `canCommentTask`, `canViewProject`, `task_project_role` | `includes/task_permissions.php` |
| `task_user_service_type_ids`, `task_user_can_see_deadline_service` | `includes/task_permissions.php` |
| `task_parse_mentions`, `task_load_task_row`, `task_load_project`, `task_client_exists` | `includes/task_lib.php` |
| `chat_can_access_conversation`, `chat_require_conversation`, `chat_assert_can_send` | `includes/chat_lib.php`, `includes/chat_rich.php` |
| `chat_open_task_conversation`, `chat_sync_task_participants`, `chat_add_user_to_task_chat` | `includes/chat_lib.php` |
| `chat_notify_new_message`, `chat_upsert_message_notification` | `includes/chat_lib.php` |
| `createTaskNotification` | `includes/task_notifications.php` |
| `chat_api_boot`, `chat_require_csrf` | `includes/chat_csrf.php` |
| `chat_issue_room_token` | `includes/chat_config.php` |
| `task_public_user` | `includes/task_lib.php` (id/fullname/username only) |

Do **not** invent a parallel ACL, notification table, CSRF, or socket-authorization path.

---

## Permission model (as implemented)

### Tasks
Admin (role 2, acting as self): any non-deleted task.  
Others: creator, primary assignee, `task_assignees`, `task_watchers`, any `task_project_members` on the task’s project, **or** deadline-linked + service-type visibility.  
Comment/send: not view-user (role 3) + `comment` capability + `canViewTask`.

### Projects
`canViewProject`: admin **or** a `task_project_members` row.  
Roles: `owner`, `manager`, `member`, `viewer` (+ PHP `admin` for app admins).  
Viewers can view project tasks and can comment on those tasks if they have the `comment` capability.  
`canManageProject`: owner/manager/admin + `manage_projects`.

### Clients / services
There is **no** `canViewClient()`.  
Dashboard / deadline visibility uses `user_service_types` ∩ `client_service_types`.  
Task lookups currently return all clients — that is **not** a chat ACL and must not be reused as “any logged-in user can open client chat.”

### Users
Table is `tbl_users`. Mention/search may return `id`, `fullname`, `username` only. Email may match internally; it is never returned.

---

## Conversation model after STEP 6 (planned)

Keep `task` and `direct`. Add:

| type | Identity columns | Access | Send |
| --- | --- | --- | --- |
| `project` | `project_id` unique | `canViewProject` | not view-user + `comment` capability + `canViewProject` |
| `client` (client-wide) | `client_id`, `service_type_id = 0` | admin **or** overlapping `user_service_types` ∩ `client_service_types` | not view-user + same access |
| `client` (service) | `client_id`, `service_type_id > 0` | admin **or** user has that service **and** client has that service | not view-user + same access |

Client/service ACL is the **existing dashboard service-type rule**, not a new membership table.  
If that intersection is empty and the user is not admin: deny (404 on open).  
`chat_participants` is never the authority for task/project/client access.

---

## Required schema changes

Additive only:

1. Extend `chat_conversations.type` ENUM with `project`, `client`.
2. Add nullable `project_id`, `client_id`, `service_type_id`.
3. Unique `project_id`; unique `(client_id, service_type_id)`.
4. `chat_message_mentions` (`message_id`, `mentioned_user_id`, unique pair). No FK to `tbl_users` (existing app avoids user FKs).

No drops, truncates, or ID rewrites.

---

## Risks

1. Stale participants after removal — access still blocked by ACL; do not destroy history.
2. Watcher/automation/project-member adds currently skip chat sync — will be fixed.
3. Deadline-only task viewers are not in `chat_task_member_ids`; they become participants only when they open the room. Mentions will use participants + known members, not a global user scan.
4. Client chat without service overlap must not leak client existence (404).
5. ENUM alter must preserve existing `task`/`direct` rows.
6. `(client_id, service_type_id)` unique requires `0` for client-wide rooms (MySQL allows multiple NULLs).
7. No existing chat audit logger — mention/project/client events will not add a P6 audit system.

---

## Intentionally deferred (this step)

- Generic user-created groups / group admin
- Redis / multi-node Socket.IO
- Retention / analytics / advanced audit
- Chat messages for every task status change (would spam; no existing “post to chat” activity hook)
- Physical participant deletion
- Inventing a full client-membership model beyond service-type visibility
- STEP 7 / P6

---

## Implementation plan

1. Backup chat tables; apply additive `install_chat_step6.php`.
2. `includes/chat_collab.php`: mentions, task keys, project/client open/sync/ACL, search extras.
3. Extend access, send, hydrate, notify, unread, list, search.
4. APIs: `mention_users.php`, `open_project_chat.php`, `open_client_chat.php`; extend `search.php` / `send_message` / `fetch_messages`.
5. Sync hooks: watcher, project member, automation assign/watcher.
6. UI: mention autocomplete, TASK links, conversation kinds, deep links, project Open Chat.
7. P5 tests + P0–P4 regression.
8. Reports. STOP.
