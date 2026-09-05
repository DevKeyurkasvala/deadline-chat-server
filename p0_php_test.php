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

check('secret is configured from env/file and is not a forbidden placeholder', chat_secret_is_configured());
check('chat_internal_secret is non-empty when configured', chat_internal_secret() !== '');

$room = chat_issue_room_token(1, 2);
check('room token issued', $room !== '');
check('room token accepts matching user+conversation', chat_verify_room_token($room, 1, 2));
check('room token rejects other user', !chat_verify_room_token($room, 3, 2));
check('room token rejects other conversation', !chat_verify_room_token($room, 1, 99));

$sock = chat_issue_socket_token(1);
$parsed = chat_verify_socket_token((string) ($sock['token'] ?? ''));
check('socket token user matches', ($parsed['user_id'] ?? 0) === 1);
check('admin socket token can_mutate', !empty($sock['can_mutate']) && !empty($parsed['can_mutate']));

$_SESSION['role'] = 3;
$viewSock = chat_issue_socket_token(3);
$viewParsed = chat_verify_socket_token((string) ($viewSock['token'] ?? ''));
check('view-only socket token cannot mutate', empty($viewSock['can_mutate']) && empty($viewParsed['can_mutate']));
$_SESSION['role'] = 2;

$legacy = '1.' . (time() + 3600) . '.' . hash_hmac('sha256', '1.' . (time() + 3600), chat_internal_secret());
check('legacy 3-part socket token rejected', (chat_verify_socket_token($legacy)['user_id'] ?? 1) === 0);

$lib = file_get_contents(__DIR__ . '/../includes/chat_lib.php') ?: '';
check('chat_lib has no CREATE TABLE', strpos($lib, 'CREATE TABLE') === false);
check('chat_lib has no chat_ensure_schema', strpos($lib, 'chat_ensure_schema') === false);
check('chat tables are present (read-only check)', chat_tables_ready());

$deny = ['type' => 'direct', 'id' => 999999, 'task_id' => null];
check('unknown direct conversation is denied', !chat_can_access_conversation(1, $deny));

$limit = chat_int_setting('rate_limit_messages', 20);
$window = chat_int_setting('rate_limit_window_seconds', 60);
check('rate limit is configured', $limit >= 1 && $window >= 10);

$sent = task_select_one(
    'SELECT COUNT(*) AS cnt FROM chat_messages WHERE sender_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)',
    'ii',
    [1, $window]
);
$sentCount = (int) ($sent['cnt'] ?? 0);
check('rate limit query is readable without DDL', $sentCount >= 0);
check('user 1 is currently under the send cap (normal usage)', $sentCount < $limit);

if ($failed > 0) {
    fwrite(STDERR, $failed . " PHP P0 checks failed\n");
    exit(1);
}
echo "All PHP P0 checks passed.\n";
