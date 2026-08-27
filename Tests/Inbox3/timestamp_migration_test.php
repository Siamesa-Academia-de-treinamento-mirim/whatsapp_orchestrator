<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/Services/Message_projection_service.php';

use Chatwoot_plugin\Services\Message_projection_service;

$root = dirname(__DIR__, 2);
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

$migration = (string) file_get_contents($root . '/Database/Migrations/V010_Add_message_delivery_timestamps.php');
$reactionMigration = (string) file_get_contents($root . '/Database/Migrations/V013_Harden_chat_message_reactions.php');
$workflowMigration = (string) file_get_contents($root . '/Database/Migrations/V014_Add_conversation_workflow_snooze.php');
$model = (string) file_get_contents($root . '/Models/Chat_messages_model.php');
$service = (string) file_get_contents($root . '/Services/Chat_service.php');
$runner = (string) file_get_contents($root . '/Libraries/Migration_runner.php');
$reactionModel = (string) file_get_contents($root . '/Models/Chat_message_reactions_model.php');
$reactionService = (string) file_get_contents($root . '/Services/Message_reaction_service.php');
$assert(str_contains($migration, 'public const VERSION = 10'), 'V010 is versioned as migration 10');
$assert(str_contains($migration, "addColumn('delivered_at'"), 'V010 adds delivered_at additively');
$assert(str_contains($migration, "addColumn('read_at'"), 'V010 adds read_at additively');
$assert(str_contains($migration, 'fieldExists'), 'V010 is idempotent when rerun');
$assert(str_contains($migration, 'Forward-only'), 'V010 does not provide a destructive rollback');
$assert(str_contains($model, "'delivered_at'") && str_contains($model, "'read_at'"), 'message model permits receipt timestamp writes');
$assert(str_contains($service, 'providerEventDate'), 'status persistence validates provider event timestamps');
$assert(str_contains($service, "\$updatePayload['delivered_at'] = \$eventAt") && str_contains($service, "\$updatePayload['read_at'] = \$eventAt"), 'status persistence maps delivered/read event timestamps');
$assert(str_contains($runner, 'V010_Add_message_delivery_timestamps::VERSION'), 'migration runner registers V010');
$assert(str_contains($reactionMigration, 'public const VERSION = 13') && str_contains($reactionMigration, 'chat_message_reaction_changes'), 'V013 adds reaction ordering and monotonic change cursor');
$assert(str_contains($reactionMigration, 'V001–V012') || str_contains($reactionMigration, 'Forward-only'), 'V013 remains additive and forward-only');
$assert(str_contains($workflowMigration, 'public const VERSION = 14') && str_contains($workflowMigration, 'snoozed_until') && str_contains($workflowMigration, 'snoozed_by'), 'V014 persists conversation snooze state');
$assert(str_contains($runner, 'V014_Add_conversation_workflow_snooze::VERSION'), 'migration runner registers V014');
$baselinePosition = strpos($service, 'changesAfter($conversationId, null)');
$aggregatePosition = strpos($service, '$reactionMap = $this->messageReactions->aggregates');
$assert($baselinePosition !== false && $aggregatePosition !== false && $baselinePosition < $aggregatePosition, 'reset reaction baseline is captured before aggregate loading');
$assert(str_contains($reactionModel, 'transBegin') && str_contains($reactionModel, 'transRollback') && str_contains($reactionModel, 'appendChange'), 'V011 projection and change cursor share one transaction');
$assert(str_contains($reactionService, 'acquireReaction') && str_contains($reactionService, 'setAttemptPreviousState'), 'reaction state mutation captures previous state under its identity lock');

$projected = (new Message_projection_service())->project([
    'id' => 1,
    'conversation_id' => 2,
    'instance_id' => 3,
    'message_type' => 'text',
    'direction' => 'outgoing',
    'status' => 'read',
    'provider_name' => 'meta_cloud',
    'created_at' => '2026-08-16 12:00:00',
    'sent_at' => '2026-08-16 12:00:01',
    'delivered_at' => '2026-08-16 12:00:02',
    'read_at' => '2026-08-16 12:00:03',
    'failed_at' => null,
]);
$assert($projected['timestamps']['delivered_at'] === '2026-08-16T12:00:02+00:00', 'V2 projection exposes delivered_at');
$assert($projected['timestamps']['read_at'] === '2026-08-16T12:00:03+00:00', 'V2 projection exposes read_at');
$assert($projected['timestamps']['failed_at'] === null, 'V2 projection does not invent failed_at');

echo "\n{$passed} passed, " . count($failures) . " failed.\n";
if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, '- ' . $failure . PHP_EOL);
    exit(1);
}
