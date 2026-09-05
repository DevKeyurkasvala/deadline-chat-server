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
check('rich messaging schema is installed', chat_rich_ready());
check('finfo is available', class_exists('finfo'));
check('edit window defaults to 15 minutes', chat_edit_window_seconds() === 900);
check('upload limit is 10 MB', CHAT_UPLOAD_MAX_BYTES === 10485760);
check('attachment count limit is 5', CHAT_UPLOAD_MAX_COUNT === 5);
check('total upload limit is 25 MB', CHAT_UPLOAD_MAX_TOTAL_BYTES === 26214400);
check('valid reactions are allowlisted', chat_allowed_reaction('👍') && !chat_allowed_reaction('fire'));
check('path traversal filename is sanitized', strpos(chat_sanitize_original_name('../../etc/passwd.php'), '..') === false);
check('php extension is blocked', in_array('php', CHAT_BLOCKED_EXTENSIONS, true));
check('svg is blocked', in_array('svg', CHAT_BLOCKED_EXTENSIONS, true));
check('jpeg maps to allowed image mime', isset(CHAT_ALLOWED_MIME['image/jpeg']));
check('zip is not an allowed chat mime', !isset(CHAT_ALLOWED_MIME['application/zip']));

$htaccess = dirname(__DIR__) . '/storage/chat/.htaccess';
check('chat storage denies direct web access', is_file($htaccess) && strpos((string) file_get_contents($htaccess), 'Require all denied') !== false);
check('chat storage is not the task upload directory', strpos(chat_storage_root(), '/uploads/tasks') === false);

$msg = task_select_one(
    "SELECT m.id, m.conversation_id, m.sender_id, m.body, m.created_at, m.deleted_at
     FROM chat_messages m
     INNER JOIN chat_participants p ON p.conversation_id = m.conversation_id AND p.user_id = 1
     WHERE m.deleted_at IS NULL
     ORDER BY m.id DESC LIMIT 1",
    '',
    []
);
if (!is_array($msg)) {
    $conv = task_select_one(
        "SELECT c.id FROM chat_conversations c
         INNER JOIN chat_participants p ON p.conversation_id = c.id AND p.user_id = 1
         LIMIT 1",
        '',
        []
    );
    if ($conv) {
        $sent = chat_send_message(1, (int) $conv['id'], 'P4 probe message');
        $msg = [
            'id' => (int) $sent['id'],
            'conversation_id' => (int) $conv['id'],
            'sender_id' => 1,
            'body' => 'P4 probe message',
            'created_at' => $sent['created_at'] ?? date('Y-m-d H:i:s'),
            'deleted_at' => null,
        ];
    }
}
check('message available for rich tests', is_array($msg));

if (is_array($msg)) {
    $messageId = (int) $msg['id'];
    $convId = (int) $msg['conversation_id'];
    check('message belongs to its conversation', chat_message_belongs_to_conversation($messageId, $convId));
    check('cross-conversation message id is rejected', !chat_message_belongs_to_conversation($messageId, 999999));

    $otherConv = task_select_one(
        'SELECT id FROM chat_conversations WHERE id <> ? LIMIT 1',
        'i',
        [$convId]
    );
    check(
        'cross-conversation reply target is rejected by membership',
        !$otherConv || !chat_message_belongs_to_conversation($messageId, (int) $otherConv['id'])
    );

    $toggle = chat_toggle_reaction(1, $messageId, '👍');
    check('valid reaction persists', isset($toggle['reactions']['counts']['👍']));
    $again = chat_toggle_reaction(1, $messageId, '👍');
    check('duplicate reaction toggles off', (($again['reactions']['counts']['👍'] ?? 1) <= ($toggle['reactions']['counts']['👍'] ?? 0)));
    check('invalid reaction is rejected by allowlist', !chat_allowed_reaction('not-a-reaction'));
    check('can_edit is sender-only', !chat_can_mutate_own_message(999888, $msg));
    check('sender can mutate own message when they are the sender', ((int) $msg['sender_id'] !== 1) || chat_can_mutate_own_message(1, $msg));
}

check('inaccessible conversation remains denied', !chat_can_access_conversation(1, [
    'id' => 999999,
    'type' => 'direct',
    'task_id' => null,
]));

$src = file_get_contents(__DIR__ . '/../includes/chat_rich.php') ?: '';
check('reaction emit happens after persist', strpos($src, 'DELETE FROM chat_message_reactions') !== false && strpos($src, 'chat_emit_reaction') !== false && strpos($src, 'chat_emit_reaction') > strpos($src, 'INSERT INTO chat_message_reactions'));
check('edit emit happens after persist', strpos($src, 'edited_at = NOW()') !== false && strpos($src, 'chat_emit_message_edited') > strpos($src, 'edited_at = NOW()'));
check('delete emit happens after persist', strpos($src, 'deleted_at = NOW()') !== false && strpos($src, 'chat_emit_message_deleted') > strpos($src, 'deleted_at = NOW()'));
check('delete clears body so search cannot leak it', strpos($src, "body = ?") !== false);
$download = (string) file_get_contents(__DIR__ . '/../apiv1/chat/download_attachment.php');
check('download streams through ACL and hides missing files as 404', strpos($download, 'readfile') !== false && strpos($download, 'Attachment not found.') !== false);
$dtoStart = strpos($src, 'function chat_public_attachment');
$dto = $dtoStart === false ? '' : substr($src, $dtoStart, 500);
check('public attachment DTO omits filesystem path', $dto !== '' && strpos($dto, 'storage_path') === false && strpos($dto, 'original_name') !== false);

$searchSrc = file_get_contents(__DIR__ . '/../includes/chat_lib.php') ?: '';
check('search excludes deleted messages', strpos($searchSrc, 'WHERE m.deleted_at IS NULL') !== false);
check('search exposes reply and forwarded metadata', strpos($searchSrc, "'forwarded'") !== false);
check('view-only cannot send/edit/delete', strpos((string) file_get_contents(__DIR__ . '/../apiv1/chat/edit_message.php'), 'task_require_not_view_user') !== false);
check('view-only may react', strpos((string) file_get_contents(__DIR__ . '/../apiv1/chat/react.php'), 'task_require_not_view_user') === false);
check('send still uses CSRF boot', strpos((string) file_get_contents(__DIR__ . '/../apiv1/chat/send_message.php'), 'chat_api_boot') !== false);

if ($failed > 0) {
    fwrite(STDERR, $failed . " PHP P4 checks failed\n");
    exit(1);
}
echo "All PHP P4 checks passed.\n";
