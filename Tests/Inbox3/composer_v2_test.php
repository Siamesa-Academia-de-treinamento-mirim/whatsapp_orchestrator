<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$passed = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passed): void {
    if ($condition) {
        echo "[OK] {$message}\n";
        $passed++;
        return;
    }
    echo "[FAIL] {$message}\n";
    $failures[] = $message;
};
$read = static function (string $relative) use ($root): string {
    $content = @file_get_contents($root . '/' . $relative);
    return is_string($content) ? $content : '';
};

$composer = $read('Assets/js/inbox/composer.js');
$state = $read('Assets/js/inbox/composer_state.js');
$quick = $read('Assets/js/inbox/composer_quick_replies.js');
$clipboard = $read('Assets/js/inbox/composer_clipboard.js');
$chat = $read('Assets/js/chatwoot.js');
$renderers = $read('Assets/js/inbox/message_renderers.js');
$actions = $read('Assets/js/inbox/message_actions.js');
$hub = $read('Assets/js/hub-workspace.js');
$scripts = $read('Views/partials/scripts.php');
$conversations = $read('Views/partials/conversations.php');
$controller = $read('Controllers/Conversations.php');
$chatService = $read('Services/Chat_service.php');
$media = $read('Services/Media_service.php');
$evolution = $read('Libraries/Evolution_client.php') . $read('Providers/Evolution_provider.php');
$meta = $read('Libraries/Meta_cloud_client.php') . $read('Providers/Meta_cloud_provider.php');
$capabilities = $read('Providers/Provider_capabilities.php');

$assert($composer !== '' && $state !== '' && $quick !== '' && $clipboard !== '', 'Composer V2 modules exist');
$assert(str_contains($scripts, 'inbox/composer_state.js') && str_contains($scripts, 'inbox/composer_clipboard.js') && str_contains($scripts, 'inbox/composer.js'), 'Composer modules are loaded after the existing workspace');
$assert(str_contains($state, 'impulso:composer:v2:') && str_contains($state, 'actorId'), 'Draft key is scoped by plugin and authenticated actor');
$serialStart = strpos($state, 'function serializable(');
$serialEnd = strpos($state, 'function read(', $serialStart === false ? 0 : $serialStart);
$serialBlock = $serialStart !== false && $serialEnd !== false ? substr($state, $serialStart, $serialEnd - $serialStart) : '';
$assert(str_contains($serialBlock, 'reply_target') && !str_contains($serialBlock, 'attachments'), 'Draft payload stores reply metadata but not attachment objects');
$assert(str_contains($composer, 'mode === \'note\'') && str_contains($composer, 'sendAttachment'), 'Note mode cannot route attachments to Media Engine');
$assert(str_contains($composer, 'event.isComposing') && str_contains($composer, 'event.key === \'Enter\''), 'Composer keyboard handler is centralized and IME-aware');
$assert(str_contains($composer, 'dragenter') && str_contains($composer, 'clipboardData'), 'Drag/drop and paste use the common attachment pipeline');
$assert(str_contains($composer, 'Clipboard.filesFromData') && str_contains($composer, 'Clipboard.shouldPreventDefault'), 'Clipboard files are extracted without replacing native text paste');
$assert(str_contains($state, 'draftLoaded') && str_contains($state, 'flushAll()') && str_contains($composer, "window.addEventListener('pagehide'"), 'Draft hydration is one-shot and dirty drafts flush on lifecycle switches/pagehide');
$assert(str_contains($composer, 'quickSourceRows') && str_contains($composer, 'quickVisibleRows') && str_contains($composer, "renderQuickReplies(quickSourceRows, '')"), 'Quick reply source and visible result sets remain separate without refetching');
$assert(str_contains($quick, 'contact.name') && str_contains($quick, 'agent.name') && str_contains($quick, 'replaceSlashToken'), 'Quick replies use an explicit variable allowlist and slash replacement');
$assert(str_contains($chat, 'reply_to_message_id') && str_contains($chat, 'state.pendingSends[clientId]') && !str_contains($chat, 'conversationChain'), 'Text send carries local reply id and tracks pending work by client message id without blocking later sends');
$sendStart = strpos($chat, 'function sendMessage(');
$sendEnd = strpos($chat, 'function retryMessage(', $sendStart === false ? 0 : $sendStart);
$sendBlock = $sendStart !== false && $sendEnd !== false ? substr($chat, $sendStart, $sendEnd - $sendStart) : '';
$assert(!str_contains($sendBlock, "input.value = ''"), 'Successful text send does not clear a newer composer value asynchronously');
$assert(str_contains($renderers, 'data-message-menu') && str_contains($actions, "result.push('reply')") && str_contains($actions, 'actions.reply'), 'Rendered messages expose a capability-gated context-menu reply action');
$assert(str_contains($controller, 'reply_to_message_id') && str_contains($chatService, 'resolveReplyTarget'), 'Backend accepts only a local reply target and validates it server-side');
$assert(str_contains($chatService, 'reply_to_external_message_id') && str_contains($chatService, 'reply_to_from_me'), 'Chat service persists and forwards resolved reply context');
$assert(str_contains($media, 'resolveReplyTarget') && str_contains($media, 'reply_target'), 'Media Engine validates and persists reply context for attachments');
$assert(str_contains($hub, 'sendContext') && str_contains($hub, 'reply_to_message_id'), 'Media retries preserve immutable caption and local reply context');
$assert(str_contains($chatService, 'ambiguous_failure') && str_contains($chatService, 'retryable_failure') && str_contains($chatService, 'Message_send_exception'), 'Text idempotency distinguishes retryable and ambiguous failures');
$projection = $read('Services/Message_projection_service.php');
$assert(str_contains($projection, 'local_message_id') && str_contains($projection, 'suggested_action'), 'Failed projections preserve local reply context and structured retry state');
$assert(substr_count($chat, 'state.activeConversationId =') === 1 && str_contains($chat, 'setActiveConversationId(null, \'filter\')') && str_contains($chat, "setActiveConversationId(null, 'search')"), 'Interactive conversation changes use one central transition helper');
$assert(str_contains($composer, "mode !== 'reply'"), 'Escape cancels a reply target only in Reply mode');
$assert(str_contains($composer, 'aria-expanded') && str_contains($conversations, 'impulso-drop-affordance'), 'Composer popovers and drag affordance expose accessibility state');
$assert(str_contains($evolution, "'quoted'") && str_contains($meta, "'context'"), 'Evolution and Meta adapters translate the common reply context independently');
$assert(substr_count($capabilities, "'reply' => true") >= 2, 'Both provider capability documents advertise operational reply support');
$assert(!preg_match('/provider\s*===|provider\s*!==|providerName/', $composer), 'Composer frontend has no provider-name branching');
$assert(str_contains($hub, 'window.ImpulsoHubMedia') && !str_contains($hub, "sendButton.addEventListener('click', composerSubmit") && !str_contains($hub, 'function composerSubmit(') && !str_contains($hub, 'function sendInternalNote('), 'Existing Media Engine is exposed without retaining the legacy composer event loop');
$assert(str_contains($chatService, 'IDEMPOTENCY_PAYLOAD_MISMATCH') && str_contains($chatService, 'existingText !== $text'), 'Text idempotency rejects a changed payload before row update or provider dispatch');
$assert(str_contains($media, 'source_sha256') && str_contains($media, 'source_size') && str_contains($media, 'source_detected_mime'), 'Media identity stores raw hash, size and detected MIME before provider dispatch');
$assert(str_contains($media, 'assertImmutableSource') && str_contains($media, 'MEDIA_SOURCE_IDENTITY_UNAVAILABLE'), 'Media retries fail closed when the original binary identity is unavailable or changed');
$assert(str_contains($media, "'not_attempted'") && str_contains($media, 'canRetryState') && str_contains($hub, "['retryable_failure', 'not_attempted']"), 'Retry controls distinguish retryable and not-attempted media from ambiguous and rejected states');
$assert(str_contains($composer, "quickQuery === ''") && str_contains($composer, "renderQuickReplies(quickSourceRows, '')"), 'Quick Reply button restores the complete source after a slash-filtered view');
$assert(str_contains($composer, 'setPopoverState') && str_contains($composer, "document.addEventListener('click'"), 'Popover triggers and outside clicks use synchronized state transitions');
$assert(!str_contains($composer, 'var plainText ='), 'Unused paste plainText variable is absent');

echo "\n{$passed} passed, " . count($failures) . " failed.\n";
exit($failures === [] ? 0 : 1);
