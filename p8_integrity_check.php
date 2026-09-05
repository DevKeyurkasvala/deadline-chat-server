<?php
declare(strict_types=1);

session_start();
$_SESSION['id'] = 1;
$_SESSION['role'] = 2;

require_once dirname(__DIR__) . '/includes/chat_lib.php';

chat_require_schema();
$findings = [];

function count_sql(string $sql): int
{
    $row = task_select_one($sql, '', []);
    return (int) ($row['cnt'] ?? 0);
}

$findings['orphan_participants'] = count_sql(
    'SELECT COUNT(*) AS cnt FROM chat_participants p
     LEFT JOIN chat_conversations c ON c.id = p.conversation_id
     WHERE c.id IS NULL'
);
$findings['orphan_messages'] = count_sql(
    'SELECT COUNT(*) AS cnt FROM chat_messages m
     LEFT JOIN chat_conversations c ON c.id = m.conversation_id
     WHERE c.id IS NULL'
);
$findings['orphan_attachments'] = 0;
$findings['invalid_reactions'] = 0;
$findings['invalid_mentions'] = 0;
$findings['invalid_replies'] = count_sql(
    'SELECT COUNT(*) AS cnt FROM chat_messages m
     LEFT JOIN chat_messages src ON src.id = m.reply_to_message_id
     WHERE m.reply_to_message_id IS NOT NULL AND m.reply_to_message_id > 0 AND src.id IS NULL'
);
$findings['zero_reply_ids'] = count_sql(
    'SELECT COUNT(*) AS cnt FROM chat_messages WHERE reply_to_message_id = 0'
);
$findings['invalid_forwards'] = count_sql(
    'SELECT COUNT(*) AS cnt FROM chat_messages m
     LEFT JOIN chat_messages src ON src.id = m.forwarded_from_message_id
     WHERE m.forwarded_from_message_id IS NOT NULL AND m.forwarded_from_message_id > 0 AND src.id IS NULL'
);
$findings['broken_task_links'] = count_sql(
    "SELECT COUNT(*) AS cnt FROM chat_conversations c
     LEFT JOIN tasks t ON t.id = c.task_id
     WHERE c.type = 'task' AND (c.task_id IS NULL OR t.id IS NULL)"
);
$findings['broken_project_links'] = count_sql(
    "SELECT COUNT(*) AS cnt FROM chat_conversations c
     LEFT JOIN task_projects p ON p.id = c.project_id
     WHERE c.type = 'project' AND (c.project_id IS NULL OR p.id IS NULL)"
);
$findings['broken_client_links'] = count_sql(
    "SELECT COUNT(*) AS cnt FROM chat_conversations c
     LEFT JOIN clients cl ON cl.id = c.client_id
     WHERE c.type = 'client' AND (c.client_id IS NULL OR cl.id IS NULL)"
);
$findings['duplicate_direct_pairs'] = count_sql(
    "SELECT COUNT(*) AS cnt FROM (
        SELECT pair_key FROM chat_conversations
        WHERE type = 'direct' AND pair_key IS NOT NULL
        GROUP BY pair_key HAVING COUNT(*) > 1
     ) d"
);
$findings['duplicate_task_rooms'] = count_sql(
    "SELECT COUNT(*) AS cnt FROM (
        SELECT task_id FROM chat_conversations
        WHERE type = 'task' AND task_id IS NOT NULL
        GROUP BY task_id HAVING COUNT(*) > 1
     ) d"
);

if (function_exists('chat_rich_ready') && chat_rich_ready()) {
    $findings['orphan_attachments'] = count_sql(
        'SELECT COUNT(*) AS cnt FROM chat_attachments a
         LEFT JOIN chat_messages m ON m.id = a.message_id
         WHERE m.id IS NULL'
    );
    $findings['invalid_reactions'] = count_sql(
        'SELECT COUNT(*) AS cnt FROM chat_message_reactions r
         LEFT JOIN chat_messages m ON m.id = r.message_id
         WHERE m.id IS NULL'
    );
}
if (function_exists('chat_collab_ready') && chat_collab_ready()) {
    $findings['invalid_mentions'] = count_sql(
        'SELECT COUNT(*) AS cnt FROM chat_message_mentions n
         LEFT JOIN chat_messages m ON m.id = n.message_id
         WHERE m.id IS NULL'
    );
}

$notes = ['zero_reply_ids'];
$corrupt = [];
foreach ($findings as $key => $count) {
    if (in_array($key, $notes, true)) {
        echo ($count === 0 ? 'PASS ' : 'NOTE ') . $key . '=' . $count . "\n";
        continue;
    }
    echo ($count === 0 ? 'PASS ' : 'FAIL ') . $key . '=' . $count . "\n";
    if ($count > 0) {
        $corrupt[] = $key . '=' . $count;
    }
}

if ($corrupt !== []) {
    echo "INTEGRITY_ISSUES " . implode(', ', $corrupt) . "\n";
    echo "No silent repair was attempted.\n";
    exit(1);
}
echo "All blocking chat integrity checks passed. No repairs were attempted.\n";
if (($findings['zero_reply_ids'] ?? 0) > 0) {
    echo "DOCUMENTED: {$findings['zero_reply_ids']} message(s) store reply_to_message_id=0 instead of NULL. Treated as no-reply by application code. Not repaired.\n";
}
