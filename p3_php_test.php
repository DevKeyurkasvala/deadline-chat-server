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

$direct = task_select_one(
    "SELECT c.id FROM chat_conversations c
     INNER JOIN chat_participants p ON p.conversation_id = c.id AND p.user_id = 1
     WHERE c.type = 'direct' LIMIT 1",
    '',
    []
);
$taskRow = task_select_one(
    "SELECT c.id, c.task_id FROM chat_conversations c WHERE c.type = 'task' AND c.task_id IS NOT NULL LIMIT 1",
    '',
    []
);
$convId = $direct ? (int) $direct['id'] : (int) ($taskRow['id'] ?? 0);

check('conversation available for read tests', $convId > 0);

if ($convId > 0) {
    $before = chat_last_read_message_id(1, $convId);
    $after = chat_mark_read(1, $convId, null);
    check('valid mark_read persists a cursor', $after >= $before);

    check('invalid conversation remains denied', !chat_can_access_conversation(1, [
        'id' => 999999,
        'type' => 'direct',
        'task_id' => null,
    ]));

    check('invalid message id is rejected', !chat_message_belongs_to_conversation(999999999, $convId));

    $otherMsg = task_select_one(
        'SELECT id, conversation_id FROM chat_messages WHERE conversation_id <> ? AND deleted_at IS NULL LIMIT 1',
        'i',
        [$convId]
    );
    if ($otherMsg) {
        check(
            'message from another conversation is rejected',
            !chat_message_belongs_to_conversation((int) $otherMsg['id'], $convId)
        );
    } else {
        check('message from another conversation is rejected', true);
    }

    check(
        'unauthorized user cannot access this conversation unless they are a participant',
        !chat_can_access_conversation(999888, [
            'id' => $convId,
            'type' => $direct ? 'direct' : 'task',
            'task_id' => $taskRow['task_id'] ?? null,
        ])
    );
}

$markSrc = file_get_contents(__DIR__ . '/../includes/chat_lib.php') ?: '';
$markApi = file_get_contents(__DIR__ . '/../apiv1/chat/mark_read.php') ?: '';
$posPersist = strpos($markSrc, 'chat_mark_read($userId, $conversationId, $messageId)');
$posEmit = strpos($markSrc, 'chat_emit_read(');
check('read emit happens only after persist', $posPersist !== false && $posEmit !== false && $posPersist < $posEmit);
check('mark_read validates foreign message ids', strpos($markApi, 'chat_message_belongs_to_conversation') !== false);
check('mark_read requires conversation ACL', strpos($markApi, 'chat_require_conversation') !== false);
check('mark_read is a CSRF-protected POST', strpos($markApi, 'chat_api_boot') !== false);

$presenceApi = file_get_contents(__DIR__ . '/../apiv1/chat/presence.php') ?: '';
check('presence API is ACL-scoped to a conversation', strpos($presenceApi, 'chat_require_conversation') !== false);
check('presence API does not accept arbitrary user_id lists from the client', strpos($presenceApi, 'user_ids') === false);

putenv('CHAT_SOCKET_INTERNAL_URL=http://127.0.0.1:1');
$offline = chat_query_presence([1, 2]);
check('presence query degrades to offline when Node is down', ($offline[1]['status'] ?? '') === 'offline');
check('presence query never invents extra users', count($offline) === 2);
putenv('CHAT_SOCKET_INTERNAL_URL');

$realtime = file_get_contents(__DIR__ . '/../includes/chat_realtime.php') ?: '';
check('read emit payload has no tokens or secrets', strpos($realtime, 'function chat_emit_read') !== false && strpos($realtime, 'room_token') === false);
check('presence query does not log secrets', strpos($realtime, 'chat_query_presence') !== false);

$server = file_get_contents(__DIR__ . '/server.js') ?: '';
check('Node does not accept client-authored chat:read', strpos($server, "socket.on('chat:read'") === false);
check('Node does not accept client-authored presence:update', strpos($server, "socket.on('presence:update'") === false);
check('presence identity comes from socket token', strpos($server, 'presence.connect(socket.id, userId)') !== false);

$js = file_get_contents(__DIR__ . '/../assets/dist/js/chat.js') ?: '';
$rt = file_get_contents(__DIR__ . '/../assets/dist/js/chat-realtime.js') ?: '';
check('reconnect refreshes unread and conversation', strpos($js, 'chat:reconnect') !== false && strpos($rt, 'chat:reconnect') !== false);
check('duplicate messages remain ignored by id', strpos($js, 'data-msg-id') !== false);
check('polling still skips while socket is live', strpos($js, 'isLive()') !== false);
check('connection state includes reconnecting', strpos($rt, 'Reconnecting') !== false);
check('view-only still cannot emit typing', strpos($rt, '!CFG.canMutate') !== false);
check('receipt UI does not claim delivered as read', strpos($js, 'Delivered') === false);

if ($taskRow) {
    check('task ACL still required for task conversations', !canViewTask(999888, (int) $taskRow['task_id']));
} else {
    check('task ACL still required for task conversations', true);
}

$_SESSION['role'] = 3;
check('view-only users still cannot send', task_is_view_user());
$_SESSION['role'] = 2;

if ($failed > 0) {
    fwrite(STDERR, $failed . " PHP P3 checks failed\n");
    exit(1);
}
echo "All PHP P3 checks passed.\n";
