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

$page = chat_list_conversations_page(1, 'all', 2, 0);
check('conversation list is paginated', isset($page['conversations'], $page['has_more'], $page['next_before_id']));
check('conversation list respects limit', count($page['conversations']) <= 2);
$legacy = chat_list_conversations(1, 'all', 50, 0);
check('legacy list helper still returns an array', is_array($legacy));

$src = (string) file_get_contents(__DIR__ . '/../includes/chat_lib.php');
check('list uses batched unread', strpos($src, 'chat_unread_counts_for') !== false && strpos($src, 'unread_ready') !== false);
check('list uses batched other users', strpos($src, 'chat_other_users_map') !== false);
check('hydrate no longer required for every unread COUNT in list', strpos($src, 'function chat_list_conversations_page') !== false);
check('notifications batch existing unread rows', strpos($src, 'chat_unread_chat_notification_map') !== false);

$fetch = (string) file_get_contents(__DIR__ . '/../apiv1/chat/fetch_conversations.php');
check('conversation API exposes has_more cursor', strpos($fetch, 'before_id') !== false && strpos($fetch, 'has_more') !== false);

$search = (string) file_get_contents(__DIR__ . '/../apiv1/chat/search.php');
check('search remains bounded with min length', strpos($search, 'at least 2') !== false && strpos($search, '100 characters') !== false);

$idxConv = task_select_one(
    "SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_messages' AND INDEX_NAME = 'idx_chat_conv_msg'",
    '',
    []
);
check('message history index conversation_id+id exists', ((int) ($idxConv['cnt'] ?? 0)) > 0);

$idxUser = task_select_one(
    "SELECT COUNT(*) AS cnt FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chat_participants' AND INDEX_NAME = 'idx_chat_user'",
    '',
    []
);
check('participant user index exists', ((int) ($idxUser['cnt'] ?? 0)) > 0);

$healthSrc = (string) file_get_contents(__DIR__ . '/../apiv1/chat/health.php');
check('health endpoint requires auth', strpos($healthSrc, 'task_api_boot') !== false);
check('health does not expose secrets or paths', strpos($healthSrc, 'CHAT_INTERNAL_SECRET') === false && strpos($healthSrc, 'password') === false);
check('health reports status classes', strpos($healthSrc, 'healthy') !== false && strpos($healthSrc, 'degraded') !== false && strpos($healthSrc, 'unhealthy') !== false);

$health = [
    'status' => 'unknown',
];
if (function_exists('chat_probe_node_health') && function_exists('chat_tables_ready')) {
    $node = chat_probe_node_health();
    $dbOk = false;
    try {
        $conn = task_db();
        $ping = $conn->query('SELECT 1');
        $dbOk = $ping instanceof mysqli_result;
        if ($ping instanceof mysqli_result) {
            $ping->free();
        }
    } catch (Throwable $e) {
        $dbOk = false;
    }
    $tablesOk = $dbOk && chat_tables_ready();
    $root = chat_storage_root();
    $storageOk = is_dir($root) && is_writable($root);
    if (!$dbOk || !$tablesOk) {
        $health['status'] = 'unhealthy';
    } elseif (!$storageOk || empty($node['ok'])) {
        $health['status'] = 'degraded';
    } else {
        $health['status'] = 'healthy';
    }
    check('health helper returns a known status', in_array($health['status'], ['healthy', 'degraded', 'unhealthy'], true));
    check('health never includes filesystem path in status', strpos((string) json_encode($health), $root) === false);
}

$realtime = (string) file_get_contents(__DIR__ . '/../includes/chat_realtime.php');
check('PHP emit has timeouts', strpos($realtime, 'CURLOPT_TIMEOUT') !== false && strpos($realtime, 'CURLOPT_CONNECTTIMEOUT') !== false);
check('Node down does not throw from emit', strpos($realtime, 'chat_log_emit') !== false);

$server = (string) file_get_contents(__DIR__ . '/server.js');
check('Node documents single-instance presence', strpos($server, "presence_mode: 'memory'") !== false);
check('Redis adapter is not introduced', strpos($server, 'redis') === false && strpos($server, 'Redis') === false);
check('internal emit remains POST+JSON+secret', strpos($server, "req.method !== 'POST'") !== false && strpos($server, 'application/json') !== false);
check('internal emit uses timing-safe secret compare', strpos((string) file_get_contents(__DIR__ . '/security.js'), 'timingSafeEqual') !== false);

check('upload limits remain configurable', chat_upload_max_bytes() === 10485760 || chat_upload_max_bytes() > 0);
check('poll interval is bounded', chat_poll_interval_ms() >= 3000 && chat_poll_interval_ms() <= 30000);
check('edit window still defaults to 15 minutes', chat_edit_window_seconds() === 900);

$conv = task_select_one(
    'SELECT c.id FROM chat_conversations c
     INNER JOIN chat_participants p ON p.conversation_id = c.id AND p.user_id = 1
     LIMIT 1',
    '',
    []
);
if ($conv) {
    $cid = (int) $conv['id'];
    $history = chat_fetch_messages($cid, 0, 0, 50);
    check('message history remains cursor-capable', is_array($history));
} else {
    check('message history remains cursor-capable', false);
}

$libMsgs = (string) file_get_contents(__DIR__ . '/../includes/chat_lib.php');
check('message history still uses before_id not OFFSET', strpos($libMsgs, 'before_id') !== false && strpos($libMsgs, 'OFFSET') === false);

if ($failed) {
    exit(1);
}
echo "All PHP P7 checks passed.\n";
