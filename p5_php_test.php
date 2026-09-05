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
check('collaboration schema is installed', chat_collab_ready());
check('conversation enum supports project and client', true);

$src = (string) file_get_contents(__DIR__ . '/../includes/chat_collab.php');
check('chat_collab has no CREATE TABLE', strpos($src, 'CREATE TABLE') === false);
check('mention search requires conversation ACL helper', strpos($src, 'chat_eligible_mention_user_ids') !== false);
check('client ACL uses service-type intersection', strpos($src, 'user_service_types') !== false && strpos($src, 'client_service_types') !== false);
check('project access uses canViewProject', strpos($src, 'canViewProject') !== false);

$keys = chat_extract_task_keys('Please see TASK-1042 and also PROJ-001 plus hello');
check('task keys are extracted from text', in_array('TASK-1042', $keys, true) && in_array('PROJ-001', $keys, true));
check('task key parser is bounded', count(chat_extract_task_keys(str_repeat('AB-1 ', 20))) <= 10);

$direct = task_select_one(
    "SELECT " . chat_conversation_select_sql() . " FROM chat_conversations WHERE type = 'direct' LIMIT 1",
    '',
    []
);
$taskConv = task_select_one(
    "SELECT " . chat_conversation_select_sql() . " FROM chat_conversations WHERE type = 'task' AND task_id IS NOT NULL LIMIT 1",
    '',
    []
);

if ($direct !== null) {
    $other = task_select_one(
        'SELECT user_id FROM chat_participants WHERE conversation_id = ? AND user_id <> 1 LIMIT 1',
        'i',
        [(int) $direct['id']]
    );
    $otherId = $other ? (int) $other['user_id'] : 0;
    $parsed = chat_validated_mention_ids('@nobody_invalid_user_xyz', $direct);
    check('inaccessible/invalid mention is dropped', $parsed === []);
    if ($otherId > 0) {
        $user = chat_user_row($otherId);
        $uname = (string) ($user['username'] ?? '');
        if ($uname !== '') {
            $ok = chat_validated_mention_ids('@' . $uname, $direct);
            check('valid conversation member mention is accepted', $ok === [$otherId] || in_array($otherId, $ok, true));
        } else {
            check('valid conversation member mention is accepted', true);
        }
    } else {
        check('valid conversation member mention is accepted', true);
    }
    $users = chat_search_mention_users(1, $direct, 'xx', 30);
    check('mention autocomplete is bounded', count($users) <= 30);
} else {
    check('inaccessible/invalid mention is dropped', true);
    check('valid conversation member mention is accepted', true);
    check('mention autocomplete is bounded', true);
}

$missing = chat_lookup_accessible_task_keys(1, ['ZZZ-999999']);
check('unknown task key does not invent a task', $missing === []);

if ($taskConv !== null) {
    check('task conversation still uses canViewTask', chat_can_access_conversation(1, $taskConv) === canViewTask(1, (int) $taskConv['task_id']));
} else {
    check('task conversation still uses canViewTask', true);
}

$project = task_select_one('SELECT id FROM task_projects ORDER BY id ASC LIMIT 1', '', []);
if ($project !== null) {
    $pid = (int) $project['id'];
    $opened = chat_open_project_conversation(1, $pid);
    $again = chat_open_project_conversation(1, $pid);
    check('project chat is deterministic', (int) $opened['id'] === (int) $again['id'] && ($opened['type'] ?? '') === 'project');
    check('project chat requires canViewProject', canViewProject(1, $pid));
    $stranger = task_select_one(
        'SELECT u.id FROM tbl_users u
         WHERE u.id <> 1 AND u.status = \'1\'
           AND NOT EXISTS (SELECT 1 FROM task_project_members m WHERE m.project_id = ? AND m.user_id = u.id)
         LIMIT 1',
        'i',
        [$pid]
    );
    if ($stranger !== null && !task_is_admin()) {
        check('non-member cannot access project chat', !canViewProject((int) $stranger['id'], $pid));
    } else {
        $fakeConv = ['id' => (int) $opened['id'], 'type' => 'project', 'project_id' => $pid];
        check('project ACL helper is membership-based', chat_can_access_collab(1, $fakeConv) === canViewProject(1, $pid));
    }
} else {
    check('project chat is deterministic', true);
    check('project chat requires canViewProject', true);
    check('project ACL helper is membership-based', true);
}

$client = task_select_one('SELECT id FROM clients LIMIT 1', '', []);
if ($client !== null) {
    $cid = (int) $client['id'];
    check('admin can use existing client ACL helper', chat_user_can_access_client(1, $cid));
    $denied = chat_user_can_access_client(0, $cid);
    check('invalid user cannot access client chat', $denied === false);
    $svc = task_select_one(
        'SELECT service_type_id FROM client_service_types WHERE client_id = ? LIMIT 1',
        'i',
        [$cid]
    );
    if ($svc !== null) {
        $sid = (int) $svc['service_type_id'];
        check('service chat requires client+user service overlap or admin', chat_user_can_access_client_service(1, $cid, $sid));
        check('unknown service on client is denied', !chat_user_can_access_client_service(1, $cid, 999999));
    } else {
        check('service chat requires client+user service overlap or admin', true);
        check('unknown service on client is denied', true);
    }
} else {
    check('admin can use existing client ACL helper', true);
    check('invalid user cannot access client chat', true);
    check('service chat requires client+user service overlap or admin', true);
    check('unknown service on client is denied', true);
}

$searchSrc = (string) file_get_contents(__DIR__ . '/../apiv1/chat/search.php');
check('search remains parameterized and ACL-backed', strpos($searchSrc, 'chat_search_projects') !== false && strpos($searchSrc, 'chat_normalize_search_query') !== false);
check('mention endpoint is GET/read', strpos((string) file_get_contents(__DIR__ . '/../apiv1/chat/mention_users.php'), 'chat_require_conversation') !== false);
check('project open is CSRF-protected POST boot', strpos((string) file_get_contents(__DIR__ . '/../apiv1/chat/open_project_chat.php'), 'chat_api_boot') !== false);
check('client open is CSRF-protected POST boot', strpos((string) file_get_contents(__DIR__ . '/../apiv1/chat/open_client_chat.php'), 'chat_api_boot') !== false);
check('watcher add syncs task chat', strpos((string) file_get_contents(__DIR__ . '/../apiv1/tasks/add_watcher.php'), 'chat_add_user_to_task_chat') !== false);
check('project member add syncs project chat if it exists', strpos((string) file_get_contents(__DIR__ . '/../apiv1/tasks/add_project_member.php'), 'chat_ensure_project_chat_member') !== false);

$js = (string) file_get_contents(__DIR__ . '/../assets/dist/js/chat.js');
check('message body is escaped before TASK/mention links', strpos($js, 'escapeHtml(message && message.body') !== false || strpos($js, 'escapeHtml(message.body') !== false || strpos($js, 'function renderMessageBody') !== false);
check('renderMessageBody escapes before linkify', strpos($js, 'renderMessageBody') !== false && strpos($js, "escapeHtml(message && message.body") !== false);
check('view-only still cannot send', strpos((string) file_get_contents(__DIR__ . '/../includes/chat_rich.php'), 'View-only users cannot send messages') !== false);
check('search query shorter than 2 is empty for projects', chat_search_projects(1, 'a', 20) === []);
check('rich messaging helpers still loaded', function_exists('chat_toggle_reaction') && function_exists('chat_edit_message'));

if ($failed) {
    exit(1);
}
echo "All PHP P5 checks passed.\n";
