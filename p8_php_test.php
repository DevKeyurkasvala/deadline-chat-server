<?php
declare(strict_types=1);

$failed = 0;
function check(string $name, bool $ok): void
{
    global $failed;
    echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n";
    if (!$ok) {
        $failed++;
    }
}

$js = (string) file_get_contents(__DIR__ . '/../assets/dist/js/chat.js');
$rt = (string) file_get_contents(__DIR__ . '/../assets/dist/js/chat-realtime.js');
$css = (string) file_get_contents(__DIR__ . '/../assets/dist/css/chat.css');
$page = (string) file_get_contents(__DIR__ . '/../chat.php');

check('mention keyboard navigation exists', strpos($js, 'mentionKeydown') !== false && strpos($js, 'ArrowDown') !== false && strpos($js, 'Escape') !== false);
check('mention Enter selects instead of sending', strpos($js, 'mentionKeydown($(this), e)') !== false);
check('task drawer has mention list', strpos($js, 'taskChatMentionList') !== false && strpos($js, "maybeMention($(this))") !== false);
check('search has loading and retry', strpos($js, 'Searching…') !== false && strpos($js, 'js-retry-search') !== false);
check('conversation list has loading error retry', strpos($js, 'js-retry-convs') !== false && strpos($js, 'Loading conversations') !== false);
check('messages have retry', strpos($js, 'js-retry-messages') !== false);
check('session expiry stops further API calls', strpos($js, 'if (sessionExpiredShown)') !== false);
check('attachment chips show size and hide paths', strpos($js, 'fileSizeLabel') !== false && strpos($js, 'storage/chat') === false);
check('upload progress is reported', strpos($js, 'chat-upload-progress') !== false);
check('reply preview can jump to source', strpos($js, 'js-jump-reply') !== false);
check('conversation kinds remain labeled', strpos($js, "return 'Task'") !== false && strpos($js, "return 'Project'") !== false && strpos($js, "'Service'") !== false && strpos($js, "return 'Direct'") !== false);
check('mobile thread back button exists', strpos($js, 'chatBackBtn') !== false && strpos($css, 'is-thread') !== false);
check('message actions are not hover-only', strpos($css, 'chat-msg-actions') !== false && strpos($css, '.chat-msg-actions { display: none') === false);
check('focus-visible styles exist', strpos($css, 'focus-visible') !== false);
check('aria labels are present on page', strpos($page, 'aria-label="Conversations"') !== false && strpos($page, 'aria-label="Write a message"') !== false);
check('live status is announced', strpos($page, 'aria-live="polite"') !== false);
check('polling interval is configurable', strpos($rt, 'pollIntervalMs') !== false);
check('320px styles exist', strpos($css, '374.98px') !== false && strpos($css, '767.98px') !== false);

if ($failed) {
    exit(1);
}
echo "All PHP P8 checks passed.\n";
