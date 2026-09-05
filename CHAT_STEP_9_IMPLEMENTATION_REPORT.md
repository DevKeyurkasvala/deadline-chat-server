# Chat STEP 9 / P8 — UX + mobile + accessibility

## What shipped

- Conversation list: loading / empty / error / retry / load more.
- Messages and task drawer: loading / error / retry.
- Search: Searching…, empty, error, retry. Results still `escapeHtml`.
- Mentions: keyboard Up/Down/Enter/Escape; task-drawer mention list; no raw HTML insert.
- Attachments: filename truncation, size label, upload progress, remove-before-send. Download URLs only.
- Reply preview jumps via `around_id` when the source is not in the current window. Cancel remains.
- Mobile: 320–414 CSS, thread/list swap, Back control, visible (not hover-only) actions.
- Session 401: stop further API calls, disconnect realtime, redirect to login. Draft still preserved on send failure.
- Aria labels, live regions, focus-visible. Visible Attach/Send labels kept.

## Tests

P8 PHP (static UX/a11y contract): **PASS**.
P8 Node: **PASS**.

Browser E2E (desktop Chrome + 375/390 mobile): **NOT RUN — Apache 403**.

`curl -sI http://127.0.0.1/deadline/chat.php` returned HTTP 403. No browser session, clicks, or viewport tests were performed.
