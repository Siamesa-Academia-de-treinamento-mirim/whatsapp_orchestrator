<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$requiredFiles = [
    'AGENTS.md',
    'CODEX_START_HERE.md',
    'INBOX3_HANDOFF_APPLY.md',
    'docs/inbox3/README.md',
    'docs/inbox3/SCOPE_AND_GUARDRAILS.md',
    'docs/inbox3/CURRENT_STATE_AUDIT.md',
    'docs/inbox3/TARGET_ARCHITECTURE.md',
    'docs/inbox3/MESSAGE_CONTRACT_V2.md',
    'docs/inbox3/CONVERSATION_CONTRACT_V2.md',
    'docs/inbox3/PROVIDER_CAPABILITIES_V2.md',
    'docs/inbox3/MEDIA_ENGINE_V2.md',
    'docs/inbox3/COMPOSER_V2.md',
    'docs/inbox3/MESSAGE_UI_AND_ACTIONS.md',
    'docs/inbox3/TEMPLATES_AND_SERVICE_WINDOW.md',
    'docs/inbox3/INBOX_WORKFLOW_V2.md',
    'docs/inbox3/COLLABORATION_AND_PRODUCTIVITY.md',
    'docs/inbox3/DATABASE_MIGRATION_PLAN.md',
    'docs/inbox3/API_SURFACE_PLAN.md',
    'docs/inbox3/REFERENCE_CHATWOOT.md',
    'docs/inbox3/IMPLEMENTATION_ROADMAP.md',
    'docs/inbox3/TEST_STRATEGY.md',
    'docs/inbox3/DEFINITION_OF_DONE.md',
    'Tests/Inbox3/media_engine_test.php',
    'Tests/Inbox3/composer_v2_test.php',
    'Tests/Inbox3/composer_v2_test.js',
    'Tests/Inbox3/message_ui_test.php',
    'Tests/Inbox3/message_ui_test.js',
    'Tests/Inbox3/phase5_test.php',
    'Database/Migrations/V010_Add_message_delivery_timestamps.php',
    'Database/Migrations/V012_Create_chat_message_reaction_attempts.php',
    'Database/Migrations/V013_Harden_chat_message_reactions.php',
    'Database/Migrations/V015_Collaboration_productivity.php',
];

$failures = [];
$passes = 0;

$assert = static function (bool $condition, string $message) use (&$failures, &$passes): void {
    if ($condition) {
        echo "[OK] {$message}\n";
        $passes++;
        return;
    }

    echo "[FAIL] {$message}\n";
    $failures[] = $message;
};

foreach ($requiredFiles as $relative) {
    $assert(is_file($root . '/' . $relative), "handoff file exists: {$relative}");
}

$read = static function (string $relative) use ($root): string {
    $content = @file_get_contents($root . '/' . $relative);
    return is_string($content) ? $content : '';
};

$agents = $read('AGENTS.md');
$start = $read('CODEX_START_HERE.md');
$roadmap = $read('docs/inbox3/IMPLEMENTATION_ROADMAP.md');
$database = $read('docs/inbox3/DATABASE_MIGRATION_PLAN.md');
$scope = $read('docs/inbox3/SCOPE_AND_GUARDRAILS.md');
$testing = $read('docs/inbox3/TEST_STRATEGY.md');

$assert(str_contains($agents, 'docs/inbox3/README.md'), 'AGENTS points to the Inbox 3 index');
$assert(str_contains($agents, 'Do not copy Chatwoot code wholesale'), 'AGENTS forbids copying Chatwoot wholesale');
$assert(str_contains($agents, 'V010 owns delivery/read'), 'AGENTS records V010 delivery/read timestamps');
$assert(str_contains($agents, 'V011 owns confirmed current reaction state'), 'AGENTS records V011 confirmed reaction state');
$assert(str_contains($agents, 'V012 owns outbound'), 'AGENTS records V012 outbound attempts');
$assert(str_contains($agents, 'V013 owns reaction ordering/status/rollback'), 'AGENTS records V013 ordering/status/rollback/change cursor');
$assert(str_contains($agents, 'V014 owns durable conversation snooze state'), 'AGENTS records V014 conversation snooze state');
$assert(str_contains($agents, 'V001-V015 are historical'), 'AGENTS protects V001-V015 as historical');
$assert(str_contains($agents, 'php Tests/run_inbox3_handoff.php'), 'AGENTS includes the handoff integrity test');
$assert(str_contains($start, 'Phase 1 complete') && str_contains($start, 'Phase 8 complete'), 'Codex start file records completed phases 1-8');
$assert(str_contains($start, 'Inbox 3 roadmap status = COMPLETE') && str_contains($start, 'No post-roadmap phase is authorized') && !str_contains($start, 'Current ' . 'authorized phase'), 'Codex start file records the completed roadmap boundary');
$assert(str_contains($roadmap, '## Phase 8'), 'Phase 8 release documentation remains indexed');
$assert(str_contains($start, 'V015 is the last migration') && str_contains($start, 'V016 is reserved'), 'Codex start file preserves the final migration boundary');

for ($phase = 0; $phase <= 8; $phase++) {
    $assert(str_contains($roadmap, "## Phase {$phase}"), "roadmap defines Phase {$phase}");
}
$assert(substr_count($roadmap, '### Gate') >= 9, 'every roadmap phase has an explicit gate');
$assert(str_contains($database, 'V001–V013'), 'database plan protects historical migrations V001-V013');
$assert(str_contains($database, '**V010**'), 'database plan starts Inbox 3 migrations at V010');
$assert(str_contains($database, '**V012**'), 'database plan defines the reaction attempts migration');
$assert(str_contains($database, '**V013**'), 'database plan defines the Reaction Engine hardening migration');
$assert(str_contains($database, 'Implemented additively in **V014**') && str_contains($database, 'snoozed_until'), 'database plan records the Phase 6 snooze migration');
$assert(str_contains($database, 'V015 is forward-only') && str_contains($database, 'chat_saved_views'), 'database plan records the Phase 7 migration');
$assert(str_contains($scope, 'Explicitly out of scope'), 'scope document has an explicit exclusion section');
$assert(str_contains($scope, 'Captain or generative AI'), 'scope explicitly excludes Captain/generative AI');
$assert(str_contains($scope, 'SLA policy'), 'scope explicitly excludes SLA');
$assert(str_contains($testing, 'Manual real-provider release matrix'), 'test strategy includes real-provider release validation');
$assert(str_contains($testing, 'Rise/database suite'), 'test strategy separates environment-dependent integration tests');

// Validate links from the Inbox 3 index without trying to resolve URL/anchor links.
$index = $read('docs/inbox3/README.md');
preg_match_all('/\[[^\]]+\]\(([^)#]+\.md)\)/', $index, $matches);
foreach ($matches[1] ?? [] as $target) {
    $assert(is_file($root . '/docs/inbox3/' . $target), "Inbox 3 index link resolves: {$target}");
}

echo "\n{$passes} passed, " . count($failures) . " failed.\n";
exit($failures === [] ? 0 : 1);
