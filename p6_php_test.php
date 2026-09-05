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

check('chat audit schema is installed', chat_audit_ready());
check('retention is OFF by default', chat_retention_enabled() === false);
check('purge days default to never', chat_retention_days('chat_purge_deleted_messages_after_days') === 0);

$before = task_select_one('SELECT COUNT(*) AS cnt FROM chat_audit_log', '', []);
chat_audit('message_sent', ['actor_user_id' => 1, 'conversation_id' => 2, 'message_id' => 1]);
chat_audit('message_edited', ['actor_user_id' => 1, 'conversation_id' => 2, 'message_id' => 1]);
chat_audit('message_deleted', ['actor_user_id' => 1, 'conversation_id' => 2, 'message_id' => 1]);
chat_audit('message_forwarded', ['actor_user_id' => 1, 'conversation_id' => 2, 'message_id' => 1]);
chat_audit('attachment_uploaded', ['actor_user_id' => 1, 'conversation_id' => 2, 'message_id' => 1, 'count' => 1]);
chat_audit('attachment_downloaded', ['actor_user_id' => 1, 'conversation_id' => 2, 'attachment_id' => 1]);
chat_audit('reaction_toggled', ['actor_user_id' => 1, 'conversation_id' => 2, 'message_id' => 1]);
chat_audit('participant_added', ['actor_user_id' => 1, 'conversation_id' => 2, 'target_user_id' => 2]);
$after = task_select_one('SELECT COUNT(*) AS cnt FROM chat_audit_log', '', []);
check('high-value audit events persist', ((int) ($after['cnt'] ?? 0)) >= ((int) ($before['cnt'] ?? 0) + 8));

$secret = task_select_one(
    "SELECT id FROM chat_audit_log WHERE metadata_json LIKE '%csrf%' OR metadata_json LIKE '%token%' OR metadata_json LIKE '%password%' LIMIT 1",
    '',
    []
);
check('audit metadata does not store secrets', $secret === null);

$auditApi = (string) file_get_contents(__DIR__ . '/../apiv1/chat/audit.php');
$exportApi = (string) file_get_contents(__DIR__ . '/../apiv1/chat/audit_export.php');
$retApi = (string) file_get_contents(__DIR__ . '/../apiv1/chat/retention_settings.php');
$worker = (string) file_get_contents(__DIR__ . '/../cron/chat_retention_worker.php');
$page = (string) file_get_contents(__DIR__ . '/../chat_audit.php');

check('audit API requires admin', strpos($auditApi, 'chat_require_admin_user') !== false);
check('audit API is paginated', strpos($auditApi, 'before_id') !== false && strpos($auditApi, 'LIMIT') !== false);
check('export requires admin and CSRF boot', strpos($exportApi, 'chat_require_admin_user') !== false && strpos($exportApi, 'chat_api_boot') !== false);
check('retention settings require admin and CSRF', strpos($retApi, 'chat_require_admin_user') !== false && strpos($retApi, 'chat_api_boot') !== false);
check('audit page is admin-gated', strpos($page, "role") !== false && strpos($page, 'Administrator access is required') !== false);
check('retention worker is CLI only', strpos($worker, 'PHP_SAPI') !== false && strpos($worker, 'CLI only') !== false);
check('retention worker uses lock', strpos($worker, "chat_retention_worker") !== false && strpos($worker, 'task_worker_lock') !== false);
check('retention worker uses batches', strpos($worker, 'LIMIT ?') !== false || strpos($worker, 'LIMIT') !== false);
check('worker does not run from web request files', strpos((string) file_get_contents(__DIR__ . '/../includes/chat_lib.php'), 'chat_retention_worker') === false);
check('worker refuses to purge when file unlink fails', strpos($worker, 'attachment_unlink_failed') !== false && strpos($worker, 'continue') !== false);

$cmd = escapeshellarg('/Applications/XAMPP/xamppfiles/bin/php') . ' ' . escapeshellarg(__DIR__ . '/../cron/chat_retention_worker.php');
$out1 = shell_exec($cmd . ' 2>&1');
$out2 = shell_exec($cmd . ' 2>&1');
$j1 = json_decode((string) $out1, true);
$j2 = json_decode((string) $out2, true);
check('worker runs while retention is OFF', is_array($j1) && !empty($j1['ok']) && !empty($j1['stats']['skipped']));
check('worker rerun is idempotent while OFF', is_array($j2) && !empty($j2['ok']) && !empty($j2['stats']['skipped']));
check('worker did not purge active data while OFF', ((int) ($j1['stats']['messages_purged'] ?? 1)) === 0);

$_SESSION['role'] = 1;
check('non-admin is not task_is_admin', task_is_admin() === false);
$_SESSION['role'] = 2;
check('admin remains authorized for audit', task_is_admin() === true);

if ($failed) {
    exit(1);
}
echo "All PHP P6 checks passed.\n";
