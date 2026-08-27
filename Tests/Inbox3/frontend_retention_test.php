<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$javascript = (string) file_get_contents($root . '/Assets/js/chatwoot.js');
$workspaceJavascript = (string) file_get_contents($root . '/Assets/js/hub-workspace.js');
$chat = (string) file_get_contents($root . '/Services/Chat_service.php');
$mediaService = (string) file_get_contents($root . '/Services/Media_service.php');
$mediaController = (string) file_get_contents($root . '/Controllers/Media.php');
$passed = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$passed, &$failures): void {
    if ($condition) {
        ++$passed;
        echo "[OK] {$message}\n";
        return;
    }
    $failures[] = $message;
    echo "[FAIL] {$message}\n";
};

foreach (['function normalizeInstance', 'function normalizeConversation', 'function normalizeMessage'] as $function) {
    $assert(str_contains($javascript, $function), "frontend keeps {$function}");
}
foreach (['capabilities:', 'contract_version:', 'instance_details:'] as $field) {
    $assert(str_contains($javascript, $field), "frontend retains V2 conversation/instance field {$field}");
}
foreach (['content:', 'sender:', 'timestamps:', 'error:', 'actions:', 'metadata:', 'provider_message_id:', 'provider:', 'reply_to:', 'reactions:'] as $field) {
    $assert(str_contains($javascript, $field), "frontend retains V2 message field {$field}");
}
$assert(str_contains($chat, "'contract_version' => 2"), 'backend publishes V2 contract version');
$assert(str_contains($chat, "'capabilities' => Provider_capabilities::forProvider"), 'backend publishes capabilities on conversation data');
$assert(str_contains($chat, "'media_url' => \$mediaUrl"), 'legacy media URL remains mapped alongside V2 projection');
$assert(str_contains($workspaceJavascript, 'pendingAttachments'), 'media engine keeps an ordered pending attachment collection');
$assert(str_contains($workspaceJavascript, '/attachments/batch'), 'frontend uses the additive batch media endpoint');
$assert(str_contains($workspaceJavascript, 'voiceNote: true'), 'recorded audio carries voice-note intent to the backend');
$assert(str_contains($workspaceJavascript, 'URL.revokeObjectURL'), 'removed attachments release object URLs');
$assert(str_contains($workspaceJavascript, 'conversationId'), 'pending attachments retain their source conversation');
$assert(str_contains($workspaceJavascript, 'belongs to another conversation'), 'cross-conversation attachment send fails closed');
$assert(str_contains($workspaceJavascript, "'not_attempted'") && str_contains($workspaceJavascript, 'data-state'), 'partial batch keeps not-attempted state visible');
$assert(str_contains($workspaceJavascript, 'impulso-recording-waveform'), 'recorder renders a waveform canvas');
$assert(str_contains($workspaceJavascript, 'recordingAnalyser'), 'recorder owns analyser lifecycle');
$assert(str_contains($workspaceJavascript, 'recordingTimer'), 'recorder timer is explicitly cleaned up');
$assert(str_contains($workspaceJavascript, 'recordingAnimationFrame'), 'waveform animation frame is explicitly cleaned up');
$assert(str_contains($workspaceJavascript, 'pagehide'), 'pagehide cleans recorder resources and object URLs');
$assert(str_contains($workspaceJavascript, 'onConversationChange'), 'workspace subscribes to provider-neutral conversation change hook');
$assert(str_contains($workspaceJavascript, 'recordingToken'), 'late recorder callbacks are invalidated on conversation change');
$assert(str_contains($javascript, 'notifyConversationChange'), 'conversation change hook is emitted by the main workspace bridge');
$assert(str_contains($javascript, 'function setActiveConversationId'), 'active conversation changes use a provider-neutral helper');
$assert(str_contains($javascript, 'if (previousKey === nextKey) return false;'), 'same active conversation does not emit a redundant transition');
$activeAssignmentCount = preg_match_all('/state\.activeConversationId\s*=/', $javascript, $activeAssignments);
$assert($activeAssignmentCount === 1, 'interactive flows have no uncontrolled active conversation assignments');
foreach (['filter', 'search', 'channel', 'clear', 'selection'] as $reason) {
    $assert(str_contains($javascript, "setActiveConversationId(null, '{$reason}')") || str_contains($javascript, "setActiveConversationId(conversation.id, '{$reason}')"), "active conversation transition covers {$reason}");
}
$assert(str_contains($javascript, 'activeWasCleared') && str_contains($javascript, 'reconcileActiveConversationRecord'), 'full authoritative list reconciliation clears the active record without reinserting it');
foreach (['renderAttachmentPreview', 'clearAttachment', 'removeAttachment', 'setAttachments', 'sendAttachment', 'startVoiceRecording'] as $function) {
    $count = preg_match_all('/function\s+' . preg_quote($function, '/') . '\s*\(/', $workspaceJavascript, $declarations);
    $assert($count === 1, "media composer has exactly one {$function} declaration");
}
$assert(str_contains($mediaController, 'Media_engine_exception ? $exception->details()'), 'single media endpoint preserves structured engine details');
$assert(str_contains($mediaService, "\$result['details'] = \$exception->details()") && str_contains($mediaService, "\$details = \$exception->details()") && str_contains($mediaService, "\$results[\$index]['details'] = \$details"), 'batch media items preserve structured engine details');
$assert(str_contains($mediaService, 'source_sha256') && str_contains($mediaService, 'IDEMPOTENCY_PAYLOAD_MISMATCH'), 'media binary identity is immutable across single and batch retries');
$assert(str_contains($mediaService, "['rejected', 'failed', 'not_attempted']"), 'batch not-attempted items contribute to deterministic failure state');
$assert(str_contains($workspaceJavascript, "['retryable_failure', 'not_attempted']") && str_contains($workspaceJavascript, 'ambiguous_failure'), 'frontend exposes retry only for safe media states');
$assert(str_contains($javascript, 'apiException.details = payload && payload.details ? payload.details : {};'), 'frontend retains structured media error details');
$assert(str_contains($chat, 'Send_lock_service'), 'text send lock remains in the shared idempotency path');

echo "\n{$passed} passed, " . count($failures) . " failed.\n";
if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . PHP_EOL);
    exit(1);
}
