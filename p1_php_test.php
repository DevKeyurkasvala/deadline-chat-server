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

$csrf = chat_csrf_token();
check('CSRF token is issued for the session user', strlen($csrf) >= 32);
check('valid CSRF token is accepted', chat_csrf_is_valid(1, $csrf));
check('missing CSRF token is rejected', !chat_csrf_is_valid(1, ''));
check('invalid CSRF token is rejected', !chat_csrf_is_valid(1, str_repeat('a', 64)));
check('other user cannot use this session CSRF token', !chat_csrf_is_valid(99, $csrf));

$sock = chat_issue_socket_token(1);
$parsed = chat_verify_socket_token((string) ($sock['token'] ?? ''));
check('valid socket token works', ($parsed['user_id'] ?? 0) === 1);
check('socket token TTL is session-aware and <= 4h', ($sock['ttl'] ?? 0) >= 300 && ($sock['ttl'] ?? 0) <= 14400);
check('socket token includes CSRF for refresh', ($sock['csrf_token'] ?? '') === $csrf);

$expiredSock = '1.' . (time() - 30) . '.1.' . hash_hmac('sha256', '1.' . (time() - 30) . '.1', chat_internal_secret());
check('expired socket token rejected', (chat_verify_socket_token($expiredSock)['user_id'] ?? 1) === 0);
check('invalid socket token rejected', (chat_verify_socket_token('1.9999999999.1.deadbeef')['user_id'] ?? 1) === 0);

$expiredRoom = '1.2.' . (time() - 30) . '.' . hash_hmac('sha256', '1.2.' . (time() - 30), chat_internal_secret());
check('expired room token rejected', !chat_verify_room_token($expiredRoom, 1, 2));
$validRoom = chat_issue_room_token(1, 2);
check('wrong-user room token rejected', !chat_verify_room_token($validRoom, 99, 2));

check('HTTPS helper upgrades mixed-content URLs', chat_force_https_url('http://chat.example.com') === 'https://chat.example.com');

$src = file_get_contents(__DIR__ . '/../includes/task_lib.php') ?: '';
$notifyPos = strpos($src, 'function task_notify_assignment');
$chatPos = strpos($src, 'chat_add_user_to_task_chat');
$selfAssignPos = strpos($src, 'assigneeId !== $actorId');
check(
    'assignment always syncs task chat before notification gating',
    $notifyPos !== false && $chatPos !== false && $selfAssignPos !== false && $chatPos < $selfAssignPos
);

putenv('CHAT_SOCKET_INTERNAL_URL=http://127.0.0.1:1');
try {
    chat_emit('chat:message', ['user:1'], ['conversation_id' => 2, 'message' => ['id' => 0]]);
    check('Node unavailable emit does not throw', true);
} catch (Throwable $e) {
    check('Node unavailable emit does not throw', false);
}
putenv('CHAT_SOCKET_INTERNAL_URL');

$lib = file_get_contents(__DIR__ . '/../includes/chat_lib.php') ?: '';
$realtime = file_get_contents(__DIR__ . '/../includes/chat_realtime.php') ?: '';
check('emit logger does not interpolate secrets or bodies', strpos($realtime, 'chat_log_emit') !== false && strpos($realtime, 'message body') === false);
check('chat notifications skip the sender', strpos($lib, 'if ($uid === $senderId)') !== false);
check('chat notifications coalesce unread rows', strpos($lib, 'chat_upsert_message_notification') !== false);

if ($failed > 0) {
    fwrite(STDERR, $failed . " PHP P1 checks failed\n");
    exit(1);
}
echo "All PHP P1 checks passed.\n";
