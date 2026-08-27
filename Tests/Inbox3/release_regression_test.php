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
    $content = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($content) ? $content : '';
};

$start = $read('CODEX_START_HERE.md');
$handoff = $read('CODEX_HANDOFF.md');
$applyHandoff = $read('INBOX3_HANDOFF_APPLY.md');
$roadmap = $read('docs/inbox3/IMPLEMENTATION_ROADMAP.md');
$definition = $read('docs/inbox3/DEFINITION_OF_DONE.md');
$chat = $read('Assets/js/chatwoot.js');
$workspace = $read('Assets/js/hub-workspace.js');
$styles = $read('Views/partials/styles.php');
$conversationView = $read('Views/partials/conversations.php');
$modals = $read('Views/modals/common.php');
$scripts = $read('Views/partials/scripts.php');
$templatePicker = $read('Assets/js/inbox/template_picker.js');
$staticQuality = $read('Tests/run_static_quality.sh');
$composer = $read('Assets/js/inbox/composer.js');
$composerState = $read('Assets/js/inbox/composer_state.js');
$workflow = $read('Assets/js/inbox/conversation_workflow.js');
$presence = $read('Assets/js/inbox/presence.js');
$bulk = $read('Assets/js/inbox/bulk_actions.js');
$savedViews = $read('Assets/js/inbox/saved_views.js');
$mentions = $read('Assets/js/inbox/mentions.js');
$presenceService = $read('Services/Conversation_presence_service.php');
$savedViewController = $read('Controllers/Saved_views.php');
$bulkService = $read('Services/Conversation_bulk_action_service.php');

foreach (range(1, 7) as $phase) {
    $assert(str_contains($start, "Phase {$phase} complete"), "phase {$phase} is recorded complete");
}
$assert(str_contains($start, 'Phase 8 complete'), 'Phase 8 is recorded complete');
$assert(str_contains($start, 'Inbox 3 roadmap status = COMPLETE') && str_contains($start, 'No post-roadmap phase is authorized'), 'roadmap is complete and has no authorized successor phase');
$assert(!str_contains($start, 'Current ' . 'authorized phase') && !str_contains($handoff, 'Current ' . 'authorized phase') && !str_contains($applyHandoff, 'Current ' . 'authorized phase'), 'completed documentation has no active-phase marker');
$assert(str_contains($start, 'V015 is the last migration') && str_contains($start, 'V016 is reserved'), 'final migration boundary is documented');
$assert(str_contains($handoff, 'Inbox 3 roadmap status = COMPLETE') && str_contains($applyHandoff, 'roadmap status = COMPLETE'), 'handoff documents record the completed roadmap');
$assert(str_starts_with($staticQuality, "#!/usr/bin/env sh\nset -eu\n") && !str_contains($staticQuality, "\r\n"), 'static-quality script is POSIX and LF-only');
$assert(substr_count($roadmap, '## Phase ') >= 9 && substr_count($roadmap, '### Gate') >= 9, 'roadmap retains all phase gates');
$assert(str_contains($definition, 'Keyboard/focus behavior remains usable'), 'definition of done retains frontend accessibility gate');

$migrationFiles = glob($root . '/Database/Migrations/V*.php') ?: [];
$versions = [];
foreach ($migrationFiles as $migrationFile) {
    if (preg_match('/[\\/]V(\d+)_/', $migrationFile, $match)) $versions[] = (int) $match[1];
}
sort($versions);
$assert($versions !== [] && max($versions) === 15 && !in_array(16, $versions, true), 'migration set ends at V015 and has no V016 implementation');

$assert(str_contains($scripts, 'message_renderers.js') && str_contains($scripts, 'conversation_workflow.js') && str_contains($scripts, 'template_picker.js'), 'critical frontend modules are loaded explicitly');
$assert(!str_contains($chat, 'Legacy inline template picker') && !str_contains($chat, 'data-template-media-link') && !str_contains($chat, 'data-template-media-id'), 'known replaced picker paths remain removed');
$assert(substr_count($chat, "window.addEventListener('pagehide'") === 1, 'chat lifecycle owns one pagehide listener');
$assert(str_contains($chat, 'clearTimeout(state.pollingTimer)') && str_contains($chat, 'runtime.timers.push(state.pollingTimer)'), 'polling timer has one replaceable lifecycle boundary');
$assert(substr_count($workspace, "window.addEventListener('pagehide'") === 1, 'media lifecycle owns one pagehide listener');

$assert(str_contains($chat, 'activeConversationRecord') && str_contains($chat, 'activeConversationDetached') && str_contains($chat, 'openConversationById'), 'detached active conversation invariants remain wired');
$assert(str_contains($composerState, 'conversationId') && str_contains($composerState, 'mode') && str_contains($composerState, 'draftKey') && str_contains($composerState, 'storage'), 'composer drafts remain conversation and mode scoped');
$assert(str_contains($templatePicker, 'sessions') && str_contains($templatePicker, 'attemptsByFingerprint') && str_contains($templatePicker, 'sessionMatches'), 'template picker keeps session and attempt isolation');
$assert(str_contains($presence, 'viewing') && str_contains($presence, 'typing') && str_contains($presence, 'sequence'), 'presence keeps bounded lifecycle and conversation context');
$assert(str_contains($presenceService, 'ON DUPLICATE KEY UPDATE') && str_contains($presenceService, 'typing_until'), 'presence persistence remains atomic and field-specific');
$assert(str_contains($savedViews, 'owner') || str_contains($savedViewController, 'actor'), 'saved views remain actor-scoped');
$assert(str_contains($bulk, 'state.bulkSelectedIds = failed') && str_contains($bulk, 'render()'), 'bulk partial state reconciles immediately');
$assert(str_contains($read('Controllers/Conversations.php'), 'requireManageConversationsPermission') && str_contains($bulkService, 'conversation_ids'), 'bulk backend retains authorization authority');
$assert(str_contains($mentions, 'role',) && str_contains($mentions, 'aria-selected') && str_contains($mentions, 'aria-activedescendant') && str_contains($mentions, 'input.focus()'), 'mention picker projects listbox state onto the focused textarea');

$assert(str_contains($conversationView, 'role="group"') && str_contains($conversationView, 'aria-pressed') && !str_contains($conversationView, 'role="tab"'), 'queue and channel filters expose button filter semantics');
$assert(str_contains($chat, 'aria-pressed') && !str_contains($chat, 'role="tab"'), 'filter button state is synchronized without a tab system');
$assert(str_contains($chat, 'data-bulk-select') && str_contains($chat, 'aria-label="Selecionar'), 'bulk selection remains keyboard-labelled');
$assert(str_contains($conversationView, 'role="dialog"') && str_contains($conversationView, 'impulso-custom-snooze'), 'custom snooze remains an explicit dialog surface');
$assert(str_contains($styles, 'focus-visible') && str_contains($styles, 'overflow-wrap: anywhere'), 'focus and long-content visual safeguards are present');
foreach (['max-width: 1480px', 'max-width: 1100px', 'max-width: 840px', 'max-width: 575.98px', '--impulso-available-height'] as $responsiveRule) {
    $assert(str_contains($styles, $responsiveRule), "responsive rule exists: {$responsiveRule}");
}
$assert(str_contains($styles, 'impulso-template-option') && str_contains($styles, 'impulso-template-field'), 'template picker has deliberate list and form states');
$assert(str_contains($styles, 'impulso-media-stage') && str_contains($styles, 'overscroll-behavior'), 'media viewer is constrained to the viewport');
$assert(str_contains($modals, 'impulso-media-modal'), 'media viewer remains available through the existing modal');

echo "\n{$passed} passed, " . count($failures) . " failed.\n";
exit($failures === [] ? 0 : 1);
