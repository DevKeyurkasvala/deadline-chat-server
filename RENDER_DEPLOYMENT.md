# Render deployment — chat-server only

This directory is a Socket.IO **relay**. PHP remains the source of truth. The database stays on the existing PHP host. Do not add Redis. Do not persist chat data on Render.

This document does **not** create a Render service. It is readiness guidance only.

## Required environment variables (Render)

| Variable | Required | Notes |
|---|---|---|
| `PORT` | Yes (set by Render) | Listen on `process.env.PORT`. Do not set this to 3015. |
| `CHAT_INTERNAL_SECRET` | Yes | ≥16 characters. Same value as PHP. Not a placeholder. Never put this in browser JS. |
| `CHAT_CORS_ORIGINS` | Yes in production | Comma-separated PHP origins, e.g. `https://your-php-host.example`. Do **not** use `*`. |
| `CHAT_BIND` | No | Defaults to `0.0.0.0`. Required bind for Render. |
| `CHAT_EMIT_ALLOW_IPS` | No | Leave **empty** on Render (secret-only). The process sees Render’s proxy IP, so an allowlist usually blocks PHP. |

Do **not** set on the Node service (PHP-side only):

- `CHAT_SOCKET_PUBLIC_URL` / `CHAT_PUBLIC_URL` — browser Socket.IO URL returned by PHP (`socket_token.php`)
- `CHAT_SOCKET_INTERNAL_URL` / `CHAT_INTERNAL_URL` — PHP → Node `/internal/emit` and `/internal/presence`
- Rate limits, edit window, upload limits, poll interval — PHP config

## PHP host configuration

Frontend and PHP emit now default to the live Render relay:

- `CHAT_SOCKET_PUBLIC_URL=https://nodeapi-3itx.onrender.com`
- `CHAT_SOCKET_INTERNAL_URL=https://nodeapi-3itx.onrender.com`
- `CHAT_INTERNAL_SECRET` must be identical on PHP and Render (env / `chat-server/.env` only; never in browser JS)

Local Node override on the PHP host:

- `CHAT_SOCKET_PUBLIC_URL=http://localhost:3015`
- `CHAT_SOCKET_INTERNAL_URL=http://127.0.0.1:3015`

`assets/dist/js/chat-realtime.js` prefers `ChatConfig.socketUrl`, then the token `socket_url`, and connects with `window.io(socketUrl, { auth: { token }, transports: ['websocket', 'polling'] })`. It uses the Socket.IO default path `/socket.io/` and upgrades `http://` → `https://` when the PHP page is HTTPS. It never receives `CHAT_INTERNAL_SECRET`.

## Local development command

```
cd chat-server
cp .env.example .env
# set a unique CHAT_INTERNAL_SECRET (≥16 chars, not a placeholder)
npm ci
npm start
```

Local fallback port is `3015` only when `PORT` is unset (`CHAT_PORT` or default).

## Production start command

```
npm start
```

which is:

```
node server.js
```

## Render build command

```
npm ci
```

## Render start command

```
npm start
```

## Expected PORT behavior

1. `process.env.PORT` (Render)
2. `process.env.CHAT_PORT` (optional local override)
3. `chat-server/.env` `CHAT_PORT` (local file; not used on Render if `.env` is not uploaded)
4. `3015` last-resort **local** fallback

Production must use Render’s `PORT`. Bind address is `0.0.0.0`.

## CORS configuration

Set `CHAT_CORS_ORIGINS` to the real PHP origin(s), including scheme and host, no trailing slash unless that is how the browser sends `Origin`.

Example:

```
CHAT_CORS_ORIGINS=https://app.example.com,https://www.example.com
```

Empty list or `*` currently allows any browser origin (same as previous local default). That is **not** acceptable for production. Set explicit origins. Requests with no `Origin` (server-to-server `/internal/*`) are still allowed.

## Socket.IO URL configuration

| Direction | URL | Who sets it |
|---|---|---|
| Browser → Node | `https://<render-service>.onrender.com` | PHP `CHAT_SOCKET_PUBLIC_URL` |
| PHP → Node emit/presence | `https://<render-service>.onrender.com` | PHP `CHAT_SOCKET_INTERNAL_URL` |
| Path | `/socket.io/` | Socket.IO default; also set explicitly on the server |
| Transports | `websocket`, then `polling` | `chat-realtime.js` |
| TLS | Render terminates HTTPS; Node listens HTTP on `PORT` | Browser uses WSS via `https://` |

Node does not call PHP and does not use localhost PHP URLs.

## Internal emit configuration

- `POST /internal/emit` and `POST /internal/presence`
- JSON only, 64kb body
- Shared `CHAT_INTERNAL_SECRET` (timing-safe compare)
- Event allowlist and `user:` / `conversation:` room pattern
- Optional IP allowlist: **leave unset on Render**
- Do not expose these paths as a public product feature. Secret remains mandatory even if the URL is reachable.

## Health check path

```
GET /health
```

Returns `ok`, `service`, `uptime_sec`, `sockets`, `online_users`, `presence_mode`, `multi_instance`. No secrets, tokens, credentials, or user lists.

Render health check: `GET /health`.

## Persistence

No local filesystem is required for application data. Presence is in-memory and resets on every Render restart. Chat messages stay in the existing PHP/MySQL database.

## Deployment checklist

1. Do **not** move MySQL to Render.
2. Create a Render **Web Service** with root directory `chat-server` (or this folder as the repo root).
3. Build: `npm ci`. Start: `npm start`.
4. Confirm Node version ≥ 18 (`package.json` `engines`).
5. Set `CHAT_INTERNAL_SECRET` (unique, ≥16, not a placeholder).
6. Set `CHAT_CORS_ORIGINS` to the live PHP origin(s). No `*`.
7. Leave `CHAT_EMIT_ALLOW_IPS` empty on Render.
8. Do not set `PORT` manually.
9. On the PHP host, set public + internal Socket URLs to `https://<render-service>.onrender.com` and the same secret.
10. Confirm `GET https://<render-service>.onrender.com/health` returns `{ "ok": true }`.
11. Confirm a logged-in browser can open a WebSocket to `/socket.io/` (or polling fallback).
12. Confirm PHP `chat_emit` succeeds (Node log / PHP `scope=chat_emit`).
13. Confirm `/internal/emit` still rejects missing/invalid secret.
14. Restart Node after env changes (Render redeploy).

## Rollback checklist

1. Point PHP `CHAT_SOCKET_PUBLIC_URL` and `CHAT_SOCKET_INTERNAL_URL` back to the previous Node host (e.g. `http://127.0.0.1:3015` for local).
2. Keep the same `CHAT_INTERNAL_SECRET` or revert both PHP and Node together.
3. Suspend or delete the Render service if it is no longer used.
4. Presence on Render is discarded on rollback; that is expected.
5. No database rollback is required for this Node-only deploy.

## Known operational warnings

- Single instance only. A second Render instance would split in-memory presence. Do not scale horizontally without a later, separate design.
- Render free-tier spin-down drops sockets; PHP polling fallback still works.
- IP allowlist is not reliable behind Render’s proxy.
- Empty CORS still allows all origins if you forget to set `CHAT_CORS_ORIGINS`.
