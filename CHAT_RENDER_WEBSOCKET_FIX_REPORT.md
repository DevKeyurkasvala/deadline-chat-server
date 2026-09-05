# Chat Render WebSocket fix report

Date: 2026-09-05  
Production PHP: `https://deadline.eondotcom.com`  
Render Socket.IO: `https://nodeapi-3itx.onrender.com`

This report covers investigation and the smallest code fix. It does **not** claim a live browser WebSocket PASS.

---

## 1. Root cause

The browser Socket.IO client was initialized with:

```js
transports: ['websocket', 'polling']
```

That forces a **WebSocket-first** Engine.IO handshake.

Observed production URL:

```
wss://nodeapi-3itx.onrender.com/socket.io/?EIO=4&transport=websocket
```

There is **no `sid=`**. A normal polling → upgrade request includes the Engine.IO session id from the earlier polling handshake.

What was happening:

1. Engine.IO GET polling (`transport=polling`) works. Render returns HTTP 200 with `sid` and `upgrades:["websocket"]`. That is only the Engine.IO open packet. It is **not** proof that WebSocket or Socket.IO auth succeeded.
2. Because `websocket` was first, the client then opened a raw WSS connection with no `sid`.
3. Render’s HTTPS/WebSocket proxy consistently dropped that direct upgrade (~700–900 ms, 0 B, Chrome “Finished”, console “WebSocket connection failed”).
4. The client could still fall back to HTTP polling. That is why PHP APIs and `/socket.io/?transport=polling` looked healthy while WSS failed.

This is not an authentication rewrite issue, not a Socket.IO major-version mismatch, and not a missing Render listen/bind. The Node process is already:

- bound to `0.0.0.0`
- using Render `PORT` (observed `10000`)
- serving `/socket.io/`
- using HTTPS termination on Render (plain `http.createServer`, no second TLS server)

**Fix:** restore standard Engine.IO negotiation: polling first, then upgrade to WebSocket. Keep PHP socket tokens and server auth middleware unchanged.

---

## 2. Exact files changed

| File | Change |
|---|---|
| `assets/dist/js/chat-realtime.js` | Polling → websocket upgrade; explicit path; safe client logs |
| `chat-server/server.js` | Explicit transports/upgrade; disable `perMessageDeflate`; reject `*` CORS; handshake/upgrade/disconnect logs |
| `chat-server/security.js` | `inspectSocketToken()` returns a reason code; still never logs the token |
| `chat-server/.env.example` | Production CORS example set to `https://deadline.eondotcom.com` |
| `chat-server/CHAT_RENDER_WEBSOCKET_FIX_REPORT.md` | This report |

Not changed: PHP chat APIs, ACL, CSRF, schema, `socket_token.php` contract, Node token algorithm, wildcard-free CORS allowlist for the PHP origin.

---

## 3. Exact configuration changed

No new secrets. No `CHAT_INTERNAL_SECRET` in browser JS.

Code-level Socket.IO config (not Render dashboard):

- Client transports: `['polling', 'websocket']`
- Client `upgrade: true`
- Client `rememberUpgrade: false`
- Client `path: '/socket.io/'`
- Server transports: `['polling', 'websocket']`
- Server `allowUpgrades: true`
- Server `perMessageDeflate: false` (proxy-friendly; not a security change)
- Server `cookie: false` (token auth, not cookies)
- CORS `*` no longer treated as allow-all

**Render dashboard (already set; do not print the secret):**

| Variable | Required value | Notes |
|---|---|---|
| `PORT` | Render-injected | Already working (`10000`) |
| `CHAT_INTERNAL_SECRET` | Same as PHP | Do not expose |
| `CHAT_CORS_ORIGINS` | `https://deadline.eondotcom.com` | Already set. Do not use `*` |
| `CHAT_EMIT_ALLOW_IPS` | Optional | Affects `/internal/emit` only, not browser WSS |
| `CHAT_PORT` | **Unset on Render** | Unused when `PORT` exists. `3015` is local-only |

After deploying this Node change, redeploy the Render service. After deploying `chat-realtime.js`, refresh the PHP site (hard reload).

---

## 4. Browser Socket.IO configuration

### Before (production JS still served this at audit time)

```js
window.io(socketUrl, {
    auth: { token: tokenData.token },
    transports: ['websocket', 'polling'],
    reconnection: true,
    reconnectionDelay: 2000
});
```

- URL: `https://nodeapi-3itx.onrender.com` from `ChatConfig.socketUrl` / token `socket_url`
- Path: Socket.IO default `/socket.io`
- Auth: `handshake.auth.token` from `apiv1/chat/socket_token.php`
- Forced WebSocket first (skips normal negotiation)

### After

```js
window.io(socketUrl, {
    path: '/socket.io/',
    auth: { token: tokenData.token },
    transports: ['polling', 'websocket'],
    upgrade: true,
    rememberUpgrade: false,
    reconnection: true,
    reconnectionDelay: 2000,
    reconnectionDelayMax: 10000
});
```

Token is still obtained only from PHP and sent as `auth.token`. Token values are not logged.

Expected Network sequence after deploy:

1. `GET .../socket.io/?EIO=4&transport=polling` → 200 + `sid`
2. polling POST (Socket.IO CONNECT + auth)
3. `wss://.../socket.io/?EIO=4&transport=websocket&sid=...` → 101 / live WebSocket

---

## 5. Server Socket.IO configuration

### Before

```js
new Server(server, {
    path: '/socket.io/',
    cors: { origin: corsOrigin, credentials: true },
    pingInterval: 20000,
    pingTimeout: 20000,
    allowEIO3: true,
});
```

- Bind: `0.0.0.0`
- Port: `process.env.PORT` then `CHAT_PORT` then `3015`
- HTTP server only (correct behind Render TLS)
- Auth middleware: `handshake.auth.token` or `query.token` → HMAC verify
- CORS allowed configured origins, empty list, or `*`

### After

```js
new Server(server, {
    path: '/socket.io/',
    transports: ['polling', 'websocket'],
    allowUpgrades: true,
    upgradeTimeout: 10000,
    perMessageDeflate: false,
    cors: { origin: corsOrigin, credentials: true },
    pingInterval: 20000,
    pingTimeout: 20000,
    allowEIO3: true,
    cookie: false,
});
```

`*` is no longer accepted. Empty `CHAT_CORS_ORIGINS` still allows all (legacy local fallback) and still logs a startup warning. Production already sets the PHP origin.

Safe server logs now include:

- `handshake`: origin, token present/absent, transport, auth reason (`ok`, `token_absent`, `token_malformed`, `token_expired`, `token_invalid`)
- `origin_rejected`: origin only
- `socket_connected`: socket.id, user_id, transport, origin, token present
- `transport_upgrade`: socket.id, transport
- `socket_disconnected`: socket.id, transport, disconnect reason

Never logged: secret, full token, HMAC, cookies, session IDs, Authorization headers.

---

## 6. Authentication flow verification

```
Browser
  → GET apiv1/chat/socket_token.php  (PHP session + ACL)
  → { token, expires_at, socket_url, csrf_token }
  → io(https://nodeapi-3itx.onrender.com, { auth: { token } })
  → Engine.IO polling open
  → Socket.IO CONNECT packet carries auth.token
  → io.use() reads handshake.auth.token
  → security.inspectSocketToken() / HMAC verify
  → connection | Unauthorized
```

Confirmed in code:

- Token is issued only by PHP `chat_issue_socket_token()`
- Browser still sends it via Socket.IO `auth`, not a new mechanism
- Server still rejects missing/malformed/expired/invalid tokens
- No secret is placed in `ChatConfig` or frontend JS

Unauthenticated `GET /socket.io/?EIO=4&transport=polling` returning 200 is expected Engine.IO behavior. Auth runs on the Socket.IO CONNECT, not on that first GET.

---

## 7. Versions

| Component | Version | Protocol |
|---|---|---|
| Browser CDN `socket.io.min.js` | 4.8.1 | EIO 4 |
| npm `socket.io` | 4.8.3 (lockfile) | EIO 4 |
| npm `engine.io` | 6.6.10 | EIO 4 |

Compatible. Not a version-mismatch root cause.

---

## 8. Tests executed

| Check | Result |
|---|---|
| `php -l` `includes/chat_config.php` | PASS |
| `php -l` `includes/chat_settings.php` | PASS |
| `php -l` `global_footer.php` | PASS |
| `php -l` `apiv1/chat/socket_token.php` | PASS |
| `php -l` `includes/chat_realtime.php` | PASS |
| `node --check` `assets/dist/js/chat-realtime.js` | PASS |
| `node --check` `chat-server/server.js` | PASS |
| `node --check` `chat-server/security.js` | PASS |
| CLI `chat_socket_public_url()` | `https://nodeapi-3itx.onrender.com` |
| Grep `localhost:3015` / `127.0.0.1:3015` in app/frontend | Only local-override docs |
| Grep forced `transports: ['websocket']` | None |
| Client/server transports | `['polling', 'websocket']` |
| Render polling probe `GET /socket.io/?EIO=4&transport=polling` | HTTP 200, `sid`, `upgrades:["websocket"]` |
| Production `chat-realtime.js` at audit time | Still had `['websocket', 'polling']` |
| P0–P9 chat tests | **NOT RUN** — files not present |
| Logged-in browser E2E on `https://deadline.eondotcom.com` | **NOT RUN** — `chat.php` redirects to login; no authenticated browser session here |

---

## 9. Browser verification result

**WebSocket: NOT PASS (not verified in a logged-in browser after this fix).**

What was verified without login:

- Render polling handshake is live
- Production PHP chat page requires login
- Production still served the old WebSocket-first client at audit time

Do not treat the polling 200 as WebSocket success. Do not treat “Render service is live” as WebSocket success.

### Manual check after deploying PHP JS + Render Node

1. Hard-reload `https://deadline.eondotcom.com/chat.php` while logged in.
2. Console should show `[chat-realtime] connect_init` with `url: https://nodeapi-3itx.onrender.com` and `auth: present` (no token value).
3. Network → `socket.io`:
   - polling 200 with `sid`
   - websocket URL **includes `sid=`**
   - websocket stays **pending / 101**, not Finished 0 B
4. Console: `connected` with `transport: "polling"`, then `upgraded` with `transport: "websocket"`.
5. Render logs should show `handshake` `auth: ok`, `socket_connected` `transport: polling`, then `transport_upgrade` `transport: websocket`.

Only then mark WebSocket PASS.

---

## 10. Remaining issues

1. **Deploy gap.** Production PHP still served WebSocket-first JS at audit time. This fix is in the repo only until `chat-realtime.js` is deployed to `deadline.eondotcom.com` and `server.js` is redeployed on Render.
2. **Browser WSS after fix is unverified.** No logged-in production browser session was available here.
3. **`CHAT_PORT=3015` on Render** is unused and confusing. Unset it. Keep Render `PORT`.
4. **`CHAT_EMIT_ALLOW_IPS=103.171.181.129`** does not affect browser WebSocket. If PHP emit/presence from another egress IP fails, that is a separate `/internal/emit` allowlist issue. Render still sees its own proxy IP, so an allowlist can block PHP even when the public egress IP is correct. Prefer empty allowlist + shared secret on Render if emit breaks.
5. CORS must remain exactly `https://deadline.eondotcom.com`. `www.` or `http://` will be rejected and logged as `origin_rejected`.
6. Application polling fallback (`unread_count` interval) is unchanged and still used when Socket.IO is down.

STOP. No further chat features in this task.
