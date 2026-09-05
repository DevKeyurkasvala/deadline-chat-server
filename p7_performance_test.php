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

function timed_ms(callable $fn): array
{
    $started = microtime(true);
    $result = $fn();
    return [$result, (int) round((microtime(true) - $started) * 1000)];
}

chat_require_schema();

$results = [];

[$list, $listMs] = timed_ms(static function () {
    return chat_list_conversations_page(1, 'all', 100, 0);
});
$results['conversation_list_ms'] = $listMs;
$results['conversation_list_count'] = count($list['conversations'] ?? []);
check('conversation list ran', is_array($list) && isset($list['conversations']));
echo 'MEASURE conversation_list count=' . $results['conversation_list_count'] . ' ms=' . $listMs . "\n";

$convId = 0;
if (!empty($list['conversations'][0]['id'])) {
    $convId = (int) $list['conversations'][0]['id'];
} else {
    $row = task_select_one(
        'SELECT c.id FROM chat_conversations c
         INNER JOIN chat_participants p ON p.conversation_id = c.id AND p.user_id = 1
         LIMIT 1',
        '',
        []
    );
    $convId = $row ? (int) $row['id'] : 0;
}

if ($convId > 0) {
    [$history, $histMs] = timed_ms(static function () use ($convId) {
        return chat_fetch_message_page($convId, 0, 0, 0, 1000);
    });
    $results['message_history_ms'] = $histMs;
    $results['message_history_count'] = count($history['messages'] ?? []);
    check('message history ran', isset($history['messages']));
    echo 'MEASURE message_history conversation_id=' . $convId . ' count=' . $results['message_history_count'] . ' ms=' . $histMs . "\n";

    [$unread, $unreadMs] = timed_ms(static function () {
        return chat_unread_total(1);
    });
    $results['unread_ms'] = $unreadMs;
    $results['unread_total'] = (int) $unread;
    check('unread calculation ran', is_int($unread));
    echo 'MEASURE unread_total=' . $unread . ' ms=' . $unreadMs . "\n";

    [$search, $searchMs] = timed_ms(static function () {
        return [
            'conversations' => chat_search_conversations(1, 'ta', 20, 0),
            'messages' => chat_search_messages(1, 'ta', 20, 0, 0),
        ];
    });
    $results['search_ms'] = $searchMs;
    check('search ran', is_array($search));
    echo 'MEASURE search ms=' . $searchMs . "\n";

    [$rich, $richMs] = timed_ms(static function () use ($convId) {
        return chat_fetch_message_page($convId, 0, 0, 0, 50);
    });
    $results['rich_hydrate_ms'] = $richMs;
    check('reactions/attachments metadata hydrate ran', isset($rich['messages']));
    echo 'MEASURE rich_hydrate count=' . count($rich['messages'] ?? []) . ' ms=' . $richMs . "\n";
} else {
    echo "MEASURE message_history NOT RUN — no conversation for user 1\n";
    echo "MEASURE unread NOT RUN — no conversation for user 1\n";
    echo "MEASURE search still running against empty-ish corpus\n";
    [$search, $searchMs] = timed_ms(static function () {
        return chat_search_conversations(1, 'ta', 20, 0);
    });
    $results['search_ms'] = $searchMs;
    check('search ran', is_array($search));
    echo 'MEASURE search ms=' . $searchMs . "\n";
    check('message history ran', false);
    check('unread calculation ran', false);
}

[$notifyMap, $notifyMs] = timed_ms(static function () {
    return chat_unread_chat_notification_map([1], 'chat', 1);
});
$results['notification_lookup_ms'] = $notifyMs;
check('notification lookup ran', is_array($notifyMap));
echo 'MEASURE notification_lookup ms=' . $notifyMs . "\n";

$emitTimes = [];
for ($i = 0; $i < 5; $i++) {
    $started = microtime(true);
    chat_emit('chat:message', ['user:1'], ['conversation_id' => 1, 'probe' => true]);
    $emitTimes[] = (int) round((microtime(true) - $started) * 1000);
}
$results['emit_ms_samples'] = $emitTimes;
echo 'MEASURE concurrent_internal_emits_serial samples_ms=' . implode(',', $emitTimes) . "\n";
check('internal emit samples completed without throwing', count($emitTimes) === 5);

$presenceStarted = microtime(true);
$presence = chat_query_presence([1]);
$presenceMs = (int) round((microtime(true) - $presenceStarted) * 1000);
$results['presence_query_ms'] = $presenceMs;
check('presence query degrades safely', isset($presence[1]['status']));
echo 'MEASURE presence_query ms=' . $presenceMs . "\n";

echo 'MEASURE_JSON ' . json_encode($results, JSON_UNESCAPED_SLASHES) . "\n";

if ($failed) {
    exit(1);
}
echo "All PHP P7 performance checks passed.\n";
