<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$passed = 0;
$assert = static function (bool $condition, string $message) use (&$failures, &$passed): void {
    if ($condition) { echo "[OK] {$message}\n"; $passed++; return; }
    echo "[FAIL] {$message}\n"; $failures[] = $message;
};
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . '/' . $relative);
    return is_string($value) ? $value : '';
};

$renderers = $read('Assets/js/inbox/message_renderers.js');
$safe = $read('Assets/js/inbox/message_safe_content.js');
$actions = $read('Assets/js/inbox/message_actions.js');
$chat = $read('Assets/js/chatwoot.js');
$scripts = $read('Views/partials/scripts.php');
$view = $read('Views/partials/conversations.php');
$composer = $read('Assets/js/inbox/composer.js');
$normalizer = $read('Services/Webhook_normalizer.php') . $read('Services/Meta_webhook_normalizer.php');
$migration = $read('Database/Migrations/V011_Create_chat_message_reactions.php');
$attemptMigration = $read('Database/Migrations/V012_Create_chat_message_reaction_attempts.php');
$reactionService = $read('Services/Message_reaction_service.php');

$types = ['text', 'image', 'gallery', 'audio', 'voice', 'video', 'document', 'sticker', 'location', 'contact', 'template', 'interactive', 'internal_note', 'activity', 'unsupported', 'reaction'];
foreach ($types as $type) $assert((bool) preg_match('/\b' . preg_quote($type, '/') . '\s*:/', $renderers), "renderer registry names {$type}");
$assert(str_contains($renderers, "var registry =") && str_contains($renderers, ": 'unsupported'"), 'renderer dispatch has explicit registry and unsupported fallback');
$assert(str_contains($safe, 'safeHttpUrl') && str_contains($safe, 'noopener noreferrer nofollow'), 'safe content module controls autolink rel attributes');
$assert(str_contains($safe, 'MEDIA_PATH') && str_contains($safe, "parsed.protocol !== 'http:'"), 'media URLs are restricted to same-origin secure media paths');
$assert(str_contains($renderers, 'referrerpolicy="no-referrer"') && str_contains($renderers, 'coordinates'), 'media and location renderers use safe browser attributes and coordinate validation');
$assert(str_contains($renderers, 'data-message-jump-id') && str_contains($renderers, 'status-') && str_contains($renderers, 'failed'), 'reply jump and status output are explicit');
$assert(str_contains($actions, "setAttribute('role', 'menu')") && str_contains($actions, 'contextmenu') && str_contains($actions, 'create_quick_reply'), 'message actions are accessible and context-menu scoped');
$assert(str_contains($actions, 'quick-replies-invalidated') && str_contains($actions, 'quickReplies'), 'quick reply action uses existing API and invalidation bridge');
$assert(str_contains($composer, 'ImpulsoComposerBridge') && str_contains($composer, 'setReplyTarget'), 'Reply action uses the existing Composer V2 bridge');
$assert(str_contains($chat, 'ImpulsoMessageRenderers') && str_contains($chat, 'ImpulsoMessageActions') && !str_contains($chat, 'function mediaHtml('), 'Chatwoot delegates rendering/actions to modular message modules');
$assert(str_contains($scripts, 'message_safe_content.js') && str_contains($scripts, 'message_renderers.js') && str_contains($scripts, 'message_actions.js'), 'message modules load before the workspace runtime');
$assert(str_contains($view, 'impulso-message-context-menu') && str_contains($view, 'role="menu"'), 'message menu has a dedicated provider-neutral DOM surface');
$assert(str_contains($normalizer, "'reactor_key'") && str_contains($normalizer, "'provider_event_id'"), 'provider normalizers retain reaction identity without raw payload exposure');
$assert(str_contains($migration, 'V011') && str_contains($migration, 'uq_chat_reaction_target_actor') && str_contains($migration, 'uq_chat_reaction_client'), 'V011 reaction persistence is additive and idempotent');
$assert(str_contains($reactionService, 'conversationId') && str_contains($reactionService, 'applyIncoming') && str_contains($reactionService, 'aggregates'), 'reaction service targets the same conversation and aggregates active state');
$assert(str_contains($attemptMigration, 'requested_emoji') && str_contains($attemptMigration, 'uq_chat_reaction_attempt_client') && str_contains($attemptMigration, 'idx_chat_reaction_attempt_target_created'), 'V012 separates reaction attempts with immutable client identity');
$assert(str_contains($reactionService, 'normalizeRequest') && str_contains($reactionService, 'Extended_Pictographic'), 'reaction validation has explicit remove semantics and Unicode emoji validation');
$assert(str_contains($actions, 'impulso-reaction-picker') && str_contains($actions, 'Escolher reação') && !str_contains($actions, 'window.prompt'), 'reaction picker is compact, accessible and prompt-free');
$assert(str_contains($renderers, 'você reagiu') && str_contains($renderers, 'impulso-video-message') && !preg_match('/<button[^>]*>[^<]*<video/i', $renderers), 'reaction ownership and video interaction semantics are explicit');
$assert(str_contains($renderers, 'resolved_parameters') && str_contains($renderers, 'Abrir anexo') && str_contains($renderers, 'sender && message.sender.name'), 'template, unsupported attachment and note author fallbacks are rendered');
$assert(str_contains($chat, 'reaction_after') && str_contains($chat, 'mergeReactionUpdates') && !str_contains($chat, "onReaction: function () { loadMessages"), 'reaction updates merge the target without polling messages');
$assert(str_contains($normalizer, "? 'self'") && str_contains($normalizer, 'participant_jid'), 'Evolution reaction identity preserves self precedence and group participant context');

$directAssignments = preg_match_all('/state\.activeConversationId\s*=/', $chat, $matches);
$assert($directAssignments === 1 && str_contains($chat, 'notifyConversationChange'), 'interactive conversation state has one guarded assignment and one transition hook');
$assert(!str_contains($actions, "'delete'") && !str_contains($actions, "'translate'"), 'message menu omits out-of-scope destructive and translation actions');

echo "\n{$passed} passed, " . count($failures) . " failed.\n";
exit($failures === [] ? 0 : 1);
