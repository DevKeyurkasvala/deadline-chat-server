# Chat STEP 3 — P2 Core Chat UX

Status: **complete for review**. STEP 4 was not started.

This step adds older-message pagination, ACL-aware search, safer user search, `task_key` display, search-result navigation, send/load error UX, typing lifecycle, and stable deep links. PHP remains the source of truth. Realtime transport and polling fallback are unchanged.

---

## Files changed

| File | Change |
| --- | --- |
| `includes/task_permissions.php` | `task_load_task_row()` now SELECTs existing `tasks.task_key`. |
| `includes/chat_lib.php` | Cursor pagination helpers, around-window load, conversation/message/user search, snippet, batched other-user map. |
| `apiv1/chat/fetch_messages.php` | `before_id` / `before_message_id`, `around_id`, `has_more_before` / `has_more_after`, `found`. Mark-read only when not paging older/newer. |
| `apiv1/chat/search.php` | **New GET** conversation + message search. |
| `apiv1/chat/fetch_users.php` | Requires `q` ≥ 2, max 100, limit 30, name/username/email match, no email in JSON. |
| `chat.php` | `conversation_id` (preferred) or `c`, `task_id`, `message_id`; search UI. |
| `global_footer.php` | `ChatConfig.initialMessageId`. |
| `assets/dist/js/chat.js` | Load older, search, deep links, around/highlight, error mapping, draft+retry, user search. |
| `assets/dist/js/chat-realtime.js` | Typing debounce/idle/stop; toast URL uses `conversation_id`. |
| `assets/dist/css/chat.css` | Search results, load-older, target highlight. |
| `chat-server/p2_php_test.php` | New PHP P2 checks. |
| `chat-server/p2_security_test.js` | View-only `canMutate` regression. |

Node `chat-server/server.js` was not redesigned. Typing authorization remains the existing socket `canMutate` gate.

---

## APIs added / changed

### Added

`GET apiv1/chat/search.php`

Parameters:

- `q` — required, 2–100 characters
- `type` — `conversations` | `messages` | `all` (default `all`)
- `conversation_id` — optional message-search scope; ACL-checked
- `limit` — default 20, max 50
- `cursor` — older-id cursor when `type` is a single result class

Auth: existing session via `task_api_boot()`. GET, so no CSRF.

### Changed

`GET apiv1/chat/fetch_messages.php`

- Latest page (no cursor): newest 50, chronological, unchanged default.
- Older: `before_id` or `before_message_id`.
- Context: `around_id` (bounded window, max 100).
- `limit` default 50, max 100.
- Response adds `has_more_before`, `has_more_after`, `found`.
- `chat_mark_read()` runs only when `after_id <= 0` and `before_id <= 0` (open/latest/around). Older and incremental pages do not mark read.

`GET apiv1/chat/fetch_users.php`

- Empty / 1-character `q` returns no users (`query_required: true`).
- Matches `fullname`, `username`, `email`.
- Returns `id`, `fullname`, `username` only.

Existing POST write APIs still use `chat_api_boot()` + `chat_require_csrf()`.

---

## Database changes

**None.**

`tasks.task_key` already exists (`varchar(32)`, unique). No new key generator and no new columns.

Indexes already present and used:

- `chat_messages(conversation_id, id)` — history / older / around
- `chat_messages(sender_id, created_at)`
- `chat_conversations(type, last_message_at)`
- `chat_participants` unique `(conversation_id, user_id)` and `(user_id, last_read_message_id)`

Not added:

- `chat_messages(conversation_id, created_at)` — pagination is by `id`, not `created_at`
- standalone `chat_conversations(last_message_at)` — covered by `(type, last_message_at)`
- FULLTEXT — not introduced
- No migration file was applied or required

---

## Pagination strategy

Cursor / ID based. Default 50, max 100, clamped server-side.

| Mode | Parameters | SQL |
| --- | --- | --- |
| Latest | no cursor | `id DESC LIMIT n`, then reverse to chronological |
| Older | `before_id` | `id < before_id ORDER BY id DESC`, then reverse |
| Newer / poll | `after_id` | `id > after_id ORDER BY id ASC` |
| Jump | `around_id` | target + bounded neighbors, then merge/dedupe |

Client:

- prepends older rows
- preserves scroll (`newScrollHeight - prevHeight + prevTop`)
- ignores duplicate `data-msg-id`
- hides Load older when `has_more_before` is false
- does not walk unlimited pages for search jumps

---

## Search strategy

Server-side only. Parameterized `LIKE` via `task_like_contains()`.

Conversation search matches:

- conversation title
- task title
- task key
- other participant name / username

Message search matches message body, optionally scoped to one conversation.

User search matches name, username, and email; email is not returned.

Minimum query length 2 (API 422 / helper empty). Maximum 100 (API 422). Limits: search 50, users 30.

No global-then-filter-in-JavaScript path.

---

## ACL behavior

- Conversation access: existing `chat_can_access_conversation()` — task rooms use `canViewTask()`, DMs require participant row.
- List / hydrate / send / fetch / search / older history all go through that path.
- Message-search ACL uses **conversation_id**, not message id (direct-chat check would otherwise miss).
- Inaccessible `conversation_id` remains 404 (missing) or 403 (no access).
- Inaccessible `task_id` remains 403/404 via `chat_open_task_conversation()`.
- View-only users can read and search; they cannot send or emit typing (`canMutate` / no composer).
- User search lists active users (`status = '1'`) except self. There is no separate contact graph; no new relationship system.

---

## task_key fix

Root cause: `task_load_task_row()` did not SELECT `task_key`, so hydrate always saw `null`.

Fix: SELECT the existing column. Display surfaces:

- conversation list (`Task · KEY`)
- chat header (`KEY · title`)
- task drawer “Open centralized chat”
- search conversation and message rows
- deep-linked task chats after hydrate

No new keys are generated.

---

## Error handling

Client `normalizeError()`:

| Status | UX |
| --- | --- |
| 401 | Session-expired toast, disconnect socket, redirect to `auth_login.php` |
| 403 | Permission / CSRF message; no auto-retry |
| 409 | Conflict message; no auto-retry |
| 429 | Rate-limit message; no auto-retry |
| 5xx | Temporary failure; manual retry |
| Network (0) | Connection message; draft kept |

Send failure: draft stays, composer unlocks, user can retry.

Also handled: conversation list, message load, older load, search, user search.

No SQL, stack traces, CSRF tokens, session IDs, or internal paths are shown. Existing Toastr / SweetAlert only.

---

## Typing lifecycle

- Starts from composer `input` when there is actual text
- Debounced 400ms (not every keystroke)
- Idle stop after 1600ms
- Explicit stop on send, clear, conversation change, leave, and socket disconnect
- `CFG.canMutate` required; view-only cannot emit
- Node still enforces `canMutate` on `chat:typing`
- No presence / online status

---

## Realtime / polling behavior

Unchanged from STEP 2:

- Socket.IO messages still render; duplicate IDs ignored in the DOM
- Polling every 8s only when `connected === false`
- Reconnect re-joins rooms with stored room tokens
- Active conversation does not poll while the socket is healthy
- Unread badge still uses `unread_count.php` + coalesce logic
- No Redis

Toast deep link now uses `chat.php?conversation_id=` (legacy `?c=` still accepted).

---

## Deep links

Supported:

- `chat.php?conversation_id=123`
- `chat.php?c=123` (legacy)
- `chat.php?task_id=456`
- `chat.php?conversation_id=123&message_id=789`
- `chat.php?conversation_id=123&task_id=456`

`history.replaceState` keeps the selected conversation (and task / message when relevant). URLs contain only numeric IDs. No CSRF, socket, or room tokens.

Inaccessible IDs return the existing safe JSON error; the UI shows that message and does not dump internals.

---

## Security considerations

- New search endpoints are session-authenticated and ACL-aware
- Parameterized SQL only
- Bounded `q`, `limit`, and cursors
- User search no longer returns the full user list on empty `q`
- Email may be used as a match key (existing user-table behavior) but is not returned
- Write POSTs still require CSRF
- Client IDs are never trusted without `chat_require_conversation()` / `canViewTask()`
- STEP 1/2 room tokens, emit hardening, and rate limits were not weakened

---

## Performance considerations

- Conversation search hydrates other users in one `IN (...)` query
- Message search is a single bounded SQL query plus per-row ACL (task visibility), not load-all-then-filter
- Page size capped at 100
- Search / user limits capped at 50 / 30
- `LIKE '%q%'` cannot use the existing btree indexes well; FULLTEXT was not added. Acceptable for current volume; revisit if search load grows.

---

## Tests executed

### PHP syntax

All touched PHP files: **PASS** (`php -l`, 13 files).

### CLI / unit

| Suite | Result |
| --- | --- |
| `chat-server/p0_php_test.php` | PASS |
| `chat-server/p1_php_test.php` | PASS |
| `chat-server/p2_php_test.php` | PASS |
| `chat-server/p0_security_test.js` | PASS |
| `chat-server/p1_security_test.js` | PASS |
| `chat-server/p2_security_test.js` | PASS |

P2 coverage:

1. Load latest messages — PASS  
2. Load older messages — PASS  
3. No duplicate messages — PASS  
4. Message ordering — PASS  
5. Conversation ACL — PASS  
6. Message ACL — PASS  
7. Search minimum length — PASS  
8. Search maximum length — PASS  
9. Search result ACL — PASS  
10. Task key hydration — PASS  
11. User search permission / bound — PASS  
12. CSRF on write APIs — PASS (existing helper; no new POST)  
13–16. 401 / 403 / 409 / 429 client mapping — PASS (source contract)  
17–19. Typing debounce / stop / view-only — PASS (source + Node `canMutate`)  
20–22. Reconnect / polling / duplicate realtime — covered by P0/P1 + source (`isLive`, `data-msg-id`)  
23–25. Deep-link / invalid conversation / invalid task — PASS (ACL + `canViewTask`)

### Browser E2E

**NOT RUN — Apache 403.**

`http://127.0.0.1/deadline/` and `http://localhost/deadline/chat.php` both returned HTTP 403. No browser pass is claimed.

---

## Tests not executable

- Browser click-through of Load older, search highlight, and composer retry
- Live Socket.IO reconnect against Apache-hosted pages
- HTTP integration against `/apiv1/chat/*` through Apache (same 403)

CLI/API helpers were executed directly against the existing MariaDB schema.

---

## Known remaining risks

- Conversation-search cursor uses `id` while the first page is ordered by `last_message_at`. Fine for the first page the UI uses; later pages can skip/duplicate if a cursor UI is added later.
- Two-character user `LIKE` can still enumerate some accounts. Empty listing is closed; a contact graph was out of scope.
- Session cookie flags (`Secure` / `HttpOnly` / `SameSite`) were not changed (STEP 2 leftover).
- Logout still does not instantly revoke in-flight sockets (STEP 2 leftover).
- Search is `LIKE`, not full-text. Large histories will scan.
- `around_id` is a single bounded window. Deleted / other-conversation messages show a clear empty/warning state instead of paging forever.
- This workspace is not a git repository, so a formal `git diff` review was not available. The changed file set was reviewed manually; no P3/P4 features (attachments, reactions, replies, forwarding, edit/delete, mentions, presence, groups, rooms, Redis, comments unification) were added.

---

## Deployment notes

1. Deploy the PHP, JS, and CSS files listed above.
2. Do **not** run a schema migration.
3. Node process restart is optional; server authorization is unchanged. Clients must get the new JS for typing debounce and error UX.
4. Confirm `tasks.task_key` already exists before go-live (it does in the current schema).
5. Keep Apache/PHP as the chat source of truth. Do not point clients at Node for persistence.
6. After deploy, smoke-test: latest messages, Load older, search, user search, task header key, send failure retry, typing stop, `?conversation_id=` refresh.

---

## STOP

STEP 3 implementation is finished and ready for review.

Do not start STEP 4 until this report is approved.
