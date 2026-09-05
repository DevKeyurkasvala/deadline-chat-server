# Chat STEP 7 / P6 — Audit + Retention

## What shipped

- Dedicated `chat_audit_log` (task_activity_log cannot cover DM/project/client).
- High-value events only: conversation opened, participant added (new inserts), message send/edit/delete/forward, attachment upload/download, reaction toggle, mention created, unauthorized access.
- Not logged: typing, presence, read receipts.
- Admin viewer `chat_audit.php` + paginated `GET apiv1/chat/audit.php` + CSRF-protected CSV export.
- Retention OFF by default. CLI worker `cron/chat_retention_worker.php` with `GET_LOCK`, batch 100, trusted storage root, skip-on-unlink-failure.
- Lifecycle: active → soft-deleted → retention-eligible → cleanup.

## Schema

Applied via `php install_chat_step7.php` after `backups/chat_pre_step7.sql` (row counts only). Existing tables were not rewritten.

## Tests

P6 PHP: **PASS** (including worker OFF + rerun/idempotent).
P6 Node: **PASS**.
P0–P5 regression after P6: **PASS**.

Browser E2E: **NOT RUN — Apache 403**.
