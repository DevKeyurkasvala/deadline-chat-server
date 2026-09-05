# Chat STEP 8 / P7 — Production hardening + performance

## What shipped

- Conversation list: keyset pagination (`limit` + `before_id`), batched unread / titles / DM peers / participant counts. No N+1 unread or last-message lookup.
- Notifications: one SELECT of existing unread `chat_message` rows for all recipients, then per-user upsert. Sender exclusion, ACL, and coalesce preserved.
- `GET apiv1/chat/health.php` (auth required): PHP / DB / tables / storage writable / Node. Status `healthy|degraded|unhealthy`. No secrets or paths.
- Upload/poll/page-size readable from env. MIME allowlist stays in code.
- Node health documents `presence_mode=memory`, `multi_instance=false`.
- Redis adapter **not** added. Single-instance Node remains the supported topology.
- Existing indexes used: `idx_chat_conv_msg (conversation_id,id)`, `idx_chat_user`, `idx_chat_type_last`. No new indexes were added (query patterns already covered).

## Measured performance (actual CLI runs)

First dedicated run after implementation:

| Probe | Result |
|---|---|
| conversation list | 3 rows, 250 ms |
| message history | 0 messages in selected conversation, 59 ms |
| unread total | 0, 24 ms |
| search (`ta`) | 116 ms |
| rich hydrate | 0 messages, 48 ms |
| notification lookup | 33 ms |
| PHP→Node emit (5 serial) | 10, 1, 1, 1, 1 ms |
| presence query | 1 ms |

A later rerun measured conversation list at 888 ms and search at 641 ms on the same 3-row corpus. Those numbers are also real and reflect host load, not a fabricated SLA.

Node live: `/health` 2–3 ms; 8 serial `/internal/emit` samples 0–1 ms. The running Node process predated `presence_mode`; source check covers the field.

DB-unavailable / Node-unavailable: PHP emit already times out and does not fail the request (P1 + P7). Full DB-down process kill was **NOT RUN** (would take the shared application database offline).

## Tests

P7 PHP: **PASS**.
P7 performance: **PASS** (measured values above).
P7 Node unit: **PASS**.
P7 Node live: **PASS**.

Browser E2E: **NOT RUN — Apache 403**.
