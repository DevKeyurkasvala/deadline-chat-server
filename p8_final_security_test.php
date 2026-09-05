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

check('unauthenticated helper rejects empty session user', chat_verify_socket_token('')['user_id'] === 0);
check('invalid CSRF is rejected', !chat_csrf_is_valid(1, 'not-a-token'));
check('missing CSRF is rejected', !chat_csrf_is_valid(1, ''));
$other = chat_csrf_token();
$_SESSION['id'] = 2;
check('other-session CSRF cannot be reused after user switch without remint', !chat_csrf_is_valid(2, $other) || $other === '');
$_SESSION['id'] = 1;

check('unknown conversation is denied', !chat_can_access_conversation(1, ['id' => 999999, 'type' => 'direct', 'task_id' => null]));
check('unknown task is not viewable', !canViewTask(1, 999999999));
check('php upload extension remains blocked', in_array('php', CHAT_BLOCKED_EXTENSIONS, true));
check('phtml remains blocked', in_array('phtml', CHAT_BLOCKED_EXTENSIONS, true));
check('phar remains blocked', in_array('phar', CHAT_BLOCKED_EXTENSIONS, true));
check('js/html/svg remain blocked', in_array('js', CHAT_BLOCKED_EXTENSIONS, true) && in_array('html', CHAT_BLOCKED_EXTENSIONS, true) && in_array('svg', CHAT_BLOCKED_EXTENSIONS, true));
check('download API exists and uses ACL', strpos((string) file_get_contents(__DIR__ . '/../apiv1/chat/download_attachment.php'), 'chat_can_access_conversation') !== false);
check('audit API remains admin-gated', strpos((string) file_get_contents(__DIR__ . '/../apiv1/chat/audit.php'), 'chat_require_admin_user') !== false);
check('retention worker remains CLI-locked', strpos((string) file_get_contents(__DIR__ . '/../cron/chat_retention_worker.php'), "PHP_SAPI !== 'cli'") !== false);
check('storage deny file present', is_file(dirname(__DIR__) . '/storage/chat/.htaccess'));
check('search still escapes in the client', strpos((string) file_get_contents(__DIR__ . '/../assets/dist/js/chat.js'), 'escapeHtml') !== false);

$send = (string) file_get_contents(__DIR__ . '/../apiv1/chat/send_message.php');
check('send remains CSRF-booted', strpos($send, 'chat_api_boot') !== false);

if ($failed) {
    exit(1);
}
echo "All PHP final security checks passed.\n";
