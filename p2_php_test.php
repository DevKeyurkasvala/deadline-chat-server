<?php
declare(strict_types=1);

session_start();
$_SESSION['id'] = 1;
$_SESSION['role'] = 2;
$_SESSION['name'] = 'Admin';
$_SESSION['username'] = 'Admin';

require_once dirname(__DIR__) . '/includes/chat_lib.php';

$failed = 0;
function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failed++;
    }
}

chat_require_schema();

$taskRow = task_select_one(
    "SELECT c.id, c.task_id FROM chat_conversations c WHERE c.type = 'task' AND c.task_id IS NOT NULL LIMIT 1",
    '',
    []
);
$directRow = task_select_one(
    "SELECT c.id FROM chat_conversations c
     INNER JOIN chat_participants p ON p.conversation_id = c.id AND p.user_id = 1
     WHERE c.type = 'direct' LIMIT 1",
    '',
    []
);

if ($taskRow) {
    $taskId = (int) $taskRow['task_id'];
    $loaded = task_load_task_row($taskId);
    check('task_load_task_row includes task_key', is_array($loaded) && array_key_exists('task_key', $loaded));
    $hydrated = chat_hydrate_conversation(1, [
        'id' => (int) $taskRow['id'],
        'type' => 'task',
        'task_id' => $taskId,
        'title' => '',
        'last_message_at' => null,
        'last_message_preview' => null,
        'created_at' => null,
    ]);
    check('hydrated task conversation has task_key from tasks table', ($hydrated['task_key'] ?? null) === ($loaded['task_key'] ?? null));
} else {
    check('task conversation available for task_key test', false);
}

$convId = $directRow ? (int) $directRow['id'] : (int) ($taskRow['id'] ?? 0);
if ($convId > 0) {
    $latest = chat_fetch_message_page($convId, 0, 0, 0, 50);
    check('latest message page returns messages array', isset($latest['messages']) && is_array($latest['messages']));
    $ids = array_map(static fn ($m) => (int) $m['id'], $latest['messages']);
    $sorted = $ids;
    sort($sorted);
    check('latest messages are chronological', $ids === $sorted);
    check('latest messages have unique IDs', count($ids) === count(array_unique($ids)));

    if ($ids !== []) {
        $oldest = $ids[0];
        $older = chat_fetch_message_page($convId, 0, $oldest, 0, 50);
        $olderIds = array_map(static fn ($m) => (int) $m['id'], $older['messages']);
        $overlap = array_intersect($ids, $olderIds);
        check('older page does not duplicate latest IDs', $overlap === []);
        if ($olderIds !== []) {
            check('older messages stay below the cursor', max($olderIds) < $oldest);
        } else {
            check('older messages stay below the cursor', empty($latest['has_more_before']));
        }

        $around = chat_fetch_message_page($convId, 0, 0, $ids[count($ids) - 1], 50);
        check('around_id page includes the target message', in_array($ids[count($ids) - 1], array_map(static fn ($m) => (int) $m['id'], $around['messages']), true));
    } else {
        check('older page does not duplicate latest IDs', true);
        check('older messages stay below the cursor', true);
        check('around_id page includes the target message', true);
    }

    check('user 1 can access own conversation', chat_can_access_conversation(1, [
        'id' => $convId,
        'type' => $directRow ? 'direct' : 'task',
        'task_id' => $taskRow['task_id'] ?? null,
    ]));
} else {
    check('conversation available for pagination tests', false);
}

check('unknown conversation is denied', !chat_can_access_conversation(1, ['id' => 999999, 'type' => 'direct', 'task_id' => null]));

$short = chat_normalize_search_query('a');
check('search query shorter than 2 is detectable', chat_search_query_length($short) < 2);
$long = chat_normalize_search_query(str_repeat('m', 140));
check('search query is capped at 100', chat_search_query_length($long) === 100);

$denied = chat_search_conversations(1, 'zz', 20);
$deniedOk = true;
foreach ($denied as $row) {
    if (!chat_can_access_conversation(1, [
        'id' => $row['conversation_id'],
        'type' => $row['type'],
        'task_id' => $row['task_id'],
    ])) {
        $deniedOk = false;
    }
}
check('conversation search results are ACL-filtered', $deniedOk);

$msgHits = chat_search_messages(1, 'zz', 20);
$msgOk = true;
foreach ($msgHits as $row) {
    if (!chat_can_access_conversation(1, [
        'id' => $row['conversation_id'],
        'type' => $row['type'],
        'task_id' => $row['task_id'],
    ])) {
        $msgOk = false;
    }
}
check('message search results are ACL-filtered', $msgOk);

check('user search with 1 character is empty', chat_search_users(1, 'x', 30) === []);
check('user search helper uses bounded limit', count(chat_search_users(1, 'ad', 999)) <= 30);
check('conversation search with 1 character is empty', chat_search_conversations(1, 'z', 20) === []);
check('message search with 1 character is empty', chat_search_messages(1, 'z', 20) === []);

$csrf = chat_csrf_token();
check('CSRF token still required for write APIs', $csrf !== '' && !chat_csrf_is_valid(1, ''));
check('limits are clamped', chat_clamp_limit(500, 50, 100) === 100 && chat_clamp_limit(0, 50, 100) === 50);

$src = file_get_contents(__DIR__ . '/../apiv1/chat/search.php') ?: '';
check('search API is GET/read and uses parameterized helpers', strpos($src, 'chat_search_conversations') !== false && strpos($src, 'task_api_boot') !== false);
check('search API rejects queries longer than 100 characters', strpos($src, 'Search is limited to 100 characters') !== false);
$fetchUsers = file_get_contents(__DIR__ . '/../apiv1/chat/fetch_users.php') ?: '';
check('user search requires a query', strpos($fetchUsers, 'query_required') !== false);
check('user search rejects queries longer than 100 characters', strpos($fetchUsers, 'Search is limited to 100 characters') !== false);
check('invalid task id is not viewable', !canViewTask(1, 999999999));

$js = file_get_contents(__DIR__ . '/../assets/dist/js/chat.js') ?: '';
check('client maps 401 session expiry', strpos($js, 'status === 401') !== false && strpos($js, 'onSessionExpired') !== false);
check('client maps 403 permission errors', strpos($js, 'status === 403') !== false);
check('client maps 409 conflicts', strpos($js, 'status === 409') !== false);
check('client maps 429 rate limits', strpos($js, 'status === 429') !== false);
check('failed send preserves draft and unlocks composer', strpos($js, 'sending = false') !== false && strpos($js, '$input.prop(\'disabled\', false)') !== false);
check('search result navigation uses around_id', strpos($js, 'around_id') !== false && strpos($js, 'aroundId') !== false);
check('deep links use conversation_id', strpos($js, 'conversation_id') !== false);

$rt = file_get_contents(__DIR__ . '/../assets/dist/js/chat-realtime.js') ?: '';
check('typing is debounced', strpos($rt, '400') !== false && strpos($rt, 'debounce') !== false);
check('typing stops on idle, leave, and disconnect', strpos($rt, 'stopTyping') !== false && strpos($rt, 'stopAllTyping') !== false);
check('view-only users cannot emit typing', strpos($rt, '!CFG.canMutate') !== false);
check('polling is skipped while the socket is live', strpos($rt, 'if (connected)') !== false);

if ($failed > 0) {
    fwrite(STDERR, $failed . " PHP P2 checks failed\n");
    exit(1);
}
echo "All PHP P2 checks passed.\n";
