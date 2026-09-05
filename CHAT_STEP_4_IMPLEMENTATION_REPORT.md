# Chat STEP 4 — P3 Realtime Collaboration & Presence

Status: **complete for review**. STEP 5 was not started.

PHP remains the source of truth for authorization and persistence. Node is still a relay plus an ephemeral presence registry.

---

## Audit findings

Inspected before implementation: `chat.php`, `chat.js`, `chat-realtime.js`, `chat_lib.php`, `chat_realtime.php`, `chat_config.php`, `chat_csrf.php`, `server.js`, `security.js`, `apiv1/chat/*`, schema, notifications, unread/read cursor, Socket.IO events, token lifecycle, polling, task membership.

Findings:

- A user can have multiple browser tabs. Each tab opens its own Socket.IO connection with the same signed token (`userId` from HMAC, not from the client).
- Socket lifecycle: token handshake → `user:{id}` room → `chat:join` with room token → disconnect clears Socket.IO rooms. No presence existed.
- Read cursor is **message ID** (`chat_participants.last_read_message_id`), not a timestamp. `last_read_at` is only a stamp.
- Unread count = messages in the user’s conversations where `id > last_read_message_id` and `sender_id <> self`.
- Messages persist in PHP, then `chat:message` is emitted to `conversation:{id}` and participant `user:{id}` rooms.
- Task chat membership follows `canViewTask()` / `canCommentTask()`; join still requires a PHP-signed room token.
- No prior presence, last-seen, or read-receipt events existed.
- Existing schema is sufficient. **No migration.**

---

## Architecture

```
Browser tab(s)
    │  Socket.IO (identity from signed token)
    ▼
Node PresenceRegistry (memory)
    user_id → socket set, joined conversations, last_seen
    emit presence:update only to conversation:* rooms the user joined
    + user:{id} (other tabs)

Browser → PHP mark_read / fetch_messages
    ACL → persist last_read_message_id → then emit chat:read
    never the reverse
```

Presence is **not** authorization. PHP ACL is unchanged.

---

## Files changed

| File | Change |
| --- | --- |
| `chat-server/presence.js` | **New** in-memory multi-socket registry. |
| `chat-server/server.js` | Connect/disconnect presence, scoped events, `/internal/presence`, shutdown clear, health counts. |
| `chat-server/security.js` | Allow `chat:read` emit; validate presence queries. |
| `includes/chat_lib.php` | Message-in-conversation check, participant read states, persist-then-emit mark read. |
| `includes/chat_realtime.php` | `chat_emit_read()`, `chat_query_presence()`. |
| `apiv1/chat/mark_read.php` | Validate `message_id`, persist, emit, return cursors. |
| `apiv1/chat/presence.php` | **New GET** — conversation ACL, then Node overlay. |
| `apiv1/chat/fetch_messages.php` | Mark-read emits; returns participants. |
| `chat.php` | Presence line in header. |
| `assets/dist/js/chat.js` | Presence, Sent/Read, reconnect refresh, sync rooms. |
| `assets/dist/js/chat-realtime.js` | Connected / Reconnecting / Polling; presence/read events; `syncRooms`. |
| `assets/dist/css/chat.css` | Presence + receipt styles. |
| `chat-server/p3_php_test.php` | New PHP P3 checks. |
| `chat-server/p3_security_test.js` | New Node P3 checks. |
| `chat-server/CHAT_PRODUCTION.md` | Presence / read / health notes. |

---

## APIs changed

### Added

`GET apiv1/chat/presence.php?conversation_id=`

- Session auth.
- `chat_require_conversation()` first.
- Participant list from DB only (no client `user_ids`).
- Node `/internal/presence` overlay. If Node is down, everyone is `offline` with `last_seen` null.

`POST /internal/presence` (Node, secret + IP allowlist)

- `{ secret, user_ids }` max 50.
- Returns `{ user_id, status, last_seen }` for those IDs only.

### Changed

`POST apiv1/chat/mark_read.php`

- CSRF via `chat_api_boot()`.
- Optional `message_id` must belong to the conversation (404 otherwise).
- Persists cursor, then may emit `chat:read`.
- Returns `last_read_message_id`, `participants`, `unread_count`.

`GET apiv1/chat/fetch_messages.php`

- Opening a thread still marks read, now through `chat_mark_read_and_emit()`.
- Response includes `participants` (id, name, `last_read_message_id`).

---

## Socket.IO events

| Event | Direction | Auth |
| --- | --- | --- |
| `presence:update` | Node → clients in scoped rooms | Identity from socket token; payload `{ user_id, status, last_seen }` |
| `presence:snapshot` | Node → joining socket | Users known for that conversation in this process |
| `chat:read` | PHP → Node `/internal/emit` → rooms | After DB persist only |
| Existing `chat:join` / `leave` / `typing` / `message` | unchanged | Room token / `canMutate` |

Clients cannot emit `presence:update` or `chat:read`. Those listeners do not exist.

No socket IDs, tokens, session IDs, IPs, or server internals in payloads.

---

## Presence design

- States shown to users: **Online** / **Offline** (with last seen when known).
- Internal connection label: Connected / Reconnecting / Polling. Not shown as another user’s status.
- Registry tracks `user_id`, socket IDs, socket count, joined conversation IDs, last_seen.
- Online while `socket_count > 0`.
- Last socket disconnect → offline, `last_seen = now`, emit scoped update.
- No MariaDB heartbeat writes.
- last_seen map is capped (5000). Conversations per user capped (50).
- Node restart: `presence.clear()`. Acceptable; clients rebuild.

DM header: other participant Online or “Last seen N minutes ago”.
Task header: “N online” from **that conversation’s** participants only.

---

## Read receipt design

- Cursor: `last_read_message_id` (existing).
- No per-message-per-user rows.
- **Read** only when a recipient’s persisted cursor ≥ message id.
- Delivered is **not** implemented (socket delivery ≠ read).
- DM: Sent / Read.
- Task: Sent / “Read by N” / “Read by participants” when all other known participants have the cursor.
- Emit only if the cursor actually increased, and only after `UPDATE`.
- `task_execute` failure exits before emit.

---

## Multi-tab behavior

- Same user, many sockets → one online user.
- Closing one tab does not go offline.
- Closing the last tab goes offline and sets last_seen.
- `chat:read` is also sent to `user:{reader}` so the reader’s other tabs update receipts and unread.
- Notifications still coalesce; presence does not create notifications.

---

## Reconnect behavior

On socket `connect` with existing room tokens:

1. Re-authenticate (existing token / refresh).
2. Re-join stored conversations.
3. Emit `chat:reconnect` to the page.
4. Refresh unread, presence, and new messages after `lastMessageId`.
5. Duplicate DOM ids are ignored.
6. Polling stays paused while `connected`.

Node does not persist client state across process restart.

---

## Polling behavior

Unchanged 8-second fallback.

- Socket healthy: no active-thread poll.
- Disconnect: polling resumes.
- Reconnect: realtime resumes, polling skips.
- Presence does **not** use PHP polling.

---

## Security / privacy

- Presence identity = verified socket token `userId`. Clients cannot claim another user.
- No global presence listing.
- Presence API requires conversation access.
- Task users who cannot `canViewTask` still get 403 on that conversation (no presence, no read events).
- View-only: may receive presence/read; cannot type or send (`canMutate` / `task_require_not_view_user`).
- Read events cannot be spoofed from the browser.
- Logs: `socket_connected`, `socket_disconnected`, `presence_transition`, existing `chat_emit` (now includes `chat:read`). No tokens, secrets, bodies, session IDs.

---

## Performance

- No heartbeat SQL.
- No per-message read rows.
- Participant hydration is one query per opened conversation (not per list row).
- Presence query is one Node POST, max 50 ids.
- Broadcasts are conversation-scoped, not global.

---

## DB changes

**None.** No `chat-step4-presence-read.sql`. Existing indexes and `last_read_message_id` are sufficient.

---

## Tests

| Suite | Result |
| --- | --- |
| PHP syntax (changed files) | PASS |
| `p0_php_test.php` | PASS |
| `p1_php_test.php` | PASS |
| `p2_php_test.php` | PASS |
| `p3_php_test.php` | PASS |
| `p0_security_test.js` | PASS |
| `p1_security_test.js` | PASS |
| `p2_security_test.js` | PASS |
| `p3_security_test.js` | PASS |

P3 coverage: first/second/final socket, last_seen, identity injection, scoped snapshot, registry clear, mark_read persist, invalid conversation/message/cross-conversation, unauthorized access, emit-after-persist order, Node-down presence fallback, no client `chat:read` / `presence:update`, reconnect + dedupe + polling, view-only send/type, task ACL.

---

## Browser test status

**NOT RUN — Apache 403.**

`http://127.0.0.1/deadline/` and `http://127.0.0.1/deadline/chat.php` returned 403.

Manual two-user plan (when Apache works) is in the STEP 4 request, items 1–18. Do not treat those as passed.

---

## Remaining risks

- `last_seen` is lost on Node restart until the user connects and disconnects again.
- Presence updates reach conversation rooms the user has joined this process (client joins up to 50 recent conversations). A peer who never opened/joined that room this session will see presence after PHP `presence.php` or the next join.
- Abnormal hard kills rely on Socket.IO ping timeout (~40s) before offline.
- Logout still does not instantly revoke sockets (STEP 2 leftover).
- This workspace has no git repository; scope was reviewed from the changed file set. No P4/P5/P6 features were added.

---

## Deployment requirements

1. Deploy the PHP, JS, CSS, and `chat-server` files above.
2. **Restart Node** so `presence.js` and the new `/internal/presence` route load.
3. Do not run a schema migration.
4. Keep `/internal/emit` and `/internal/presence` off the public internet.
5. `CHAT_INTERNAL_SECRET` unchanged.
6. After deploy, smoke-test (when Apache allows): two tabs same user, last tab offline, DM last-seen, mark-read → Sent/Read, disconnect → Polling, reconnect → Connected.

---

## STOP

STEP 4 is finished and ready for review.

Do not start STEP 5 until this report is approved.
