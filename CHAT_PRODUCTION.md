# Chat Production Configuration Guide

PHP remains the source of truth. Node is a Socket.IO relay only.

## PHP environment variables

Set these in the web server / PHP-FPM environment, or in `chat-server/.env` (readable by PHP).

| Variable | Purpose | Default |
|---|---|---|
| `CHAT_INTERNAL_SECRET` | Shared HMAC secret for socket tokens, room tokens, and `/internal/emit` | none — required |
| `CHAT_SOCKET_PUBLIC_URL` or `CHAT_PUBLIC_URL` | Browser Socket.IO URL | Derived from the current host |
| `CHAT_SOCKET_INTERNAL_URL` or `CHAT_INTERNAL_URL` | PHP → Node emit URL | `http://127.0.0.1:3015` |
| `CHAT_EMIT_ALLOW_IPS` | Optional PHP-side documentation / Node allowlist source | empty |
| `CHAT_RATE_LIMIT_MESSAGES` | Max sends per user per window | `20` |
| `CHAT_RATE_LIMIT_WINDOW_SECONDS` | Rate-limit window | `60` |
| `CHAT_ROOM_TOKEN_TTL` | Signed room-token lifetime (seconds) | `7200` |
| `CHAT_SOCKET_TOKEN_TTL` | Socket-token lifetime (seconds), capped by `session.gc_maxlifetime` | `3600` |
| `CHAT_EDIT_WINDOW_SECONDS` | Own-message edit/delete window (P4) | `900` |

`includes/chat_settings.php` can hold non-secret defaults. Do not put a real secret in that file.

## Node environment variables

| Variable | Purpose | Default |
|---|---|---|
| `CHAT_INTERNAL_SECRET` | Same secret as PHP | required |
| `CHAT_PORT` / `PORT` | Listen port | `3015` |
| `CHAT_BIND` | Bind address | `0.0.0.0` |
| `CHAT_CORS_ORIGINS` | Comma-separated browser origins | empty = allow any origin |
| `CHAT_EMIT_ALLOW_IPS` | Comma-separated IPs allowed to call `/internal/emit` | empty = secret-only |

## `CHAT_INTERNAL_SECRET` requirements

- At least 16 characters.
- Identical on every PHP host and every Node host.
- Never use `CHANGE_ME_CHAT_SECRET`, `deadline-chat-local-dev-secret`, `changeme`, or `secret`.
- Never commit a live secret.
- Node refuses to start if the secret is missing or forbidden.
- Never log or expose the secret.

## `CHAT_EMIT_ALLOW_IPS`

- Local PHP + Node: `127.0.0.1,::1`.
- Split hosting: set the PHP host IP, or leave empty and rely on the shared secret.
- Empty allowlist is secret-only. That is acceptable when IP restriction is not safe.

## HTTPS / WSS

- Production website must be HTTPS.
- Browser Socket.IO URL must be `https://` (Socket.IO upgrades to WSS).
- If the page is HTTPS, PHP will not return an `http://` public socket URL.
- `chat-realtime.js` also upgrades `http://` socket URLs to `https://` on HTTPS pages and will not open mixed-content sockets.
- Local HTTP development can still use `http://localhost:3015`.

## Reverse proxy

If Apache or Nginx terminates TLS and Node stays on an internal port:

1. Proxy `/socket.io/` (WebSocket + polling) to `http://127.0.0.1:3015`.
2. Enable WebSocket upgrade headers (`Upgrade`, `Connection`).
3. Set `CHAT_SOCKET_PUBLIC_URL` to the public HTTPS origin (often the same host as the PHP app).
4. Keep `CHAT_SOCKET_INTERNAL_URL` on the private interface, e.g. `http://127.0.0.1:3015`.
5. Do not expose `/internal/emit` on the public internet. Restrict it with `CHAT_EMIT_ALLOW_IPS` and/or proxy rules.

Example Nginx location (adjust host names):

```
location /socket.io/ {
    proxy_pass http://127.0.0.1:3015;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Do not point a public location at `/internal/emit`.

## Node restart

Restart Node after changing `server.js`, `.env`, or `CHAT_INTERNAL_SECRET`.

```
cd chat-server
node server.js
```

Use a process manager (`systemd`, etc.) in production.

## `.env` handling

- Copy `.env.example` to `.env` on the server.
- Replace the placeholder secret with a unique value.
- `chat-server/.env` is gitignored.
- PHP and Node must read the same secret.

## Schema install

Chat APIs do not `CREATE TABLE` at request time.

```
php install_chat_schema.php
```

or apply `create_chat.sql` once. Re-running the installer is safe.

P4 rich messaging (additive):

```
php install_chat_step5.php
```

or apply `chat-step5-rich-messaging.sql`. Backup chat tables first. Re-running the installer is safe.

P5 collaboration (additive):

```
php install_chat_step6.php
```

or apply `chat-step6-collaboration.sql`. Backup chat tables first. Re-running the installer is safe.

New conversation types: `project` (unique `project_id`) and `client` (`client_id` + `service_type_id`; `0` = client-wide). No new environment variables.

P6 audit + retention (additive):

```
php install_chat_step7.php
```

or apply `chat-step7-audit-retention.sql`. Backup chat tables first. Re-running the installer is safe.

Creates `chat_audit_log` and seeds `task_settings`:

- `chat_retention_enabled` = `0` (OFF)
- `chat_purge_deleted_messages_after_days` = `0` (never)
- `chat_purge_audit_after_days` = `0` (never)

Cron (CLI only, lock `chat_retention_worker`):

```
php cron/chat_retention_worker.php
```

Do not enable retention until an administrator sets the policy. The worker never runs from a web request.

Optional production overrides:

| Variable | Purpose | Default |
|---|---|---|
| `CHAT_EDIT_WINDOW_SECONDS` | Own-message edit/delete window | `900` |
| `CHAT_UPLOAD_MAX_BYTES` | Per-file upload cap | `10485760` |
| `CHAT_UPLOAD_MAX_COUNT` | Attachments per message | `5` |
| `CHAT_UPLOAD_MAX_TOTAL_BYTES` | Combined upload cap | `26214400` |
| `CHAT_POLL_INTERVAL_MS` | Polling fallback interval | `8000` |
| `CHAT_CONVERSATION_PAGE_SIZE` | Conversation list page size | `50` |

## Health check

Node `GET /health` returns `{ "ok": true, "service": "deadline-chat", "uptime_sec": ..., "sockets": ..., "online_users": ..., "presence_mode": "memory", "multi_instance": false }`.

PHP `GET apiv1/chat/health.php` (authenticated) reports `healthy` / `degraded` / `unhealthy` for PHP, database, chat tables, storage writability, and Node relay. It does not return secrets, credentials, filesystem paths, or user data.

Counts are runtime-only.

## Scaling

- Current Node is **single-instance**. Presence is in-memory.
- Redis / Socket.IO adapter is **not** installed. Do not add it unless more than one Node process is required.
- PHP remains the source of truth if Node is down. Polling fallback continues.

## Chat audit (P6)

- Admin-only page: `chat_audit.php`
- APIs: `apiv1/chat/audit.php`, `apiv1/chat/audit_export.php`, `apiv1/chat/retention_settings.php`
- Authorization uses existing `task_is_admin()` (role 2). Menu hiding is not the control.
- Audit rows are append-only. Normal users cannot update or delete them.

## Presence (P3)

- Presence is an in-memory Node registry (socket count per user). It is **not** authorization.
- Multi-tab: a user is online while `socket_count > 0`. Offline and `last_seen` update only when the last socket disconnects.
- `last_seen` is ephemeral. A Node restart clears runtime presence; clients reconnect and rebuild.
- Do not persist heartbeats to MariaDB.
- `POST /internal/presence` is PHP → Node only (same secret / IP allowlist as `/internal/emit`). Do not expose it publicly.
- Browser presence is conversation-scoped. There is no global user-presence listing API.

## Read receipts (P3)

- `chat_participants.last_read_message_id` remains the source of truth.
- The browser cannot announce read state to other clients. `POST apiv1/chat/mark_read.php` persists first, then PHP may emit `chat:read`.
- Allowed `/internal/emit` events: `chat:message`, `notification:new`, `chat:read`, `chat:reaction`, `chat:message_edited`, `chat:message_deleted`.

## Rich messaging / attachments (P4)

- Run `php install_chat_step5.php` once (additive). Backup chat tables first.
- Files are stored in `storage/chat/YYYY/MM/` with random names. That directory is denied by `.htaccess`. Never serve it as a static URL.
- Downloads go through `apiv1/chat/download_attachment.php` (session + conversation ACL). Unauthorized requests return 404.
- Allowed types: JPEG, PNG, GIF, WebP, PDF, TXT. Max 10 MB each, 5 per message, 25 MB total.
- MIME is detected with Fileinfo and must match the extension. PHP/SVG/HTML/JS and executables are rejected.
- Optional: `CHAT_EDIT_WINDOW_SECONDS` (default 900). Own messages only. No invented moderator role.
- Restart Node after deploy so the new emit events are accepted.

## Logs

- PHP: standard error log (`error_log`). Chat emit lines are JSON with `scope=chat_emit`.
- Node: process stdout/stderr. Denied join/emit lines do not include secrets, tokens, or message bodies.
- Never log passwords, HMAC secrets, socket tokens, session IDs, or full message bodies.

## Token / logout behavior

- Socket tokens expire at `CHAT_SOCKET_TOKEN_TTL` (also capped by PHP session GC).
- The browser refreshes the socket token before expiry.
- Logout destroys the PHP session. Chat write APIs then return 401. The UI disconnects and sends the user to login.
- There is no server-side socket-token revocation list. A just-logged-out socket may remain connected until the short-lived token expires or the next unauthorized handshake.

## Session cookies

PHP session cookies are application-wide. This step does **not** change `Secure` / `HttpOnly` / `SameSite` globally because many existing modules start sessions independently. Harden those flags in a dedicated application-wide session change.

## CSRF

Chat POST APIs require header `X-Chat-CSRF` (or body `csrf_token`) matching the session-bound token from `ChatConfig.csrfToken`.
